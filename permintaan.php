<?php
require __DIR__ . '/includes/bootstrap.php';
require_bidang();

$u = current_user();
$uploadDir = __DIR__ . '/uploads/permintaan/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
$allowedBastTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

// ============================================================
// EDIT MODE — konstanta & helper
// ============================================================
const EDIT_WINDOW_SECONDS = 3600; // 1 jam

/**
 * Permintaan hanya boleh diedit kalau:
 * 1) status masih 'menunggu' (belum diproses admin)
 * 2) belum lewat 1 jam sejak dibuat
 */
function permintaan_bisa_diedit(array $r): bool
{
    if ($r['status'] !== 'menunggu') return false;
    $elapsed = time() - strtotime($r['created_at']);
    return $elapsed < EDIT_WINDOW_SECONDS;
}

/**
 * Token sekali-pakai untuk mencegah submit dobel (klik ganda / back-button
 * resubmit) saat edit. Disimpan di session, terikat ke id permintaan.
 */
function get_edit_token(int $permintaanId): string
{
    if (empty($_SESSION['edit_tokens'][$permintaanId])) {
        $_SESSION['edit_tokens'][$permintaanId] = bin2hex(random_bytes(16));
    }
    return $_SESSION['edit_tokens'][$permintaanId];
}

function consume_edit_token(int $permintaanId, string $tokenDikirim): bool
{
    $tokenValid = $_SESSION['edit_tokens'][$permintaanId] ?? null;
    if (!$tokenValid || !hash_equals($tokenValid, $tokenDikirim)) {
        return false;
    }
    unset($_SESSION['edit_tokens'][$permintaanId]); // langsung mati setelah dipakai
    return true;
}

// ============================================================
// POST: ajukan permintaan baru (LOGIKA ASLI, tidak diubah)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ajukan') {
    csrf_check();
    $penanggungJawab = trim($_POST['penanggung_jawab'] ?? '');
    if ($penanggungJawab === '') $penanggungJawab = $u['username'];

    $itemIds = $_POST['item_id'] ?? [];
    $jumlahs = $_POST['jumlah'] ?? [];
    $satuanPilihan = $_POST['satuan_id'] ?? [];

    $rows = [];
    foreach ($itemIds as $i => $itemId) {
        $itemId = (int)$itemId;
        $jmlInput = max(0, (int)($jumlahs[$i] ?? 0));
        $satPilih = $satuanPilihan[$i] ?? 'base';
        if ($itemId > 0 && $jmlInput > 0) $rows[] = ['item_id' => $itemId, 'jumlah_input' => $jmlInput, 'satuan_id' => $satPilih];
    }

    if (!$rows) {
        flash_set('Pilih minimal satu barang dengan jumlah lebih dari 0.', 'error');
        redirect('permintaan.php');
    }

    $bastError = null;
    $hasBastUpload = !empty($_FILES['bast_file']) && $_FILES['bast_file']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasBastUpload) {
        $f = $_FILES['bast_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $bastError = 'Gagal mengunggah berkas BAST. Coba lagi.';
        } elseif (!in_array($f['type'], $allowedBastTypes, true)) {
            $bastError = 'Format berkas BAST harus PDF, JPG, PNG, atau WebP.';
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $bastError = 'Ukuran berkas BAST maksimal 5 MB.';
        }
    }
    if ($bastError) {
        flash_set($bastError, 'error');
        redirect('permintaan.php');
    }

    $pdo->beginTransaction();
    try {
        $stmtLock = $pdo->prepare('SELECT * FROM items WHERE id = ? FOR UPDATE');
        $stmtSat = $pdo->prepare('SELECT * FROM item_satuan WHERE id = ? AND item_id = ?');

        $itemsCache = [];
        $perItemNeed = [];
        $preparedRows = [];

        foreach ($rows as $r) {
            $itemId = $r['item_id'];
            if (!isset($itemsCache[$itemId])) {
                $stmtLock->execute([$itemId]);
                $it = $stmtLock->fetch();
                if (!$it) { continue; }
                $itemsCache[$itemId] = $it;
            }
            $item = $itemsCache[$itemId];

            $faktor = 1;
            $satuanNama = $item['satuan'];
            if ($r['satuan_id'] !== 'base') {
                $stmtSat->execute([(int)$r['satuan_id'], $itemId]);
                $sat = $stmtSat->fetch();
                if ($sat) {
                    $faktor = (int)$sat['isi'];
                    $satuanNama = $sat['nama_satuan'];
                }
            }

            $jumlahBase = $r['jumlah_input'] * $faktor;
            $keteranganSatuan = $faktor > 1
                ? ($r['jumlah_input'] . ' ' . $satuanNama . ' (= ' . $jumlahBase . ' ' . $item['satuan'] . ')')
                : null;

            $perItemNeed[$itemId] = ($perItemNeed[$itemId] ?? 0) + $jumlahBase;
            $preparedRows[] = [
                'item_id' => $itemId,
                'nama_barang' => $item['nama'],
                'jumlah_base' => $jumlahBase,
                'keterangan_satuan' => $keteranganSatuan,
            ];
        }

        $insufficient = [];
        foreach ($perItemNeed as $itemId => $need) {
            $it = $itemsCache[$itemId];
            if ($need > (int)$it['stok']) {
                $insufficient[] = $it['nama'] . ' (diminta ' . $need . ' ' . $it['satuan'] . ', tersedia ' . $it['stok'] . ' ' . $it['satuan'] . ')';
            }
        }

        if ($insufficient) {
            $pdo->rollBack();
            flash_set('Stok tidak mencukupi untuk: ' . implode(', ', $insufficient) . '. Kurangi jumlah atau tunggu stok bertambah.', 'error');
            redirect('permintaan.php');
        }

        $pdo->prepare('INSERT INTO permintaan (user_id, bidang, penanggung_jawab, status, tanggal) VALUES (?,?,?,?,?)')
            ->execute([$u['id'], $u['bidang_nama'] ?? '-', $penanggungJawab, 'menunggu', date('Y-m-d')]);
        $pid = $pdo->lastInsertId();

        $insItem = $pdo->prepare('INSERT INTO permintaan_items (permintaan_id, item_id, nama_barang, jumlah, keterangan_satuan) VALUES (?,?,?,?,?)');
        foreach ($preparedRows as $pr) {
            $insItem->execute([$pid, $pr['item_id'], $pr['nama_barang'], $pr['jumlah_base'], $pr['keterangan_satuan']]);
        }
        foreach ($perItemNeed as $itemId => $need) {
            $pdo->prepare('UPDATE items SET stok = stok - ? WHERE id = ?')->execute([$need, $itemId]);
        }

        if ($hasBastUpload) {
            $ext = pathinfo($_FILES['bast_file']['name'], PATHINFO_EXTENSION);
            $safeName = 'permintaan-' . $pid . '-' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            if (move_uploaded_file($_FILES['bast_file']['tmp_name'], $uploadDir . $safeName)) {
                $pdo->prepare('UPDATE permintaan SET bast_file = ? WHERE id = ?')->execute([$safeName, $pid]);
            }
        }

        $pdo->commit();
        log_activity($pdo, 'permintaan', 'tambah', 'Permintaan #' . $pid . ' dari ' . ($u['bidang_nama'] ?? '-') . ': ' . implode(', ', array_map(function ($r) { return $r['nama_barang'] . ' x' . $r['jumlah_base']; }, array_slice($preparedRows, 0, 4))) . (count($preparedRows) > 4 ? ' +' . (count($preparedRows) - 4) . ' lainnya' : ''), 'keluar', (int)array_sum(array_column($preparedRows, 'jumlah_base')));
        flash_set('Permintaan ATK berhasil diajukan, stok otomatis dikurangi. Pantau progresnya di menu Progres ATK.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Gagal menyimpan permintaan.', 'error');
    }
    redirect('permintaan.php');
}

// ============================================================
// POST: simpan hasil edit permintaan (BARU)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'edit') {
    csrf_check();

    $editId = (int)($_POST['edit_id'] ?? 0);
    $editTokenDikirim = $_POST['edit_token'] ?? '';

    if ($editId <= 0 || !consume_edit_token($editId, $editTokenDikirim)) {
        flash_set('Perubahan ini sudah tersimpan sebelumnya, tidak diproses ulang.', 'error');
        redirect('progres');
    }

    $penanggungJawab = trim($_POST['penanggung_jawab'] ?? '');
    if ($penanggungJawab === '') $penanggungJawab = $u['username'];

    $itemIds = $_POST['item_id'] ?? [];
    $jumlahs = $_POST['jumlah'] ?? [];
    $satuanPilihan = $_POST['satuan_id'] ?? [];

    $rows = [];
    foreach ($itemIds as $i => $itemId) {
        $itemId = (int)$itemId;
        $jmlInput = max(0, (int)($jumlahs[$i] ?? 0));
        $satPilih = $satuanPilihan[$i] ?? 'base';
        if ($itemId > 0 && $jmlInput > 0) $rows[] = ['item_id' => $itemId, 'jumlah_input' => $jmlInput, 'satuan_id' => $satPilih];
    }

    if (!$rows) {
        flash_set('Pilih minimal satu barang dengan jumlah lebih dari 0.', 'error');
        redirect('permintaan.php?edit=' . $editId);
    }

    $bastError = null;
    $hasBastUpload = !empty($_FILES['bast_file']) && $_FILES['bast_file']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasBastUpload) {
        $f = $_FILES['bast_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $bastError = 'Gagal mengunggah berkas BAST. Coba lagi.';
        } elseif (!in_array($f['type'], $allowedBastTypes, true)) {
            $bastError = 'Format berkas BAST harus PDF, JPG, PNG, atau WebP.';
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $bastError = 'Ukuran berkas BAST maksimal 5 MB.';
        }
    }
    if ($bastError) {
        flash_set($bastError, 'error');
        redirect('permintaan.php?edit=' . $editId);
    }

    $pdo->beginTransaction();
    try {
        // Kunci baris permintaan & cek ulang kepemilikan/status/batas waktu DI DALAM
        // transaction. Wajib dicek ulang di sini (bukan cuma saat form dibuka),
        // karena waktu bisa lewat 1 jam antara form dibuka dan disubmit, dan supaya
        // tidak bentrok kalau admin memproses permintaan ini di saat bersamaan.
        $stmtReq = $pdo->prepare('SELECT * FROM permintaan WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmtReq->execute([$editId, $u['id']]);
        $existingReq = $stmtReq->fetch();

        if (!$existingReq || !permintaan_bisa_diedit($existingReq)) {
            $pdo->rollBack();
            flash_set('Permintaan tidak bisa diedit lagi (sudah diproses admin atau lewat 1 jam).', 'error');
            redirect('progres');
        }

        // Item lama milik permintaan ini -> dipakai untuk menghitung selisih stok.
        $stmtOldItems = $pdo->prepare('SELECT item_id, SUM(jumlah) AS jumlah FROM permintaan_items WHERE permintaan_id = ? GROUP BY item_id');
        $stmtOldItems->execute([$editId]);
        $oldNeedByItem = [];
        foreach ($stmtOldItems->fetchAll() as $row) {
            $oldNeedByItem[(int)$row['item_id']] = (int)$row['jumlah'];
        }

        // Kunci semua baris item yang terlibat (lama maupun baru) sekaligus,
        // supaya perhitungan stok konsisten dan tidak tabrakan dengan request lain.
        $involvedItemIds = array_values(array_unique(array_merge(array_keys($oldNeedByItem), array_map(fn($r) => $r['item_id'], $rows))));
        $itemsCache = [];
        if ($involvedItemIds) {
            $in = implode(',', array_fill(0, count($involvedItemIds), '?'));
            $stmtLockAll = $pdo->prepare("SELECT * FROM items WHERE id IN ($in) FOR UPDATE");
            $stmtLockAll->execute($involvedItemIds);
            foreach ($stmtLockAll->fetchAll() as $it) $itemsCache[(int)$it['id']] = $it;
        }

        $stmtSat = $pdo->prepare('SELECT * FROM item_satuan WHERE id = ? AND item_id = ?');

        $newNeedByItem = [];
        $preparedRows = [];
        $itemTidakDitemukan = [];

        foreach ($rows as $r) {
            $itemId = $r['item_id'];
            if (!isset($itemsCache[$itemId])) {
                // DEBUG: barang yang dipilih di form tidak ada di tabel items (id sudah dihapus/berubah?)
                $itemTidakDitemukan[] = $itemId;
                continue;
            }
            $item = $itemsCache[$itemId];

            $faktor = 1;
            $satuanNama = $item['satuan'];
            if ($r['satuan_id'] !== 'base') {
                $stmtSat->execute([(int)$r['satuan_id'], $itemId]);
                $sat = $stmtSat->fetch();
                if ($sat) {
                    $faktor = (int)$sat['isi'];
                    $satuanNama = $sat['nama_satuan'];
                }
            }

            $jumlahBase = $r['jumlah_input'] * $faktor;
            $keteranganSatuan = $faktor > 1
                ? ($r['jumlah_input'] . ' ' . $satuanNama . ' (= ' . $jumlahBase . ' ' . $item['satuan'] . ')')
                : null;

            $newNeedByItem[$itemId] = ($newNeedByItem[$itemId] ?? 0) + $jumlahBase;
            $preparedRows[] = [
                'item_id' => $itemId,
                'nama_barang' => $item['nama'],
                'jumlah_base' => $jumlahBase,
                'keterangan_satuan' => $keteranganSatuan,
            ];
        }

        if ($itemTidakDitemukan) {
            error_log('[EDIT PERMINTAAN] item_id tidak ditemukan di tabel items: ' . implode(',', $itemTidakDitemukan));
        }

        // Delta positif = butuh stok tambahan; delta negatif = stok dikembalikan.
        $delta = [];
        foreach ($involvedItemIds as $itemId) {
            $old = $oldNeedByItem[$itemId] ?? 0;
            $new = $newNeedByItem[$itemId] ?? 0;
            if ($new !== $old) $delta[$itemId] = $new - $old;
        }

        $insufficient = [];
        foreach ($delta as $itemId => $d) {
            if ($d > 0 && isset($itemsCache[$itemId]) && $d > (int)$itemsCache[$itemId]['stok']) {
                $it = $itemsCache[$itemId];
                $insufficient[] = $it['nama'] . ' (butuh tambahan ' . $d . ' ' . $it['satuan'] . ', tersedia ' . $it['stok'] . ' ' . $it['satuan'] . ')';
            }
        }

        if ($insufficient) {
            $pdo->rollBack();
            flash_set('Stok tidak mencukupi untuk: ' . implode(', ', $insufficient) . '. Kurangi jumlah atau tunggu stok bertambah.', 'error');
            redirect('permintaan.php?edit=' . $editId);
        }

        if (!$preparedRows) {
            $pdo->rollBack();
            flash_set('Minimal harus ada 1 barang dengan jumlah lebih dari 0.', 'error');
            redirect('permintaan.php?edit=' . $editId);
        }

        foreach ($delta as $itemId => $d) {
            if ($d !== 0) {
                $pdo->prepare('UPDATE items SET stok = stok - ? WHERE id = ?')->execute([$d, $itemId]);
            }
        }

        $pdo->prepare('DELETE FROM permintaan_items WHERE permintaan_id = ?')->execute([$editId]);
        $insItem = $pdo->prepare('INSERT INTO permintaan_items (permintaan_id, item_id, nama_barang, jumlah, keterangan_satuan) VALUES (?,?,?,?,?)');
        foreach ($preparedRows as $pr) {
            $insItem->execute([$editId, $pr['item_id'], $pr['nama_barang'], $pr['jumlah_base'], $pr['keterangan_satuan']]);
        }

        $pdo->prepare('UPDATE permintaan SET penanggung_jawab = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$penanggungJawab, $editId]);

        if ($hasBastUpload) {
            $ext = pathinfo($_FILES['bast_file']['name'], PATHINFO_EXTENSION);
            $safeName = 'permintaan-' . $editId . '-' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            if (move_uploaded_file($_FILES['bast_file']['tmp_name'], $uploadDir . $safeName)) {
                $oldFile = $existingReq['bast_file'] ?? null;
                $pdo->prepare('UPDATE permintaan SET bast_file = ? WHERE id = ?')->execute([$safeName, $editId]);
                if ($oldFile && is_file($uploadDir . $oldFile)) {
                    @unlink($uploadDir . $oldFile); // berkas lama dihapus, gagal hapus tidak menggagalkan proses
                }
            }
        }

        $pdo->commit();
        log_activity($pdo, 'permintaan', 'ubah', 'Permintaan #' . $editId);
        flash_set('Perubahan permintaan berhasil disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // DEBUG SEMENTARA: catat pesan error asli ke error log PHP/Apache, dan
        // tampilkan juga di flash message supaya kita bisa lihat penyebab
        // pastinya. Setelah bug ketemu, kembalikan flash_set ke pesan generik
        // ('Gagal menyimpan perubahan.') agar tidak membocorkan detail
        // internal database ke user biasa.
        error_log('[EDIT PERMINTAAN] ' . $e->getMessage());
        flash_set('Gagal menyimpan perubahan. [DEBUG] ' . $e->getMessage(), 'error');
        redirect('permintaan.php?edit=' . $editId);
    }
    redirect('progres');
}

// ============================================================
// Muat data untuk tampilan form (dipakai mode baru maupun edit)
// ============================================================
$items = $pdo->query('SELECT id, kode, nama, satuan, stok FROM items ORDER BY nama')->fetchAll();
$allSatuan = $pdo->query('SELECT * FROM item_satuan ORDER BY item_id, isi')->fetchAll();
$satuanByItem = [];
foreach ($allSatuan as $s) $satuanByItem[$s['item_id']][] = $s;

// ============================================================
// EDIT MODE — muat permintaan yang mau diedit (?edit=ID)
// ============================================================
$editingRequest = null;
$editItemsPreset = [];
$editSisaMenit = 0;

$editIdParam = (int)($_GET['edit'] ?? 0);
if ($editIdParam > 0) {
    $stmt = $pdo->prepare('SELECT * FROM permintaan WHERE id = ? AND user_id = ?');
    $stmt->execute([$editIdParam, $u['id']]);
    $found = $stmt->fetch();

    if (!$found) {
        flash_set('Permintaan tidak ditemukan.', 'error');
        redirect('progres');
    }
    if (!permintaan_bisa_diedit($found)) {
        $alasan = $found['status'] !== 'menunggu'
            ? 'Permintaan sudah diproses admin sehingga tidak bisa diedit.'
            : 'Batas waktu edit (1 jam) sudah habis.';
        flash_set($alasan, 'error');
        redirect('progres');
    }

    $editingRequest = $found;
    $elapsed = time() - strtotime($found['created_at']);
    $editSisaMenit = max(0, (int) ceil((EDIT_WINDOW_SECONDS - $elapsed) / 60));

    // Ambil item lama, digabung per item_id (jumlah dalam satuan dasar).
    // NB: kolom permintaan_items tidak menyimpan satuan_id, jadi saat form
    // dibuka satuan otomatis di-preset ke "satuan dasar". Kalau dulu barang
    // ini dipilih pakai satuan lain (mis. dus), user perlu pilih ulang satuannya.
    $stmt = $pdo->prepare('SELECT item_id, SUM(jumlah) AS jumlah FROM permintaan_items WHERE permintaan_id = ? GROUP BY item_id, nama_barang ORDER BY MIN(id)');
    $stmt->execute([$editIdParam]);
    foreach ($stmt->fetchAll() as $row) {
        $editItemsPreset[] = ['item_id' => (int)$row['item_id'], 'satuan_id' => 'base', 'jumlah' => (int)$row['jumlah']];
    }
}

// "Stok efektif" saat edit = stok saat ini + jumlah yang sudah dipakai permintaan
// ini sebelumnya (karena stok itu sudah kadung dikurangi saat pertama kali
// diajukan). Ini supaya slider jumlah di form tidak salah membatasi ke bawah.
$oldNeedForEffective = [];
if ($editingRequest) {
    $stmt = $pdo->prepare('SELECT item_id, SUM(jumlah) AS jumlah FROM permintaan_items WHERE permintaan_id = ? GROUP BY item_id');
    $stmt->execute([$editIdParam]);
    foreach ($stmt->fetchAll() as $row) $oldNeedForEffective[(int)$row['item_id']] = (int)$row['jumlah'];
}

require __DIR__ . '/includes/header.php';

// Kalau baru saja berhasil mengajukan permintaan BARU, bersihkan draft/keranjang tersimpan di browser.
if (!empty($flash) && $flash['type'] === 'ok' && strpos($flash['msg'], 'diajukan') !== false) {
    echo '<script>try { localStorage.removeItem(\'atk_req_cart\'); } catch(e){}</script>';
}
?>
<div class="topline">
  <?php if ($editingRequest): ?>
    <div><h1>Edit Permintaan</h1><div class="sub">Ubah permintaan ATK yang diajukan <?= e(date('d M Y H:i', strtotime($editingRequest['created_at']))) ?></div></div>
  <?php else: ?>
    <div><h1>Permintaan ATK</h1><div class="sub">Ajukan kebutuhan ATK untuk bidang <?= e($u['bidang_nama'] ?? '-') ?></div></div>
  <?php endif; ?>
</div>

<div class="card">
  <?php if ($editingRequest): ?>
    <h3>Edit permintaan #<?= (int)$editingRequest['id'] ?></h3>
    <div class="req-intro" style="background:rgba(224,178,53,.1);">
      <?= icon('bell', 16) ?>
      <span>Sisa waktu edit: <b><?= $editSisaMenit ?> menit</b> lagi, setelah itu permintaan terkunci otomatis. Stok akan disesuaikan otomatis sesuai selisih jumlah yang kamu ubah. Jika barang sebelumnya dipilih dalam satuan selain satuan dasar (mis. dus), pilih ulang satuannya di sini.</span>
    </div>
    <div style="margin:10px 0;"><a href="progres" class="btn btn-ghost" style="font-size:12px;padding:6px 10px;">← Batal, kembali ke Progres ATK</a></div>
  <?php else: ?>
    <h3>Ajukan permintaan baru</h3>
    <div class="req-intro">
      <?= icon('bell', 16) ?>
      <span>Stok akan otomatis berkurang begitu permintaan diajukan. Jika permintaan ditolak admin, stok akan dikembalikan otomatis. Barang yang dipilih dari Katalog otomatis tersimpan di sini, meski Anda pindah halaman terlebih dahulu.</span>
    </div>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="empty"><b>Belum ada barang</b>Hubungi admin gudang untuk menambahkan data barang.</div>
  <?php else: ?>
  <form method="post" id="req-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($editingRequest): ?>
      <input type="hidden" name="do" value="edit">
      <input type="hidden" name="edit_id" value="<?= (int)$editingRequest['id'] ?>">
      <input type="hidden" name="edit_token" value="<?= e(get_edit_token((int)$editingRequest['id'])) ?>">
    <?php else: ?>
      <input type="hidden" name="do" value="ajukan">
    <?php endif; ?>

    <div class="req-section-label">
      <span>Daftar barang yang diinginkan</span>
      <span class="req-count" id="req-count">1 item</span>
    </div>

    <div class="req-rows" id="req-rows"></div>
    <button type="button" class="req-add-row" id="req-add-row"><?= icon('plus', 15) ?> Tambah barang</button>

    <div class="req-bast-section">
      <label>Berkas BAST (opsional<?= $editingRequest && $editingRequest['bast_file'] ? ' — unggah untuk mengganti yang lama' : '' ?>)</label>
      <div class="req-bast-zone">
        <input type="file" name="bast_file" id="req-bast-input" accept="application/pdf,image/*" style="display:none;">
        <button type="button" class="btn btn-ghost" id="req-bast-pick-btn">Pilih Berkas</button>
        <span class="helptext" style="margin:0;">PDF, JPG, PNG, atau WebP. Maks 5 MB.</span>
      </div>
      <div id="req-bast-preview" class="req-bast-preview"></div>
    </div>

    <div class="req-footer">
      <div class="req-pj-field">
        <label>Penanggung jawab / penerima</label>
        <input name="penanggung_jawab" value="<?= e($editingRequest['penanggung_jawab'] ?? $u['username']) ?>">
      </div>
      <button class="btn btn-primary req-submit-btn" type="submit" id="req-submit-btn">
        <?= $editingRequest ? 'Simpan Perubahan' : 'Ajukan Permintaan' ?>
      </button>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
(function () {
  var CART_KEY = 'atk_req_cart';
  var isEditMode = <?= $editingRequest ? 'true' : 'false' ?>;

  var itemsData = <?= json_encode(array_map(function ($it) use ($oldNeedForEffective) {
      $effectiveStok = (int)$it['stok'] + ($oldNeedForEffective[$it['id']] ?? 0);
      return ['id' => $it['id'], 'nama' => $it['nama'], 'kode' => $it['kode'], 'satuan' => $it['satuan'], 'stok' => $effectiveStok];
  }, $items)) ?>;
  var satuanByItem = <?= json_encode($satuanByItem) ?>;
  var editPreset = <?= json_encode($editItemsPreset) ?>;

  var rowsWrap = document.getElementById('req-rows');
  var addBtn = document.getElementById('req-add-row');
  var countBadge = document.getElementById('req-count');
  if (!rowsWrap) return;

  function readCart() {
    if (isEditMode) return []; // mode edit tidak pakai draft localStorage
    try { return JSON.parse(localStorage.getItem(CART_KEY) || '[]'); } catch (e) { return []; }
  }
  function writeCart(cart) {
    if (isEditMode) return; // jangan campur draft "ajukan baru" dengan sesi edit
    try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) {}
  }

  function updateCount() {
    var n = rowsWrap.children.length;
    countBadge.textContent = n + ' item';
    Array.prototype.forEach.call(rowsWrap.children, function (row, idx) {
      var num = row.querySelector('.req-row-num');
      if (num) num.textContent = idx + 1;
    });
  }

  function persistState() {
    if (isEditMode) return;
    var state = [];
    Array.prototype.forEach.call(rowsWrap.children, function (row) {
      var hid = row.querySelector('input[name="item_id[]"]');
      var usel = row.querySelector('select[name="satuan_id[]"]');
      var qin = row.querySelector('input[name="jumlah[]"]');
      if (hid && hid.value) {
        state.push({ item_id: hid.value, satuan_id: usel ? usel.value : 'base', jumlah: qin ? qin.value : 1 });
      }
    });
    writeCart(state);
  }

  var addRow = function (preset) {
    var row = document.createElement('div');
    row.className = 'req-row';

    var top = document.createElement('div');
    top.className = 'req-row-top';

    var numBadge = document.createElement('div');
    numBadge.className = 'req-row-num';
    numBadge.textContent = rowsWrap.children.length + 1;

    var searchCol = document.createElement('div');
    searchCol.className = 'req-row-search-col';

    var acWrap = document.createElement('div');
    acWrap.className = 'req-ac-wrap';

    var hiddenItemId = document.createElement('input');
    hiddenItemId.type = 'hidden';
    hiddenItemId.name = 'item_id[]';

    var searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'req-ac-input';
    searchInput.placeholder = 'Ketik nama atau kode barang…';
    searchInput.autocomplete = 'off';

    var listBox = document.createElement('div');
    listBox.className = 'req-ac-list';

    acWrap.appendChild(searchInput);
    acWrap.appendChild(hiddenItemId);
    acWrap.appendChild(listBox);
    searchCol.appendChild(acWrap);

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'req-row-remove';
    removeBtn.innerHTML = '✕';
    removeBtn.addEventListener('click', function () {
      if (rowsWrap.children.length > 1) {
        row.remove();
        updateCount();
        persistState();
      }
    });

    top.appendChild(numBadge);
    top.appendChild(searchCol);
    top.appendChild(removeBtn);

    var bottom = document.createElement('div');
    bottom.className = 'req-row-bottom';

    var stokCol = document.createElement('div');
    stokCol.className = 'req-row-stok-col';
    var stokLabel = document.createElement('div');
    stokLabel.className = 'req-row-field-label';
    stokLabel.textContent = 'Ketersediaan';
    var stokInfo = document.createElement('div');
    stokInfo.className = 'req-row-stok';
    stokInfo.innerHTML = '<span class="req-row-stok-dot"></span><span class="req-row-stok-text">Tersedia</span><span class="req-row-stok-num">—</span>';
    stokCol.appendChild(stokLabel);
    stokCol.appendChild(stokInfo);

    var unitCol = document.createElement('div');
    unitCol.className = 'req-unit-col';
    var unitLabel = document.createElement('div');
    unitLabel.className = 'req-row-field-label';
    unitLabel.textContent = 'Satuan';
    var unitSelect = document.createElement('select');
    unitSelect.name = 'satuan_id[]';
    unitSelect.disabled = true;
    var unitConvert = document.createElement('div');
    unitConvert.className = 'req-unit-convert';
    unitCol.appendChild(unitLabel);
    unitCol.appendChild(unitSelect);
    unitCol.appendChild(unitConvert);

    var qtyCol = document.createElement('div');
    qtyCol.className = 'req-qty-col';
    var qtyLabel = document.createElement('div');
    qtyLabel.className = 'req-row-field-label';
    qtyLabel.textContent = 'Jumlah';
    var qty = document.createElement('input');
    qty.type = 'number';
    qty.name = 'jumlah[]';
    qty.min = '1';
    qty.value = '1';
    qty.disabled = true;
    qtyCol.appendChild(qtyLabel);
    qtyCol.appendChild(qty);

    bottom.appendChild(stokCol);
    bottom.appendChild(unitCol);
    bottom.appendChild(qtyCol);

    row.appendChild(top);
    row.appendChild(bottom);
    rowsWrap.appendChild(row);

    var selectedItem = null;

    function renderSuggestions(query) {
      listBox.innerHTML = '';
      var q = query.trim().toLowerCase();
      var matches = itemsData.filter(function (it) {
        return q === '' || it.nama.toLowerCase().indexOf(q) !== -1 || it.kode.toLowerCase().indexOf(q) !== -1;
      }).slice(0, 30);

      if (!matches.length) {
        var empty = document.createElement('div');
        empty.className = 'req-ac-empty';
        empty.textContent = 'Barang tidak ditemukan.';
        listBox.appendChild(empty);
      } else {
        matches.forEach(function (it) {
          var opt = document.createElement('div');
          opt.className = 'req-ac-item';
          if (it.stok <= 0) opt.classList.add('is-empty');
          var t = document.createElement('div');
          t.className = 'req-ac-item-name';
          t.textContent = it.nama;
          var s = document.createElement('div');
          s.className = 'req-ac-item-sub';
          s.textContent = it.kode + ' · stok ' + it.stok + ' ' + it.satuan + (it.stok <= 0 ? ' (habis)' : '');
          opt.appendChild(t);
          opt.appendChild(s);
          opt.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            selectItem(it);
          });
          listBox.appendChild(opt);
        });
      }
      listBox.classList.add('show');
    }

    function selectItem(it) {
      selectedItem = it;
      hiddenItemId.value = it.id;
      searchInput.value = it.nama + ' (' + it.kode + ')';
      listBox.classList.remove('show');
      unitSelect.disabled = false;
      qty.disabled = false;
      populateUnits();
    }

    searchInput.addEventListener('focus', function () {
      renderSuggestions(searchInput.value.indexOf('(') !== -1 ? '' : searchInput.value);
    });
    searchInput.addEventListener('input', function () {
      if (selectedItem && searchInput.value !== (selectedItem.nama + ' (' + selectedItem.kode + ')')) {
        selectedItem = null;
        hiddenItemId.value = '';
        unitSelect.disabled = true;
        qty.disabled = true;
        unitSelect.innerHTML = '';
        unitConvert.textContent = '';
        stokInfo.classList.remove('low', 'empty');
        stokInfo.querySelector('.req-row-stok-num').textContent = '—';
        persistState();
      }
      renderSuggestions(searchInput.value);
    });
    document.addEventListener('click', function (ev) {
      if (!acWrap.contains(ev.target)) listBox.classList.remove('show');
    });

    function populateUnits(presetSatuanId, presetJumlah) {
      if (!selectedItem) return;
      var baseSatuan = selectedItem.satuan;

      unitSelect.innerHTML = '';
      var baseOpt = document.createElement('option');
      baseOpt.value = 'base';
      baseOpt.textContent = baseSatuan + ' (satuan dasar)';
      baseOpt.dataset.faktor = 1;
      unitSelect.appendChild(baseOpt);

      var alt = satuanByItem[selectedItem.id] || [];
      alt.forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.nama_satuan + ' (1 ' + s.nama_satuan + ' = ' + s.isi + ' ' + baseSatuan + ')';
        o.dataset.faktor = s.isi;
        unitSelect.appendChild(o);
      });

      if (presetSatuanId) unitSelect.value = presetSatuanId;
      if (presetJumlah) qty.value = presetJumlah;

      updateStokAndConvert();
    }

    function updateStokAndConvert() {
      if (!selectedItem) return;
      var stok = selectedItem.stok;
      var baseSatuan = selectedItem.satuan;

      stokInfo.classList.remove('low', 'empty');
      if (stok <= 0) stokInfo.classList.add('empty');
      else if (stok <= 5) stokInfo.classList.add('low');
      stokInfo.querySelector('.req-row-stok-num').textContent = stok + ' ' + baseSatuan;

      var unitOpt = unitSelect.options[unitSelect.selectedIndex];
      var faktor = unitOpt ? parseInt(unitOpt.dataset.faktor, 10) || 1 : 1;
      var jml = parseInt(qty.value, 10) || 0;

      unitConvert.textContent = faktor > 1 ? ('= ' + (jml * faktor) + ' ' + baseSatuan) : '';

      var maxByUnit = faktor > 0 ? Math.floor(stok / faktor) : stok;
      qty.max = maxByUnit > 0 ? maxByUnit : 1;
      if (jml > maxByUnit && maxByUnit > 0) qty.value = maxByUnit;
    }

    unitSelect.addEventListener('change', function () { updateStokAndConvert(); persistState(); });
    qty.addEventListener('input', function () { updateStokAndConvert(); persistState(); });

    updateCount();

    // Kalau baris ini dibuat dari data tersimpan (keranjang / draft / preset edit), otomatis pilih barangnya.
    if (preset && preset.item_id) {
      var found = itemsData.find(function (it) { return String(it.id) === String(preset.item_id); });
      if (found) {
        selectedItem = found;
        hiddenItemId.value = found.id;
        searchInput.value = found.nama + ' (' + found.kode + ')';
        unitSelect.disabled = false;
        qty.disabled = false;
        populateUnits(preset.satuan_id, preset.jumlah);
      }
    }
  };

  addBtn.addEventListener('click', function () {
    addRow();
    persistState();
  });

  // ---------- Muat baris awal ----------
  if (isEditMode) {
    // Mode edit: isi form dari data permintaan yang sedang diedit, bukan dari draft/localStorage.
    if (editPreset.length) {
      editPreset.forEach(function (c) { addRow(c); });
    } else {
      addRow();
    }
  } else {
    var cart = readCart();
    var urlParams = new URLSearchParams(window.location.search);
    var urlItemId = urlParams.get('item_id');
    if (urlItemId && !cart.find(function (c) { return String(c.item_id) === String(urlItemId); })) {
      cart.push({ item_id: urlItemId, satuan_id: 'base', jumlah: 1 });
    }
    if (cart.length) {
      cart.forEach(function (c) { addRow(c); });
    } else {
      addRow();
    }
  }

  // ---------- Validasi & anti-dobel klik sebelum submit ----------
  var reqForm = document.getElementById('req-form');
  var submitBtn = document.getElementById('req-submit-btn');
  if (reqForm) {
    reqForm.addEventListener('submit', function (ev) {
      var hiddenIds = rowsWrap.querySelectorAll('input[name="item_id[]"]');
      var allFilled = true;
      hiddenIds.forEach(function (h) { if (!h.value) allFilled = false; });
      if (!allFilled) {
        ev.preventDefault();
        alert('Ada baris barang yang belum dipilih. Klik kotak pencarian dan pilih barangnya dari daftar.');
        return;
      }
      // Proteksi UX: cegah klik ganda tombol simpan. Proteksi utamanya tetap
      // di server lewat edit_token (mode edit) / transaction lock stok.
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = isEditMode ? 'Menyimpan...' : 'Mengajukan...';
      }
    });
  }

  // ---------- Preview berkas BAST sebelum kirim ----------
  var bastPickBtn = document.getElementById('req-bast-pick-btn');
  var bastInput = document.getElementById('req-bast-input');
  var bastPreview = document.getElementById('req-bast-preview');
  var allowedBastTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

  if (bastPickBtn && bastInput) {
    bastPickBtn.addEventListener('click', function () { bastInput.click(); });

    bastInput.addEventListener('change', function () {
      var file = bastInput.files[0];
      bastPreview.innerHTML = '';
      if (!file) { bastPreview.style.display = 'none'; return; }

      if (allowedBastTypes.indexOf(file.type) === -1) {
        alert('Format tidak didukung. Gunakan PDF, JPG, PNG, atau WebP.');
        bastInput.value = '';
        bastPreview.style.display = 'none';
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        alert('Ukuran berkas maksimal 5 MB.');
        bastInput.value = '';
        bastPreview.style.display = 'none';
        return;
      }

      var objectUrl = URL.createObjectURL(file);
      var isImage = file.type.indexOf('image/') === 0;

      var card = document.createElement('div');
      card.className = 'req-bast-preview-card';

      var thumb = document.createElement('div');
      thumb.className = 'req-bast-thumb';
      if (isImage) {
        var img = document.createElement('img');
        img.src = objectUrl;
        thumb.appendChild(img);
      } else {
        thumb.classList.add('pdf');
        thumb.textContent = 'PDF';
      }

      var info = document.createElement('div');
      info.className = 'req-bast-info';
      var name = document.createElement('div');
      name.className = 'req-bast-name';
      name.textContent = file.name;
      var size = document.createElement('div');
      size.className = 'req-bast-size';
      size.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
      info.appendChild(name);
      info.appendChild(size);

      var viewBtn = document.createElement('button');
      viewBtn.type = 'button';
      viewBtn.className = 'btn btn-ghost';
      viewBtn.style.fontSize = '12px';
      viewBtn.style.padding = '6px 10px';
      viewBtn.textContent = 'Lihat';
      viewBtn.addEventListener('click', function () {
        if (typeof openDocViewer === 'function') {
          openDocViewer({ url: objectUrl, downloadUrl: objectUrl, filename: file.name, isImage: isImage });
        }
      });

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'req-bast-remove';
      removeBtn.textContent = '✕';
      removeBtn.addEventListener('click', function () {
        bastInput.value = '';
        bastPreview.style.display = 'none';
        bastPreview.innerHTML = '';
      });

      card.appendChild(thumb);
      card.appendChild(info);
      card.appendChild(viewBtn);
      card.appendChild(removeBtn);
      bastPreview.appendChild(card);
      bastPreview.style.display = 'block';
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>