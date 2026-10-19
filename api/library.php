<?php
require_once __DIR__ . '/../includes/lib_core.php';

// Раздел «Библиотека»: книги, загрузка, результаты обработки, синхронизация.
$UID = libRequireApi();

set_exception_handler(function (Throwable $e) {
    error_log('library api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    jsonErr('Ошибка сервера: ' . $e->getMessage(), 500);
});

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$NOW    = date('Y-m-d H:i:s');
libEnsureTables($pdo);

function input(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function libJson(array $data, int $code): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function str($v, int $max): string {
    return mb_substr(trim((string)$v), 0, $max);
}

function ownBook(PDO $pdo, int $uid, int $id): array {
    $st = $pdo->prepare("SELECT * FROM lib_books WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    $b = $st->fetch();
    if (!$b) jsonErr('Книга не найдена', 404);
    return $b;
}

function bookById(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM lib_books WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch();
}

function warningsOf(array $b): array {
    $w = $b['warnings'] ? json_decode($b['warnings'], true) : null;
    return is_array($w) ? $w + ['upload' => [], 'process' => []] : ['upload' => [], 'process' => []];
}

function usedSpace(PDO $pdo, int $uid): int {
    $st = $pdo->prepare("SELECT COALESCE(SUM(orig_size + derived_size), 0) FROM lib_books WHERE user_id = ?");
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

function validUuid(string $s): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

function validDevice(string $s): bool {
    return (bool)preg_match('/^[A-Za-z0-9-]{8,40}$/', $s);
}

const META_FIELDS = ['title' => 500, 'authors' => 500, 'language' => 20, 'publisher' => 255, 'year' => 20,
                     'series' => 255, 'identifier' => 255, 'description' => 20000];
const READ_STATUSES = ['new', 'want', 'planned', 'reading', 'paused', 'finished'];

// ══════════════════════════════════════════════════════════════════════════════

// ── СПИСОК КНИГ (+ коллекции и категории) ─────────────────────────────────────
if ($action === 'books' && $method === 'GET') {
    $st = $pdo->prepare("SELECT * FROM lib_books WHERE user_id = ? ORDER BY created_at DESC, id DESC");
    $st->execute([$UID]);
    $books = array_map(fn($b) => libBookOut($b), $st->fetchAll());

    $st = $pdo->prepare("SELECT id, kind, name FROM lib_collections WHERE user_id = ? ORDER BY name");
    $st->execute([$UID]);
    $cols = array_map(fn($c) => ['id' => (int)$c['id'], 'kind' => $c['kind'], 'name' => $c['name']], $st->fetchAll());

    $st = $pdo->prepare("SELECT bc.book_id, bc.collection_id FROM lib_book_collections bc
                         JOIN lib_collections c ON c.id = bc.collection_id WHERE c.user_id = ?");
    $st->execute([$UID]);
    $links = array_map(fn($r) => [(int)$r['book_id'], (int)$r['collection_id']], $st->fetchAll());

    jsonOk(['books' => $books, 'collections' => $cols, 'links' => $links,
            'used' => usedSpace($pdo, $UID), 'quota' => LIB_QUOTA, 'max_file' => LIB_MAX_FILE, 'chunk' => LIB_CHUNK]);
}

if ($action === 'book' && $method === 'GET') {
    $b = ownBook($pdo, $UID, (int)($_GET['id'] ?? 0));
    $out = libBookOut($b, true);
    $st = $pdo->prepare("SELECT collection_id FROM lib_book_collections WHERE book_id = ?");
    $st->execute([$b['id']]);
    $out['collections'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    jsonOk(['book' => $out]);
}

// ── ПРАВКА: метаданные, статус чтения, избранное ──────────────────────────────
if ($action === 'book_save' && $method === 'POST') {
    $d = input();
    $b = ownBook($pdo, $UID, (int)($d['id'] ?? 0));
    $set = []; $args = [];
    $edited = $b['meta_edited'] === '' ? [] : explode(',', $b['meta_edited']);
    foreach (META_FIELDS as $f => $max) {
        if (!array_key_exists($f, $d)) continue;
        $v = str($d[$f], $max);
        if ($v === (string)$b[$f]) continue;
        $set[] = "$f = ?"; $args[] = $v;
        if (!in_array($f, $edited, true)) $edited[] = $f;   // обработка больше это поле не трогает
    }
    if ($set) { $set[] = 'meta_edited = ?'; $args[] = implode(',', $edited); }

    if (array_key_exists('read_status', $d)) {
        $rs = (string)$d['read_status'];
        if (!in_array($rs, READ_STATUSES, true)) jsonErr('Неверный статус');
        $set[] = 'read_status = ?'; $args[] = $rs;
        if ($rs === 'finished' && !$b['finished_at']) { $set[] = 'finished_at = ?'; $args[] = $NOW; }
        if ($rs !== 'finished') { $set[] = 'finished_at = NULL'; }
    }
    if (array_key_exists('favorite', $d)) { $set[] = 'favorite = ?'; $args[] = $d['favorite'] ? 1 : 0; }

    if ($set) {
        $set[] = 'updated_at = ?'; $args[] = $NOW;
        $args[] = $b['id'];
        $pdo->prepare("UPDATE lib_books SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
    }
    jsonOk(['book' => libBookOut(bookById($pdo, (int)$b['id']), true)]);
}

if ($action === 'book_del' && $method === 'DELETE') {
    $b = ownBook($pdo, $UID, (int)($_GET['id'] ?? 0));
    foreach (['lib_positions', 'lib_annotations', 'lib_book_collections', 'lib_sessions'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE book_id = ?")->execute([$b['id']]);
    }
    $pdo->prepare("DELETE FROM lib_books WHERE id = ?")->execute([$b['id']]);
    libRemoveDir(libPath(libBookDir($UID, (int)$b['id'])));
    jsonOk();
}

// ── ЗАГРУЗКА ЧАСТЯМИ (с докачкой после обрыва) ────────────────────────────────
if ($action === 'upload_init' && $method === 'POST') {
    $d    = input();
    $name = str(basename(str_replace('\\', '/', (string)($d['name'] ?? ''))), 255);
    $size = (int)($d['size'] ?? 0);
    $lm   = (int)($d['last_modified'] ?? 0);
    if ($name === '' || $size <= 0) jsonErr('Пустой файл');
    if ($size > LIB_MAX_FILE) jsonErr('Файл больше ' . round(LIB_MAX_FILE / 1048576) . ' МБ');
    if (usedSpace($pdo, $UID) + $size > LIB_QUOTA) jsonErr('Библиотека заполнена: превышен лимит ' . round(LIB_QUOTA / 1073741824, 1) . ' ГБ');
    $tmpDir = libDir('tmp');
    $free = @disk_free_space($tmpDir);
    if ($free !== false && $free < $size * 1.1 + 50 * 1048576) jsonErr('На сервере не хватает места для этого файла');

    // старые недогруженные файлы (больше 2 суток) — убираем
    $old = $pdo->prepare("SELECT id FROM lib_uploads WHERE updated_at < ?");
    $old->execute([date('Y-m-d H:i:s', time() - 172800)]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oid) {
        @unlink("$tmpDir/$oid.part");
        $pdo->prepare("DELETE FROM lib_uploads WHERE id = ?")->execute([$oid]);
    }

    $st = $pdo->prepare("SELECT id FROM lib_uploads WHERE user_id = ? AND name = ? AND size = ? AND last_modified = ?");
    $st->execute([$UID, $name, $size, $lm]);
    $id = $st->fetchColumn();
    if ($id && is_file("$tmpDir/$id.part")) {
        clearstatcache();
        jsonOk(['id' => $id, 'received' => filesize("$tmpDir/$id.part"), 'chunk' => LIB_CHUNK]);
    }
    $id = bin2hex(random_bytes(16));
    touch("$tmpDir/$id.part");
    $pdo->prepare("INSERT INTO lib_uploads (id, user_id, name, size, received, last_modified, created_at, updated_at)
                   VALUES (?,?,?,?,0,?,?,?)")->execute([$id, $UID, $name, $size, $lm, $NOW, $NOW]);
    jsonOk(['id' => $id, 'received' => 0, 'chunk' => LIB_CHUNK]);
}

function ownUpload(PDO $pdo, int $uid, string $id): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) jsonErr('Неверная загрузка');
    $st = $pdo->prepare("SELECT * FROM lib_uploads WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    $u = $st->fetch();
    if (!$u) jsonErr('Загрузка не найдена — начните заново', 404);
    return $u;
}

if ($action === 'upload_chunk' && $method === 'POST') {
    $u      = ownUpload($pdo, $UID, (string)($_GET['id'] ?? ''));
    $offset = (int)($_GET['offset'] ?? -1);
    $tmp    = libDir('tmp') . '/' . $u['id'] . '.part';
    clearstatcache();
    $cur = is_file($tmp) ? filesize($tmp) : 0;
    if ($offset !== $cur) libJson(['ok' => false, 'error' => 'Сдвиг не совпадает', 'received' => $cur], 409);

    $in  = fopen('php://input', 'rb');
    $out = fopen($tmp, 'ab');
    $n   = stream_copy_to_stream($in, $out, LIB_CHUNK * 2 + 1);
    if ($n === false || $n > LIB_CHUNK * 2 || $cur + $n > (int)$u['size']) {
        ftruncate($out, $cur);
        fclose($out);
        jsonErr('Неверный размер куска');
    }
    fclose($out);
    $pdo->prepare("UPDATE lib_uploads SET received = ?, updated_at = ? WHERE id = ?")->execute([$cur + $n, $NOW, $u['id']]);
    jsonOk(['received' => $cur + $n]);
}

if ($action === 'upload_cancel' && $method === 'DELETE') {
    $u = ownUpload($pdo, $UID, (string)($_GET['id'] ?? ''));
    @unlink(libDir('tmp') . '/' . $u['id'] . '.part');
    $pdo->prepare("DELETE FROM lib_uploads WHERE id = ?")->execute([$u['id']]);
    jsonOk();
}

// Завершение: проверяем содержимое и сохраняем оригинал.
// ?as=derived — это производная EPUB-версия уже существующей книги.
if ($action === 'upload_finish' && $method === 'POST') {
    $d   = input();
    $u   = ownUpload($pdo, $UID, (string)($d['id'] ?? ''));
    $tmp = libDir('tmp') . '/' . $u['id'] . '.part';
    clearstatcache();
    if (!is_file($tmp) || filesize($tmp) !== (int)$u['size']) jsonErr('Файл загружен не полностью — продолжите загрузку');
    $dropUpload = function () use ($pdo, $u, $tmp) {
        @unlink($tmp);
        $pdo->prepare("DELETE FROM lib_uploads WHERE id = ?")->execute([$u['id']]);
    };

    // ── производная версия ──
    if (($d['as'] ?? '') === 'derived') {
        $b    = ownBook($pdo, $UID, (int)($d['book_id'] ?? 0));
        $conv = (string)($d['converter'] ?? '');
        $ver  = (int)($d['converter_version'] ?? 0);
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $conv) || $ver < 1 || $ver > 9999) { $dropUpload(); jsonErr('Неверный конвертер'); }
        $det = libDetectFormat($tmp, 'derived.epub');
        if ($det['format'] !== 'epub') { $dropUpload(); jsonErr('Производная версия должна быть EPUB'); }
        $dir = libDir(libBookDir($UID, (int)$b['id']));
        rename($tmp, "$dir/derived.epub");
        $pdo->prepare("DELETE FROM lib_uploads WHERE id = ?")->execute([$u['id']]);
        $pdo->prepare("UPDATE lib_books SET derived_format = 'epub', derived_path = ?, derived_size = ?,
                       converter = ?, converter_version = ?, status_at = ?, updated_at = ? WHERE id = ?")
            ->execute([libBookDir($UID, (int)$b['id']) . '/derived.epub', (int)$u['size'], $conv, $ver, $NOW, $NOW, $b['id']]);
        jsonOk(['book' => libBookOut(bookById($pdo, (int)$b['id']), true)]);
    }

    // ── новая книга ──
    $det = libDetectFormat($tmp, $u['name']);
    $sha = hash_file('sha256', $tmp);

    $st = $pdo->prepare("SELECT * FROM lib_books WHERE user_id = ? AND orig_sha256 = ?");
    $st->execute([$UID, $sha]);
    if ($dup = $st->fetch()) {
        $dropUpload();
        jsonOk(['duplicate' => true, 'book' => libBookOut($dup, true)]);
    }

    $ext = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
    if (preg_match('/\.fb2\.zip$/i', $u['name'])) $ext = 'fb2.zip';
    $format = $det['format'];
    $warn = [];
    if ($det['note']) $warn[] = $det['note'];
    if ($format) {
        $expected = LIB_FORMATS[$format]['ext'];
        $aliases = ['fb2.zip' => ['fbz', 'zip'], 'mobi' => ['prc', 'azw'], 'azw3' => ['azw', 'kf8', 'mobi'], 'djvu' => ['djv'], 'txt' => ['text', 'md']];
        if ($ext !== '' && $ext !== $expected && !in_array($ext, $aliases[$expected] ?? [], true)) {
            $warn[] = 'Расширение «.' . $ext . '», а по содержимому это ' . LIB_FORMATS[$format]['label'];
        }
    }

    if ($det['drm']) {
        $status = 'unsupported';
        $error  = 'Файл защищён DRM. Обходить защиту библиотека не будет — откройте книгу в официальном приложении магазина (Kindle, Adobe Digital Editions и т. п.). Оригинал сохранён.';
    } elseif (!$format) {
        $status = 'unsupported';
        $error  = ($det['reason'] ?: 'Формат не распознан') . '. Оригинал сохранён, его можно скачать.';
    } else {
        $status = LIB_FORMATS[$format]['convert'] ? 'needs_conversion' : 'uploaded';
        $error  = null;
    }
    $storeFormat = $format ?: (preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'unknown');
    $storeExt    = $format ? LIB_FORMATS[$format]['ext'] : (preg_match('/^[a-z0-9.]{1,10}$/', $ext) ? $ext : 'bin');

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO lib_books (user_id, orig_name, orig_format, orig_size, orig_sha256, status, status_at,
                   error, warnings, drm, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$UID, $u['name'], $storeFormat, (int)$u['size'], $sha, $status, $NOW, $error,
                   json_encode(['upload' => $warn, 'process' => []], JSON_UNESCAPED_UNICODE), $det['drm'] ? 1 : 0, $NOW, $NOW]);
    $bookId = (int)$pdo->lastInsertId();
    $rel = libBookDir($UID, $bookId) . '/original.' . $storeExt;
    libDir(libBookDir($UID, $bookId));
    if (!rename($tmp, libPath($rel))) { $pdo->rollBack(); jsonErr('Не удалось сохранить файл'); }
    $pdo->prepare("UPDATE lib_books SET orig_path = ? WHERE id = ?")->execute([$rel, $bookId]);
    $pdo->prepare("DELETE FROM lib_uploads WHERE id = ?")->execute([$u['id']]);
    $pdo->commit();
    jsonOk(['duplicate' => false, 'book' => libBookOut(bookById($pdo, $bookId), true)]);
}

// ── ОБРАБОТКА (идёт в браузере, сюда — её результаты) ─────────────────────────
// Взять книгу в обработку: чтобы два устройства не делали одно и то же.
if ($action === 'process_claim' && $method === 'POST') {
    $d     = input();
    $b     = ownBook($pdo, $UID, (int)($d['id'] ?? 0));
    $force = !empty($d['force']);
    $fmt   = LIB_FORMATS[$b['orig_format']] ?? null;
    $stale = !$b['status_at'] || strtotime($b['status_at']) < time() - 900;
    $s     = $b['status'];

    $can = $fmt && !$b['drm'] && (
        in_array($s, ['uploaded', 'needs_conversion'], true)
        || (in_array($s, ['checking', 'converting'], true) && $stale)
        || ($force && in_array($s, ['error', 'ready', 'converted', 'checking', 'converting', 'unsupported'], true))
    );
    if (!$can) jsonOk(['claimed' => false, 'book' => libBookOut($b, true)]);

    $new = $fmt['convert'] ? 'converting' : 'checking';
    $pdo->prepare("UPDATE lib_books SET status = ?, status_at = ?, error = NULL WHERE id = ?")->execute([$new, $NOW, $b['id']]);
    jsonOk(['claimed' => true, 'book' => libBookOut(bookById($pdo, (int)$b['id']), true)]);
}

if ($action === 'process_result' && $method === 'POST') {
    $d   = input();
    $b   = ownBook($pdo, $UID, (int)($d['id'] ?? 0));
    $fmt = LIB_FORMATS[$b['orig_format']] ?? null;
    $w   = warningsOf($b);
    $w['process'] = array_values(array_map(fn($x) => str($x, 500), array_slice((array)($d['warnings'] ?? []), 0, 50)));

    if (empty($d['ok']) || !$fmt) {
        $pdo->prepare("UPDATE lib_books SET status = 'error', status_at = ?, error = ?, warnings = ? WHERE id = ?")
            ->execute([$NOW, str($d['error'] ?? 'Неизвестная ошибка обработки', 2000), json_encode($w, JSON_UNESCAPED_UNICODE), $b['id']]);
        jsonOk(['book' => libBookOut(bookById($pdo, (int)$b['id']), true)]);
    }
    if ($fmt['convert'] && $b['derived_path'] === '') jsonErr('Сначала нужно загрузить производную версию');

    $set = ['status = ?', 'status_at = ?', 'error = NULL', 'warnings = ?', 'updated_at = ?'];
    $args = [$fmt['convert'] ? 'converted' : 'ready', $NOW, json_encode($w, JSON_UNESCAPED_UNICODE), $NOW];

    // метаданные из файла не затирают то, что поправлено руками, и пустыми не перезаписываются
    $edited = $b['meta_edited'] === '' ? [] : explode(',', $b['meta_edited']);
    foreach (META_FIELDS as $f => $max) {
        $v = str($d['meta'][$f] ?? '', $max);
        if ($v === '' || in_array($f, $edited, true)) continue;
        $set[] = "$f = ?"; $args[] = $v;
    }
    if (isset($d['toc']) && is_array($d['toc'])) {
        $toc = json_encode($d['toc'], JSON_UNESCAPED_UNICODE);
        if (strlen($toc) <= 2000000) { $set[] = 'toc = ?'; $args[] = $toc; }
    }
    if (array_key_exists('pages', $d))    { $set[] = 'pages = ?';    $args[] = $d['pages'] !== null ? max(0, (int)$d['pages']) : null; }
    if (array_key_exists('has_text', $d)) { $set[] = 'has_text = ?'; $args[] = $d['has_text'] === null ? null : ($d['has_text'] ? 1 : 0); }
    $hash = str($d['structure_hash'] ?? '', 64);
    if ($hash !== '') {
        $set[] = 'structure_hash = ?'; $args[] = $hash;
        if ($b['structure_hash'] !== '' && $b['structure_hash'] !== $hash) $set[] = 'structure_changed = 1';
    }
    $args[] = $b['id'];
    $pdo->prepare("UPDATE lib_books SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
    jsonOk(['book' => libBookOut(bookById($pdo, (int)$b['id']), true)]);
}

// Пользователь видел предупреждение «структура изменилась» — снимаем флаг
if ($action === 'structure_ack' && $method === 'POST') {
    $b = ownBook($pdo, $UID, (int)(input()['id'] ?? 0));
    $pdo->prepare("UPDATE lib_books SET structure_changed = 0 WHERE id = ?")->execute([$b['id']]);
    jsonOk();
}

if ($action === 'cover_upload' && $method === 'POST') {
    $b = ownBook($pdo, $UID, (int)($_GET['id'] ?? 0));
    $data = (string)file_get_contents('php://input', false, null, 0, 3 * 1048576 + 1);
    if (strlen($data) > 3 * 1048576) jsonErr('Обложка больше 3 МБ');
    $type = str_starts_with($data, "\xFF\xD8\xFF") ? 'jpg'
          : (str_starts_with($data, "\x89PNG") ? 'png'
          : (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP' ? 'webp' : ''));
    if ($type === '') jsonErr('Обложка должна быть JPEG, PNG или WebP');
    $dir = libDir(libBookDir($UID, (int)$b['id']));
    foreach (['jpg', 'png', 'webp'] as $t) @unlink("$dir/cover.$t");
    file_put_contents("$dir/cover.$type", $data);
    $pdo->prepare("UPDATE lib_books SET cover_type = ?, status_at = ? WHERE id = ?")->execute([$type, $NOW, $b['id']]);
    jsonOk(['cover' => true]);
}

// ── ПОЗИЦИЯ ЧТЕНИЯ ────────────────────────────────────────────────────────────
if ($action === 'positions' && $method === 'GET') {
    $b = ownBook($pdo, $UID, (int)($_GET['id'] ?? 0));
    $st = $pdo->prepare("SELECT device_id, device_name, locator, fraction, label, client_ts, server_at
                         FROM lib_positions WHERE book_id = ? ORDER BY client_ts DESC");
    $st->execute([$b['id']]);
    $rows = array_map(fn($r) => [
        'device_id' => $r['device_id'], 'device_name' => $r['device_name'],
        'locator' => json_decode($r['locator'], true), 'fraction' => (float)$r['fraction'],
        'label' => $r['label'], 'client_ts' => (int)$r['client_ts'], 'server_at' => $r['server_at'],
    ], $st->fetchAll());
    jsonOk(['positions' => $rows, 'server_ts' => (int)(microtime(true) * 1000)]);
}

if ($action === 'position' && $method === 'POST') {
    $d   = input();
    $b   = ownBook($pdo, $UID, (int)($d['book_id'] ?? 0));
    $dev = (string)($d['device_id'] ?? '');
    $ts  = (int)($d['client_ts'] ?? 0);
    $loc = json_encode($d['locator'] ?? null, JSON_UNESCAPED_UNICODE);
    if (!validDevice($dev) || $ts <= 0 || !is_array($d['locator'] ?? null) || strlen($loc) > 8000) jsonErr('Неверная позиция');

    $st = $pdo->prepare("SELECT client_ts FROM lib_positions WHERE book_id = ? AND device_id = ?");
    $st->execute([$b['id'], $dev]);
    $prev = $st->fetchColumn();
    // из очереди пришла более старая позиция, чем уже сохранённая — не откатываем
    if ($prev !== false && (int)$prev > $ts) jsonOk(['stale' => true, 'saved_at' => $NOW]);

    $pdo->prepare("REPLACE INTO lib_positions (book_id, device_id, user_id, device_name, locator, fraction, label, client_ts, server_at)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$b['id'], $dev, $UID, str($d['device_name'] ?? '', 80), $loc,
                   max(0, min(1, (float)($d['fraction'] ?? 0))), str($d['label'] ?? '', 255), $ts, $NOW]);

    $st = $pdo->prepare("SELECT fraction, label FROM lib_positions WHERE book_id = ? ORDER BY client_ts DESC LIMIT 1");
    $st->execute([$b['id']]);
    $last = $st->fetch();
    $pdo->prepare("UPDATE lib_books SET progress = ?, position_label = ? WHERE id = ?")
        ->execute([(float)$last['fraction'], $last['label'], $b['id']]);
    jsonOk(['stale' => false, 'saved_at' => $NOW]);
}

// Книгу открыли: «последнее открытие» и автоматически «Читаю сейчас»
if ($action === 'opened' && $method === 'POST') {
    $b = ownBook($pdo, $UID, (int)(input()['id'] ?? 0));
    $rs = in_array($b['read_status'], ['new', 'want', 'planned'], true) ? 'reading' : $b['read_status'];
    $pdo->prepare("UPDATE lib_books SET last_opened_at = ?, read_status = ? WHERE id = ?")->execute([$NOW, $rs, $b['id']]);
    jsonOk(['read_status' => $rs]);
}

// ── ЗАКЛАДКИ, ВЫДЕЛЕНИЯ, ЗАМЕТКИ, ЦИТАТЫ ──────────────────────────────────────
function annOut(array $r): array {
    return [
        'id' => $r['id'], 'book_id' => (int)$r['book_id'], 'type' => $r['type'],
        'locator' => json_decode($r['locator'], true), 'text' => $r['text'], 'note' => $r['note'],
        'color' => $r['color'], 'chapter' => $r['chapter'], 'fraction' => (float)$r['fraction'],
        'created_ts' => (int)$r['created_ts'], 'updated_ts' => (int)$r['updated_ts'],
        'deleted' => (bool)$r['deleted'], 'server_at' => $r['server_at'],
    ];
}

if ($action === 'annotations' && $method === 'GET') {
    $sql = "SELECT * FROM lib_annotations WHERE user_id = ?";
    $args = [$UID];
    if (!empty($_GET['book_id'])) { $sql .= " AND book_id = ?"; $args[] = (int)$_GET['book_id']; }
    if (!empty($_GET['since']))   { $sql .= " AND server_at >= ?"; $args[] = (string)$_GET['since']; }
    if (empty($_GET['with_deleted'])) $sql .= " AND deleted = 0";
    $st = $pdo->prepare($sql . " ORDER BY created_ts");
    $st->execute($args);
    jsonOk(['items' => array_map('annOut', $st->fetchAll()), 'server_at' => $NOW]);
}

// Пакет изменений с устройства. Более старая правка не затирает более новую:
// такой элемент возвращается с серверной версией.
if ($action === 'annotations_sync' && $method === 'POST') {
    $items = array_slice((array)(input()['items'] ?? []), 0, 500);
    $st = $pdo->prepare("SELECT id FROM lib_books WHERE user_id = ?");
    $st->execute([$UID]);
    $own = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $get = $pdo->prepare("SELECT * FROM lib_annotations WHERE id = ?");
    $put = $pdo->prepare("REPLACE INTO lib_annotations (id, user_id, book_id, type, locator, text, note, color, chapter,
                          fraction, created_ts, updated_ts, deleted, server_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $results = [];
    foreach ($items as $it) {
        $id = (string)($it['id'] ?? '');
        if (!validUuid($id)) { $results[] = ['id' => $id, 'status' => 'invalid']; continue; }
        $bookId = (int)($it['book_id'] ?? 0);
        if (!isset($own[$bookId])) { $results[] = ['id' => $id, 'status' => 'book_missing']; continue; }
        $type = (string)($it['type'] ?? '');
        $loc  = json_encode($it['locator'] ?? null, JSON_UNESCAPED_UNICODE);
        $upd  = (int)($it['updated_ts'] ?? 0);
        if (!in_array($type, ['bookmark', 'highlight', 'note', 'quote'], true) || !is_array($it['locator'] ?? null)
            || strlen($loc) > 16000 || $upd <= 0) { $results[] = ['id' => $id, 'status' => 'invalid']; continue; }

        $get->execute([$id]);
        $cur = $get->fetch();
        if ($cur && (int)$cur['user_id'] !== $UID) { $results[] = ['id' => $id, 'status' => 'invalid']; continue; }
        if ($cur && (int)$cur['updated_ts'] > $upd) { $results[] = ['id' => $id, 'status' => 'older', 'server' => annOut($cur)]; continue; }

        $put->execute([$id, $UID, $bookId, $type, $loc,
            mb_substr((string)($it['text'] ?? ''), 0, 20000), mb_substr((string)($it['note'] ?? ''), 0, 20000),
            str($it['color'] ?? '', 16), str($it['chapter'] ?? '', 255), max(0, min(1, (float)($it['fraction'] ?? 0))),
            $cur ? (int)$cur['created_ts'] : max(1, (int)($it['created_ts'] ?? $upd)), $upd, !empty($it['deleted']) ? 1 : 0, $NOW]);
        $results[] = ['id' => $id, 'status' => 'ok'];
    }
    jsonOk(['results' => $results, 'server_at' => $NOW]);
}

// ── НАСТРОЙКИ ЧТЕНИЯ (общие для всех устройств) ───────────────────────────────
if ($action === 'settings' && $method === 'GET') {
    $st = $pdo->prepare("SELECT data, updated_ts FROM lib_settings WHERE user_id = ?");
    $st->execute([$UID]);
    $r = $st->fetch();
    jsonOk(['data' => $r ? (json_decode($r['data'], true) ?: new stdClass) : new stdClass, 'updated_ts' => $r ? (int)$r['updated_ts'] : 0]);
}

if ($action === 'settings' && $method === 'POST') {
    $d = input();
    $ts = (int)($d['updated_ts'] ?? 0);
    $json = json_encode($d['data'] ?? new stdClass, JSON_UNESCAPED_UNICODE);
    if (!is_array($d['data'] ?? null) || strlen($json) > 20000 || $ts <= 0) jsonErr('Неверные настройки');
    $st = $pdo->prepare("SELECT data, updated_ts FROM lib_settings WHERE user_id = ?");
    $st->execute([$UID]);
    $r = $st->fetch();
    if ($r && (int)$r['updated_ts'] > $ts) jsonOk(['stale' => true, 'data' => json_decode($r['data'], true), 'updated_ts' => (int)$r['updated_ts']]);
    $pdo->prepare("REPLACE INTO lib_settings (user_id, data, updated_ts) VALUES (?,?,?)")->execute([$UID, $json, $ts]);
    jsonOk(['stale' => false]);
}

// ── КОЛЛЕКЦИИ И КАТЕГОРИИ ─────────────────────────────────────────────────────
function ownCollection(PDO $pdo, int $uid, int $id): array {
    $st = $pdo->prepare("SELECT * FROM lib_collections WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    $c = $st->fetch();
    if (!$c) jsonErr('Коллекция не найдена', 404);
    return $c;
}

if ($action === 'collection_add' && $method === 'POST') {
    $d = input();
    $name = str($d['name'] ?? '', 120);
    $kind = ($d['kind'] ?? '') === 'category' ? 'category' : 'collection';
    if ($name === '') jsonErr('Введите название');
    $st = $pdo->prepare("SELECT COUNT(*) FROM lib_collections WHERE user_id = ? AND kind = ? AND name = ?");
    $st->execute([$UID, $kind, $name]);
    if ($st->fetchColumn()) jsonErr($kind === 'category' ? 'Такая категория уже есть' : 'Такая коллекция уже есть');
    $pdo->prepare("INSERT INTO lib_collections (user_id, kind, name, created_at) VALUES (?,?,?,?)")->execute([$UID, $kind, $name, $NOW]);
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'collection_save' && $method === 'POST') {
    $d = input();
    $c = ownCollection($pdo, $UID, (int)($d['id'] ?? 0));
    $name = str($d['name'] ?? '', 120);
    if ($name === '') jsonErr('Введите название');
    $pdo->prepare("UPDATE lib_collections SET name = ? WHERE id = ?")->execute([$name, $c['id']]);
    jsonOk();
}

if ($action === 'collection_del' && $method === 'DELETE') {
    $c = ownCollection($pdo, $UID, (int)($_GET['id'] ?? 0));
    $pdo->prepare("DELETE FROM lib_book_collections WHERE collection_id = ?")->execute([$c['id']]);
    $pdo->prepare("DELETE FROM lib_collections WHERE id = ?")->execute([$c['id']]);
    jsonOk();
}

// Задать, в каких коллекциях/категориях лежит книга
if ($action === 'book_collections' && $method === 'POST') {
    $d = input();
    $b = ownBook($pdo, $UID, (int)($d['book_id'] ?? 0));
    $st = $pdo->prepare("SELECT id FROM lib_collections WHERE user_id = ?");
    $st->execute([$UID]);
    $own = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $ids = array_values(array_intersect(array_map('intval', (array)($d['ids'] ?? [])), $own));
    $pdo->prepare("DELETE FROM lib_book_collections WHERE book_id = ?")->execute([$b['id']]);
    $ins = $pdo->prepare("INSERT INTO lib_book_collections (book_id, collection_id) VALUES (?,?)");
    foreach (array_unique($ids) as $cid) $ins->execute([$b['id'], $cid]);
    jsonOk(['ids' => $ids]);
}

// ── СЕАНСЫ ЧТЕНИЯ (дневник и статистика) ──────────────────────────────────────
if ($action === 'session' && $method === 'POST') {
    $d = input();
    $id = (string)($d['id'] ?? '');
    $b = ownBook($pdo, $UID, (int)($d['book_id'] ?? 0));
    $start = (int)($d['started_ts'] ?? 0); $end = (int)($d['ended_ts'] ?? 0);
    $sec = max(0, min(86400, (int)($d['seconds'] ?? 0)));
    if (!validUuid($id) || $start <= 0 || $end < $start) jsonErr('Неверный сеанс');
    $st = $pdo->prepare("SELECT user_id FROM lib_sessions WHERE id = ?");
    $st->execute([$id]);
    $owner = $st->fetchColumn();
    if ($owner !== false && (int)$owner !== $UID) jsonErr('Неверный сеанс');
    $pdo->prepare("REPLACE INTO lib_sessions (id, user_id, book_id, device_id, device_name, started_at, ended_at, seconds, from_fraction, to_fraction)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $UID, $b['id'], validDevice((string)($d['device_id'] ?? '')) ? $d['device_id'] : '', str($d['device_name'] ?? '', 80),
                   date('Y-m-d H:i:s', intdiv($start, 1000)), date('Y-m-d H:i:s', intdiv($end, 1000)), $sec,
                   max(0, min(1, (float)($d['from'] ?? 0))), max(0, min(1, (float)($d['to'] ?? 0)))]);
    jsonOk();
}

jsonErr('Неизвестный запрос', 400);
