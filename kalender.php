<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$u = current_user();
$isBidang = is_bidang();
if (!$isBidang && !has_permission('kelola_permintaan')) {
    http_response_code(403);
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><div class="empty"><b>Akses ditolak</b>Menu ini untuk admin bidang, admin gudang, dan super admin.</div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- Bulan yang ditampilkan ----------
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) $bulan = date('Y-m');
$first = $bulan . '-01';
$daysInMonth = (int)date('t', strtotime($first));
$last = $bulan . '-' . str_pad((string)$daysInMonth, 2, '0', STR_PAD_LEFT);
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$judulBulan = $namaBulan[(int)substr($bulan, 5, 2)] . ' ' . substr($bulan, 0, 4);

// ---------- Filter bidang (hanya untuk admin) ----------
$fBidang = $isBidang ? '' : trim($_GET['bidang'] ?? '');
$bidangList = [];
if (!$isBidang) {
    $bidangList = $pdo->query('SELECT DISTINCT bidang FROM permintaan WHERE bidang IS NOT NULL AND bidang <> "" ORDER BY bidang')->fetchAll(PDO::FETCH_COLUMN);
}

$where = ['p.tanggal BETWEEN ? AND ?'];
$par = [$first, $last];
if ($isBidang) { $where[] = 'p.user_id = ?'; $par[] = $u['id']; }
elseif ($fBidang !== '') { $where[] = 'p.bidang = ?'; $par[] = $fBidang; }

$st = $pdo->prepare('SELECT p.* FROM permintaan p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.tanggal, p.id');
$st->execute($par);
$rows = $st->fetchAll();

$items = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $si = $pdo->prepare("SELECT pi.*, i.foto AS foto_barang, i.satuan AS satuan_barang FROM permintaan_items pi LEFT JOIN items i ON i.id = pi.item_id WHERE pi.permintaan_id IN ($in) ORDER BY pi.id");
    $si->execute($ids);
    foreach ($si->fetchAll() as $it) $items[$it['permintaan_id']][] = $it;
}

// Status gabungan untuk warna kalender.
function kal_state(array $r): string {
    if ($r['status'] === 'ditolak') return 'ditolak';
    $adaBast = !empty($r['bast_file']);
    if ($r['status'] === 'selesai') return $adaBast ? 'selesai' : 'bast';
    if ($r['status'] === 'diproses') return $adaBast ? 'diproses' : 'bast';
    return 'menunggu';
}
$stateLabel = ['selesai' => 'Selesai', 'bast' => 'Belum upload BAST', 'diproses' => 'Diproses', 'menunggu' => 'Menunggu', 'ditolak' => 'Ditolak'];

$byDay = []; $ringkas = ['total' => 0, 'belum_selesai' => 0, 'belum_bast' => 0, 'selesai' => 0];
$perBidang = [];
foreach ($rows as $r) {
    $state = kal_state($r);
    $ringkas['total']++;
    if (!in_array($r['status'], ['selesai', 'ditolak'], true)) $ringkas['belum_selesai']++;
    if (in_array($r['status'], ['diproses', 'selesai'], true) && empty($r['bast_file'])) $ringkas['belum_bast']++;
    if ($state === 'selesai') $ringkas['selesai']++;
    $b = $r['bidang'] ?: '-';
    $perBidang[$b] = $perBidang[$b] ?? ['total' => 0, 'belum_selesai' => 0, 'belum_bast' => 0];
    $perBidang[$b]['total']++;
    if (!in_array($r['status'], ['selesai', 'ditolak'], true)) $perBidang[$b]['belum_selesai']++;
    if (in_array($r['status'], ['diproses', 'selesai'], true) && empty($r['bast_file'])) $perBidang[$b]['belum_bast']++;

    $its = [];
    foreach ($items[$r['id']] ?? [] as $it) {
        $its[] = [
            'nama' => $it['nama_barang'], 'jumlah' => (int)$it['jumlah'], 'satuan' => $it['satuan_barang'] ?: 'pcs',
            'ket' => $it['keterangan_satuan'] ?: '',
            'foto' => (!empty($it['foto_barang']) && !empty($it['item_id'])) ? 'serve_foto_barang.php?id=' . (int)$it['item_id'] . '&thumb=1&v=' . urlencode($it['foto_barang']) : '',
            'fotoFull' => (!empty($it['item_id'])) ? 'serve_foto_barang.php?id=' . (int)$it['item_id'] : '',
        ];
    }
    $byDay[$r['tanggal']][] = [
        'id' => (int)$r['id'], 'bidang' => $r['bidang'], 'pj' => $r['penanggung_jawab'], 'status' => $r['status'],
        'state' => $state, 'stateLabel' => $stateLabel[$state], 'bast' => !empty($r['bast_file']), 'bastName' => $r['bast_file'],
        'catatan' => $r['catatan_admin'], 'items' => $its,
    ];
}

$today = date('Y-m-d');
$selected = $_GET['tgl'] ?? '';
if (!isset($byDay[$selected])) {
    $selected = isset($byDay[$today]) ? $today : (array_key_first($byDay) ?? '');
}

$startDow = (int)date('N', strtotime($first)); // 1=Senin
$jsonDay = json_encode($byDay, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$qBase = function (array $extra) use ($bulan, $fBidang) {
    return 'kalender.php?' . http_build_query(array_filter(array_merge(['bulan' => $bulan, 'bidang' => $fBidang], $extra), fn($v) => $v !== ''));
};

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div>
    <h1>Kalender Pengambilan</h1>
    <div class="sub"><?= $isBidang ? 'Lihat barang apa saja yang diambil pada tiap tanggal, status penyelesaian, dan kelengkapan BAST' : 'Pantau pengambilan barang semua bidang: apa yang diminta, mana yang belum selesai, dan mana yang belum upload BAST' ?></div>
  </div>
</div>

<div class="grid-stats pq-stats">
  <div class="stat-card"><span class="stat-ic g-indigo"><?= icon('clipboard', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['total'] ?></div><div class="label">Permintaan bulan ini</div></span></div>
  <div class="stat-card"><span class="stat-ic g-amber"><?= icon('clock', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['belum_selesai'] ?></div><div class="label">Belum selesai</div></span></div>
  <div class="stat-card"><span class="stat-ic g-red"><?= icon('file', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['belum_bast'] ?></div><div class="label">Belum upload BAST</div></span></div>
  <div class="stat-card"><span class="stat-ic g-green"><?= icon('user-check', 26) ?></span><span class="stat-body"><div class="val"><?= $ringkas['selesai'] ?></div><div class="label">Selesai &amp; lengkap</div></span></div>
</div>

<div class="kal-wrap">
  <div class="card kal-card">
    <div class="kal-head">
      <a class="icon-btn" href="<?= e($qBase(['bulan' => $prev])) ?>" title="Bulan sebelumnya">&larr;</a>
      <h3><?= e($judulBulan) ?></h3>
      <a class="icon-btn" href="<?= e($qBase(['bulan' => $next])) ?>" title="Bulan berikutnya">&rarr;</a>
      <a class="btn btn-ghost" style="font-size:12px;padding:6px 12px;margin-left:6px;" href="<?= e($qBase(['bulan' => date('Y-m')])) ?>">Bulan ini</a>
      <?php if (!$isBidang): ?>
        <form method="get" class="kal-filter">
          <input type="hidden" name="bulan" value="<?= e($bulan) ?>">
          <select name="bidang" onchange="this.form.submit()">
            <option value="">Semua bidang</option>
            <?php foreach ($bidangList as $b): ?><option value="<?= e($b) ?>" <?= $b === $fBidang ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>

    <div class="kal-legend">
      <span><i class="d d-selesai"></i>Selesai</span><span><i class="d d-bast"></i>Belum upload BAST</span>
      <span><i class="d d-diproses"></i>Diproses</span><span><i class="d d-menunggu"></i>Menunggu</span><span><i class="d d-ditolak"></i>Ditolak</span>
    </div>

    <div class="kal-grid">
      <?php foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $h): ?><div class="kal-dow"><?= $h ?></div><?php endforeach; ?>
      <?php for ($i = 1; $i < $startDow; $i++): ?><div class="kal-cell kal-empty"></div><?php endfor; ?>
      <?php for ($d = 1; $d <= $daysInMonth; $d++):
        $tgl = $bulan . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
        $list = $byDay[$tgl] ?? [];
        $cls = 'kal-cell' . ($tgl === $today ? ' kal-today' : '') . ($list ? ' kal-has' : '') . ($tgl === $selected ? ' kal-sel' : '');
      ?>
        <button type="button" class="<?= $cls ?>" data-tgl="<?= $tgl ?>">
          <span class="kal-num"><?= $d ?></span>
          <?php if ($list): ?>
            <span class="kal-dots">
              <?php foreach (array_slice($list, 0, 4) as $x): ?><i class="d d-<?= e($x['state']) ?>"></i><?php endforeach; ?>
              <?php if (count($list) > 4): ?><em>+<?= count($list) - 4 ?></em><?php endif; ?>
            </span>
            <span class="kal-count"><?= count($list) ?> permintaan</span>
          <?php endif; ?>
        </button>
      <?php endfor; ?>
    </div>
  </div>

  <div class="card kal-panel" id="kal-panel">
    <div class="empty-panel"><span class="empty-ic"><?= icon('clock', 26) ?></span><b>Pilih tanggal</b><p>Klik tanggal di kalender untuk melihat barang yang diambil.</p></div>
  </div>
</div>

<?php if (!$isBidang && $perBidang): ?>
<div class="card" style="margin-top:18px;padding:0;overflow:auto;">
  <div style="padding:16px 20px 4px;"><h3 style="margin:0;">Rekap per bidang &middot; <?= e($judulBulan) ?></h3></div>
  <table>
    <thead><tr><th>Bidang</th><th>Total</th><th>Belum selesai</th><th>Belum upload BAST</th></tr></thead>
    <tbody>
    <?php ksort($perBidang); foreach ($perBidang as $nm => $p): ?>
      <tr>
        <td><b><?= e($nm) ?></b></td><td><?= $p['total'] ?></td>
        <td><?= $p['belum_selesai'] ? '<span class="tag tag-warn">' . $p['belum_selesai'] . '</span>' : '<span class="tag tag-ok">0</span>' ?></td>
        <td><?= $p['belum_bast'] ? '<span class="tag tag-danger">' . $p['belum_bast'] . '</span>' : '<span class="tag tag-ok">0</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
(function(){
  var DATA = <?= $jsonDay ?>, IS_BIDANG = <?= $isBidang ? 'true' : 'false' ?>, panel = document.getElementById('kal-panel');
  var bln = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }
  function tglIndo(t){ var p=t.split('-'); return parseInt(p[2],10)+' '+bln[parseInt(p[1],10)]+' '+p[0]; }
  function render(t){
    var list = DATA[t] || [];
    var h = '<div class="kp-head"><h3>' + esc(tglIndo(t)) + '</h3><span>' + list.length + ' permintaan</span></div>';
    if (!list.length) h += '<div class="empty-panel"><span class="empty-ic">&#128197;</span><b>Tidak ada pengambilan</b><p>Tidak ada permintaan barang pada tanggal ini.</p></div>';
    list.forEach(function(r){
      h += '<div class="kp-req st-card-' + esc(r.status) + '"><div class="kp-top"><div><b>Permintaan #' + r.id + '</b>' + (IS_BIDANG ? '' : ' &middot; ' + esc(r.bidang)) +
           '<div class="kp-sub">Penanggung jawab: ' + esc(r.pj) + '</div></div><span class="kal-pill d-' + esc(r.state) + '">' + esc(r.stateLabel) + '</span></div><div class="kp-items">';
      r.items.forEach(function(it){
        h += '<div class="kp-item">' + (it.foto ? '<img loading="lazy" alt="" src="' + esc(it.foto) + '" onerror="this.onerror=null;this.src=\'' + esc(it.fotoFull) + '\'">' : '<span class="kp-noimg">&#128230;</span>') +
             '<span><b>' + esc(it.nama) + '</b><em>' + it.jumlah + ' ' + esc(it.satuan) + (it.ket ? ' (' + esc(it.ket) + ')' : '') + '</em></span></div>';
      });
      h += '</div><div class="kp-foot">';
      h += '<span class="kp-bast ' + (r.bast ? 'ok' : (r.status === 'menunggu' ? '' : 'no')) + '">' + (r.bast ? '&#10003; BAST sudah diunggah' : (r.status === 'menunggu' ? 'BAST belum diperlukan (menunggu diproses)' : '&#9888; BAST belum diunggah')) + '</span>';
      if (r.bast) h += '<button type="button" class="btn btn-ghost js-view-doc" style="font-size:12px;padding:5px 10px;" data-url="serve_permintaan_bast.php?id=' + r.id + '" data-download="serve_permintaan_bast.php?id=' + r.id + '&download=1" data-filename="' + esc(r.bastName) + '" data-image="' + (/\.pdf$/i.test(r.bastName) ? '0' : '1') + '">Lihat BAST</button>';
      else if (IS_BIDANG && (r.status === 'diproses' || r.status === 'selesai')) h += '<a class="btn btn-orange" style="font-size:12px;padding:5px 12px;" href="input_bast">Upload BAST</a>';
      if (!IS_BIDANG) h += '<a class="btn btn-ghost" style="font-size:12px;padding:5px 10px;" href="kelola_permintaan">Kelola</a>';
      h += '</div>' + (r.catatan ? '<div class="pq-note"><b>Catatan admin:</b> ' + esc(r.catatan) + '</div>' : '') + '</div>';
    });
    panel.innerHTML = h;
  }
  document.querySelectorAll('.kal-cell[data-tgl]').forEach(function(c){
    c.addEventListener('click', function(){
      document.querySelectorAll('.kal-cell.kal-sel').forEach(function(x){ x.classList.remove('kal-sel'); });
      c.classList.add('kal-sel'); render(c.dataset.tgl);
      if (window.innerWidth < 980) panel.scrollIntoView({behavior:'smooth',block:'start'});
    });
  });
  var sel = <?= json_encode($selected) ?>; if (sel) render(sel);
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
