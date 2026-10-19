<?php
// ════════════════════════════════════════════════════════════════
//  База данных сайта «Ikhlas — трекер привычек» (ikhlas.website).
//  Таблицы создаются сами при первом открытии сайта.
// ════════════════════════════════════════════════════════════════
define('DB_HOST',    'localhost');
define('DB_NAME',    'sulmin_ikhlas');
define('DB_USER',    'sulmin_ikhlas');
define('DB_PASS',    'rH6TIHNc&0dC');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
