<?php
/**
 * Доступ к библиотеке для внешнего ИИ-помощника — только чтение, по ключу.
 * Ключ создаётся в «Библиотека → Настройки → Доступ для ИИ» и передаётся
 * заголовком  Authorization: Bearer ikl_…  (или ?token=… для простых инструментов).
 *
 *   ?action=books                         каталог: название, автор, статус, прогресс
 *   ?action=book&id=12                    описание книги, оглавление, заметки
 *   ?action=notes[&book_id=12]            закладки, выделения, заметки, цитаты
 *   ?action=search&q=…                    поиск по каталогу, заметкам и тексту книг
 *   ?action=text&book_id=12[&from=0]      текст книги из индекса (кусками), если он проиндексирован
 *
 * Файлы книг этот адрес не отдаёт и ничего не меняет.
 */
require_once __DIR__ . '/../includes/lib_core.php';
session_write_close();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$auth  = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_GET['token'] ?? '');
if (!preg_match('/^ikl_[a-f0-9]{48}$/', $token)) jsonErr('Нужен ключ доступа (Authorization: Bearer ikl_…)', 401);

$pdo = getDB();
libEnsureTables($pdo);
$st = $pdo->prepare("SELECT id, user_id FROM lib_tokens WHERE token_hash = ?");
$st->execute([hash('sha256', $token)]);
$tok = $st->fetch();
if (!$tok) jsonErr('Ключ не найден или отозван', 401);
$UID = (int)$tok['user_id'];
$pdo->prepare("UPDATE lib_tokens SET last_used_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $tok['id']]);

$q = function (string $sql, array $args) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };
$short = fn(array $b) => [
    'id' => (int)$b['id'], 'title' => $b['title'] ?: preg_replace('/\.[^.]+$/', '', $b['orig_name']), 'authors' => $b['authors'],
    'format' => $b['orig_format'], 'language' => $b['language'], 'year' => $b['year'], 'series' => $b['series'],
    'read_status' => $b['read_status'], 'progress' => round((float)$b['progress'], 3), 'favorite' => (bool)$b['favorite'],
    'added' => $b['created_at'], 'last_opened' => $b['last_opened_at'], 'finished' => $b['finished_at'],
    'text_indexed' => !empty($b['indexed_at']) || ($b['ocr_status'] ?? '') !== '',
];
$note = fn(array $a) => [
    'book_id' => (int)$a['book_id'], 'type' => $a['type'], 'text' => $a['text'], 'comment' => $a['note'],
    'chapter' => $a['chapter'], 'progress' => round((float)$a['fraction'], 3), 'created' => date('c', intdiv((int)$a['created_ts'], 1000)),
];
$own = function (int $id) use ($q, $UID) {
    $r = $q("SELECT * FROM lib_books WHERE id = ? AND user_id = ?", [$id, $UID]);
    if (!$r) jsonErr('Книга не найдена', 404);
    return $r[0];
};

switch ($_GET['action'] ?? '') {
    case 'books':
        $cols = [];
        foreach ($q("SELECT bc.book_id, c.kind, c.name FROM lib_book_collections bc JOIN lib_collections c ON c.id = bc.collection_id WHERE c.user_id = ?", [$UID]) as $r) {
            $cols[(int)$r['book_id']][] = ($r['kind'] === 'category' ? 'категория: ' : 'коллекция: ') . $r['name'];
        }
        jsonOk(['books' => array_map(fn($b) => $short($b) + ['collections' => $cols[(int)$b['id']] ?? []],
                $q("SELECT * FROM lib_books WHERE user_id = ? ORDER BY created_at DESC", [$UID]))]);

    case 'book':
        $b = $own((int)($_GET['id'] ?? 0));
        jsonOk(['book' => $short($b) + ['publisher' => $b['publisher'], 'description' => $b['description'],
                'pages' => $b['pages'] !== null ? (int)$b['pages'] : null, 'toc' => $b['toc'] ? json_decode($b['toc'], true) : []],
                'notes' => array_map($note, $q("SELECT * FROM lib_annotations WHERE user_id = ? AND book_id = ? AND deleted = 0 ORDER BY fraction", [$UID, $b['id']]))]);

    case 'notes':
        $bid = (int)($_GET['book_id'] ?? 0);
        $rows = $bid ? $q("SELECT * FROM lib_annotations WHERE user_id = ? AND book_id = ? AND deleted = 0 ORDER BY fraction", [$UID, $bid])
                     : $q("SELECT * FROM lib_annotations WHERE user_id = ? AND deleted = 0 ORDER BY book_id, fraction", [$UID]);
        jsonOk(['notes' => array_map($note, $rows)]);

    case 'search':
        $s = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($s) < 2) jsonErr('Слишком короткий запрос');
        $like = '%' . addcslashes($s, '%_\\') . '%';
        $books = $q("SELECT * FROM lib_books WHERE user_id = ? AND (title LIKE ? OR authors LIKE ? OR series LIKE ? OR orig_name LIKE ?) LIMIT 50", [$UID, $like, $like, $like, $like]);
        $notes = $q("SELECT * FROM lib_annotations WHERE user_id = ? AND deleted = 0 AND (text LIKE ? OR note LIKE ?) LIMIT 50", [$UID, $like, $like]);
        $text = [];
        foreach ($q("SELECT book_id, source, locator, body FROM lib_text WHERE user_id = ? AND body LIKE ? LIMIT 50", [$UID, $like]) as $r) {
            $pos = mb_stripos($r['body'], $s) ?: 0;
            $text[] = ['book_id' => (int)$r['book_id'], 'where' => json_decode($r['locator'], true), 'source' => $r['source'],
                       'excerpt' => mb_substr($r['body'], max(0, $pos - 200), mb_strlen($s) + 400)];
        }
        jsonOk(['books' => array_map($short, $books), 'notes' => array_map($note, $notes), 'text' => $text]);

    case 'text':
        $b = $own((int)($_GET['book_id'] ?? 0));
        $from = max(0, (int)($_GET['from'] ?? 0));
        $rows = $q("SELECT seq, source, locator, body FROM lib_text WHERE book_id = ? AND seq >= ? ORDER BY source = 'ocr', seq LIMIT 30", [$b['id'], $from]);
        if (!$rows && !$from) jsonErr('Текст этой книги не проиндексирован (Библиотека → Настройки → «Проиндексировать»)', 404);
        jsonOk(['book' => $short($b), 'chunks' => array_map(fn($r) => ['seq' => (int)$r['seq'], 'where' => json_decode($r['locator'], true), 'text' => $r['body']], $rows),
                'next' => count($rows) === 30 ? (int)end($rows)['seq'] + 1 : null]);
}
jsonErr('Неизвестный запрос. Доступно: books, book, notes, search, text', 400);
