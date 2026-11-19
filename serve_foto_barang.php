<?php
// Bersihkan buffer output apa pun yang mungkin sudah terlanjur keluar
// (mis. whitespace/BOM dari file yang di-require) sebelum kirim header gambar.
ini_set('display_errors', '0');
while (ob_get_level() > 0) { ob_end_clean(); }

// Cegah PHP menambah header "Pragma: no-cache" & "Expires: 1981" yang membuat browser
// tidak mau menyimpan gambar di cache. Harus dipanggil SEBELUM session_start() (di bootstrap).
session_cache_limiter('');

require __DIR__ . '/includes/bootstrap.php';
require_login();

// Lepas kunci session. Tanpa ini, request gambar harus antre satu per satu
// (PHP mengunci session selama script jalan), jadi 30 foto = 30 kali menunggu.
session_write_close();

require_once __DIR__ . '/includes/foto_helper.php';

// Jaga-jaga kalau masih ada header cache lama dari session.
header_remove('Pragma');
header_remove('Expires');

if (headers_sent($hsFile, $hsLine)) {
    http_response_code(500);
    header_remove();
    error_log("serve_foto_barang.php: headers already sent by $hsFile:$hsLine");
    // Jangan kirim teks apa pun agar tidak makin merusak stream gambar;
    // cukup catat ke error log server untuk didiagnosis.
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$wantThumb = !empty($_GET['thumb']);
$path = null;
$fotoDir = __DIR__ . '/uploads/barang/';

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT foto FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && !empty($row['foto'])) {
        // Cegah path traversal: hanya nama file polos yang diizinkan.
        $namaFile = basename($row['foto']);
        $candidate = $fotoDir . $namaFile;
        if (is_file($candidate)) {
            $path = $candidate;
        }
    }
}

if (!$path) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Foto tidak ditemukan.';
    exit;
}

// Mode thumbnail: pakai versi kecil (dibuat otomatis sekali saja untuk foto lama).
$mime = null;
if ($wantThumb) {
    $thumbPath = foto_thumb_path($fotoDir, $namaFile);
    if (!is_file($thumbPath)) {
        foto_buat_thumb($path, $thumbPath);
    }
    if (is_file($thumbPath)) {
        $path = $thumbPath;
        $mime = 'image/webp';
    }
}

if (!$mime) {
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null;
}
if (!$mime) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $mime = $map[$ext] ?? 'application/octet-stream';
}

$size = filesize($path);
$mtime = filemtime($path);
$etag = '"' . md5($path . '|' . $mtime . '|' . $size) . '"';

// Kalau URL membawa ?v=<nama file> (berubah tiap foto diganti), aman disimpan sangat lama.
// Kalau tidak, browser tetap boleh simpan tapi wajib cek ulang lewat ETag.
if (isset($_GET['v']) && $_GET['v'] !== '') {
    header('Cache-Control: private, max-age=31536000, immutable');
} else {
    header('Cache-Control: private, no-cache');
}
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Content-Type-Options: nosniff');

// Browser sudah punya versi terbaru -> cukup jawab 304 tanpa kirim ulang gambar.
$inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($inm !== '' && trim($inm) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
readfile($path);
exit;