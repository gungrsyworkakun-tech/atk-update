<?php
require __DIR__ . '/includes/bootstrap.php';
require_bidang();
require_once __DIR__ . '/includes/riwayat_helper.php';

$u = current_user();
$namaBidang = trim((string)($u['bidang_nama'] ?? ''));
$siap = oper_tabel_siap($pdo);
$kiriman = $siap ? oper_untuk_user($pdo, (int)$u['id']) : [];

// Yang belum dibuka diberi tanda "Baru", lalu otomatis dianggap sudah dibaca (lencana & banner hilang).
$baruIds = array_map('intval', array_column(array_filter($kiriman, fn($o) => !(int)$o['dibaca']), 'id'));
if ($baruIds) {
    $pdo->prepare('UPDATE oper_bidang SET dibaca = 1 WHERE user_id = ? AND dibaca = 0')->execute([$u['id']]);
}

// Riwayat lain bidang ini (permintaan yang sudah selesai + barang yang diambil langsung),
// kecuali yang sudah muncul di kiriman di atas. Bisa dicentang bersama untuk satu BAST gabungan.
$sudahAda = [];
foreach ($kiriman as $o) foreach ($o['items'] as $it) $sudahAda[$it['sumber_key']] = true;
$riwayatLain = [];
if ($namaBidang !== '') {
    foreach (riwayat_bidang($pdo, $namaBidang) as $r) {
        if (!isset($sudahAda[$r['key']])) $riwayatLain[] = $r;
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Riwayat Pengambilan</h1><div class="sub">Barang yang diambil bidang <?= e($namaBidang !== '' ? $namaBidang : '-') ?>. Centang barang dari kiriman admin dan/atau permintaan sebelumnya, lalu klik "Buat BAST" untuk membuat satu BAST gabungan.</div></div>
</div>

<?php if (!$kiriman && !$riwayatLain): ?>
  <div class="card"><div class="empty"><b>Belum ada barang</b>Daftar muncul di sini setelah admin ATK mengirim barang yang kamu ambil, atau setelah permintaanmu berstatus Selesai.</div></div>
<?php else: ?>
<form method="post" action="cetak_bast.php" target="_blank" id="formBast">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <div class="card" style="position:sticky;top:8px;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <div style="font-size:13px;color:var(--text-dim);">Centang barang di bawah, bisa dari beberapa kiriman sekaligus.</div>
    <button type="submit" class="btn btn-primary" id="btnBast" style="font-size:13px;padding:8px 18px;" disabled>Buat BAST (0)</button>
  </div>

  <?php foreach ($kiriman as $o):
    $baru = in_array((int)$o['id'], $baruIds, true);
  ?>
  <div class="card js-seksi">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
      <div>
        <h3 style="margin:0;">
          Kiriman dari <?= e($o['dibuat_oleh_nama'] ?: 'admin') ?>
          <?php if ($baru): ?><span style="background:#DC2626;color:#fff;border-radius:20px;font-size:10.5px;font-weight:700;padding:2px 8px;margin-left:6px;">Baru</span><?php endif; ?>
        </h3>
        <div style="font-size:12px;color:var(--text-dim);margin-top:2px;"><?= e(tgl_indo(substr((string)$o['created_at'], 0, 10))) ?> · <?= count($o['items']) ?> barang</div>
        <?php if (!empty($o['catatan'])): ?><div style="font-size:12.5px;margin-top:6px;">Catatan: <?= e($o['catatan']) ?></div><?php endif; ?>
      </div>
      <label style="font-size:12px;color:var(--text-dim);cursor:pointer;"><input type="checkbox" class="js-pilih-semua"> Pilih semua</label>
    </div>
    <table>
      <thead><tr><th style="width:34px;"></th><th>Tanggal ambil</th><th>Barang</th><th>Jumlah</th></tr></thead>
      <tbody>
        <?php foreach ($o['items'] as $it): ?>
        <tr>
          <td><input type="checkbox" name="k[]" value="o:<?= (int)$it['id'] ?>" class="js-baris"></td>
          <td class="mono"><?= e($it['tanggal']) ?></td>
          <td><?= e($it['nama_barang']) ?></td>
          <td class="mono"><?= (int)$it['jumlah'] ?> <?= e($it['satuan']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>

  <?php if ($riwayatLain): ?>
  <div class="card js-seksi">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
      <div>
        <h3 style="margin:0;">Permintaan sebelumnya</h3>
        <div style="font-size:12px;color:var(--text-dim);margin-top:2px;">Permintaan yang sudah Selesai dan barang yang dicatat diambil langsung · <?= count($riwayatLain) ?> baris</div>
      </div>
      <label style="font-size:12px;color:var(--text-dim);cursor:pointer;"><input type="checkbox" class="js-pilih-semua"> Pilih semua</label>
    </div>
    <table>
      <thead><tr><th style="width:34px;"></th><th>Tanggal</th><th>Barang</th><th>Jumlah</th><th>Asal</th></tr></thead>
      <tbody>
        <?php foreach ($riwayatLain as $r): ?>
        <tr>
          <td><input type="checkbox" name="k[]" value="<?= e($r['key']) ?>" class="js-baris"></td>
          <td class="mono"><?= e($r['tanggal']) ?></td>
          <td><?= e($r['nama']) ?></td>
          <td class="mono"><?= (int)$r['jumlah'] ?> <?= e($r['satuan']) ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= e($r['sumber']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</form>

<script>
(function () {
  var f = document.getElementById('formBast');
  var btn = document.getElementById('btnBast');
  function hitung() {
    var n = f.querySelectorAll('.js-baris:checked').length;
    btn.textContent = 'Buat BAST (' + n + ')';
    btn.disabled = n === 0;
    f.querySelectorAll('.js-seksi').forEach(function (s) {
      var rows = s.querySelectorAll('.js-baris');
      s.querySelector('.js-pilih-semua').checked = rows.length > 0 && s.querySelectorAll('.js-baris:checked').length === rows.length;
    });
  }
  f.querySelectorAll('.js-seksi').forEach(function (s) {
    s.querySelector('.js-pilih-semua').addEventListener('change', function (e) {
      s.querySelectorAll('.js-baris').forEach(function (c) { c.checked = e.target.checked; });
      hitung();
    });
  });
  f.querySelectorAll('.js-baris').forEach(function (c) { c.addEventListener('change', hitung); });
  hitung();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
