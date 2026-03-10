<?php
if (getenv('APP_ENV') === 'testing') {
    // ---------- Adatbázis beállítások (TEST ENV) ----------
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'test.sqlite');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', 'http://localhost:8000');

    // ---------- PDO kapcsolat (TEST ENV) ----------
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/test.sqlite', '', '', [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Exception $e) {
        die('Teszt adatbázis hiba. Kérjük próbálja később: ' . $e->getMessage());
    }
} else {
    // ---------- Adatbázis beállítások ----------
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'my_cms');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', 'http://localhost/cms');

    // ---------- PDO kapcsolat ----------
    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (Exception $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        die('Adatbázis hiba. Kérjük próbálja később.');
    }
}

// ---------- CSRF védelem ----------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals(csrf_token(), $token);
}