<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/riwayat_helper.php';

// Hanya admin yang punya menu Bidang/BAST, atau akun bidang (untuk bidangnya sendiri).
if (!is_bidang() && !has_permission('bidang') && !has_permission('bast')) {
    http_response_code(403);
    exit('Anda tidak memiliki izin membuat BAST.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(is_bidang() ? 'riwayat_ambil.php' : 'bidang.php');
csrf_check();

$u = current_user();
$keys = array_values(array_filter((array)($_POST['k'] ?? []), 'is_string'));

if (is_bidang()) {
    // Akun bidang: kiriman untuk akunnya (key "o:<id>") + riwayat bidangnya sendiri (key "p:"/"t:"),
    // semuanya dicek ulang di database dan dikunci ke akun/bidang ini. Boleh digabung dalam satu BAST.
    $bidang = trim((string)($u['bidang_nama'] ?? ''));
    $rows = $keys ? oper_baris_untuk_bast($pdo, (int)$u['id'], $keys) : [];
    $keysPT = array_values(array_filter($keys, fn($k) => preg_match('/^[pt]:\d+$/', $k)));
    if ($bidang !== '' && $keysPT) $rows = array_merge($rows, riwayat_bidang($pdo, $bidang, null, null, $keysPT));
    // Jangan sampai satu barang tampil dua kali (mis. sama-sama dicentang lewat kiriman dan riwayat).
    $lihat = [];
    $rows = array_values(array_filter($rows, function ($r) use (&$lihat) {
        $src = $r['src'] ?? $r['key'];
        if (isset($lihat[$src])) return false;
        return $lihat[$src] = true;
    }));
    if ($rows && $bidang === '') $bidang = trim((string)$rows[0]['bidang']);
} else {
    // Admin: baris dari riwayat bidang yang dipilih, dikunci ke bidang itu.
    $bidang = trim((string)($_POST['bidang'] ?? ''));
    $rows = ($bidang !== '' && $keys) ? riwayat_bidang($pdo, $bidang, null, null, $keys) : [];
}

if (!$rows) {
    http_response_code(400);
    exit('Tidak ada barang yang dipilih untuk BAST.');
}
usort($rows, fn($a, $b) => strcmp($a['tanggal'], $b['tanggal']) ?: strcmp($a['key'], $b['key']));

$tglMin = $rows[0]['tanggal'];
$tglMax = $rows[count($rows) - 1]['tanggal'];
$periode = $tglMin === $tglMax ? tgl_indo($tglMin) : tgl_indo($tglMin) . ' s.d. ' . tgl_indo($tglMax);

$hariNama = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$hariIni = $hariNama[(int)date('w')];
$tglIni  = tgl_indo(date('Y-m-d'));

// Penanggung jawab bidang: dipakai kalau semua baris punya satu nama yang sama.
$pjList = array_values(array_unique(array_filter(array_map(fn($r) => trim($r['pj']), $rows))));
$pjDefault = count($pjList) === 1 ? $pjList[0] : '....................................';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BAST - <?= e($bidang) ?></title>
<style>
  @page { size: A4; margin: 18mm 16mm; }
  * { box-sizing: border-box; }
  body { font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; background: #e9ecf1; margin: 0; }
  .toolbar { position: sticky; top: 0; background: #1f2937; color: #fff; padding: 10px 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; font-family: Arial, sans-serif; font-size: 13px; z-index: 5; }
  .toolbar button { background: #3b82f6; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; font-weight: 600; }
  .page { background: #fff; width: 210mm; min-height: 297mm; margin: 16px auto; padding: 18mm 16mm; box-shadow: 0 4px 24px rgba(0,0,0,.18); }
  h1 { text-align: center; font-size: 14pt; margin: 0 0 4px; text-transform: uppercase; letter-spacing: .3px; }
  .no { text-align: center; margin-bottom: 18px; }
  p { text-align: justify; line-height: 1.5; margin: 8px 0; }
  table.data { width: 100%; border-collapse: collapse; margin: 12px 0 18px; font-size: 11.5pt; }
  table.data th, table.data td { border: 1px solid #000; padding: 5px 7px; vertical-align: top; }
  table.data th { background: #f1f1f1; text-align: center; }
  table.data td.c { text-align: center; }
  .sign { display: flex; justify-content: space-between; gap: 24px; margin-top: 26px; page-break-inside: avoid; }
  .sign > div { flex: 1; text-align: center; }
  .sign .space { height: 70px; }
  .sign .nm { font-weight: bold; text-decoration: underline; }
  [contenteditable] { outline: 1px dashed #93a3b8; outline-offset: 2px; min-width: 30px; display: inline-block; }
  [contenteditable]:focus { outline: 2px solid #3b82f6; background: #f0f7ff; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .page { margin: 0; padding: 0; width: auto; min-height: 0; box-shadow: none; }
    [contenteditable] { outline: none !important; background: none !important; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
  <span>Teks yang dikelilingi garis putus-putus bisa diklik dan diubah (nomor BAST, nama, NIP, jabatan) sebelum dicetak.</span>
</div>

<div class="page">
  <h1>Berita Acara Serah Terima Barang</h1>
  <h1 style="margin-top:0;">Alat Tulis Kantor (ATK)</h1>
  <div class="no">Nomor: <span contenteditable="true">............./BAST-ATK/........../<?= e(date('Y')) ?></span></div>

  <p>Pada hari ini, <b><?= e($hariIni) ?></b> tanggal <b><?= e($tglIni) ?></b>, yang bertanda tangan di bawah ini:</p>

  <table style="width:100%;border-collapse:collapse;margin-left:0;">
    <tr><td style="width:30px;vertical-align:top;">1.</td><td>
      Nama: <span contenteditable="true">....................................</span><br>
      NIP: <span contenteditable="true">....................................</span><br>
      Jabatan: <span contenteditable="true">Pengelola Barang / Pengurus ATK</span><br>
      Selanjutnya disebut <b>PIHAK PERTAMA</b> (yang menyerahkan).
    </td></tr>
    <tr><td colspan="2" style="height:8px;"></td></tr>
    <tr><td style="vertical-align:top;">2.</td><td>
      Nama: <span contenteditable="true"><?= e($pjDefault) ?></span><br>
      NIP: <span contenteditable="true">....................................</span><br>
      Jabatan: <span contenteditable="true">Penanggung jawab ATK <?= e($bidang) ?></span><br>
      Selanjutnya disebut <b>PIHAK KEDUA</b> (yang menerima), mewakili bidang <b><?= e($bidang) ?></b>.
    </td></tr>
  </table>

  <p>PIHAK PERTAMA telah menyerahkan kepada PIHAK KEDUA barang Alat Tulis Kantor (ATK) yang diambil pada periode <b><?= e($periode) ?></b>, dan PIHAK KEDUA menyatakan telah menerimanya dalam keadaan baik dan cukup, dengan rincian sebagai berikut:</p>

  <table class="data">
    <thead><tr><th style="width:36px;">No</th><th>Nama Barang</th><th style="width:70px;">Jumlah</th><th style="width:70px;">Satuan</th><th style="width:110px;">Tanggal Ambil</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td class="c"><?= $i + 1 ?></td>
        <td><?= e($r['nama']) ?></td>
        <td class="c"><?= (int)$r['jumlah'] ?></td>
        <td class="c"><?= e($r['satuan']) ?></td>
        <td class="c"><?= e(tgl_indo($r['tanggal'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p>Demikian Berita Acara Serah Terima ini dibuat dalam rangkap secukupnya untuk dipergunakan sebagaimana mestinya.</p>

  <div class="sign">
    <div>PIHAK KEDUA<br>(Penerima)<div class="space"></div><div class="nm"><span contenteditable="true"><?= e($pjDefault) ?></span></div>NIP. <span contenteditable="true">..................</span></div>
    <div>PIHAK PERTAMA<br>(Yang menyerahkan)<div class="space"></div><div class="nm"><span contenteditable="true">....................................</span></div>NIP. <span contenteditable="true">..................</span></div>
  </div>
</div>
</body>
</html>
