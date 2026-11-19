<?php
// Menampilkan berkas BAST untuk fitur "Cek barang" (tanpa login).
// Syarat: id transaksi DAN kode barang harus cocok, hanya transaksi keluar, hanya
// berkas di folder uploads/bast/. Matikan lewat PUBLIC_BAST_ENABLED di includes/public_helpers.php.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public_helpers.php';

function bast_fail(int $code, string $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!PUBLIC_BAST_ENABLED) bast_fail(403, 'Fitur ini dinonaktifkan.');
if (!pub_rate_ok('bast', 40)) bast_fail(429, 'Terlalu banyak permintaan.');

$id   = (int)($_GET['id'] ?? 0);
$kode = trim((string)($_GET['kode'] ?? ''));
if ($id <= 0 || $kode === '' || strlen($kode) > 50) bast_fail(400, 'Permintaan tidak valid.');

$stmt = $pdo->prepare("SELECT t.bast_file FROM transactions t
    JOIN items i ON i.id = t.item_id
    WHERE t.id = ? AND i.kode = ? AND t.tipe = 'keluar' LIMIT 1");
$stmt->execute([$id, $kode]);
$row = $stmt->fetch();
if (!$row || empty($row['bast_file'])) bast_fail(404, 'Berkas tidak ditemukan.');

$baseDir = realpath(__DIR__ . '/uploads/bast');
$path    = $baseDir ? realpath($baseDir . '/' . basename($row['bast_file'])) : false;
if (!$path || strpos($path, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    bast_fail(404, 'Berkas tidak ditemukan.');
}

$mimes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!isset($mimes[$ext])) bast_fail(415, 'Jenis berkas tidak didukung.');

header('Content-Type: ' . $mimes[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="bast-' . $id . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');   // boleh ditampilkan di iframe halaman login
header('Cache-Control: private, no-store');
readfile($path);
