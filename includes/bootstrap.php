<?php
// ---- Pengerasan sesi: harus sebelum session_start() ----
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
$__https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => $__https, 'httponly' => true, 'samesite' => 'Lax',
]);

// ---- Header keamanan untuk SEMUA halaman ----
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    if ($__https) header('Strict-Transport-Security: max-age=31536000');
}
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity_log.php';
sanitize_uploads();

ensure_default_superadmin($pdo);

// ---- Sesi: batas tidak aktif + data akun selalu segar ----
// - Tidak aktif > 8 jam -> keluar otomatis.
// - Tiap 60 detik data akun dicek ulang ke database: akun dihapus/diubah izinnya langsung berlaku,
//   tidak menunggu admin tersebut logout.
if (!empty($_SESSION['user'])) {
    $__now = time();
    if (isset($_SESSION['last_seen']) && $__now - $_SESSION['last_seen'] > 8 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen'] = $__now;
        if ($__now - ($_SESSION['user_checked'] ?? 0) > 60) {
            try {
                $__st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $__st->execute([$_SESSION['user']['id']]);
                $__row = $__st->fetch();
                if (!$__row) {
                    $_SESSION = [];
                    session_regenerate_id(true);
                } else {
                    $_SESSION['user']['username'] = $__row['username'];
                    $_SESSION['user']['role'] = $__row['role'];
                    $_SESSION['user']['permissions'] = decode_permissions($__row['permissions']);
                    $_SESSION['user']['bidang_nama'] = $__row['bidang_nama'] ?? null;
                    $_SESSION['user_checked'] = $__now;
                }
            } catch (Throwable $e) { /* DB sibuk: lewati, coba lagi nanti */ }
        }
    }
}
