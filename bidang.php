<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('bidang');
require_once __DIR__ . '/includes/riwayat_helper.php';

$fBidang = trim($_GET['bidang'] ?? '');
$dari    = tgl_valid($_GET['dari'] ?? '');
$sampai  = tgl_valid($_GET['sampai'] ?? '');

$rows = riwayat_bidang($pdo, $fBidang, $dari, $sampai);
$byBidang = kelompok_per_bidang($rows);
$bidangList = daftar_bidang_riwayat($pdo);
$operMap = oper_map($pdo);
$operSiap = oper_tabel_siap($pdo);
$akunBidang = $operSiap ? daftar_akun_bidang($pdo) : [];
$kirimanTerkirim = $operSiap ? oper_semua($pdo, 30) : [];

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Bidang Pengambilan</h1><div class="sub">Riwayat barang yang sudah diambil tiap bidang (permintaan yang berstatus Selesai + barang keluar yang dicatat admin). Centang barangnya, lalu klik "Oper ke bidang" (dikirim ke akun admin bidang) atau "Buat BAST".</div></div>
</div>

<form method="get" class="card" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
  <div class="field" style="margin:0;">
    <label style="font-size:11px;">Bidang</label>
    <select name="bidang">
      <option value="">Semua bidang</option>
      <?php foreach ($bidangList as $b): ?>
        <option value="<?= e($b) ?>" <?= mb_strtolower($b) === mb_strtolower($fBidang) ? 'selected' : '' ?>><?= e($b) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin:0;"><label style="font-size:11px;">Dari tanggal</label><input type="date" name="dari" value="<?= e($dari) ?>"></div>
  <div class="field" style="margin:0;"><label style="font-size:11px;">Sampai tanggal</label><input type="date" name="sampai" value="<?= e($sampai) ?>"></div>
  <button class="btn btn-primary" type="submit" style="padding:9px 16px;">Tampilkan</button>
  <?php if ($fBidang !== '' || $dari !== '' || $sampai !== ''): ?><a class="btn btn-ghost" href="bidang.php" style="padding:9px 14px;">Reset</a><?php endif; ?>
</form>

<?php if (!$operSiap): ?>
  <div class="card" style="border-color:var(--ochre-dark);"><div class="empty" style="color:var(--ochre-dark);">Tombol "Oper ke bidang" belum aktif. Jalankan file <b>db tambahan/migrasi_oper_bidang.sql</b> di phpMyAdmin satu kali.</div></div>
<?php endif; ?>

<?php if (!$byBidang): ?>
  <div class="card"><div class="empty"><b>Belum ada data pengambilan</b>Data muncul setelah permintaan bidang berstatus Selesai, atau saat admin mencatat barang keluar dengan mengisi "Bidang pengambil".</div></div>
<?php else: render_riwayat_cards($byBidang, $operMap, $akunBidang); endif; ?>

<?php if ($kirimanTerkirim): ?>
<div class="topline" id="kiriman-terkirim" style="margin-top:28px;">
  <div><h2 style="margin:0;font-size:17px;">Kiriman yang sudah dikirim ke bidang</h2><div class="sub">Salah kirim? Hapus barangnya atau seluruh kirimannya di sini. Menghapus kiriman tidak mengubah stok. Kalau barangnya tidak jadi diambil atau salah input, hapus transaksinya di menu Barang Masuk &amp; Keluar (stok kembali otomatis dan kirimannya ikut hilang).</div></div>
</div>
<?php foreach ($kirimanTerkirim as $o): ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
      <div>
        <h3 style="margin:0;">Ke <?= e($o['bidang_nama']) ?> <span style="font-size:12px;font-weight:500;color:var(--text-dim);">(<?= e($o['username'] ?? '-') ?>)</span></h3>
        <div style="font-size:12px;color:var(--text-dim);margin-top:2px;">
          <?= e(tgl_indo(substr((string)$o['created_at'], 0, 10))) ?> · oleh <?= e($o['dibuat_oleh_nama'] ?: '-') ?> ·
          <?= (int)$o['dibaca'] ? 'sudah dibuka bidang' : '<b style="color:var(--ochre-dark);">belum dibuka bidang</b>' ?>
          <?php if (!empty($o['catatan'])): ?> · <?= e($o['catatan']) ?><?php endif; ?>
        </div>
      </div>
      <form method="post" action="oper_bidang.php" style="margin:0;" onsubmit="return confirm('Hapus seluruh kiriman ini (<?= count($o['items']) ?> barang)? Stok tidak berubah.');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="aksi" value="hapus_kiriman">
        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
        <button type="submit" class="btn btn-ghost" style="font-size:12px;padding:6px 12px;color:var(--red);">Hapus kiriman</button>
      </form>
    </div>
    <table>
      <thead><tr><th>Tanggal ambil</th><th>Barang</th><th>Jumlah</th><th style="width:70px;"></th></tr></thead>
      <tbody>
        <?php foreach ($o['items'] as $it): ?>
        <tr>
          <td class="mono"><?= e($it['tanggal']) ?></td>
          <td><?= e($it['nama_barang']) ?></td>
          <td class="mono"><?= (int)$it['jumlah'] ?> <?= e($it['satuan']) ?></td>
          <td>
            <form method="post" action="oper_bidang.php" style="margin:0;" onsubmit="return confirm('Hapus &quot;<?= e(addslashes($it['nama_barang'])) ?>&quot; dari kiriman ini? Stok tidak berubah.');">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="aksi" value="hapus_item">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button type="submit" class="btn btn-ghost" style="font-size:11.5px;padding:4px 10px;color:var(--red);">Hapus</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
