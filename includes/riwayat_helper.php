<?php
/**
 * Riwayat barang yang sudah diambil bidang.
 * Gabungan dua sumber (tanpa dobel, karena keduanya tidak saling mencatat):
 *   - permintaan bidang yang statusnya "selesai"  -> key "p:<id permintaan_items>"
 *   - barang keluar yang dicatat admin langsung    -> key "t:<id transactions>"
 *
 * $keys (opsional): daftar key di atas; kalau diisi, hanya baris itu yang diambil.
 */
function riwayat_bidang(PDO $pdo, ?string $bidang = null, ?string $dari = null, ?string $sampai = null, ?array $keys = null): array
{
    $bidang = trim((string)$bidang);
    $dari = tgl_valid($dari);
    $sampai = tgl_valid($sampai);

    $idsP = $idsT = [];
    if ($keys !== null) {
        foreach ($keys as $k) {
            if (preg_match('/^([pt]):(\d+)$/', (string)$k, $m)) {
                if ($m[1] === 'p') $idsP[] = (int)$m[2]; else $idsT[] = (int)$m[2];
            }
        }
    }

    $rows = [];

    // --- 1) Permintaan bidang yang sudah selesai ---
    if ($keys === null || $idsP) {
        $w = ["p.status = 'selesai'"];
        $a = [];
        if ($bidang !== '') { $w[] = 'LOWER(TRIM(p.bidang)) = LOWER(TRIM(?))'; $a[] = $bidang; }
        if ($dari !== '')   { $w[] = 'p.tanggal >= ?'; $a[] = $dari; }
        if ($sampai !== '') { $w[] = 'p.tanggal <= ?'; $a[] = $sampai; }
        if ($keys !== null) {
            $w[] = 'pi.id IN (' . implode(',', array_fill(0, count($idsP), '?')) . ')';
            $a = array_merge($a, $idsP);
        }
        $st = $pdo->prepare("
            SELECT pi.id, p.tanggal, p.bidang, p.penanggung_jawab AS pj,
                   pi.nama_barang AS nama, pi.jumlah, COALESCE(i.satuan, 'pcs') AS satuan
            FROM permintaan_items pi
            JOIN permintaan p ON p.id = pi.permintaan_id
            LEFT JOIN items i ON i.id = pi.item_id
            WHERE " . implode(' AND ', $w));
        $st->execute($a);
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'key' => 'p:' . $r['id'], 'tanggal' => $r['tanggal'], 'bidang' => $r['bidang'],
                'pj' => (string)$r['pj'], 'nama' => $r['nama'], 'jumlah' => (int)$r['jumlah'],
                'satuan' => $r['satuan'], 'sumber' => 'Permintaan bidang',
                'jenis' => 'permintaan', 'orang' => trim((string)$r['pj']),
            ];
        }
    }

    // --- 2) Barang keluar yang dicatat admin langsung ---
    if ($keys === null || $idsT) {
        $w = ["t.tipe = 'keluar'", "t.bidang IS NOT NULL", "TRIM(t.bidang) <> ''"];
        $a = [];
        if ($bidang !== '') { $w[] = 'LOWER(TRIM(t.bidang)) = LOWER(TRIM(?))'; $a[] = $bidang; }
        if ($dari !== '')   { $w[] = 't.tanggal >= ?'; $a[] = $dari; }
        if ($sampai !== '') { $w[] = 't.tanggal <= ?'; $a[] = $sampai; }
        if ($keys !== null) {
            $w[] = 't.id IN (' . implode(',', array_fill(0, count($idsT), '?')) . ')';
            $a = array_merge($a, $idsT);
        }
        $st = $pdo->prepare("
            SELECT t.id, t.tanggal, t.bidang, t.jumlah, t.penerima,
                   COALESCE(i.nama, '(barang dihapus)') AS nama, COALESCE(i.satuan, 'pcs') AS satuan
            FROM transactions t
            LEFT JOIN items i ON i.id = t.item_id
            WHERE " . implode(' AND ', $w));
        $st->execute($a);
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'key' => 't:' . $r['id'], 'tanggal' => $r['tanggal'], 'bidang' => $r['bidang'],
                'pj' => '', 'nama' => $r['nama'], 'jumlah' => (int)$r['jumlah'],
                'satuan' => $r['satuan'], 'sumber' => 'Dicatat admin',
                'jenis' => 'langsung', 'orang' => trim((string)($r['penerima'] ?? '')),
            ];
        }
    }

    usort($rows, function ($x, $y) {
        $c = strcasecmp(trim($x['bidang']), trim($y['bidang']));
        if ($c !== 0) return $c;
        return strcmp($y['tanggal'], $x['tanggal']); // tanggal terbaru dulu
    });
    return $rows;
}

/** Daftar nama bidang yang pernah mengambil barang (untuk pilihan filter). */
function daftar_bidang_riwayat(PDO $pdo): array
{
    $out = [];
    foreach (riwayat_bidang($pdo) as $r) {
        $b = trim($r['bidang']);
        if ($b !== '' && !isset($out[mb_strtolower($b)])) $out[mb_strtolower($b)] = $b;
    }
    natcasesort($out);
    return array_values($out);
}

/** Kelompokkan baris per bidang; "Bidang Umum" dan "bidang umum" dianggap satu bidang. */
function kelompok_per_bidang(array $rows): array
{
    $out = [];
    $nama = [];
    $dariPermintaan = [];
    foreach ($rows as $r) {
        $k = mb_strtolower(trim($r['bidang']));
        // Utamakan penulisan nama dari permintaan bidang (biasanya sudah rapi).
        if (!isset($nama[$k]) || $r['sumber'] === 'Permintaan bidang' && empty($dariPermintaan[$k])) {
            $nama[$k] = trim($r['bidang']);
            if ($r['sumber'] === 'Permintaan bidang') $dariPermintaan[$k] = true;
        }
        $out[$k][] = $r;
    }
    $hasil = [];
    foreach ($out as $k => $list) $hasil[$nama[$k]] = $list;
    return $hasil;
}

/** Apakah tabel fitur "oper" sudah dibuat (migrasi_oper_bidang.sql sudah dijalankan)? */
function oper_tabel_siap(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM oper_bidang LIMIT 1');
        $pdo->query('SELECT 1 FROM oper_bidang_items LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Jumlah kiriman yang belum dibuka admin bidang (untuk lencana di menu). Aman walau tabel belum ada. */
function oper_belum_dibaca(?PDO $pdo, int $userId): int
{
    if (!$pdo || $userId <= 0) return 0;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM oper_bidang WHERE user_id = ? AND dibaca = 0');
        $st->execute([$userId]);
        return (int)$st->fetch()['c'];
    } catch (Throwable $e) {
        return 0;
    }
}

/** Semua akun admin bidang (untuk pilihan tujuan oper). */
function daftar_akun_bidang(PDO $pdo): array
{
    return $pdo->query("SELECT id, username, bidang_nama FROM users WHERE role = 'bidang' ORDER BY bidang_nama, username")->fetchAll();
}

/** Peta "key baris" => daftar akun tujuan yang sudah pernah dikirimi baris itu. */
function oper_map(PDO $pdo): array
{
    $map = [];
    try {
        $rows = $pdo->query("
            SELECT oi.sumber_key, u.username, o.bidang_nama
            FROM oper_bidang_items oi
            JOIN oper_bidang o ON o.id = oi.oper_id
            LEFT JOIN users u ON u.id = o.user_id")->fetchAll();
        foreach ($rows as $r) $map[$r['sumber_key']][] = $r['bidang_nama'] ?: ($r['username'] ?? '?');
    } catch (Throwable $e) {
    }
    return $map;
}

/** Kiriman (oper) untuk satu akun admin bidang, terbaru dulu, lengkap dengan barangnya. */
function oper_untuk_user(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT * FROM oper_bidang WHERE user_id = ? ORDER BY created_at DESC, id DESC');
    $st->execute([$userId]);
    $opers = $st->fetchAll();
    if (!$opers) return [];

    $ids = array_column($opers, 'id');
    $st = $pdo->prepare('SELECT * FROM oper_bidang_items WHERE oper_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY tanggal, id');
    $st->execute($ids);
    $items = [];
    foreach ($st->fetchAll() as $it) $items[$it['oper_id']][] = $it;
    foreach ($opers as &$o) $o['items'] = $items[$o['id']] ?? [];
    return $opers;
}

/** Baris untuk BAST milik akun bidang (key "o:<id>"); hanya milik akun itu sendiri. */
function oper_baris_untuk_bast(PDO $pdo, int $userId, array $keys): array
{
    $ids = [];
    foreach ($keys as $k) if (preg_match('/^o:(\d+)$/', (string)$k, $m)) $ids[] = (int)$m[1];
    if (!$ids) return [];
    $st = $pdo->prepare('
        SELECT oi.id, oi.tanggal, oi.nama_barang, oi.jumlah, oi.satuan, oi.penanggung_jawab, oi.sumber_key, o.bidang_nama
        FROM oper_bidang_items oi
        JOIN oper_bidang o ON o.id = oi.oper_id
        WHERE o.user_id = ? AND oi.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_merge([$userId], $ids));
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'key' => 'o:' . $r['id'], 'tanggal' => $r['tanggal'], 'bidang' => $r['bidang_nama'],
            'pj' => (string)$r['penanggung_jawab'], 'nama' => $r['nama_barang'], 'jumlah' => (int)$r['jumlah'],
            'satuan' => $r['satuan'], 'sumber' => 'Dioper admin', 'src' => $r['sumber_key'],
        ];
    }
    return $rows;
}

/**
 * Dipanggil saat admin ATK mencatat barang KELUAR baru untuk satu bidang (transaksi.php):
 * mengirim notifikasi ke akun admin bidang yang namanya sama. Kiriman yang belum dibuka
 * dari admin yang sama digabung, supaya beberapa barang sekaligus tidak jadi banyak notifikasi.
 * Mengembalikan kalimat singkat untuk ditambahkan ke pesan sukses. Tidak pernah melempar error.
 */
function kirim_notif_bidang(PDO $pdo, string $bidang, int $trxId, string $tanggal, string $nama, int $jumlah, string $satuan, ?string $penerima, array $admin): string
{
    if (!oper_tabel_siap($pdo)) {
        return ' (Notifikasi ke bidang belum aktif: jalankan db tambahan/migrasi_oper_bidang.sql.)';
    }
    try {
        $st = $pdo->prepare("SELECT id, username, bidang_nama FROM users WHERE role = 'bidang' AND LOWER(TRIM(bidang_nama)) = LOWER(TRIM(?))");
        $st->execute([$bidang]);
        $akun = $st->fetchAll();
        if (!$akun) {
            return ' (Tidak ada akun admin bidang "' . $bidang . '", jadi notifikasi tidak terkirim.)';
        }

        $catatan = 'Diambil langsung di gudang ATK';
        $cari = $pdo->prepare('SELECT id FROM oper_bidang WHERE user_id = ? AND dibaca = 0 AND catatan = ? AND dibuat_oleh = ? ORDER BY id DESC LIMIT 1');
        $insO = $pdo->prepare('INSERT INTO oper_bidang (user_id, bidang_nama, catatan, dibuat_oleh, dibuat_oleh_nama) VALUES (?,?,?,?,?)');
        $insI = $pdo->prepare('INSERT INTO oper_bidang_items (oper_id, sumber_key, tanggal, nama_barang, jumlah, satuan, penanggung_jawab) VALUES (?,?,?,?,?,?,?)');

        foreach ($akun as $a) {
            $cari->execute([$a['id'], $catatan, $admin['id']]);
            $ada = $cari->fetch();
            if ($ada) {
                $operId = (int)$ada['id'];
            } else {
                $insO->execute([$a['id'], $a['bidang_nama'] ?: $bidang, $catatan, $admin['id'], $admin['username'] ?? null]);
                $operId = (int)$pdo->lastInsertId();
            }
            $insI->execute([$operId, 't:' . $trxId, $tanggal, $nama, $jumlah, $satuan, ($penerima !== null && $penerima !== '') ? $penerima : null]);
        }
        return ' Notifikasi terkirim ke admin bidang (' . implode(', ', array_column($akun, 'username')) . ').';
    } catch (Throwable $e) {
        return ' (Notifikasi ke bidang gagal dikirim.)';
    }
}

/** Hapus kiriman (oper) yang sudah kosong barangnya. */
function oper_bersihkan_kosong(PDO $pdo): void
{
    try {
        $pdo->exec('DELETE FROM oper_bidang WHERE id NOT IN (SELECT DISTINCT oper_id FROM oper_bidang_items)');
    } catch (Throwable $e) {
    }
}

/** Hapus satu barang dari kiriman. Kiriman yang jadi kosong ikut dihapus. Stok tidak berubah. */
function oper_hapus_item(PDO $pdo, int $itemId): bool
{
    try {
        $st = $pdo->prepare('DELETE FROM oper_bidang_items WHERE id = ?');
        $st->execute([$itemId]);
        $ada = $st->rowCount() > 0;
        oper_bersihkan_kosong($pdo);
        return $ada;
    } catch (Throwable $e) {
        return false;
    }
}

/** Hapus satu kiriman beserta semua barangnya. Stok tidak berubah. */
function oper_hapus_kiriman(PDO $pdo, int $operId): bool
{
    try {
        $pdo->prepare('DELETE FROM oper_bidang_items WHERE oper_id = ?')->execute([$operId]);
        $st = $pdo->prepare('DELETE FROM oper_bidang WHERE id = ?');
        $st->execute([$operId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Hapus semua salinan kiriman untuk satu baris sumber (mis. "t:12" saat transaksi dihapus). */
function oper_hapus_by_key(PDO $pdo, string $key): void
{
    try {
        $pdo->prepare('DELETE FROM oper_bidang_items WHERE sumber_key = ?')->execute([$key]);
        oper_bersihkan_kosong($pdo);
    } catch (Throwable $e) {
    }
}

/**
 * Dipanggil saat transaksi barang keluar DIEDIT: samakan salinannya di kiriman bidang.
 * Kalau tipe/bidang berubah sehingga tidak cocok lagi dengan kiriman, salinannya dihapus.
 */
function oper_sinkron_transaksi(PDO $pdo, int $trxId, string $nama, int $jumlah, string $satuan, string $tanggal, ?string $penerima, string $tipe, string $bidang): void
{
    try {
        $key = 't:' . $trxId;
        $st = $pdo->prepare('SELECT oi.id, o.bidang_nama FROM oper_bidang_items oi JOIN oper_bidang o ON o.id = oi.oper_id WHERE oi.sumber_key = ?');
        $st->execute([$key]);
        $baris = $st->fetchAll();
        if (!$baris) return;

        $upd = $pdo->prepare('UPDATE oper_bidang_items SET nama_barang = ?, jumlah = ?, satuan = ?, tanggal = ?, penanggung_jawab = ? WHERE id = ?');
        $hapus = false;
        foreach ($baris as $b) {
            $cocok = $tipe === 'keluar' && $bidang !== '' && mb_strtolower(trim($b['bidang_nama'])) === mb_strtolower(trim($bidang));
            if ($cocok) {
                $upd->execute([$nama, $jumlah, $satuan, $tanggal, ($penerima !== null && $penerima !== '') ? $penerima : null, $b['id']]);
            } else {
                $pdo->prepare('DELETE FROM oper_bidang_items WHERE id = ?')->execute([$b['id']]);
                $hapus = true;
            }
        }
        if ($hapus) oper_bersihkan_kosong($pdo);
    } catch (Throwable $e) {
    }
}

/** Kiriman terbaru ke semua akun bidang (untuk halaman admin), lengkap dengan barangnya. */
function oper_semua(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, $limit);
    $opers = $pdo->query('SELECT o.*, u.username FROM oper_bidang o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $limit)->fetchAll();
    if (!$opers) return [];
    $ids = array_column($opers, 'id');
    $st = $pdo->prepare('SELECT * FROM oper_bidang_items WHERE oper_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY tanggal, id');
    $st->execute($ids);
    $items = [];
    foreach ($st->fetchAll() as $it) $items[$it['oper_id']][] = $it;
    foreach ($opers as &$o) $o['items'] = $items[$o['id']] ?? [];
    return $opers;
}

/** Hanya terima format YYYY-MM-DD yang benar; selain itu dianggap kosong. */
function tgl_valid($s): string
{
    $s = trim((string)$s);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : '';
}

function tgl_indo(string $ymd): string
{
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) return $ymd;
    return (int)$m[3] . ' ' . $bulan[(int)$m[2]] . ' ' . $m[1];
}

/** Skrip kecil: tombol "pilih semua" + penghitung baris yang dicentang di tiap form riwayat. */
function render_riwayat_js(): void
{
?>
<script>
document.querySelectorAll('form[action="cetak_bast.php"]').forEach(function (f) {
  var rows = f.querySelectorAll('.js-baris');
  var all = f.querySelector('.js-pilih-semua');
  var btnBast = f.querySelector('.js-buat-bast');
  var btnOper = f.querySelector('.js-oper');
  function refresh() {
    var n = f.querySelectorAll('.js-baris:checked').length;
    btnBast.textContent = 'Buat BAST (' + n + ')';
    btnBast.disabled = n === 0;
    if (btnOper) { btnOper.textContent = 'Oper ke bidang (' + n + ')'; btnOper.disabled = n === 0; }
    all.checked = n > 0 && n === rows.length;
  }
  all.addEventListener('change', function () { rows.forEach(function (c) { c.checked = all.checked; }); refresh(); });
  rows.forEach(function (c) { c.addEventListener('change', refresh); });
  refresh();
});
</script>
<?php
}

/**
 * Daftar riwayat per bidang (halaman admin): centang barang, lalu "Buat BAST" atau "Oper ke bidang".
 * $operMap  : hasil oper_map()  -> penanda "sudah dioper ke ..."
 * $akun     : hasil daftar_akun_bidang() -> pilihan akun tujuan oper (kosong = tombol oper disembunyikan)
 */
function render_riwayat_cards(array $byBidang, array $operMap = [], array $akun = []): void
{
    foreach ($byBidang as $bidang => $list):
        $formId = 'f' . substr(md5($bidang), 0, 8);
        $default = 0;
        foreach ($akun as $a) if (mb_strtolower(trim((string)$a['bidang_nama'])) === mb_strtolower($bidang)) { $default = (int)$a['id']; break; }
?>
  <div class="card">
    <form method="post" action="cetak_bast.php" target="_blank" id="<?= $formId ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="bidang" value="<?= e($bidang) ?>">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <h3 style="margin:0;"><?= e($bidang) ?> <span style="font-size:12px;font-weight:500;color:var(--text-dim);">· <?= count($list) ?> baris</span></h3>
        <label style="font-size:12px;color:var(--text-dim);cursor:pointer;">
          <input type="checkbox" class="js-pilih-semua"> Pilih semua
        </label>
      </div>
      <?php render_riwayat_kelompok($list, $operMap); ?>

      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid var(--line,#232a35);">
        <?php if ($akun): ?>
        <div class="field" style="margin:0;">
          <label style="font-size:11px;">Oper ke akun bidang</label>
          <select name="tujuan">
            <option value="">— pilih akun tujuan —</option>
            <?php foreach ($akun as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $default ? 'selected' : '' ?>><?= e(($a['bidang_nama'] ?: '-') . ' (' . $a['username'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin:0;flex:1;min-width:180px;">
          <label style="font-size:11px;">Catatan untuk bidang (opsional)</label>
          <input type="text" name="catatan" maxlength="255" placeholder="mis. ATK triwulan IV">
        </div>
        <button type="submit" class="btn btn-primary js-oper" formaction="oper_bidang.php" formtarget="_self" style="font-size:12.5px;padding:8px 14px;" disabled>Oper ke bidang (0)</button>
        <?php endif; ?>
        <button type="submit" class="btn btn-ghost js-buat-bast" style="font-size:12.5px;padding:8px 14px;" disabled>Buat BAST (0)</button>
      </div>
    </form>
  </div>
<?php
    endforeach;
    render_riwayat_js();
}

/**
 * Pisahkan riwayat satu bidang jadi dua bagian supaya tidak membingungkan:
 *  1) Permintaan lewat admin bidang (Permintaan ATK yang sudah Selesai), lengkap dengan penanggung jawabnya.
 *  2) Diambil langsung di gudang oleh orang di bidang itu, dikelompokkan per nama pengambil.
 * Semua baris tetap bisa dicentang (name="k[]", class "js-baris") untuk Buat BAST / Oper.
 */
function render_riwayat_kelompok(array $list, array $operMap = []): void
{
    static $jsSudah = false;
    $minta = [];
    $langsung = [];
    foreach ($list as $r) {
        if (($r['jenis'] ?? '') === 'permintaan') { $minta[] = $r; continue; }
        $o = trim((string)($r['orang'] ?? ''));
        $langsung[$o !== '' ? $o : '(nama pengambil tidak dicatat)'][] = $r;
    }
    $tanpaNama = '(nama pengambil tidak dicatat)';
    $adaTanpa = $langsung[$tanpaNama] ?? null;
    unset($langsung[$tanpaNama]);
    ksort($langsung, SORT_NATURAL | SORT_FLAG_CASE);
    if ($adaTanpa) $langsung[$tanpaNama] = $adaTanpa;

    $tabel = function (array $rows, bool $kolomPj) use ($operMap) {
        echo '<table><thead><tr><th style="width:34px;"></th><th>Tanggal</th><th>Barang</th><th>Jumlah</th>' . ($kolomPj ? '<th>Penanggung jawab</th>' : '') . '<th></th></tr></thead><tbody>';
        foreach ($rows as $t) {
            echo '<tr><td><input type="checkbox" name="k[]" value="' . e($t['key']) . '" class="js-baris"></td>'
               . '<td class="mono">' . e($t['tanggal']) . '</td><td>' . e($t['nama']) . '</td>'
               . '<td class="mono">' . (int)$t['jumlah'] . ' ' . e($t['satuan']) . '</td>';
            if ($kolomPj) echo '<td>' . e($t['pj'] !== '' ? $t['pj'] : '-') . '</td>';
            echo '<td style="font-size:12px;color:var(--teal-dark);">';
            if (!empty($operMap[$t['key']])) echo '✓ sudah dioper ke ' . e(implode(', ', array_unique($operMap[$t['key']])));
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    };

    if ($minta): ?>
    <div class="rk-grup js-grup rk-minta">
      <div class="rk-head">
        <div><b><?= icon('clipboard', 15) ?> Diminta lewat admin bidang</b><small>Permintaan ATK yang sudah selesai · <?= count($minta) ?> barang</small></div>
        <label class="rk-pilih"><input type="checkbox" class="js-pilih-grup"> Pilih bagian ini</label>
      </div>
      <?php $tabel($minta, true); ?>
    </div>
    <?php endif;
    if ($langsung): ?>
    <div class="rk-sec"><?= icon('user-check', 15) ?> Diambil langsung di gudang oleh orang di bidang ini</div>
    <?php foreach ($langsung as $orang => $rows): ?>
    <div class="rk-grup js-grup rk-langsung">
      <div class="rk-head">
        <div><b><span class="rk-avatar"><?= $orang[0] === '(' ? '?' : e(mb_strtoupper(mb_substr($orang, 0, 1))) ?></span> <?= e($orang) ?></b><small><?= count($rows) ?> barang</small></div>
        <label class="rk-pilih"><input type="checkbox" class="js-pilih-grup"> Pilih orang ini</label>
      </div>
      <?php $tabel($rows, false); ?>
    </div>
    <?php endforeach; endif;

    if (!$jsSudah) { $jsSudah = true; ?>
<script>
document.querySelectorAll('.js-grup').forEach(function (g) {
  var all = g.querySelector('.js-pilih-grup'), rows = g.querySelectorAll('.js-baris');
  function sync() { all.checked = rows.length > 0 && g.querySelectorAll('.js-baris:checked').length === rows.length; }
  all.addEventListener('change', function () {
    rows.forEach(function (c) { c.checked = all.checked; c.dispatchEvent(new Event('change', {bubbles: true})); });
  });
  rows.forEach(function (c) { c.addEventListener('change', sync); });
  var f = g.closest('form');
  if (f) f.addEventListener('change', function (e) { if (e.target.classList.contains('js-pilih-semua')) setTimeout(sync, 0); });
  sync();
});
</script>
<?php }
}
