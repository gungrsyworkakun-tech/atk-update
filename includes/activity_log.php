<?php
// Catatan aktivitas: siapa (akun) mengubah apa dan kapan.
// Tabel dibuat otomatis kalau belum ada, jadi tidak perlu import SQL manual.

function activity_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, username TEXT NULL, role TEXT NULL,
                modul TEXT NOT NULL, aksi TEXT NOT NULL, detail TEXT NULL, tipe TEXT NULL, jumlah INTEGER NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(100) NULL,
                role VARCHAR(20) NULL,
                modul VARCHAR(40) NOT NULL,
                aksi VARCHAR(20) NOT NULL,
                detail VARCHAR(500) NULL,
                tipe VARCHAR(10) NULL,
                jumlah INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created (created_at)
            ) CHARACTER SET utf8mb4');
        }
    } catch (Throwable $e) { error_log('activity_log table: ' . $e->getMessage()); }
}

/**
 * Catat satu aktivitas. Tidak pernah melempar error (supaya proses utama tidak ikut gagal).
 * $modul : 'barang','transaksi','harga','stok_minimum','permintaan','penitipan','akun','bidang','notifikasi','bast'
 * $aksi  : 'tambah','ubah','hapus','masuk','keluar','status'
 * $tipe/$jumlah : khusus barang masuk/keluar.
 */
function log_activity(PDO $pdo, string $modul, string $aksi, string $detail = '', ?string $tipe = null, ?int $jumlah = null): void {
    try {
        activity_ensure_table($pdo);
        $u = function_exists('current_user') ? current_user() : null;
        $pdo->prepare('INSERT INTO activity_log (user_id, username, role, modul, aksi, detail, tipe, jumlah) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $u['id'] ?? null, $u['username'] ?? null, $u['role'] ?? null,
                $modul, $aksi, mb_substr($detail, 0, 500), $tipe, $jumlah,
            ]);
    } catch (Throwable $e) { error_log('log_activity: ' . $e->getMessage()); }
}

function activity_label(array $r): array {
    // Kembalikan [judul, ikon-kelas 'green'|'amber'|'blue'|'red'] untuk tampilan.
    $m = [
        'barang' => 'Data barang', 'transaksi' => 'Transaksi', 'harga' => 'Harga', 'stok_minimum' => 'Stok minimum',
        'permintaan' => 'Permintaan', 'penitipan' => 'Penitipan', 'akun' => 'Akun', 'bidang' => 'Bidang',
        'notifikasi' => 'Notifikasi', 'bast' => 'BAST', 'backup' => 'Backup',
    ];
    $aksi = ['tambah' => 'menambah', 'ubah' => 'mengubah', 'hapus' => 'menghapus', 'status' => 'memperbarui', 'masuk' => 'mencatat masuk', 'keluar' => 'mencatat keluar', 'unduh' => 'mengunduh'];
    $warna = 'blue';
    if ($r['aksi'] === 'hapus') $warna = 'red';
    elseif ($r['tipe'] === 'masuk') $warna = 'green';
    elseif ($r['tipe'] === 'keluar') $warna = 'amber';
    return [($m[$r['modul']] ?? $r['modul']), ($aksi[$r['aksi']] ?? $r['aksi']), $warna];
}
