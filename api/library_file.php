<?php
require_once __DIR__ . '/../includes/lib_core.php';

// Выдача файлов библиотеки: ?id=…&kind=original|derived|cover[&dl=1]
// Только владельцу; поддерживает Range (докачка, большие PDF).
$UID = libRequireApi();

$pdo  = getDB();
libEnsureTables($pdo);
$id   = (int)($_GET['id'] ?? 0);
$kind = (string)($_GET['kind'] ?? 'original');

$st = $pdo->prepare("SELECT * FROM lib_books WHERE id = ? AND user_id = ?");
$st->execute([$id, $UID]);
$b = $st->fetch();
if (!$b) { http_response_code(404); exit('Not found'); }

$name = $b['orig_name'];
if ($kind === 'original') {
    $rel = $b['orig_path'];
    $type = 'application/octet-stream';
    $etag = $b['orig_sha256'];
} elseif ($kind === 'derived' && $b['derived_path'] !== '') {
    $rel = $b['derived_path'];
    $type = 'application/octet-stream';
    $etag = md5($b['derived_path'] . $b['derived_size'] . $b['converter_version']);
    $name = preg_replace('/\.[^.]+$/', '', $b['orig_name']) . ' (' . $b['converter'] . ' v' . $b['converter_version'] . ').epub';
} elseif ($kind === 'cover' && $b['cover_type'] !== '') {
    $rel = libBookDir($UID, (int)$b['id']) . '/cover.' . $b['cover_type'];
    $type = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$b['cover_type']];
    $etag = md5($rel . $b['status_at']);
} else {
    http_response_code(404); exit('Not found');
}

$path = libPath($rel);
if (!is_file($path)) { http_response_code(404); exit('File missing'); }
$size = filesize($path);

// Книги отдаём как «просто байты» и в песочнице: даже если открыть ссылку
// напрямую, браузер ничего из файла не выполнит.
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: "' . $etag . '"');
header('Accept-Ranges: bytes');
if (!empty($_GET['dl'])) {
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
}
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"') === $etag) { http_response_code(304); exit; }

$start = 0; $end = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && $size > 0) {
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

@set_time_limit(0);
while (ob_get_level()) ob_end_clean();
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, min(1048576, $left));
    echo $chunk;
    flush();
    $left -= strlen($chunk);
}
fclose($fh);
