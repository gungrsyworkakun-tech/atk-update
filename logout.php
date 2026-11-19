<?php
require __DIR__ . '/includes/bootstrap.php';

// Hapus token "ingat saya" di database & cookie-nya, supaya setelah keluar tidak otomatis masuk lagi.
if (!empty($_COOKIE['remember_me'])) {
    $sel = explode(':', $_COOKIE['remember_me'], 2)[0];
    try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$sel]); } catch (Throwable $e) {}
    setcookie('remember_me', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS'])]);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'],
        'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
}
session_destroy();
redirect('login.php');
