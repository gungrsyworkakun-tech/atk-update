<?php
require __DIR__ . '/includes/bootstrap.php';
require_bidang();

$u = current_user();

$stmt = $pdo->prepare('SELECT * FROM permintaan WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$u['id']]);
$myRequests = $stmt->fetchAll();

$itemsByReq = [];
if ($myRequests) {
    $ids = array_column($myRequests, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM permintaan_items WHERE permintaan_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) $itemsByReq[$row['permintaan_id']][] = $row;
}

// Batas waktu edit: 1 jam sejak permintaan dibuat.
const EDIT_WINDOW_SECONDS = 3600;

/**
 * Permintaan hanya boleh diedit jika:
 * 1) statusnya masih 'menunggu' (belum diproses admin)
 * 2) belum lewat 1 jam sejak dibuat
 */
function permintaan_bisa_diedit(array $r): bool
{
    if ($r['status'] !== 'menunggu') return false;
    $elapsed = time() - strtotime($r['created_at']);
    return $elapsed < EDIT_WINDOW_SECONDS;
}

/**
 * Sisa waktu edit dalam menit (untuk ditampilkan ke user), min 0.
 */
function sisa_waktu_edit_menit(array $r): int
{
    $elapsed = time() - strtotime($r['created_at']);
    $sisaDetik = EDIT_WINDOW_SECONDS - $elapsed;
    return max(0, (int) ceil($sisaDetik / 60));
}

// Hitung ringkasan untuk kartu angka di atas.
$ringkas = ['total' => count($myRequests), 'menunggu' => 0, 'diproses' => 0, 'selesai' => 0];
foreach ($myRequests as $r) { if (isset($ringkas[$r['status']])) $ringkas[$r['status']]++; }
$stepIndex = ['menunggu' => 0, 'diproses' => 1, 'selesai' => 2, 'ditolak' => 0];

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Progres ATK</h1><div class="sub">Pantau status permintaan ATK bidang <?= e($u['bidang_nama'] ?? '-') ?> secara langsung</div></div>
  <a class="btn btn-orange" href="permintaan.php">+ Ajukan Permintaan</a>
</div>

<?php if (!empty($_GET['msg'])): ?>
  <div class="flash ok"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['err'])): ?>
  <div class="flash error"><?= e($_GET['err']) ?></div>
<?php endif; ?>

<div class="grid-stats pq-stats">
  <div class="stat-card"><span class="stat-ic g-indigo"><?= icon('clipboard', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['total'] ?></div><div class="label">Total permintaan</div></span></div>
  <div class="stat-card"><span class="stat-ic g-amber"><?= icon('clock', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['menunggu'] ?></div><div class="label">Menunggu</div></span></div>
  <div class="stat-card"><span class="stat-ic g-gray"><?= icon('arrows', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['diproses'] ?></div><div class="label">Diproses</div></span></div>
  <div class="stat-card"><span class="stat-ic g-green"><?= icon('user-check', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['selesai'] ?></div><div class="label">Selesai</div></span></div>
</div>

<?php if (!$myRequests): ?>
  <div class="card"><div class="empty-panel" style="padding:36px 10px;">
    <span class="empty-ic"><?= icon('clipboard', 26) ?></span>
    <b>Belum ada permintaan</b>
    <p style="margin:0 auto;max-width:300px;">Ajukan permintaan ATK pertama bidangmu lewat tombol di atas.</p>
  </div></div>
<?php else: foreach ($myRequests as $r):
  $ditolak = $r['status'] === 'ditolak';
  $si = $stepIndex[$r['status']] ?? 0;
  $items = $itemsByReq[$r['id']] ?? [];
?>
  <div class="pq-card st-card-<?= e($r['status']) ?>">
    <div class="pq-top">
      <div class="pq-head">
        <span class="pq-avatar"><?= icon('clipboard', 20) ?></span>
        <div>
          <div class="pq-date">Permintaan #<?= (int)$r['id'] ?> <span class="pq-dim">· <?= e(date('d M Y', strtotime($r['tanggal']))) ?></span></div>
          <div class="pq-sub">Penanggung jawab: <b><?= e($r['penanggung_jawab']) ?></b></div>
        </div>
      </div>
      <span class="st-badge <?= permintaan_status_class($r['status']) ?>"><?= e(permintaan_status_label($r['status'])) ?></span>
    </div>

    <?php if ($ditolak): ?>
      <div class="pq-rejected">Permintaan ini ditolak. Stok yang tertahan sudah dikembalikan.</div>
    <?php else: ?>
      <ol class="pq-steps">
        <?php foreach (['Diajukan', 'Diproses', 'Selesai'] as $n => $label): ?>
          <li class="<?= $n < $si ? 'done' : ($n === $si ? 'now' : '') ?>"><span class="dot"><?= $n < $si || ($n === $si && $r['status'] === 'selesai') ? '&#10003;' : ($n + 1) ?></span><span class="lbl"><?= $label ?></span></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <div class="pq-chips">
      <?php foreach ($items as $it): ?>
        <span class="pq-chip"><b><?= (int)$it['jumlah'] ?></b> <?= e($it['nama_barang']) ?><?php if (!empty($it['keterangan_satuan'])): ?> <i>(diajukan: <?= e($it['keterangan_satuan']) ?>)</i><?php endif; ?></span>
      <?php endforeach; ?>
    </div>

    <div class="pq-foot">
      <?php if (permintaan_bisa_diedit($r)): ?>
        <a class="btn btn-ghost" style="font-size:12.5px;padding:7px 12px;" href="permintaan.php?edit=<?= (int)$r['id'] ?>">Edit Permintaan</a>
        <span class="pq-edit-hint">Bisa diedit <?= sisa_waktu_edit_menit($r) ?> menit lagi</span>
      <?php elseif ($r['status'] === 'menunggu'): ?>
        <span class="pq-edit-hint">Waktu edit (1 jam) sudah habis</span>
      <?php endif; ?>
      <?php if ($r['bast_file']): ?>
        <button type="button" class="btn btn-ghost js-view-doc" style="font-size:12.5px;padding:7px 12px;margin-left:auto;"
          data-url="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>"
          data-download="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>&download=1"
          data-filename="<?= e($r['bast_file']) ?>"
          data-image="<?= strtolower(pathinfo($r['bast_file'], PATHINFO_EXTENSION)) === 'pdf' ? '0' : '1' ?>">Lihat BAST</button>
      <?php else: ?>
        <span class="pq-edit-hint" style="margin-left:auto;">Belum ada BAST &mdash; unggah lewat menu Input BAST</span>
      <?php endif; ?>
    </div>
    <?php if ($r['catatan_admin']): ?>
      <div class="pq-note"><b>Catatan admin:</b> <?= e($r['catatan_admin']) ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
