<?php
require __DIR__ . '/includes/bootstrap.php';

// ================= REMEMBER ME: helper functions =================
// Pola "selector + validator" (bukan sekadar memperpanjang session cookie):
// - selector  : dipakai untuk MENCARI baris token di DB (boleh terlihat).
// - validator : rahasia; yang disimpan di DB hanya hash SHA-256-nya, bukan nilai aslinya.
// Kalau tabel remember_tokens belum ada, buat dulu dengan SQL berikut:
//
// CREATE TABLE remember_tokens (
//   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
//   user_id INT UNSIGNED NOT NULL,
//   selector CHAR(24) NOT NULL UNIQUE,
//   validator_hash CHAR(64) NOT NULL,
//   expires_at DATETIME NOT NULL,
//   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   INDEX (user_id),
//   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
// );

const REMEMBER_COOKIE_NAME = 'remember_me';
const REMEMBER_TTL_DAYS = 30;

function remember_set_cookie(string $value, int $expiresAt) {
    setcookie(REMEMBER_COOKIE_NAME, $value, [
        'expires'  => $expiresAt,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function remember_clear_cookie() {
    setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Buat token ingat-saya baru untuk user, simpan hash-nya di DB,
 * dan kirim cookie berisi "selector:validator" (nilai asli, tidak di-hash).
 */
function remember_issue_token($pdo, int $userId) {
    $selector  = bin2hex(random_bytes(12)); // 24 karakter hex
    $validator = bin2hex(random_bytes(32)); // rahasia, tidak pernah disimpan mentah
    $hash      = hash('sha256', $validator);
    $expiresAt = time() + REMEMBER_TTL_DAYS * 86400;

    try {
        $stmt = $pdo->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $selector, $hash, date('Y-m-d H:i:s', $expiresAt)]);
        remember_set_cookie($selector . ':' . $validator, $expiresAt);
    } catch (\PDOException $e) {
        // Tabel remember_tokens belum ada / DB error lain: jangan bikin login gagal total,
        // cukup lewati fitur "ingat saya" untuk request ini dan catat ke error log server.
        error_log('remember_issue_token gagal: ' . $e->getMessage());
    }
}

function remember_delete_by_selector($pdo, string $selector) {
    try {
        $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?');
        $stmt->execute([$selector]);
    } catch (\PDOException $e) {
        error_log('remember_delete_by_selector gagal: ' . $e->getMessage());
    }
}

/**
 * Cek cookie remember-me. Kalau valid, kembalikan baris user dan
 * SEKALIGUS merotasi token (token lama dihapus, token baru diterbitkan).
 * Kalau selector ditemukan tapi validator TIDAK cocok, itu indikasi token
 * dicuri/dipakai ulang — token langsung dihapus dari DB demi keamanan.
 */
function remember_try_autologin($pdo) {
    if (empty($_COOKIE[REMEMBER_COOKIE_NAME])) return null;
    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
    if (count($parts) !== 2) { remember_clear_cookie(); return null; }
    [$selector, $validator] = $parts;

    try {
        $stmt = $pdo->prepare('SELECT * FROM remember_tokens WHERE selector = ? LIMIT 1');
        $stmt->execute([$selector]);
        $tokenRow = $stmt->fetch();
    } catch (\PDOException $e) {
        // Tabel remember_tokens belum ada / DB error lain: anggap saja tidak ada
        // token tersimpan, biarkan user login manual seperti biasa.
        error_log('remember_try_autologin gagal: ' . $e->getMessage());
        return null;
    }

    if (!$tokenRow) { remember_clear_cookie(); return null; }

    if (strtotime($tokenRow['expires_at']) < time()) {
        remember_delete_by_selector($pdo, $selector);
        remember_clear_cookie();
        return null;
    }

    if (!hash_equals($tokenRow['validator_hash'], hash('sha256', $validator))) {
        // Selector cocok tapi validator tidak — kemungkinan token dicuri.
        // Hapus token ini sepenuhnya (paksa user login ulang manual).
        remember_delete_by_selector($pdo, $selector);
        remember_clear_cookie();
        return null;
    }

    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $userStmt->execute([$tokenRow['user_id']]);
    $userRow = $userStmt->fetch();
    if (!$userRow) { remember_delete_by_selector($pdo, $selector); remember_clear_cookie(); return null; }

    // Rotasi: token lama dibuang, token baru diterbitkan (mengurangi jendela pakai-ulang token).
    remember_delete_by_selector($pdo, $selector);
    remember_issue_token($pdo, (int)$userRow['id']);

    return $userRow;
}
// ================= /REMEMBER ME =================

if (is_logged_in()) redirect('index.php');

// Kalau belum login tapi bawa cookie "ingat saya" yang valid, login otomatis.
if (!is_logged_in()) {
    $rememberedUser = remember_try_autologin($pdo);
    if ($rememberedUser) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $rememberedUser['id'],
            'username' => $rememberedUser['username'],
            'role' => $rememberedUser['role'],
            'permissions' => decode_permissions($rememberedUser['permissions']),
            'bidang_nama' => $rememberedUser['bidang_nama'] ?? null,
        ];
        redirect('index.php');
    }
}

// ---------------- Pengerasan cookie sesi (jaga-jaga jika belum diatur di bootstrap.php) ----------------
// Idealnya ini dipanggil SEBELUM session_start() di bootstrap.php. Ditaruh di sini sebagai
// pengaman tambahan — tidak berefek jika sesi sudah terlanjur dimulai dengan konfigurasi lain.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']), // wajib true kalau sudah pakai HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---------------- Header keamanan ----------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// camera=(self): halaman ini sendiri boleh memakai kamera (untuk fitur "Cek barang").
header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
// HSTS hanya aman dikirim kalau situs SUDAH full HTTPS (kalau belum, jangan aktifkan dulu).
if (!empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
// CSP dasar. Karena halaman ini masih pakai <style> inline, style-src perlu 'unsafe-inline'
// untuk sementara — idealnya inline style dipindah ke assets/style.css lalu baris ini diperketat.
// img-src menambahkan blob: supaya pemindai bisa membaca foto QR yang diunggah.
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

// ---------------- Proteksi brute-force (rate limiting IP + username) ----------------
$rlDir = __DIR__ . '/data/';
if (!is_dir($rlDir)) @mkdir($rlDir, 0700, true); // 0700: hanya pemilik proses yang bisa akses
$rlFile = $rlDir . 'login_attempts.json';
$rlLogFile = $rlDir . 'login_audit.log';
$rlMaxAttempt = 5;        // maksimal percobaan gagal per kunci
$rlWindow = 15 * 60;      // dalam jendela waktu 15 menit
$rlLockDuration = 5 * 60; // dikunci selama 5 menit

/**
 * Baca file rate-limit dengan shared lock supaya tidak baca data
 * yang sedang ditulis proses lain (mencegah race condition ringan).
 */
function rl_load($rlFile) {
    if (!is_file($rlFile)) return [];
    $fh = @fopen($rlFile, 'r');
    if (!$fh) return [];
    $data = [];
    if (flock($fh, LOCK_SH)) {
        $raw = stream_get_contents($fh);
        $decoded = json_decode((string)$raw, true);
        $data = is_array($decoded) ? $decoded : [];
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $data;
}

function rl_save($rlFile, $data) {
    @file_put_contents($rlFile, json_encode($data), LOCK_EX);
}

/**
 * Kunci gabungan IP + username (bila username tersedia).
 * - Mencegah 1 IP mencoba banyak username sekaligus (credential stuffing).
 * - Mencegah 1 username diserang dari banyak IP (distributed brute-force).
 * Keduanya dicek secara terpisah; yang mana pun yang terkunci akan memblokir percobaan.
 */
function rl_key_ip() {
    return 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}
function rl_key_user($username) {
    $username = mb_strtolower(trim((string)$username));
    return $username !== '' ? 'user:' . hash('sha256', $username) : null;
}

function rl_status_for_key($rlFile, $key) {
    if ($key === null) return 0;
    $data = rl_load($rlFile);
    $entry = $data[$key] ?? null;
    if ($entry && $entry['locked_until'] > time()) {
        return $entry['locked_until'] - time();
    }
    return 0;
}

function rl_register_fail_for_key($rlFile, $key, $rlMaxAttempt, $rlWindow, $rlLockDuration) {
    if ($key === null) return;
    $data = rl_load($rlFile);
    $now = time();
    $entry = $data[$key] ?? ['count' => 0, 'first' => $now, 'locked_until' => 0];
    if ($now - $entry['first'] > $rlWindow) {
        $entry = ['count' => 0, 'first' => $now, 'locked_until' => 0];
    }
    $entry['count']++;
    if ($entry['count'] >= $rlMaxAttempt) {
        $entry['locked_until'] = $now + $rlLockDuration;
    }
    $data[$key] = $entry;
    rl_save($rlFile, $data);
}

function rl_register_success_for_key($rlFile, $key) {
    if ($key === null) return;
    $data = rl_load($rlFile);
    unset($data[$key]);
    rl_save($rlFile, $data);
}

/**
 * Catat jejak audit percobaan gagal TANPA pernah menulis kata sandi.
 * Berguna untuk investigasi kalau ada pola serangan.
 */
function rl_audit_log($rlLogFile, $username, $success) {
    $line = sprintf(
        "[%s] ip=%s user=%s hasil=%s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $username !== '' ? mb_substr($username, 0, 50) : '(kosong)',
        $success ? 'sukses' : 'gagal'
    );
    @file_put_contents($rlLogFile, $line, FILE_APPEND | LOCK_EX);
}

// Cek status kunci untuk IP dan (kalau ada) username yang diketik di POST sebelumnya masih kosong
// saat GET, jadi untuk tampilan awal cukup cek berdasarkan IP.
$ipKey = rl_key_ip();
$lockRemaining = rl_status_for_key($rlFile, $ipKey);

$error = '';
if ($lockRemaining > 0) {
    $error = 'Terlalu banyak percobaan login gagal dari perangkat ini. Coba lagi dalam ' . ceil($lockRemaining / 60) . ' menit.';
}

// Hash dummy dipakai untuk menyamakan waktu proses ketika username tidak ditemukan,
// supaya penyerang tidak bisa menebak username valid lewat perbedaan waktu respons.
// (Ini bukan hash akun sungguhan, hanya untuk menjaga durasi password_verify tetap konsisten.)
const DUMMY_PASSWORD_HASH = '$2y$10$abcdefghijklmnopqrstuuOe1cQAqz8m1r1r1r1r1r1r1r1r1r1r1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $userKey  = rl_key_user($username);

    // Cek ulang kunci IP + kunci username spesifik sebelum memproses kredensial.
    $lockRemaining = max(
        rl_status_for_key($rlFile, $ipKey),
        rl_status_for_key($rlFile, $userKey)
    );

    if ($lockRemaining > 0) {
        $error = 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . ceil($lockRemaining / 60) . ' menit.';
    } else {
        // Batas panjang input tambahan di sisi server (jangan andalkan maxlength HTML saja).
        if (strlen($username) > 50 || strlen($password) > 200) {
            rl_register_fail_for_key($rlFile, $ipKey, $rlMaxAttempt, $rlWindow, $rlLockDuration);
            rl_audit_log($rlLogFile, $username, false);
            $error = 'Nama pengguna atau kata sandi salah.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            // Selalu jalankan password_verify, baik user ditemukan atau tidak,
            // supaya waktu respons konsisten (mitigasi timing attack / user enumeration).
            $hashToCheck = $row['password'] ?? DUMMY_PASSWORD_HASH;
            $passwordOk = password_verify($password, $hashToCheck);

            if ($row && $passwordOk) {
                rl_register_success_for_key($rlFile, $ipKey);
                rl_register_success_for_key($rlFile, $userKey);
                rl_audit_log($rlLogFile, $username, true);

                // Regenerasi session ID untuk mencegah session fixation.
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => $row['id'],
                    'username' => $row['username'],
                    'role' => $row['role'],
                    'permissions' => decode_permissions($row['permissions']),
                    'bidang_nama' => $row['bidang_nama'] ?? null,
                ];

                if (!empty($_POST['remember'])) {
                    remember_issue_token($pdo, (int)$row['id']);
                }

                redirect('index.php');
            } else {
                rl_register_fail_for_key($rlFile, $ipKey, $rlMaxAttempt, $rlWindow, $rlLockDuration);
                rl_register_fail_for_key($rlFile, $userKey, $rlMaxAttempt, $rlWindow, $rlLockDuration);
                rl_audit_log($rlLogFile, $username, false);
                usleep(400000); // jeda kecil, perlambat percobaan otomatis
                $error = 'Nama pengguna atau kata sandi salah.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — Pendataan ATK</title>
    <link rel="icon" type="image/png" href="assets/1.png">
    <link rel="shortcut icon" type="image/png" href="assets/1.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/style.css">

    <style>
        :root {
            --ink: #0c0e12;
            --panel: #11141a;
            --field: #161a21;
            --line: #252a35;
            --gold: #d4b483;
            --gold-deep: #b99764;
            --text: #ece8df;
            --dim: #9aa1ae;
            --faint: #636b79;
            --danger: #e0877d;
        }

        html, body {
            margin: 0;
            min-height: 100vh;
            background: var(--ink) !important;
            color: var(--text) !important;
            font-family: 'Inter', Arial, sans-serif;
        }
        .lg, .lg * { box-sizing: border-box; }
        .lg { min-height: 100vh; display: flex; }

        /* ===== Panel kiri ===== */
        .lg-side {
            position: relative;
            flex: 1 1 56%;
            overflow: hidden;
            padding: 56px 64px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            background: #0f1218 url("assets/kantor.jpg?v=2") 24% center / cover no-repeat;
            border-right: 1px solid var(--line);
        }
        /* Lapisan gelap supaya teks tetap jelas dibaca di atas foto */
        .lg-side::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(9,11,15,.78) 0%, rgba(9,11,15,.40) 38%, rgba(9,11,15,.62) 66%, rgba(9,11,15,.94) 100%),
                linear-gradient(90deg, rgba(9,11,15,.55) 0%, rgba(9,11,15,0) 70%);
            pointer-events: none;
        }
        .lg-side > * { position: relative; }

        .lg-brand { display: flex; align-items: center; gap: 14px; }
        .lg-brand img {
            width: 46px; height: 46px; object-fit: contain;
            padding: 6px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(255,255,255,.03);
        }
        .lg-brand span {
            font-size: 12.5px;
            line-height: 1.5;
            letter-spacing: .02em;
            color: #cfd3da;
        }

        .lg-hero { margin-top: auto; }
        .lg-hero h2 {
            margin: 0 0 18px;
            max-width: 520px;
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 400;
            font-size: clamp(34px, 4.2vw, 56px);
            line-height: 1.08;
            letter-spacing: -0.015em;
            color: var(--text);
            text-shadow: 0 2px 24px rgba(0,0,0,.45);
        }
        .lg-hero h2 em { font-style: italic; color: var(--gold); }
        .lg-hero p {
            margin: 0;
            max-width: 400px;
            font-size: 15px;
            line-height: 1.7;
            color: var(--dim);
        }

        .lg-meta {
            margin-top: 40px;
            display: flex;
            gap: 36px;
            font-size: 12.5px;
            color: var(--faint);
        }
        .lg-meta b { display: block; margin-bottom: 3px; font-weight: 500; color: var(--dim); }

        /* ===== Panel kanan ===== */
        .lg-main {
            flex: 0 0 44%;
            max-width: 560px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 48px;
        }
        .lg-form { width: 100%; max-width: 360px; }

        .lg-form .eyebrow {
            display: inline-block;
            margin-bottom: 14px;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--gold);
        }
        .lg-form h1 {
            margin: 0 0 8px;
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 400;
            font-size: 34px;
            letter-spacing: -0.01em;
            color: var(--text);
        }
        .lg-form .sub { margin: 0 0 32px; font-size: 14px; line-height: 1.6; color: var(--dim); }

        .lg-form .row { margin-bottom: 20px; }
        .lg-form label.lbl {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--dim);
        }
        .lg-form input.f-input {
            display: block;
            width: 100%;
            height: 48px;
            padding: 0 16px;
            background: var(--field) !important;
            color: var(--text) !important;
            border: 1px solid var(--line) !important;
            border-radius: 10px !important;
            font-family: inherit;
            font-size: 15px;
            box-shadow: none !important;
            transition: border-color .2s, background .2s, box-shadow .2s;
        }
        .lg-form input.f-input:hover:not(:disabled) { border-color: #353c4a !important; }
        .lg-form input.f-input:focus {
            outline: none;
            border-color: var(--gold) !important;
            background: #191d25 !important;
            box-shadow: 0 0 0 4px rgba(212,180,131,.10) !important;
        }
        .lg-form input.f-input:disabled { opacity: .45; cursor: not-allowed; }
        .lg-form input:-webkit-autofill {
            -webkit-text-fill-color: var(--text);
            -webkit-box-shadow: 0 0 0 100px var(--field) inset;
        }

        .lg-form .remember {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 4px 0 28px;
            font-size: 14px;
            color: var(--dim);
        }
        .lg-form .remember input {
            appearance: none;
            -webkit-appearance: none;
            width: 18px; height: 18px; margin: 0;
            border: 1px solid #3a4150;
            border-radius: 5px;
            background: var(--field);
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
            transition: border-color .15s, background .15s;
        }
        .lg-form .remember input:checked { background: var(--gold); border-color: var(--gold); }
        .lg-form .remember input:checked::after {
            content: '';
            position: absolute;
            left: 5.5px; top: 2px;
            width: 4px; height: 9px;
            border: solid var(--ink);
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .lg-form .remember input:focus-visible { box-shadow: 0 0 0 4px rgba(212,180,131,.18); outline: none; }
        .lg-form .remember label { cursor: pointer; user-select: none; }

        .lg-form button.btn-masuk {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            height: 50px;
            background: linear-gradient(180deg, #dcbf90, var(--gold-deep));
            color: #17130c;
            border: 0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .01em;
            cursor: pointer;
            transition: filter .2s, transform .1s;
        }
        .lg-form button.btn-masuk .arrow { transition: transform .2s; }
        .lg-form button.btn-masuk:hover:not(:disabled) { filter: brightness(1.07); }
        .lg-form button.btn-masuk:hover:not(:disabled) .arrow { transform: translateX(4px); }
        .lg-form button.btn-masuk:active:not(:disabled) { transform: scale(.99); }
        .lg-form button.btn-masuk:focus-visible { outline: none; box-shadow: 0 0 0 4px rgba(212,180,131,.25); }
        .lg-form button.btn-masuk:disabled { opacity: .4; cursor: not-allowed; }

        .lg-err {
            margin-bottom: 22px;
            padding: 12px 14px;
            background: rgba(224,135,125,.08);
            border: 1px solid rgba(224,135,125,.28);
            border-radius: 10px;
            color: var(--danger);
            font-size: 13.5px;
            line-height: 1.5;
        }

        .lg-foot {
            margin-top: 36px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
            font-size: 12px;
            line-height: 1.6;
            color: var(--faint);
        }

        /* ===== Cek barang (tanpa login) ===== */
        .cek-box {
            margin-top: 28px;
            padding: 20px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
        }
        .cek-box h3 {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
        }
        .cek-box .cek-note {
            margin: 0 0 16px;
            font-size: 12.5px;
            line-height: 1.6;
            color: var(--dim);
        }
        .cek-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .cek-btn {
            flex: 1 1 130px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 42px;
            padding: 0 14px;
            background: var(--field);
            color: var(--text);
            border: 1px solid var(--line);
            border-radius: 10px;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            transition: border-color .2s, background .2s;
        }
        .cek-btn:hover { border-color: var(--gold); background: #191d25; }
        .cek-btn:focus-visible { outline: none; box-shadow: 0 0 0 4px rgba(212,180,131,.18); }
        #cek-reader { display: none; max-width: 300px; margin: 14px auto 0; border-radius: 10px; overflow: hidden; }
        .cek-status { min-height: 18px; margin-top: 12px; font-size: 12.5px; line-height: 1.5; color: var(--dim); }
        .cek-status.is-error { color: var(--danger); }
        #cek-card {
            display: none;
            margin-top: 14px;
            padding: 14px 16px;
            background: var(--field);
            border: 1px solid var(--line);
            border-left: 3px solid var(--gold);
            border-radius: 10px;
        }
        #cek-card .cek-nama { margin: 0 0 10px; font-size: 15px; font-weight: 600; color: var(--text); }
        #cek-card dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 13px; }
        #cek-card dt { color: var(--faint); }
        #cek-card dd { margin: 0; color: var(--text); word-break: break-word; }
        #cek-card dd.cek-stok { font-weight: 600; color: var(--gold); }
        #cek-card .cek-hint { margin: 14px 0 0; font-size: 12px; color: var(--faint); }

        .cek-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 14px; }
        .cek-stat { padding: 10px; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; text-align: center; }
        .cek-stat span { display: block; margin-bottom: 4px; font-size: 11px; color: var(--faint); }
        .cek-stat b { font-size: 14px; font-weight: 600; color: var(--text); word-break: break-word; }
        .cek-stat.is-sisa b { color: var(--gold); }

        .cek-sub { margin: 16px 0 8px; font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--dim); }
        .cek-empty { font-size: 12.5px; color: var(--faint); }

        .cek-line {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 8px 0; border-bottom: 1px solid var(--line); font-size: 13px;
        }
        .cek-line:last-child { border-bottom: 0; }
        .cek-line .l { min-width: 0; }
        .cek-line .l b { display: block; font-weight: 500; color: var(--text); word-break: break-word; }
        .cek-line .l small { color: var(--faint); font-size: 11.5px; }
        .cek-line .r { white-space: nowrap; font-weight: 600; color: var(--text); text-align: right; }
        .cek-line .r.masuk { color: #8fcf9f; }
        .cek-line .r.keluar { color: var(--danger); }
        .cek-bast-btn {
            margin-left: 8px; height: 28px; padding: 0 10px;
            background: transparent; color: var(--gold);
            border: 1px solid var(--line); border-radius: 7px;
            font-family: inherit; font-size: 12px; cursor: pointer;
        }
        .cek-bast-btn:hover { border-color: var(--gold); }
        .cek-bast-view { margin: 6px 0 10px; }
        .cek-bast-view img { display: block; width: 100%; max-height: 420px; object-fit: contain; background: #fff; border-radius: 8px; border: 1px solid var(--line); }
        .cek-bast-view iframe { display: block; width: 100%; height: 420px; background: #fff; border: 1px solid var(--line); border-radius: 8px; }
        .cek-bast-view a { display: inline-block; margin-top: 6px; font-size: 12px; color: var(--gold); }

        /* Animasi masuk halus */
        @keyframes lg-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        .lg-form > *, .lg-hero, .lg-brand { animation: lg-rise .6s ease both; }
        .lg-form > *:nth-child(2) { animation-delay: .05s; }
        .lg-form > *:nth-child(3) { animation-delay: .1s; }
        .lg-form > *:nth-child(4) { animation-delay: .15s; }
        .lg-form > *:nth-child(5) { animation-delay: .2s; }
        .lg-form > *:nth-child(6) { animation-delay: .25s; }
        .lg-form > *:nth-child(7) { animation-delay: .3s; }
        .lg-form > *:nth-child(8) { animation-delay: .35s; }
        @media (prefers-reduced-motion: reduce) {
            .lg-form > *, .lg-hero, .lg-brand { animation: none; }
        }

        /* ===== Layar sempit ===== */
        @media (max-width: 900px) {
            .lg { flex-direction: column; }
            .lg-side {
                flex: none;
                padding: 28px 24px 30px;
                border-right: 0;
                border-bottom: 1px solid var(--line);
                gap: 22px;
            }
            .lg-side { background-position: 22% 30%; }
            .lg-hero h2 { font-size: 28px; margin-bottom: 0; }
            .lg-hero p, .lg-meta { display: none; }
            .lg-main { flex: none; max-width: none; padding: 36px 24px 48px; }
            .lg-form { margin: 0 auto; }
        }
    </style>
</head>
<body>

<div class="lg">
    <aside class="lg-side">
        <div class="lg-brand">
            <img src="assets/1.png" alt="Logo">
            <span>Kantor Imigrasi Kelas I Khusus TPI<br>Ngurah Rai</span>
        </div>

        <div class="lg-hero">
            <h2>Pendataan ATK, <em>Dapat</em> terkontrol.</h2>
            <p>Catat barang masuk, barang keluar, dan pantau stok alat tulis kantor dalam satu sistem.</p>
        </div>

        <div class="lg-meta">
            <div><b>Dikelola oleh</b>Seksi Teknologi Informasi</div>
            <div><b>Akses</b>Khusus pegawai terdaftar</div>
        </div>
    </aside>

    <main class="lg-main">
        <div class="lg-form">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                <span class="eyebrow">Sistem Inventaris</span>
                <h1>Selamat datang</h1>
                <p class="sub">Masuk dengan akun yang diberikan oleh admin.</p>

                <?php if ($error): ?>
                    <div class="lg-err" role="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <div class="row">
                    <label class="lbl" for="username">Nama pengguna</label>
                    <input class="f-input" id="username" name="username" placeholder="Masukkan nama pengguna" required autofocus maxlength="50" <?= $lockRemaining > 0 ? 'disabled' : '' ?>>
                </div>

                <div class="row">
                    <label class="lbl" for="password">Kata sandi</label>
                    <input class="f-input" type="password" id="password" name="password" placeholder="Masukkan kata sandi" required maxlength="200" <?= $lockRemaining > 0 ? 'disabled' : '' ?>>
                </div>

                <div class="remember">
                    <input type="checkbox" id="remember" name="remember" value="1">
                    <label for="remember">Ingat saya selama 30 hari</label>
                </div>

                <button class="btn-masuk" type="submit" <?= $lockRemaining > 0 ? 'disabled' : '' ?>>
                    <span>Masuk</span><span class="arrow" aria-hidden="true">&rarr;</span>
                </button>
            </form>

            <!-- ===== Cek barang tanpa login (hanya lihat) ===== -->
            <section class="cek-box" aria-labelledby="cek-title">
                <h3 id="cek-title">Cek barang tanpa login</h3>
                <p class="cek-note">Pindai QR pada label barang untuk melihat nama dan stoknya. Untuk menambah atau mengubah data, silakan masuk dulu.</p>

                <div class="cek-actions">
                    <button type="button" class="cek-btn" id="cek-btn-scan">Mulai Kamera</button>
                    <label class="cek-btn" id="cek-file-label" for="cek-file">Ambil/Unggah Foto QR</label>
                    <input type="file" id="cek-file" accept="image/*" capture="environment" style="display:none;">
                </div>

                <div id="cek-reader"></div>
                <div id="cek-status" class="cek-status" role="status" aria-live="polite"></div>

                <div id="cek-card">
                    <p class="cek-nama" id="cek-nama"></p>
                    <dl>
                        <dt>Kode</dt><dd id="cek-kode"></dd>
                        <dt>Jenis</dt><dd id="cek-jenis"></dd>
                        <dt>Tahun masuk</dt><dd id="cek-tahun"></dd>
                    </dl>

                    <div class="cek-stats">
                        <div class="cek-stat"><span>Masuk</span><b id="cek-masuk"></b></div>
                        <div class="cek-stat"><span>Keluar</span><b id="cek-keluar"></b></div>
                        <div class="cek-stat is-sisa"><span>Sisa stok</span><b id="cek-stok"></b></div>
                    </div>

                    <h4 class="cek-sub">Bidang yang sudah mengambil</h4>
                    <div id="cek-bidang"></div>

                    <h4 class="cek-sub">Riwayat terbaru</h4>
                    <div id="cek-riwayat"></div>

                    <p class="cek-hint">Hanya untuk melihat. Masuk untuk mencatat barang masuk/keluar.</p>
                </div>
            </section>

            <div class="lg-foot">Seksi Teknologi Informasi<br>Kantor Imigrasi Kelas I Khusus TPI Ngurah Rai</div>
        </div>
    </main>
</div>

<script src="assets/html5-qrcode.min.js"></script>
<script src="assets/login-scan.js"></script>
</body>
</html>