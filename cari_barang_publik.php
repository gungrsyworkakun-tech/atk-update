<?php
// Endpoint PUBLIK (tanpa login) untuk fitur "Cek barang" di halaman login.
// Hanya membaca. Tidak mengirim harga, stok minimum, foto, nama penerima, atau keterangan.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function pub_json(int $status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pub_json(405, ['error' => 'Metode tidak diizinkan.']);
}
if (!pub_rate_ok('scan', 30)) {
    pub_json(429, ['error' => 'Terlalu banyak pencarian. Coba lagi sebentar lagi.']);
}

$kode = trim((string)($_GET['kode'] ?? ''));
if ($kode === '' || strlen($kode) > 50) {
    pub_json(400, ['error' => 'Kode barang tidak valid.']);
}

$stmt = $pdo->prepare('SELECT id, kode, nama, jenis, satuan, stok, tahun_masuk FROM items WHERE kode = ? LIMIT 1');
$stmt->execute([$kode]);
$item = $stmt->fetch();
if (!$item) {
    pub_json(404, ['error' => 'Barang dengan kode tersebut tidak ditemukan.']);
}
$itemId = (int)$item['id'];

// Total masuk & keluar (dalam satuan dasar barang)
$stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END), 0) AS total_masuk,
        COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END), 0) AS total_keluar,
        COUNT(*) AS jumlah_transaksi
    FROM transactions WHERE item_id = ?");
$stmt->execute([$itemId]);
$tot = $stmt->fetch();

// Bidang mana saja yang sudah mengambil
$stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(bidang), ''), '(tanpa bidang)') AS bidang,
        SUM(jumlah) AS total, COUNT(*) AS kali, MAX(tanggal) AS terakhir
    FROM transactions
    WHERE item_id = ? AND tipe = 'keluar'
    GROUP BY 1
    ORDER BY total DESC");
$stmt->execute([$itemId]);
$bidang = [];
foreach ($stmt->fetchAll() as $b) {
    $bidang[] = [
        'bidang'  => $b['bidang'],
        'total'   => (int)$b['total'],
        'kali'    => (int)$b['kali'],
        'terakhir'=> $b['terakhir'],
    ];
}

// Riwayat terbaru (maks 30). Tanpa nama penerima dan keterangan.
$stmt = $pdo->prepare("SELECT id, tipe, jumlah, tanggal, bidang, bast_file
    FROM transactions WHERE item_id = ?
    ORDER BY tanggal DESC, id DESC LIMIT 30");
$stmt->execute([$itemId]);
$riwayat = [];
foreach ($stmt->fetchAll() as $t) {
    $hasBast = PUBLIC_BAST_ENABLED && $t['tipe'] === 'keluar' && !empty($t['bast_file']);
    $ext = $hasBast ? strtolower(pathinfo($t['bast_file'], PATHINFO_EXTENSION)) : '';
    $riwayat[] = [
        'id'       => (int)$t['id'],
        'tipe'     => $t['tipe'],
        'jumlah'   => (int)$t['jumlah'],
        'tanggal'  => $t['tanggal'],
        'bidang'   => $t['tipe'] === 'keluar' ? ($t['bidang'] ?: null) : null,
        'bast'     => $hasBast,
        'bast_pdf' => $ext === 'pdf',
    ];
}

pub_json(200, [
    'kode'         => $item['kode'],
    'nama'         => $item['nama'],
    'jenis'        => $item['jenis'],
    'satuan'       => $item['satuan'],
    'stok'         => (int)$item['stok'],
    'tahun_masuk'  => (int)$item['tahun_masuk'],
    'total_masuk'  => (int)$tot['total_masuk'],
    'total_keluar' => (int)$tot['total_keluar'],
    'jumlah_transaksi' => (int)$tot['jumlah_transaksi'],
    'bidang'       => $bidang,
    'riwayat'      => $riwayat,
]);