<?php
/**
 * Приём и проверка Google ID-токена (Sign in with Google).
 * Полностью отдельная система входа — своя база данных, своя таблица
 * google_users. Логинов и паролей на сайте нет в принципе — только Google.
 */
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/google_config.php';

header('Content-Type: application/json; charset=utf-8');

if (str_contains(GOOGLE_CLIENT_ID, 'ВСТАВЬТЕ_СЮДА')) {
    jsonErr('Google Client ID ещё не настроен на сервере (includes/google_config.php)', 500);
}

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$credential = (string)($input['credential'] ?? '');
if (!$credential) jsonErr('Нет токена от Google', 400);

// Проверяем подлинность ID-токена через сам Google (без сторонних библиотек/composer)
$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200 || !$resp) jsonErr('Не удалось проверить токен Google', 401);
$payload = json_decode($resp, true);
if (!is_array($payload)) jsonErr('Неверный ответ Google', 401);

if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) jsonErr('Токен выдан не для этого сайта', 401);
if (!in_array($payload['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
    jsonErr('Неверный издатель токена', 401);
}
if ((int)($payload['exp'] ?? 0) < time()) jsonErr('Токен истёк, попробуйте войти снова', 401);

$sub     = (string)($payload['sub'] ?? '');
$email   = (string)($payload['email'] ?? '');
$name    = trim((string)($payload['name'] ?? '')) ?: $email;
$picture = (string)($payload['picture'] ?? '');
if (!$sub) jsonErr('Google не передал идентификатор аккаунта', 401);

$pdo = getDB();
$pdo->exec("CREATE TABLE IF NOT EXISTS google_users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    google_sub VARCHAR(64)  NOT NULL UNIQUE,
    email      VARCHAR(150) NOT NULL,
    name       VARCHAR(150) NOT NULL DEFAULT '',
    picture    VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $pdo->prepare('SELECT id FROM google_users WHERE google_sub = ?');
$stmt->execute([$sub]);
$row = $stmt->fetch();

if ($row) {
    $uid = (int)$row['id'];
    $pdo->prepare('UPDATE google_users SET email=?, name=?, picture=?, last_login=NOW() WHERE id=?')
        ->execute([$email, $name, $picture, $uid]);
} else {
    $pdo->prepare('INSERT INTO google_users (google_sub, email, name, picture) VALUES (?,?,?,?)')
        ->execute([$sub, $email, $name, $picture]);
    $uid = (int)$pdo->lastInsertId();
}

$_SESSION['user_id'] = $uid;
$_SESSION['user'] = [
    'id'        => $uid,
    'username'  => $email,
    'full_name' => $name,
    'role'      => 'google',
    'picture'   => $picture,
];

jsonOk(['redirect' => '/']);
