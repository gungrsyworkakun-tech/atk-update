<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('bidang');
require_once __DIR__ . '/includes/riwayat_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('bidang.php');
csrf_check();

$aksi = (string)($_POST['aksi'] ?? 'oper');
if ($aksi === 'hapus_item' || $aksi === 'hapus_kiriman') {
    if (!oper_tabel_siap($pdo)) redirect('bidang.php');
    if ($aksi === 'hapus_item') {
        $ok = oper_hapus_item($pdo, (int)($_POST['id'] ?? 0));
        flash_set($ok ? 'Barang dihapus dari kiriman. Stok tidak berubah.' : 'Barang tidak ditemukan.', $ok ? 'ok' : 'error');
    } else {
        $ok = oper_hapus_kiriman($pdo, (int)($_POST['id'] ?? 0));
        flash_set($ok ? 'Kiriman dihapus. Stok tidak berubah.' : 'Kiriman tidak ditemukan.', $ok ? 'ok' : 'error');
    }
    redirect('bidang.php#kiriman-terkirim');
}

$me      = current_user();
$bidang  = trim((string)($_POST['bidang'] ?? ''));
$tujuan  = (int)($_POST['tujuan'] ?? 0);
$catatan = mb_substr(trim((string)($_POST['catatan'] ?? '')), 0, 255);
$keys    = array_values(array_filter((array)($_POST['k'] ?? []), 'is_string'));

if (!oper_tabel_siap($pdo)) {
    flash_set('Fitur oper belum aktif: jalankan file db tambahan/migrasi_oper_bidang.sql di phpMyAdmin dulu.', 'error');
    redirect('bidang.php');
}

$st = $pdo->prepare("SELECT id, username, bidang_nama FROM users WHERE id = ? AND role = 'bidang'");
$st->execute([$tujuan]);
$akun = $st->fetch();
if (!$akun) {
    flash_set('Pilih dulu akun admin bidang tujuan.', 'error');
    redirect('bidang.php');
}
if (!$keys || $bidang === '') {
    flash_set('Centang dulu barang yang mau dioper.', 'error');
    redirect('bidang.php');
}

// Baris diambil ulang dari database (bukan dari kiriman form), dan hanya dari bidang yang dipilih.
$rows = riwayat_bidang($pdo, $bidang, null, null, $keys);
if (!$rows) {
    flash_set('Barang yang dipilih tidak ditemukan.', 'error');
    redirect('bidang.php');
}

// Jangan kirim dua kali baris yang sama ke akun yang sama.
$st = $pdo->prepare('SELECT oi.sumber_key FROM oper_bidang_items oi JOIN oper_bidang o ON o.id = oi.oper_id WHERE o.user_id = ?');
$st->execute([$akun['id']]);
$sudah = array_flip(array_column($st->fetchAll(), 'sumber_key'));
$baru = array_values(array_filter($rows, fn($r) => !isset($sudah[$r['key']])));
$dilewati = count($rows) - count($baru);

if (!$baru) {
    flash_set('Semua barang yang dipilih sudah pernah dioper ke akun ' . $akun['username'] . '.', 'error');
    redirect('bidang.php');
}

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO oper_bidang (user_id, bidang_nama, catatan, dibuat_oleh, dibuat_oleh_nama) VALUES (?,?,?,?,?)')
        ->execute([$akun['id'], $akun['bidang_nama'] ?: $bidang, $catatan !== '' ? $catatan : null, $me['id'], $me['username'] ?? null]);
    $operId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO oper_bidang_items (oper_id, sumber_key, tanggal, nama_barang, jumlah, satuan, penanggung_jawab) VALUES (?,?,?,?,?,?,?)');
    foreach ($baru as $r) {
        $ins->execute([$operId, $r['key'], $r['tanggal'], $r['nama'], $r['jumlah'], $r['satuan'], $r['pj'] !== '' ? $r['pj'] : null]);
    }
    $pdo->commit();
    flash_set(count($baru) . ' barang berhasil dioper ke ' . ($akun['bidang_nama'] ?: $akun['username']) . ' (' . $akun['username'] . ').'
        . ($dilewati ? " $dilewati baris dilewati karena sudah pernah dioper." : ''));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('Gagal mengoper barang. Coba lagi.', 'error');
}
redirect('bidang.php');
