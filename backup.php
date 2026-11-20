<?php
require __DIR__ . '/includes/bootstrap.php';
require_super();

$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

function bk_tables($pdo, $isSqlite) {
    if ($isSqlite) return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}
function bk_q($pdo, $v) { return $v === null ? 'NULL' : $pdo->quote((string)$v); }

// ---------- Unduh ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    if ($do === 'sql') {
        $tables = bk_tables($pdo, $isSqlite);
        $nama = 'backup-atk-' . date('Ymd-His') . '.sql';
        log_activity($pdo, 'backup', 'unduh', 'Backup database (' . count($tables) . ' tabel)');
        session_write_close();
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nama . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo "-- Backup database ATK\n-- Dibuat: " . date('Y-m-d H:i:s') . "\n-- Pulihkan lewat phpMyAdmin > Import\n\n";
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($tables as $t) {
            $tq = '`' . str_replace('`', '``', $t) . '`';
            if ($isSqlite) {
                $create = $pdo->query("SELECT sql FROM sqlite_master WHERE name=" . $pdo->quote($t))->fetchColumn();
            } else {
                $row = $pdo->query('SHOW CREATE TABLE ' . $tq)->fetch(PDO::FETCH_NUM);
                $create = $row[1] ?? '';
            }
            echo "-- Tabel $t\nDROP TABLE IF EXISTS $tq;\n$create;\n\n";
            $st = $pdo->query('SELECT * FROM ' . $tq);
            $batch = [];
            $cols = null;
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) $cols = '(' . implode(',', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($r))) . ')';
                $batch[] = '(' . implode(',', array_map(fn($v) => bk_q($pdo, $v), $r)) . ')';
                if (count($batch) >= 200) { echo "INSERT INTO $tq $cols VALUES\n" . implode(",\n", $batch) . ";\n"; $batch = []; }
            }
            if ($batch) echo "INSERT INTO $tq $cols VALUES\n" . implode(",\n", $batch) . ";\n";
            echo "\n";
        }
        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        exit;
    }

    if ($do === 'zip' && class_exists('ZipArchive')) {
        $base = realpath(__DIR__ . '/uploads');
        $tmp = tempnam(sys_get_temp_dir(), 'bk');
        $zip = new ZipArchive();
        if (!$base || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) { flash_set('Gagal membuat ZIP.', 'error'); redirect('backup.php'); }
        $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) continue;
            $rel = substr($f->getPathname(), strlen($base) + 1);
            if (strpos($rel, 'barcode-cache') === 0 || basename($rel) === '.htaccess') continue; // cache & aturan server tidak perlu
            $zip->addFile($f->getPathname(), 'uploads/' . str_replace('\\', '/', $rel));
            $n++;
        }
        if ($n === 0) { $zip->close(); @unlink($tmp); flash_set('Belum ada foto atau berkas untuk dibackup.', 'error'); redirect('backup.php'); }
        $zip->close();
        log_activity($pdo, 'backup', 'unduh', 'Backup foto & berkas (' . $n . ' file)');
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="backup-berkas-atk-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        header('X-Content-Type-Options: nosniff');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    redirect('backup.php');
}

// ---------- Tampilan ----------
$info = [];
$totalBaris = 0;
foreach (bk_tables($pdo, $isSqlite) as $t) {
    try { $c = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn(); } catch (Throwable $e) { $c = 0; }
    $info[] = [$t, $c]; $totalBaris += $c;
}
$jmlFile = 0; $ukuran = 0;
if (is_dir(__DIR__ . '/uploads')) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/uploads', FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && strpos($f->getPathname(), 'barcode-cache') === false && $f->getFilename() !== '.htaccess') { $jmlFile++; $ukuran += $f->getSize(); }
    }
}
$flash = flash_get();
require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Backup Database</h1><div class="sub">Simpan salinan data dan foto supaya aman kalau hosting bermasalah</div></div>
</div>
<?php if (!empty($flash)): ?><div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="bk-grid">
  <div class="bk-card">
    <h3><?= icon('archive', 18) ?> Data (database)</h3>
    <p>File <b>.sql</b> berisi semua tabel: barang, transaksi, permintaan, penitipan, akun, dan lainnya. <?= count($info) ?> tabel, <?= number_format($totalBaris, 0, ',', '.') ?> baris.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="sql">
      <button class="btn btn-primary" type="submit">Unduh backup data (.sql)</button>
    </form>
  </div>
  <div class="bk-card">
    <h3><?= icon('box', 18) ?> Foto &amp; berkas</h3>
    <?php if (class_exists('ZipArchive')): ?>
      <p>Foto barang, foto titipan, dan berkas BAST: <?= (int)$jmlFile ?> file, sekitar <?= number_format($ukuran / 1048576, 1, ',', '.') ?> MB.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="zip">
        <button class="btn btn-primary" type="submit">Unduh foto &amp; berkas (.zip)</button>
      </form>
    <?php else: ?>
      <p>Fitur ZIP belum aktif di server ini. Salin folder <b>uploads</b> lewat File Manager atau FTP.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Cara memulihkan</h3>
  <p style="font-size:13px;color:var(--text-dim);margin:0;">Buka phpMyAdmin, pilih database, klik <b>Import</b>, pilih file .sql, lalu jalankan. Untuk foto, ekstrak ZIP ke folder aplikasi supaya folder <b>uploads</b> terisi lagi. Simpan backup di tempat lain (flashdisk atau Google Drive), jangan hanya di server yang sama. Disarankan backup sebelum setiap update aplikasi.</p>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Isi database</h3>
  <table class="bk-tbl"><thead><tr><th>Tabel</th><th>Jumlah baris</th></tr></thead><tbody>
    <?php foreach ($info as [$t, $c]): ?><tr><td><?= e($t) ?></td><td><?= number_format($c, 0, ',', '.') ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
