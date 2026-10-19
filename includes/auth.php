<?php
/**
 * Авторизация сайта «Ikhlas — трекер привычек» (ikhlas.website).
 * Аккаунты — таблица google_users в базе sulmin_ikhlas.
 * Единственный способ входа — Google Sign-In, см. google_auth.php.
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function requireAuth(string $redirect = '/login.php'): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . $redirect);
        exit;
    }
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function jsonOk(array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonErr(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
