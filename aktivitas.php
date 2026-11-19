<?php
require __DIR__ . '/includes/bootstrap.php';
require_super();
activity_ensure_table($pdo);

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$fUser = trim($_GET['user'] ?? '');
$fModul = trim($_GET['modul'] ?? '');

$cond = []; $par = [];
if ($fUser !== '') { $cond[] = 'username = ?'; $par[] = $fUser; }
if ($fModul !== '') { $cond[] = 'modul = ?'; $par[] = $fModul; }
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM activity_log $where"); $cnt->execute($par);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$off = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT * FROM activity_log $where ORDER BY id DESC LIMIT $perPage OFFSET $off");
$st->execute($par);
$rows = $st->fetchAll();

$users = $pdo->query('SELECT DISTINCT username FROM activity_log WHERE username IS NOT NULL ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
$moduls = $pdo->query('SELECT DISTINCT modul FROM activity_log ORDER BY modul')->fetchAll(PDO::FETCH_COLUMN);

function akt_url($p, $fUser, $fModul) { return 'aktivitas.php?' . http_build_query(['page' => $p, 'user' => $fUser, 'modul' => $fModul]); }

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Riwayat Aktivitas</h1><div class="sub">Semua perubahan data oleh akun admin, termasuk barang masuk dan keluar</div></div>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
    <div class="field" style="margin:0;min-width:180px;"><label>Akun</label><select name="user"><option value="">Semua akun</option>
      <?php foreach ($users as $x): ?><option value="<?= e($x) ?>" <?= $x === $fUser ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0;min-width:180px;"><label>Jenis</label><select name="modul"><option value="">Semua</option>
      <?php foreach ($moduls as $x): ?><option value="<?= e($x) ?>" <?= $x === $fModul ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary" type="submit">Terapkan</button>
  </form>
</div>

<div class="card" style="padding:0;overflow:auto;">
  <table>
    <thead><tr><th>Waktu</th><th>Akun</th><th>Aktivitas</th><th>Detail</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="4" style="text-align:center;padding:28px;">Belum ada aktivitas tercatat.</td></tr><?php endif; ?>
    <?php foreach ($rows as $a): [$mod, $kata, $warna] = activity_label($a); ?>
      <tr>
        <td><?= e(date('d M Y H:i', strtotime($a['created_at']))) ?></td>
        <td><b><?= e($a['username'] ?? 'Sistem') ?></b><br><span class="sub"><?= e($a['role'] ?? '') ?></span></td>
        <td><span class="tag tag-<?= ["green"=>"masuk","amber"=>"keluar","red"=>"danger","blue"=>"ok"][$warna] ?>"><?= e(ucfirst($kata)) ?> <?= e(strtolower($mod)) ?><?= $a['tipe'] ? ' · ' . e($a['tipe']) : '' ?></span></td>
        <td><?= e($a['detail'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<div style="display:flex;gap:8px;align-items:center;justify-content:center;margin:14px 0;">
  <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e(akt_url($page - 1, $fUser, $fModul)) ?>">← Sebelumnya</a><?php endif; ?>
  <span>Halaman <?= $page ?> dari <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= e(akt_url($page + 1, $fUser, $fModul)) ?>">Berikutnya →</a><?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
