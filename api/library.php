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
    $backup = ($d['purpose'] ?? '') === 'backup';          // архив для восстановления — не книга, лимиты другие
    if ($name === '' || $size <= 0) jsonErr('Пустой файл');
    if (!$backup && $size > LIB_MAX_FILE) jsonErr('Файл больше ' . round(LIB_MAX_FILE / 1048576) . ' МБ');
    if ($backup && $size > LIB_QUOTA + 1073741824) jsonErr('Архив больше допустимого объёма библиотеки');
    if (!$backup && usedSpace($pdo, $UID) + $size > LIB_QUOTA) jsonErr('Библиотека заполнена: превышен лимит ' . round(LIB_QUOTA / 1073741824, 1) . ' ГБ');
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
    foreach (glob("$tmpDir/backup-*.zip") ?: [] as $f) if (filemtime($f) < time() - 172800) @unlink($f);

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

    // ── архив для проверки или восстановления (дальше — restore_step) ──
    if (($d['as'] ?? '') === 'backup') {
        $list = libZipList($tmp);
        if (!$list || !isset($list['library.json'])) { $dropUpload(); jsonErr('Это не архив библиотеки: внутри нет library.json'); }
        $data = json_decode((string)libZipRead($tmp, $list['library.json'], 300 * 1048576), true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'ikhlas-library') { $dropUpload(); jsonErr('library.json повреждён или сделан другой программой'); }
        rename($tmp, libDir('tmp') . '/backup-' . $u['id'] . '.zip');
        $pdo->prepare("DELETE FROM lib_uploads WHERE id = ?")->execute([$u['id']]);
        jsonOk(['backup_id' => $u['id'], 'books' => count((array)($data['books'] ?? [])), 'created_at' => $data['created_at'] ?? null]);
    }

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

// ── ПОИСК ПО ТЕКСТУ КНИГ (индекс и распознанный текст) ────────────────────────
if ($action === 'search' && $method === 'GET') {
    $q      = trim((string)($_GET['q'] ?? ''));
    $bookId = (int)($_GET['book_id'] ?? 0);
    if (mb_strlen($q) < 2) jsonOk(['hits' => [], 'ocr' => false]);
    $sql  = "SELECT book_id, source, locator, body FROM lib_text WHERE user_id = ? AND body LIKE ?";
    $args = [$UID, '%' . addcslashes($q, '%_\\') . '%'];
    if ($bookId) { $sql .= " AND book_id = ?"; $args[] = $bookId; }
    $st = $pdo->prepare($sql . " ORDER BY book_id, source, seq LIMIT 500");
    $st->execute($args);
    $hits = []; $perBook = [];
    $qLen = mb_strlen($q);
    foreach ($st->fetchAll() as $r) {
        $b = (int)$r['book_id'];
        if (!$bookId && ($perBook[$b] ?? 0) >= 5) continue;
        $pos = mb_stripos($r['body'], $q);
        if ($pos === false) $pos = 0;                 // совпало по правилам сравнения базы (например, ё/е)
        $start = max(0, $pos - 70);
        $hits[] = ['book_id' => $b, 'source' => $r['source'], 'locator' => json_decode($r['locator'], true),
                   'snippet' => mb_substr($r['body'], $start, $qLen + 140), 'query' => $q];
        $perBook[$b] = ($perBook[$b] ?? 0) + 1;
        if (count($hits) >= ($bookId ? 300 : 100)) break;
    }
    $ocr = false;
    if ($bookId) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM lib_text WHERE user_id = ? AND book_id = ? AND source = 'ocr'");
        $c->execute([$UID, $bookId]);
        $ocr = (int)$c->fetchColumn() > 0;
    }
    jsonOk(['hits' => $hits, 'ocr' => $ocr]);
}

// ── ИНДЕКС ТЕКСТА (текст извлекает браузер, library/js/indexer.js) ────────────
if ($action === 'index_put' && $method === 'POST') {
    $d = input();
    $b = ownBook($pdo, $UID, (int)($d['book_id'] ?? 0));
    $pdo->beginTransaction();
    if (!empty($d['replace'])) $pdo->prepare("DELETE FROM lib_text WHERE book_id = ? AND source = 'text'")->execute([$b['id']]);
    $ins = $pdo->prepare("REPLACE INTO lib_text (user_id, book_id, source, seq, locator, body) VALUES (?,?,?,?,?,?)");
    foreach (array_slice((array)($d['chunks'] ?? []), 0, 3000) as $c) {
        $text = mb_substr(trim((string)($c['text'] ?? '')), 0, 100000);
        $loc  = json_encode($c['locator'] ?? null, JSON_UNESCAPED_UNICODE);
        if ($text === '' || !is_array($c['locator'] ?? null) || strlen($loc) > 500) continue;
        $ins->execute([$UID, $b['id'], 'text', max(0, (int)($c['seq'] ?? 0)), $loc, $text]);
    }
    if (!empty($d['done'])) $pdo->prepare("UPDATE lib_books SET indexed_at = ? WHERE id = ?")->execute([$NOW, $b['id']]);
    $pdo->commit();
    jsonOk();
}

if ($action === 'index_clear' && $method === 'POST') {
    $d = input();
    if (!empty($d['book_id'])) {
        $b = ownBook($pdo, $UID, (int)$d['book_id']);
        $pdo->prepare("DELETE FROM lib_text WHERE book_id = ?")->execute([$b['id']]);
        $pdo->prepare("UPDATE lib_books SET indexed_at = NULL, ocr_status = '', ocr_pages = 0 WHERE id = ?")->execute([$b['id']]);
    } else {
        $pdo->prepare("DELETE FROM lib_text WHERE user_id = ?")->execute([$UID]);
        $pdo->prepare("UPDATE lib_books SET indexed_at = NULL, ocr_status = '', ocr_pages = 0 WHERE user_id = ?")->execute([$UID]);
    }
    jsonOk();
}

// ── РАСПОЗНАВАНИЕ (OCR идёт в браузере, сюда — текст страниц) ─────────────────
if ($action === 'ocr_state' && $method === 'GET') {
    $b = ownBook($pdo, $UID, (int)($_GET['id'] ?? 0));
    $st = $pdo->prepare("SELECT seq FROM lib_text WHERE book_id = ? AND source = 'ocr' ORDER BY seq");
    $st->execute([$b['id']]);
    jsonOk(['pages_done' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), 'status' => $b['ocr_status'],
            'lang' => $b['ocr_lang'], 'pages' => $b['pages'] !== null ? (int)$b['pages'] : null]);
}

if ($action === 'ocr_put' && $method === 'POST') {
    $d = input();
    $b = ownBook($pdo, $UID, (int)($d['book_id'] ?? 0));
    if (!in_array($b['orig_format'], ['pdf', 'djvu'], true)) jsonErr('Распознавание — только для PDF и DjVu');
    $lang = substr(preg_replace('/[^a-z+]/', '', (string)($d['lang'] ?? '')), 0, 40);
    $ins = $pdo->prepare("REPLACE INTO lib_text (user_id, book_id, source, seq, locator, body) VALUES (?,?,'ocr',?,?,?)");
    foreach (array_slice((array)($d['pages'] ?? []), 0, 50) as $p) {
        $page = (int)($p['page'] ?? 0);
        if ($page < 1 || $page > 100000) continue;
        $ins->execute([$UID, $b['id'], $page, json_encode(['type' => 'page', 'page' => $page]), mb_substr(trim((string)($p['text'] ?? '')), 0, 200000)]);
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM lib_text WHERE book_id = ? AND source = 'ocr'");
    $c->execute([$b['id']]);
    $done  = (int)$c->fetchColumn();
    $total = $b['pages'] !== null ? (int)$b['pages'] : max(0, (int)($d['total'] ?? 0));
    $status = $total && $done >= $total ? 'done' : 'partial';
    $pdo->prepare("UPDATE lib_books SET ocr_pages = ?, ocr_status = ?, ocr_lang = ? WHERE id = ?")->execute([$done, $status, $lang, $b['id']]);
    jsonOk(['ocr_pages' => $done, 'ocr_status' => $status]);
}

// ── ДНЕВНИК И СТАТИСТИКА ──────────────────────────────────────────────────────
if ($action === 'diary' && $method === 'GET') {
    $limit = max(1, min(1000, (int)($_GET['limit'] ?? 300)));
    $st = $pdo->prepare("SELECT * FROM lib_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT $limit");
    $st->execute([$UID]);
    jsonOk(['sessions' => array_map(fn($r) => [
        'id' => $r['id'], 'book_id' => (int)$r['book_id'], 'device_name' => $r['device_name'],
        'started_at' => $r['started_at'], 'ended_at' => $r['ended_at'],
        'started_ts' => strtotime($r['started_at']) * 1000, 'ended_ts' => strtotime($r['ended_at']) * 1000,
        'seconds' => (int)$r['seconds'], 'from_fraction' => (float)$r['from_fraction'], 'to_fraction' => (float)$r['to_fraction'],
    ], $st->fetchAll())]);
}

if ($action === 'stats' && $method === 'GET') {
    $off = max(-900, min(900, (int)($_GET['tz'] ?? 0)));      // как getTimezoneOffset() в браузере: UTC − местное, в минутах
    $local = fn(string $dt, string $f) => gmdate($f, strtotime($dt) - $off * 60);
    $st = $pdo->prepare("SELECT book_id, started_at, seconds FROM lib_sessions WHERE user_id = ?");
    $st->execute([$UID]);
    $days = []; $perBook = []; $total = 0; $n = 0;
    foreach ($st->fetchAll() as $r) {
        $day = $local($r['started_at'], 'Y-m-d');
        $days[$day] = ($days[$day] ?? 0) + (int)$r['seconds'];
        $perBook[(int)$r['book_id']] = ($perBook[(int)$r['book_id']] ?? 0) + (int)$r['seconds'];
        $total += (int)$r['seconds'];
        $n++;
    }
    // серия: дни подряд с чтением от минуты; если сегодня ещё не читали — считаем со вчера
    $streak = 0;
    $t = time() - $off * 60;
    if (($days[gmdate('Y-m-d', $t)] ?? 0) < 60) $t -= 86400;
    while (($days[gmdate('Y-m-d', $t)] ?? 0) >= 60) { $streak++; $t -= 86400; }
    $byYear = [];
    $st = $pdo->prepare("SELECT finished_at FROM lib_books WHERE user_id = ? AND finished_at IS NOT NULL");
    $st->execute([$UID]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) { $y = $local($f, 'Y'); $byYear[$y] = ($byYear[$y] ?? 0) + 1; }
    arsort($perBook);
    jsonOk(['days' => (object)$days, 'streak' => $streak, 'finished_by_year' => (object)$byYear, 'total_seconds' => $total, 'sessions' => $n,
            'per_book' => array_map(fn($k, $v) => ['book_id' => $k, 'seconds' => $v], array_keys($perBook), array_values($perBook))]);
}

// ── ЭКСПОРТ, РЕЗЕРВНАЯ КОПИЯ, ВОССТАНОВЛЕНИЕ ──────────────────────────────────
if ($action === 'export' && $method === 'GET') {
    [$data] = exportData($pdo, $UID, false);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ikhlas-library-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Полный архив: оригиналы, версии для чтения, обложки, распознанный текст и library.json.
// Пишется потоком прямо в ответ — временный файл не нужен.
if ($action === 'backup' && $method === 'GET') {
    @set_time_limit(0);
    [$data, $src] = exportData($pdo, $UID, true);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="ikhlas-library-' . date('Y-m-d') . '.zip"');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level()) ob_end_clean();
    $z = new LibZipStream();
    foreach ($data['books'] as $bk) {
        foreach ($bk['files'] as $kind => $f) {
            if ($kind === 'ocr') $z->addString($f['path'], $src[$f['path']]);
            else $z->addFile($f['path'], libPath($src[$f['path']]));
        }
    }
    $z->addString('library.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $z->addString('README.txt', "Резервная копия библиотеки Ikhlas от " . date('d.m.Y H:i') . ".\r\n\r\n"
        . "library.json — описание книг (с контрольными суммами SHA-256), коллекции, заметки, закладки, позиции чтения, история и настройки.\r\n"
        . "books/<номер>/original.* — оригинальные файлы в том виде, в каком были загружены.\r\n"
        . "books/<номер>/derived.epub — версии для чтения (сделаны из TXT, DOCX, RTF), cover.* — обложки, ocr.json — распознанный текст.\r\n"
        . "Восстановление: Библиотека → Настройки → «Восстановить из архива». Проверка целостности — там же.\r\n");
    $z->finish();
    exit;
}

if ($action === 'restore_step' && $method === 'POST') {
    @set_time_limit(300);
    $d   = input();
    $bid = (string)($d['backup_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $bid)) jsonErr('Неверный архив');
    $zip = libDir('tmp') . "/backup-$bid.zip";
    if (!is_file($zip)) jsonErr('Архив не найден — загрузите его снова', 404);
    $mode = ($d['mode'] ?? '') === 'restore' ? 'restore' : 'verify';
    [$list, $data] = backupOpen($zip);
    $books = array_values((array)($data['books'] ?? []));
    $from  = max(0, (int)($d['offset'] ?? 0));
    $rep   = ['ok' => 0, 'added' => 0, 'skipped' => 0, 'annotations' => 0, 'errors' => []];
    $colMap = $mode === 'restore' ? restoreCollections($pdo, $UID, (array)($data['collections'] ?? [])) : [];
    $deadline = microtime(true) + 15;
    for ($i = $from; $i < count($books); $i++) {
        if ($i > $from && microtime(true) > $deadline) break;
        $bk = $books[$i];
        if ($mode === 'restore') { restoreBook($pdo, $UID, $zip, $list, $bk, $data, $colMap, $rep); continue; }
        $bad = false;
        foreach ((array)($bk['files'] ?? []) as $f) {
            $e = $list[$f['path'] ?? ''] ?? null;
            $sha = $e ? libZipExtract($zip, $e, null) : null;
            if ($sha === null || $sha !== ($f['sha256'] ?? '')) {
                $rep['errors'][] = '«' . bookName($bk) . '»: ' . ($e ? 'файл ' . $f['path'] . ' повреждён (контрольная сумма не совпадает)' : 'в архиве нет файла ' . ($f['path'] ?? '?'));
                $bad = true;
            }
        }
        if (!$bad) $rep['ok']++;
    }
    $done = $i >= count($books);
    if ($done && $mode === 'restore' && is_array($data['settings'] ?? null) && $data['settings']) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM lib_settings WHERE user_id = ?");
        $st->execute([$UID]);
        if (!(int)$st->fetchColumn()) {
            $pdo->prepare("INSERT INTO lib_settings (user_id, data, updated_ts) VALUES (?,?,?)")
                ->execute([$UID, json_encode($data['settings'], JSON_UNESCAPED_UNICODE), (int)(microtime(true) * 1000)]);
        }
    }
    if ($done) @unlink($zip);
    jsonOk(['offset' => $i, 'total' => count($books), 'done' => $done, 'report' => $rep]);
}

if ($action === 'restore_cancel' && $method === 'POST') {
    $bid = (string)(input()['backup_id'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $bid)) @unlink(libDir('tmp') . "/backup-$bid.zip");
    jsonOk();
}

// Удалить всю библиотеку (перед этим стоит скачать архив)
if ($action === 'wipe' && $method === 'POST') {
    if ((input()['confirm'] ?? '') !== 'УДАЛИТЬ') jsonErr('Нужно подтверждение');
    $pdo->prepare("DELETE bc FROM lib_book_collections bc JOIN lib_books b ON b.id = bc.book_id WHERE b.user_id = ?")->execute([$UID]);
    $st = $pdo->prepare("SELECT id FROM lib_uploads WHERE user_id = ?");
    $st->execute([$UID]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) @unlink(libDir('tmp') . "/$uid.part");
    foreach (['lib_positions', 'lib_annotations', 'lib_collections', 'lib_settings', 'lib_sessions', 'lib_text', 'lib_tokens', 'lib_uploads', 'lib_books'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$UID]);
    }
    libRemoveDir(libPath("u$UID"));
    jsonOk();
}

// ── КЛЮЧИ ДОСТУПА ДЛЯ ИИ (api/library_ai.php, только чтение) ──────────────────
if ($action === 'tokens' && $method === 'GET') {
    $st = $pdo->prepare("SELECT id, name, created_at, last_used_at FROM lib_tokens WHERE user_id = ? ORDER BY id");
    $st->execute([$UID]);
    jsonOk(['tokens' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'last_used_at' => $r['last_used_at']], $st->fetchAll())]);
}

if ($action === 'token_add' && $method === 'POST') {
    $name  = str(input()['name'] ?? '', 80) ?: 'ИИ-помощник';
    $token = 'ikl_' . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO lib_tokens (user_id, name, token_hash, created_at) VALUES (?,?,?,?)")->execute([$UID, $name, hash('sha256', $token), $NOW]);
    jsonOk(['token' => $token]);
}

if ($action === 'token_del' && $method === 'DELETE') {
    $pdo->prepare("DELETE FROM lib_tokens WHERE id = ? AND user_id = ?")->execute([(int)($_GET['id'] ?? 0), $UID]);
    jsonOk();
}

jsonErr('Неизвестный запрос', 400);

// ══════════════════════════════════════════════════════════════════════════════

function bookName(array $bk): string {
    return mb_substr((string)(($bk['title'] ?? '') ?: ($bk['orig_name'] ?? 'без названия')), 0, 80);
}

// Всё о библиотеке в переносимом виде. $backup — плюс список файлов для архива
// (путь в архиве, размер, SHA-256). Возвращает [данные, путь в архиве → источник].
function exportData(PDO $pdo, int $uid, bool $backup): array {
    $q = function (string $sql) use ($pdo, $uid) { $st = $pdo->prepare($sql); $st->execute([$uid]); return $st->fetchAll(); };
    $books = []; $src = [];
    $ocrSt = $pdo->prepare("SELECT seq, body FROM lib_text WHERE book_id = ? AND source = 'ocr' ORDER BY seq");
    foreach ($q("SELECT * FROM lib_books WHERE user_id = ? ORDER BY id") as $b) {
        $id = (int)$b['id'];
        $o = [
            'id' => $id, 'title' => $b['title'], 'authors' => $b['authors'], 'language' => $b['language'],
            'publisher' => $b['publisher'], 'year' => $b['year'], 'series' => $b['series'], 'identifier' => $b['identifier'],
            'description' => $b['description'], 'meta_edited' => $b['meta_edited'],
            'orig_name' => $b['orig_name'], 'orig_format' => $b['orig_format'], 'orig_size' => (int)$b['orig_size'], 'sha256' => $b['orig_sha256'],
            'derived_format' => $b['derived_format'], 'converter' => $b['converter'], 'converter_version' => (int)$b['converter_version'],
            'structure_hash' => $b['structure_hash'], 'status' => $b['status'], 'error' => $b['error'],
            'warnings' => $b['warnings'] ? json_decode($b['warnings'], true) : null, 'toc' => $b['toc'] ? json_decode($b['toc'], true) : [],
            'pages' => $b['pages'] !== null ? (int)$b['pages'] : null, 'has_text' => $b['has_text'] !== null ? (bool)$b['has_text'] : null,
            'drm' => (bool)$b['drm'], 'read_status' => $b['read_status'], 'favorite' => (bool)$b['favorite'], 'progress' => (float)$b['progress'],
            'position_label' => $b['position_label'], 'last_opened_at' => $b['last_opened_at'], 'finished_at' => $b['finished_at'],
            'ocr_status' => $b['ocr_status'], 'ocr_pages' => (int)$b['ocr_pages'], 'ocr_lang' => $b['ocr_lang'],
            'created_at' => $b['created_at'], 'updated_at' => $b['updated_at'],
        ];
        if ($backup) {
            $files = [];
            $add = function (string $kind, string $rel, string $name, ?string $sha = null) use (&$files, &$src, $id) {
                if ($rel === '' || !is_file(libPath($rel))) return;
                $path = "books/$id/$name";
                $files[$kind] = ['path' => $path, 'size' => filesize(libPath($rel)), 'sha256' => $sha ?? hash_file('sha256', libPath($rel))];
                $src[$path] = $rel;
            };
            $add('original', $b['orig_path'], basename($b['orig_path']), $b['orig_sha256']);
            if ($b['derived_path'] !== '') $add('derived', $b['derived_path'], 'derived.epub');
            if ($b['cover_type'] !== '') $add('cover', libBookDir($uid, $id) . '/cover.' . $b['cover_type'], 'cover.' . $b['cover_type']);
            $ocrSt->execute([$id]);
            $pages = array_map(fn($r) => ['page' => (int)$r['seq'], 'text' => $r['body']], $ocrSt->fetchAll());
            if ($pages) {
                $json = json_encode($pages, JSON_UNESCAPED_UNICODE);
                $path = "books/$id/ocr.json";
                $files['ocr'] = ['path' => $path, 'size' => strlen($json), 'sha256' => hash('sha256', $json)];
                $src[$path] = $json;
            }
            $o['files'] = $files;
        }
        $books[] = $o;
    }
    $data = [
        'format' => 'ikhlas-library', 'version' => 1, 'created_at' => date('c'),
        'books' => $books,
        'collections' => array_map(fn($c) => ['id' => (int)$c['id'], 'kind' => $c['kind'], 'name' => $c['name']],
            $q("SELECT id, kind, name FROM lib_collections WHERE user_id = ? ORDER BY id")),
        'links' => array_map(fn($r) => [(int)$r['book_id'], (int)$r['collection_id']],
            $q("SELECT bc.book_id, bc.collection_id FROM lib_book_collections bc JOIN lib_collections c ON c.id = bc.collection_id WHERE c.user_id = ?")),
        'annotations' => array_map('annOut', $q("SELECT * FROM lib_annotations WHERE user_id = ? AND deleted = 0 ORDER BY book_id, created_ts")),
        'positions' => array_map(fn($r) => ['book_id' => (int)$r['book_id'], 'device_id' => $r['device_id'], 'device_name' => $r['device_name'],
            'locator' => json_decode($r['locator'], true), 'fraction' => (float)$r['fraction'], 'label' => $r['label'], 'client_ts' => (int)$r['client_ts']],
            $q("SELECT * FROM lib_positions WHERE user_id = ?")),
        'reading_sessions' => array_map(fn($r) => ['id' => $r['id'], 'book_id' => (int)$r['book_id'], 'device_id' => $r['device_id'],
            'device_name' => $r['device_name'], 'started_at' => $r['started_at'], 'ended_at' => $r['ended_at'], 'seconds' => (int)$r['seconds'],
            'from_fraction' => (float)$r['from_fraction'], 'to_fraction' => (float)$r['to_fraction']],
            $q("SELECT * FROM lib_sessions WHERE user_id = ? ORDER BY started_at")),
        'settings' => ($s = $q("SELECT data FROM lib_settings WHERE user_id = ?")) ? json_decode($s[0]['data'], true) : null,
    ];
    return [$data, $src];
}

function backupOpen(string $zip): array {
    $list = libZipList($zip);
    if (!$list || !isset($list['library.json'])) jsonErr('Это не архив библиотеки: внутри нет library.json');
    $json = libZipRead($zip, $list['library.json'], 300 * 1048576);
    $data = $json !== null ? json_decode($json, true) : null;
    if (!is_array($data) || ($data['format'] ?? '') !== 'ikhlas-library') jsonErr('library.json повреждён или сделан другой программой');
    return [$list, $data];
}

// Коллекции из архива: находим по названию или создаём. Возвращает старый id → новый.
function restoreCollections(PDO $pdo, int $uid, array $cols): array {
    $map  = [];
    $find = $pdo->prepare("SELECT id FROM lib_collections WHERE user_id = ? AND kind = ? AND name = ?");
    $add  = $pdo->prepare("INSERT INTO lib_collections (user_id, kind, name, created_at) VALUES (?,?,?,?)");
    foreach ($cols as $c) {
        $kind = ($c['kind'] ?? '') === 'category' ? 'category' : 'collection';
        $name = str($c['name'] ?? '', 120);
        if ($name === '') continue;
        $find->execute([$uid, $kind, $name]);
        $id = (int)$find->fetchColumn();
        if (!$id) { $add->execute([$uid, $kind, $name, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId(); }
        $map[(int)($c['id'] ?? 0)] = $id;
    }
    return $map;
}

// Одна книга из архива. Уже имеющиеся (по SHA-256 оригинала) не дублируются,
// но их заметки, закладки, позиции и коллекции из архива добавляются.
function restoreBook(PDO $pdo, int $uid, string $zip, array $list, array $bk, array $data, array $colMap, array &$rep): void {
    $NOW   = date('Y-m-d H:i:s');
    $name  = bookName($bk);
    $sha   = (string)($bk['sha256'] ?? '');
    $oldId = (int)($bk['id'] ?? 0);
    $st = $pdo->prepare("SELECT id FROM lib_books WHERE user_id = ? AND orig_sha256 = ?");
    $st->execute([$uid, $sha]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        $rep['skipped']++;
    } else {
        $orig = $bk['files']['original'] ?? null;
        $entry = $orig ? ($list[$orig['path']] ?? null) : null;
        if (!$entry || !preg_match('/^[0-9a-f]{64}$/', $sha)) { $rep['errors'][] = "«$name»: в архиве нет оригинала — книга пропущена"; return; }
        if (usedSpace($pdo, $uid) + (int)$entry['usize'] > LIB_QUOTA) { $rep['errors'][] = "«$name»: в библиотеке не хватает места"; return; }
        $fmt    = preg_match('/^[a-z0-9]{1,10}$/', (string)($bk['orig_format'] ?? '')) ? $bk['orig_format'] : 'unknown';
        $conv   = LIB_FORMATS[$fmt]['convert'] ?? false;
        $status = in_array($bk['status'] ?? '', LIB_STATUSES, true) ? $bk['status'] : 'uploaded';
        if (in_array($status, ['checking', 'converting'], true)) $status = $conv ? 'needs_conversion' : 'uploaded';
        $rs = in_array($bk['read_status'] ?? '', READ_STATUSES, true) ? $bk['read_status'] : 'new';
        $vals = ['user_id' => $uid, 'orig_name' => str($bk['orig_name'] ?? 'book', 255), 'orig_format' => $fmt, 'orig_size' => (int)$entry['usize'],
                 'orig_sha256' => $sha, 'status' => $status, 'status_at' => $NOW, 'error' => $bk['error'] ?? null,
                 'warnings' => json_encode($bk['warnings'] ?? ['upload' => [], 'process' => []], JSON_UNESCAPED_UNICODE),
                 'toc' => json_encode($bk['toc'] ?? [], JSON_UNESCAPED_UNICODE), 'pages' => isset($bk['pages']) ? (int)$bk['pages'] : null,
                 'has_text' => isset($bk['has_text']) ? (int)(bool)$bk['has_text'] : null, 'drm' => !empty($bk['drm']) ? 1 : 0,
                 'read_status' => $rs, 'favorite' => !empty($bk['favorite']) ? 1 : 0, 'progress' => max(0, min(1, (float)($bk['progress'] ?? 0))),
                 'position_label' => str($bk['position_label'] ?? '', 255), 'last_opened_at' => $bk['last_opened_at'] ?? null,
                 'finished_at' => $bk['finished_at'] ?? null, 'meta_edited' => str($bk['meta_edited'] ?? '', 255),
                 'structure_hash' => str($bk['structure_hash'] ?? '', 64),
                 'created_at' => $bk['created_at'] ?? $NOW, 'updated_at' => $NOW];
        foreach (META_FIELDS as $f => $max) $vals[$f] = str($bk[$f] ?? '', $max);
        $pdo->prepare("INSERT INTO lib_books (" . implode(', ', array_keys($vals)) . ") VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")
            ->execute(array_values($vals));
        $id  = (int)$pdo->lastInsertId();
        $dir = libBookDir($uid, $id);
        libDir($dir);
        $base = basename($orig['path']);
        if (!preg_match('/^original\.[a-z0-9.]{1,12}$/', $base)) $base = 'original.bin';
        if (libZipExtract($zip, $entry, libPath("$dir/$base")) !== $sha) {
            $pdo->prepare("DELETE FROM lib_books WHERE id = ?")->execute([$id]);
            libRemoveDir(libPath($dir));
            $rep['errors'][] = "«$name»: оригинал в архиве повреждён — книга не восстановлена";
            return;
        }
        $set = ['orig_path' => "$dir/$base"];
        $der = $bk['files']['derived'] ?? null;
        $de  = $der ? ($list[$der['path']] ?? null) : null;
        if ($de && libZipExtract($zip, $de, libPath("$dir/derived.epub")) === ($der['sha256'] ?? '')) {
            $set += ['derived_format' => 'epub', 'derived_path' => "$dir/derived.epub", 'derived_size' => (int)$de['usize'],
                     'converter' => str($bk['converter'] ?? '', 40), 'converter_version' => (int)($bk['converter_version'] ?? 0)];
        } else {
            @unlink(libPath("$dir/derived.epub"));
            if ($der) $rep['errors'][] = "«$name»: версия для чтения в архиве повреждена — книга будет обработана заново";
            if ($conv) $set['status'] = 'needs_conversion';
        }
        $cov = $bk['files']['cover'] ?? null;
        $ce  = $cov ? ($list[$cov['path']] ?? null) : null;
        $ct  = $cov ? strtolower(pathinfo($cov['path'], PATHINFO_EXTENSION)) : '';
        if ($ce && in_array($ct, ['jpg', 'png', 'webp'], true) && libZipExtract($zip, $ce, libPath("$dir/cover.$ct")) === ($cov['sha256'] ?? '')) $set['cover_type'] = $ct;
        $ocr = $bk['files']['ocr'] ?? null;
        $oe  = $ocr ? ($list[$ocr['path']] ?? null) : null;
        $json = $oe ? libZipRead($zip, $oe, 300 * 1048576) : null;
        if ($json !== null && hash('sha256', $json) === ($ocr['sha256'] ?? '')) {
            $ins = $pdo->prepare("REPLACE INTO lib_text (user_id, book_id, source, seq, locator, body) VALUES (?,?,'ocr',?,?,?)");
            foreach ((array)json_decode($json, true) as $p) {
                $page = (int)($p['page'] ?? 0);
                if ($page > 0) $ins->execute([$uid, $id, $page, json_encode(['type' => 'page', 'page' => $page]), (string)($p['text'] ?? '')]);
            }
            $set += ['ocr_status' => str($bk['ocr_status'] ?? 'partial', 12), 'ocr_pages' => (int)($bk['ocr_pages'] ?? 0), 'ocr_lang' => str($bk['ocr_lang'] ?? '', 40)];
        }
        $pdo->prepare("UPDATE lib_books SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . " WHERE id = ?")
            ->execute([...array_values($set), $id]);
        $rep['added']++;
    }
    $rep['ok']++;

    $link = $pdo->prepare("INSERT IGNORE INTO lib_book_collections (book_id, collection_id) VALUES (?,?)");
    foreach ((array)($data['links'] ?? []) as $l) {
        if ((int)($l[0] ?? 0) === $oldId && isset($colMap[(int)($l[1] ?? 0)])) $link->execute([$id, $colMap[(int)$l[1]]]);
    }
    $get = $pdo->prepare("SELECT user_id, updated_ts FROM lib_annotations WHERE id = ?");
    $put = $pdo->prepare("REPLACE INTO lib_annotations (id, user_id, book_id, type, locator, text, note, color, chapter, fraction, created_ts, updated_ts, deleted, server_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)");
    foreach ((array)($data['annotations'] ?? []) as $a) {
        if ((int)($a['book_id'] ?? 0) !== $oldId || !validUuid((string)($a['id'] ?? '')) || !in_array($a['type'] ?? '', ['bookmark', 'highlight', 'note', 'quote'], true)) continue;
        $get->execute([$a['id']]);
        $cur = $get->fetch();
        if ($cur && ((int)$cur['user_id'] !== $uid || (int)$cur['updated_ts'] >= (int)($a['updated_ts'] ?? 0))) continue;
        $put->execute([$a['id'], $uid, $id, $a['type'], json_encode($a['locator'] ?? new stdClass, JSON_UNESCAPED_UNICODE),
                       mb_substr((string)($a['text'] ?? ''), 0, 20000), mb_substr((string)($a['note'] ?? ''), 0, 20000), str($a['color'] ?? '', 16),
                       str($a['chapter'] ?? '', 255), max(0, min(1, (float)($a['fraction'] ?? 0))), (int)($a['created_ts'] ?? 0) ?: 1,
                       (int)($a['updated_ts'] ?? 0) ?: 1, $NOW]);
        $rep['annotations']++;
    }
    $pget = $pdo->prepare("SELECT client_ts FROM lib_positions WHERE book_id = ? AND device_id = ?");
    $pput = $pdo->prepare("REPLACE INTO lib_positions (book_id, device_id, user_id, device_name, locator, fraction, label, client_ts, server_at) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ((array)($data['positions'] ?? []) as $p) {
        if ((int)($p['book_id'] ?? 0) !== $oldId || !validDevice((string)($p['device_id'] ?? ''))) continue;
        $pget->execute([$id, $p['device_id']]);
        $prev = $pget->fetchColumn();
        if ($prev !== false && (int)$prev >= (int)($p['client_ts'] ?? 0)) continue;
        $pput->execute([$id, $p['device_id'], $uid, str($p['device_name'] ?? '', 80), json_encode($p['locator'] ?? new stdClass, JSON_UNESCAPED_UNICODE),
                        max(0, min(1, (float)($p['fraction'] ?? 0))), str($p['label'] ?? '', 255), (int)($p['client_ts'] ?? 0), $NOW]);
    }
    $sput = $pdo->prepare("INSERT IGNORE INTO lib_sessions (id, user_id, book_id, device_id, device_name, started_at, ended_at, seconds, from_fraction, to_fraction) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ((array)($data['reading_sessions'] ?? []) as $s) {
        if ((int)($s['book_id'] ?? 0) !== $oldId || !validUuid((string)($s['id'] ?? ''))) continue;
        $sput->execute([$s['id'], $uid, $id, str($s['device_id'] ?? '', 40), str($s['device_name'] ?? '', 80), $s['started_at'] ?? $NOW,
                        $s['ended_at'] ?? $NOW, max(0, (int)($s['seconds'] ?? 0)), (float)($s['from_fraction'] ?? 0), (float)($s['to_fraction'] ?? 0)]);
    }
    $st = $pdo->prepare("SELECT fraction, label FROM lib_positions WHERE book_id = ? ORDER BY client_ts DESC LIMIT 1");
    $st->execute([$id]);
    if ($last = $st->fetch()) $pdo->prepare("UPDATE lib_books SET progress = ?, position_label = ? WHERE id = ?")->execute([(float)$last['fraction'], $last['label'], $id]);
}

