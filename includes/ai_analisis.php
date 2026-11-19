<?php
/**
 * includes/ai_analisis.php
 * ------------------------------------------------------------
 * Tambahan untuk Asisten AI:
 *   1. Upload file (CSV/XLSX) -> dibandingkan dengan tabel `items`
 *      => mana yang SUDAH ADA, MIRIP (perlu dicek), dan BELUM ADA.
 *   2. Analisis cerdas untuk admin (audit, saran pengadaan, barang
 *      tidak bergerak, kualitas data).
 *
 * Prinsip keamanan sama seperti asisten_ai.php: AI tidak pernah
 * menyentuh SQL. Semua query di sini tetap (whitelist) + prepared
 * statement. Perbandingan file dilakukan murni di PHP (tanpa kirim
 * isi file ke API AI), jadi data tidak keluar dari server.
 */

const AI_UPLOAD_MAKS_BYTE  = 2097152; // 2 MB
const AI_UPLOAD_MAKS_BARIS = 2000;
const AI_MIRIP_AMBANG     = 78;      // % kemiripan nama minimal untuk dianggap "mirip"

/* ============================================================
 * 1. PEMBACA FILE (CSV & XLSX tanpa library tambahan)
 * ============================================================ */

function ai_kolom_ke_indeks($ref) {
    if (!preg_match('/^[A-Z]+/', strtoupper($ref), $m)) return 0;
    $n = 0;
    foreach (str_split($m[0]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function ai_baca_xlsx($path) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Server belum mengaktifkan ekstensi ZipArchive. Simpan file sebagai CSV lalu upload ulang.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('File Excel tidak bisa dibuka (rusak atau bukan format .xlsx).');
    }

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) $names[] = $n;
    }
    natsort($names);
    $sheetName = reset($names);
    if (!$sheetName) { $zip->close(); throw new RuntimeException('Sheet tidak ditemukan di file Excel.'); }

    $stat = $zip->statName($sheetName);
    if (!$stat || $stat['size'] > 30 * 1024 * 1024) { $zip->close(); throw new RuntimeException('Isi sheet terlalu besar.'); }

    // Teks bersama (shared strings)
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml, 'SimpleXMLElement', LIBXML_NONET);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) { $shared[] = (string)$si->t; continue; }
                $t = '';
                foreach ($si->r as $r) $t .= (string)$r->t;
                $shared[] = $t;
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetName);
    $zip->close();
    $sheet = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    if ($sheet === false || !isset($sheet->sheetData)) throw new RuntimeException('Isi sheet tidak bisa dibaca.');

    $out = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $idx = ai_kolom_ke_indeks((string)$c['r']);
            $tipe = (string)$c['t'];
            if ($tipe === 's')              $val = $shared[(int)$c->v] ?? '';
            elseif ($tipe === 'inlineStr')  $val = (string)$c->is->t;
            else                            $val = (string)$c->v;
            $cells[$idx] = trim($val);
        }
        $line = [];
        if ($cells) {
            $max = max(array_keys($cells));
            for ($i = 0; $i <= $max; $i++) $line[] = $cells[$i] ?? '';
        }
        $out[] = $line;
        if (count($out) > AI_UPLOAD_MAKS_BARIS + 20) break;
    }
    return $out;
}

function ai_baca_csv($path) {
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('File tidak bisa dibaca.');
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

    $first = (string)strtok($raw, "\n");
    $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($delims);
    $delim = array_key_first($delims);

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $out = [];
    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($row === [null]) continue;
        $out[] = array_map(function ($v) { return trim((string)$v); }, $row);
        if (count($out) > AI_UPLOAD_MAKS_BARIS + 20) break;
    }
    fclose($fh);
    return $out;
}

/** Cari baris header & petakan kolom (nama/kode/jumlah/satuan/harga). */
function ai_petakan_kolom(array $rows) {
    $alias = [
        'nama'   => ['nama', 'nama barang', 'barang', 'nama item', 'item', 'uraian', 'uraian barang', 'deskripsi', 'nama atk'],
        'kode'   => ['kode', 'kode barang', 'kode item', 'sku', 'no barang', 'nomor barang'],
        'jumlah' => ['jumlah', 'qty', 'kuantitas', 'volume', 'banyak', 'jumlah barang', 'stok', 'saldo'],
        'satuan' => ['satuan', 'sat', 'unit'],
        'harga'  => ['harga', 'harga satuan', 'harga barang'],
    ];
    $limit = min(10, count($rows));
    for ($r = 0; $r < $limit; $r++) {
        $map = [];
        foreach ($rows[$r] as $ci => $cell) {
            $h = trim(mb_strtolower(preg_replace('/[^a-z0-9]+/i', ' ', (string)$cell)));
            foreach ($alias as $field => $daftar) {
                if (!isset($map[$field]) && in_array($h, $daftar, true)) { $map[$field] = $ci; break; }
            }
        }
        if (isset($map['nama'])) return ['header_baris' => $r, 'map' => $map, 'fallback' => false];
    }
    // Tidak ada header yang dikenali -> anggap kolom pertama = nama barang
    return ['header_baris' => -1, 'map' => ['nama' => 0], 'fallback' => true];
}

function ai_parse_angka($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(' ', '', $v);
    if (strpos($v, '.') !== false && strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (strpos($v, ',') !== false) {
        $v = str_replace(',', '.', $v);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

function ai_fmt_angka($n) {
    if ($n === null || $n === '') return '-';
    return (floor($n) == $n) ? (string)(int)$n : (string)round($n, 2);
}

/* ============================================================
 * 2. PENCOCOKAN NAMA
 * ============================================================ */

function ai_norm_nama($s) {
    $s = mb_strtoupper((string)$s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/** Skor kemiripan 0-100 dari dua nama yang SUDAH dinormalisasi. */
function ai_skor_mirip($a, $b) {
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 100;
    similar_text($a, $b, $pct);
    $ta = array_unique(explode(' ', $a));
    $tb = array_unique(explode(' ', $b));
    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    $jac = $union ? ($inter / $union) * 100 : 0;
    $skor = max($pct, $jac);

    // Angka (ukuran/nomor tipe, mis. "BINDER CLIP 107" vs "111") harus sama.
    preg_match_all('/\d+/', $a, $na);
    preg_match_all('/\d+/', $b, $nb);
    $na = array_unique($na[0]); $nb = array_unique($nb[0]);
    sort($na); sort($nb);
    if ($na && $nb && $na !== $nb) $skor = min($skor, 60);
    elseif (($na xor $nb)) $skor -= 10;

    return round(max(0, $skor), 1);
}

/** Bandingkan baris-baris dari file dengan tabel items. */
function ai_banding_file_vs_db(PDO $pdo, array $baris) {
    $items = $pdo->query('SELECT id, kode, nama, satuan, stok, harga FROM items')->fetchAll(PDO::FETCH_ASSOC);
    $idxKode = []; $idxNama = [];
    foreach ($items as $k => $it) {
        $items[$k]['_norm'] = ai_norm_nama($it['nama']);
        $idxKode[strtoupper(trim($it['kode']))] = $k;
        $idxNama[$items[$k]['_norm']] = $k;
    }

    $belum = []; $mirip = []; $sudah = [];
    $terpakai = []; $seen = []; $duplikat = 0;

    foreach ($baris as $b) {
        $normB = ai_norm_nama($b['nama']);
        $key = $normB !== '' ? $normB : strtoupper($b['kode']);
        if ($key === '') continue;
        if (isset($seen[$key])) { $duplikat++; continue; }
        $seen[$key] = true;

        $hit = null; $via = '';
        if ($b['kode'] !== '' && isset($idxKode[strtoupper($b['kode'])])) {
            $hit = $idxKode[strtoupper($b['kode'])]; $via = 'kode';
        } elseif ($normB !== '' && isset($idxNama[$normB])) {
            $hit = $idxNama[$normB]; $via = 'nama sama';
        }

        if ($hit !== null) {
            $it = $items[$hit];
            $terpakai[$it['id']] = true;
            if ($via === 'kode' && $normB !== '' && ai_skor_mirip($normB, $it['_norm']) < 60) {
                $via = 'kode sama, NAMA BEDA (cek)';
            }
            $sudah[] = [
                'baris' => $b['baris'], 'nama_di_file' => $b['nama'], 'kode_db' => $it['kode'],
                'nama_di_db' => $it['nama'], 'cocok_via' => $via,
                'jumlah_file' => ai_fmt_angka($b['jumlah']), 'stok_db' => (int)$it['stok'],
                'selisih' => $b['jumlah'] !== null ? ai_fmt_angka($b['jumlah'] - (int)$it['stok']) : '-',
            ];
            continue;
        }

        // Cari yang paling mirip
        $bestK = null; $bestSkor = 0;
        if ($normB !== '') {
            foreach ($items as $k => $it) {
                $s = ai_skor_mirip($normB, $it['_norm']);
                if ($s > $bestSkor) { $bestSkor = $s; $bestK = $k; }
            }
        }
        if ($bestK !== null && $bestSkor >= AI_MIRIP_AMBANG) {
            $it = $items[$bestK];
            $terpakai[$it['id']] = true;
            $mirip[] = [
                'baris' => $b['baris'], 'nama_di_file' => $b['nama'], 'nama_db_yang_mirip' => $it['nama'],
                'kode_db' => $it['kode'], 'kemiripan_persen' => $bestSkor, 'stok_db' => (int)$it['stok'],
                'jumlah_file' => ai_fmt_angka($b['jumlah']),
            ];
        } else {
            $belum[] = [
                'baris' => $b['baris'], 'kode_di_file' => $b['kode'] !== '' ? $b['kode'] : '-', 'nama_di_file' => $b['nama'],
                'jumlah' => ai_fmt_angka($b['jumlah']), 'satuan' => $b['satuan'] !== '' ? $b['satuan'] : '-',
                'harga' => $b['harga'] !== null ? ai_fmt_angka($b['harga']) : '-',
                'tebakan_terdekat' => $bestK !== null ? ($items[$bestK]['nama'] . ' (' . $bestSkor . '%)') : '-',
            ];
        }
    }

    $tidakDiFile = [];
    foreach ($items as $it) {
        if (!isset($terpakai[$it['id']])) {
            $tidakDiFile[] = ['kode' => $it['kode'], 'nama' => $it['nama'], 'satuan' => $it['satuan'], 'stok' => (int)$it['stok']];
        }
    }

    return [
        'belum' => $belum, 'mirip' => $mirip, 'sudah' => $sudah, 'tidak_di_file' => $tidakDiFile,
        'duplikat_di_file' => $duplikat, 'jumlah_db' => count($items),
    ];
}

/** Bangun block hasil (format sama seperti query_* di asisten_ai.php). */
function ai_build_blocks_banding(array $h, $namaFile) {
    $nBelum = count($h['belum']); $nMirip = count($h['mirip']); $nSudah = count($h['sudah']);
    $blocks = [];

    $blocks[] = [
        'title' => 'Ringkasan Perbandingan: ' . $namaFile,
        'rows' => [
            ['status' => 'Sudah ada di database', 'jumlah' => $nSudah],
            ['status' => 'Mirip (perlu dicek admin)', 'jumlah' => $nMirip],
            ['status' => 'Belum ada di database', 'jumlah' => $nBelum],
        ],
        'chart' => ['type' => 'bar', 'labels' => ['Sudah ada', 'Mirip', 'Belum ada'], 'datasets' => [['label' => 'Jumlah barang', 'values' => [$nSudah, $nMirip, $nBelum]]]],
        'summary' => ['sudah_ada' => $nSudah, 'mirip' => $nMirip, 'belum_ada' => $nBelum, 'duplikat_di_file' => $h['duplikat_di_file']],
        'intent' => 'banding_file',
    ];
    $blocks[] = ['title' => 'Barang BELUM ADA di Database (' . $nBelum . ')', 'rows' => $h['belum'], 'chart' => null, 'summary' => ['jumlah' => $nBelum], 'intent' => 'banding_file'];
    if ($nMirip) $blocks[] = ['title' => 'Barang MIRIP - Mohon Dicek (' . $nMirip . ')', 'rows' => $h['mirip'], 'chart' => null, 'summary' => ['jumlah' => $nMirip], 'intent' => 'banding_file'];
    if ($nSudah) $blocks[] = ['title' => 'Barang SUDAH ADA (' . $nSudah . ')', 'rows' => $h['sudah'], 'chart' => null, 'summary' => ['jumlah' => $nSudah], 'intent' => 'banding_file'];
    if ($h['tidak_di_file']) $blocks[] = ['title' => 'Ada di Database tapi Tidak Muncul di File (' . count($h['tidak_di_file']) . ')', 'rows' => $h['tidak_di_file'], 'chart' => null, 'summary' => ['jumlah' => count($h['tidak_di_file'])], 'intent' => 'banding_file'];
    return $blocks;
}

function ai_potong_blocks(array $blocks, $maks) {
    foreach ($blocks as $i => $b) {
        if (!empty($b['rows']) && count($b['rows']) > $maks) $blocks[$i]['rows'] = array_slice($b['rows'], 0, $maks);
    }
    return $blocks;
}

/**
 * Endpoint upload. Dipanggil dari asisten_ai.php; kalau request-nya bukan
 * do=upload_banding, fungsi ini langsung return (tidak melakukan apa-apa).
 */
function ai_analisis_handle_upload(PDO $pdo, array $u) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['do'] ?? '') !== 'upload_banding') return;

    csrf_check();
    header('Content-Type: application/json');
    $kirim = function ($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; };

    if (($u['role'] ?? '') === 'bidang') $kirim(['error' => 'Fitur banding file hanya tersedia untuk admin/super admin.']);
    if (!ai_rate_limit_check(8)) $kirim(['error' => 'Terlalu banyak permintaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.']);

    $f = $_FILES['berkas'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $kirim(['error' => 'File gagal diupload. Coba lagi.']);
    }
    if ($f['size'] > AI_UPLOAD_MAKS_BYTE) $kirim(['error' => 'Ukuran file maksimal 2 MB.']);

    $namaAsli = basename((string)$f['name']);
    $ext = strtolower(pathinfo($namaAsli, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        $kirim(['error' => 'Format belum didukung. Gunakan .xlsx atau .csv (file .xls lama: Save As dulu ke .xlsx).']);
    }

    try {
        $rows = $ext === 'xlsx' ? ai_baca_xlsx($f['tmp_name']) : ai_baca_csv($f['tmp_name']);
    } catch (Throwable $e) {
        $kirim(['error' => $e->getMessage()]);
    }
    if (!$rows) $kirim(['error' => 'File kosong atau tidak ada data yang terbaca.']);

    $peta = ai_petakan_kolom($rows);
    $map = $peta['map'];
    $baris = []; $kodeRusak = 0;
    for ($i = $peta['header_baris'] + 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $nama = trim((string)($r[$map['nama']] ?? ''));
        $kode = isset($map['kode']) ? trim((string)($r[$map['kode']] ?? '')) : '';
        if ($nama === '' && $kode === '') continue;
        // Excel sering mengubah kode 16 digit jadi notasi ilmiah (1.01E+15) -> tidak bisa dipakai.
        if ($kode !== '' && preg_match('/^\d(\.\d+)?E\+\d+$/i', $kode)) { $kode = ''; $kodeRusak++; }
        $baris[] = [
            'baris' => $i + 1, 'nama' => $nama, 'kode' => $kode,
            'jumlah' => isset($map['jumlah']) ? ai_parse_angka($r[$map['jumlah']] ?? '') : null,
            'satuan' => isset($map['satuan']) ? trim((string)($r[$map['satuan']] ?? '')) : '',
            'harga'  => isset($map['harga']) ? ai_parse_angka($r[$map['harga']] ?? '') : null,
        ];
        if (count($baris) >= AI_UPLOAD_MAKS_BARIS) break;
    }
    if (!$baris) $kirim(['error' => 'Tidak ada baris barang yang terbaca. Pastikan ada kolom "Nama Barang".']);

    $h = ai_banding_file_vs_db($pdo, $baris);
    $blocks = ai_build_blocks_banding($h, $namaAsli);

    $nB = count($h['belum']); $nM = count($h['mirip']); $nS = count($h['sudah']);
    $jawaban = 'Selesai membandingkan ' . count($baris) . ' baris dari file "' . $namaAsli . '" dengan ' . $h['jumlah_db'] . ' barang di database. '
        . 'Sudah ada: ' . $nS . ', mirip (mohon dicek, bisa jadi barang yang sama dengan penulisan berbeda): ' . $nM . ', belum ada: ' . $nB . '.';
    if ($nB) $jawaban .= ' Daftar barang yang belum ada bisa diunduh lewat tombol Export lalu ditambahkan di menu Data Barang.';
    else     $jawaban .= ' Semua barang di file sudah tercatat di database.';
    if ($h['duplikat_di_file']) $jawaban .= ' Catatan: ' . $h['duplikat_di_file'] . ' baris ganda di dalam file diabaikan.';
    if ($kodeRusak) $jawaban .= ' Peringatan: ' . $kodeRusak . ' kode barang di file berformat ilmiah (mis. 1.01E+15) sehingga tidak dipakai; pencocokan memakai nama. Di Excel, format kolom kode sebagai Teks agar kode tidak rusak.';
    if ($peta['fallback']) $jawaban .= ' Catatan: header kolom tidak dikenali, jadi kolom pertama dianggap Nama Barang. Gunakan header seperti "Nama Barang", "Kode", "Jumlah", "Satuan", "Harga".';

    $_SESSION['ai_last_result'] = ['question' => 'Banding file ' . $namaAsli, 'blocks' => $blocks]; // lengkap, untuk export
    $blocksTampil = ai_potong_blocks($blocks, 200);

    $pertanyaan = 'Upload file: ' . $namaAsli;
    ai_audit_log($u['username'] ?? 'unknown', $pertanyaan, 'banding_file', $jawaban);
    $pdo->prepare('INSERT INTO ai_chat_history (user_id, pertanyaan, jawaban, blocks_json) VALUES (?, ?, ?, ?)')
        ->execute([$u['id'], $pertanyaan, $jawaban, json_encode($blocksTampil, JSON_UNESCAPED_UNICODE)]);

    $kirim(['jawaban' => $jawaban, 'blocks' => $blocksTampil, 'bisa_export' => true]);
}

/* ============================================================
 * 3. ANALISIS CERDAS ADMIN
 * ============================================================ */

/** Deteksi pertanyaan analisis dari kata kunci (tanpa memanggil AI klasifikasi). */
function ai_analisis_route($question) {
    $t = mb_strtolower($question, 'UTF-8');

    if (preg_match('/\b(audit|analisis menyeluruh|analisa menyeluruh|insight|periksa semua|cek kesehatan)\b|kesehatan (stok|inventaris|gudang)/u', $t)) {
        return [['intent' => 'audit_inventaris']];
    }
    $intents = [];
    if (preg_match('/(saran|rekomendasi|usulan).{0,25}(pengadaan|beli|belanja|restock|order|pesan)|perlu (dibeli|restock|dipesan)|harus (beli|restock)|restock/u', $t)) {
        $intents[] = ['intent' => 'saran_pengadaan'];
    }
    if (preg_match('/tidak bergerak|dead stock|jarang (keluar|dipakai|diambil)|tidak pernah (keluar|dipakai|diambil)|stok mati|menumpuk/u', $t)) {
        $hari = preg_match('/(\d{2,3})\s*hari/u', $t, $m) ? (int)$m[1] : 90;
        $intents[] = ['intent' => 'barang_tidak_bergerak', 'hari' => $hari];
    }
    if (preg_match('/kualitas data|data (kotor|tidak konsisten|ganda|duplikat|bermasalah)|duplikat|typo|salah (ketik|input)|rapikan data/u', $t)) {
        $intents[] = ['intent' => 'kualitas_data'];
    }
    return $intents ?: null;
}

function ai_analisis_run(PDO $pdo, $intent, array $it, $scopeBidang = null) {
    if ($scopeBidang) {
        return ['title' => 'Analisis Admin', 'rows' => [], 'chart' => null,
            'summary' => ['akses' => 'dibatasi', 'keterangan' => 'Fitur analisis ini hanya tersedia untuk admin/super admin.']];
    }
    switch ($intent) {
        case 'audit_inventaris':      return ai_q_audit_inventaris($pdo);
        case 'saran_pengadaan':       return ai_q_saran_pengadaan($pdo, (int)($it['periode_hari'] ?? 90));
        case 'barang_tidak_bergerak': return ai_q_barang_tidak_bergerak($pdo, (int)($it['hari'] ?? 90));
        case 'kualitas_data':         return ai_q_kualitas_data($pdo);
    }
    return null;
}

function ai_hitung_pemakaian(PDO $pdo, $hari) {
    $sejak = date('Y-m-d', strtotime("-{$hari} day"));
    $stmt = $pdo->prepare("SELECT i.id, i.kode, i.nama, i.satuan, i.stok, i.stok_minimum, i.harga,
            COALESCE(SUM(CASE WHEN t.tipe='keluar' AND t.tanggal >= ? THEN t.jumlah ELSE 0 END),0) AS keluar_periode
        FROM items i LEFT JOIN transactions t ON t.item_id = i.id
        GROUP BY i.id, i.kode, i.nama, i.satuan, i.stok, i.stok_minimum, i.harga");
    $stmt->execute([$sejak]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Saran pengadaan: target stok = pemakaian rata-rata x (lead time 30 hari + cadangan 30 hari),
 * minimal sebesar stok_minimum. Saran beli = target - stok saat ini.
 */
function ai_q_saran_pengadaan(PDO $pdo, $periode = 90, $leadTime = 30, $cadangan = 30) {
    $periode = max(14, min(365, (int)$periode ?: 90));
    $data = ai_hitung_pemakaian($pdo, $periode);
    $urut = ['MENDESAK' => 0, 'SEGERA' => 1, 'PANTAU' => 2];
    $rows = []; $totalBiaya = 0; $mendesak = 0;

    foreach ($data as $d) {
        $stok = (int)$d['stok']; $min = (int)$d['stok_minimum'];
        $avg = (float)$d['keluar_periode'] / $periode;
        $sisaHari = $avg > 0 ? (int)floor($stok / $avg) : null;
        $target = max($min, (int)ceil($avg * ($leadTime + $cadangan)));
        $saran = max(0, $target - $stok);
        $kritis = ($min > 0 && $stok <= $min) || ($sisaHari !== null && $sisaHari <= $leadTime);
        if ($saran <= 0 && !$kritis) continue;
        if ($saran <= 0) $saran = max(1, $min);

        $prioritas = $kritis ? 'MENDESAK' : (($sisaHari !== null && $sisaHari <= $leadTime + 15) ? 'SEGERA' : 'PANTAU');
        if ($prioritas === 'MENDESAK') $mendesak++;
        $biaya = $saran * (float)$d['harga'];
        $totalBiaya += $biaya;
        $rows[] = [
            'prioritas' => $prioritas, 'kode' => $d['kode'], 'nama' => $d['nama'], 'satuan' => $d['satuan'],
            'stok' => $stok, 'stok_minimum' => $min, 'rata2_keluar_per_hari' => round($avg, 2),
            'perkiraan_habis_hari_lagi' => $sisaHari === null ? 'tidak ada riwayat' : $sisaHari,
            'saran_beli' => $saran, 'perkiraan_biaya' => round($biaya),
        ];
    }
    usort($rows, function ($a, $b) use ($urut) {
        if ($a['prioritas'] !== $b['prioritas']) return $urut[$a['prioritas']] <=> $urut[$b['prioritas']];
        $x = is_int($a['perkiraan_habis_hari_lagi']) ? $a['perkiraan_habis_hari_lagi'] : 99999;
        $y = is_int($b['perkiraan_habis_hari_lagi']) ? $b['perkiraan_habis_hari_lagi'] : 99999;
        return $x <=> $y;
    });

    $top = array_slice($rows, 0, 10);
    return [
        'title' => 'Saran Pengadaan (berdasarkan pemakaian ' . $periode . ' hari terakhir)',
        'rows' => $rows,
        'chart' => $top ? ['type' => 'bar', 'labels' => array_column($top, 'nama'), 'datasets' => [['label' => 'Saran beli', 'values' => array_column($top, 'saran_beli')]]] : null,
        'summary' => [
            'jumlah_barang_disarankan' => count($rows), 'jumlah_mendesak' => $mendesak,
            'total_perkiraan_biaya' => round($totalBiaya),
            'asumsi' => "target stok = pemakaian rata-rata x " . ($leadTime + $cadangan) . " hari (lead time $leadTime + cadangan $cadangan), minimal stok minimum; biaya = saran x harga satuan di data barang",
        ],
    ];
}

function ai_q_barang_tidak_bergerak(PDO $pdo, $hari = 90) {
    $hari = max(14, min(730, (int)$hari ?: 90));
    $batas = date('Y-m-d', strtotime("-{$hari} day"));
    $adaTransaksi = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE tipe='keluar'")->fetchColumn();

    $stmt = $pdo->prepare("SELECT i.kode, i.nama, i.satuan, i.stok, i.harga,
            MAX(CASE WHEN t.tipe='keluar' THEN t.tanggal END) AS terakhir_keluar
        FROM items i LEFT JOIN transactions t ON t.item_id = i.id
        WHERE i.stok > 0
        GROUP BY i.id, i.kode, i.nama, i.satuan, i.stok, i.harga
        HAVING terakhir_keluar IS NULL OR terakhir_keluar < ?");
    $stmt->execute([$batas]);
    $rows = []; $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nilai = (int)$r['stok'] * (float)$r['harga'];
        $total += $nilai;
        $rows[] = [
            'kode' => $r['kode'], 'nama' => $r['nama'], 'satuan' => $r['satuan'], 'stok' => (int)$r['stok'],
            'terakhir_keluar' => $r['terakhir_keluar'] ?: 'belum pernah', 'nilai_tertahan' => round($nilai),
        ];
    }
    usort($rows, function ($a, $b) { return $b['nilai_tertahan'] <=> $a['nilai_tertahan']; });
    $top = array_slice($rows, 0, 10);

    $sum = ['batas_hari' => $hari, 'jumlah_barang' => count($rows), 'total_nilai_tertahan' => round($total)];
    if (!$adaTransaksi) $sum['catatan'] = 'Belum ada satu pun transaksi keluar tercatat, jadi hasil ini belum bermakna (semua barang tampak "tidak bergerak").';

    return [
        'title' => 'Barang Tidak Bergerak (' . $hari . ' hari tanpa pengeluaran)',
        'rows' => $rows,
        'chart' => $top ? ['type' => 'bar', 'labels' => array_column($top, 'nama'), 'datasets' => [['label' => 'Nilai tertahan (Rp)', 'values' => array_column($top, 'nilai_tertahan')]]] : null,
        'summary' => $sum,
    ];
}

function ai_q_kualitas_data(PDO $pdo) {
    $items = $pdo->query('SELECT id, kode, nama, jenis, satuan, stok, stok_minimum, harga FROM items ORDER BY nama')->fetchAll(PDO::FETCH_ASSOC);
    $temuan = [];
    $tambah = function ($jenis, $it, $detail, $saran) use (&$temuan) {
        $temuan[] = ['jenis_masalah' => $jenis, 'kode' => $it['kode'], 'nama' => $it['nama'], 'detail' => $detail, 'saran' => $saran];
    };

    foreach ($items as $it) {
        if ($it['satuan'] !== mb_strtoupper($it['satuan'], 'UTF-8')) $tambah('Satuan huruf tidak seragam', $it, 'Satuan: "' . $it['satuan'] . '"', 'Samakan menjadi huruf kapital');
        if ((float)$it['harga'] <= 0) $tambah('Harga kosong/nol', $it, 'Harga: ' . $it['harga'], 'Isi harga satuan agar nilai persediaan akurat');
        if ((int)$it['stok_minimum'] === 0) $tambah('Stok minimum belum diatur', $it, 'stok_minimum = 0', 'Atur stok minimum supaya notifikasi stok menipis aktif');
        if ((int)$it['stok'] < 0) $tambah('Stok negatif', $it, 'Stok: ' . $it['stok'], 'Periksa riwayat transaksi barang ini');
        if (!preg_match('/^\d{16}$/', (string)$it['kode'])) $tambah('Format kode tidak standar', $it, 'Kode: "' . $it['kode'] . '"', 'Kode barang lain berformat 16 digit');
    }

    // Harga janggal dibanding barang sejenis (jenis + satuan sama, minimal 3 barang)
    $grup = [];
    foreach ($items as $it) if ((float)$it['harga'] > 0) $grup[$it['jenis'] . '|' . mb_strtoupper($it['satuan'], 'UTF-8')][] = $it;
    foreach ($grup as $g) {
        if (count($g) < 3) continue;
        $h = array_map(function ($x) { return (float)$x['harga']; }, $g);
        sort($h);
        $med = $h[intdiv(count($h), 2)];
        foreach ($g as $it) {
            $v = (float)$it['harga'];
            if ($v < $med * 0.1 || $v > $med * 10) {
                $tambah('Harga janggal', $it, 'Harga ' . round($v) . ' vs median sejenis ' . round($med), 'Cek apakah salah ketik atau beda satuan');
            }
        }
    }

    // Kemungkinan duplikat (nama sangat mirip) - dibatasi 400 barang agar ringan
    if (count($items) <= 400) {
        $norm = [];
        foreach ($items as $k => $it) $norm[$k] = ai_norm_nama($it['nama']);
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $s = ai_skor_mirip($norm[$i], $norm[$j]);
                if ($s >= 88) {
                    $tambah('Kemungkinan duplikat / typo', $items[$i], 'Mirip "' . $items[$j]['nama'] . '" (kode ' . $items[$j]['kode'] . ', ' . $s . '%)', 'Jika barang sama, gabungkan atau perbaiki penulisan nama');
                }
            }
        }
    }

    $perJenis = [];
    foreach ($temuan as $t) $perJenis[$t['jenis_masalah']] = ($perJenis[$t['jenis_masalah']] ?? 0) + 1;
    arsort($perJenis);

    return [
        'title' => 'Pemeriksaan Kualitas Data Barang',
        'rows' => array_slice($temuan, 0, 150), 'chart' => null,
        'summary' => ['jumlah_temuan' => count($temuan), 'per_jenis_masalah' => $perJenis ?: null],
    ];
}

function ai_q_lonjakan(PDO $pdo) {
    $awalIni = date('Y-m-01');
    $awalLalu = date('Y-m-01', strtotime('first day of last month'));
    $stmt = $pdo->prepare("SELECT i.kode, i.nama, i.satuan,
            SUM(CASE WHEN t.tanggal >= ? THEN t.jumlah ELSE 0 END) AS bulan_ini,
            SUM(CASE WHEN t.tanggal >= ? AND t.tanggal < ? THEN t.jumlah ELSE 0 END) AS bulan_lalu
        FROM transactions t JOIN items i ON i.id = t.item_id
        WHERE t.tipe='keluar' AND t.tanggal >= ?
        GROUP BY i.id, i.kode, i.nama, i.satuan");
    $stmt->execute([$awalIni, $awalLalu, $awalIni, $awalLalu]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ini = (int)$r['bulan_ini']; $lalu = (int)$r['bulan_lalu'];
        $lonjak = $lalu === 0 ? ($ini >= 20) : ($ini >= 10 && $ini >= 2 * $lalu);
        if ($lonjak) $rows[] = ['kode' => $r['kode'], 'nama' => $r['nama'], 'satuan' => $r['satuan'], 'bulan_lalu' => $lalu, 'bulan_ini' => $ini];
    }
    usort($rows, function ($a, $b) { return $b['bulan_ini'] <=> $a['bulan_ini']; });
    return ['title' => 'Lonjakan Pengeluaran Bulan Ini vs Bulan Lalu', 'rows' => $rows, 'chart' => null, 'summary' => ['jumlah' => count($rows)]];
}

/** Audit menyeluruh: gabungan semua temuan, diurutkan dari yang paling perlu tindakan. */
function ai_q_audit_inventaris(PDO $pdo) {
    $saran = ai_q_saran_pengadaan($pdo);
    $diam  = ai_q_barang_tidak_bergerak($pdo, 90);
    $data  = ai_q_kualitas_data($pdo);
    $lonj  = ai_q_lonjakan($pdo);

    $stmt = $pdo->query("SELECT p.id, p.bidang, p.penanggung_jawab, p.tanggal, DATEDIFF(CURDATE(), p.tanggal) AS hari_menunggu
        FROM permintaan p WHERE p.status = 'menunggu' AND p.tanggal <= (CURDATE() - INTERVAL 3 DAY) ORDER BY p.tanggal ASC");
    $permLama = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT nama_penitip, nama_barang, tanggal_titip, DATEDIFF(CURDATE(), tanggal_titip) AS hari_dititip
        FROM penitipan WHERE status = 'dititip' AND tanggal_titip <= (CURDATE() - INTERVAL 30 DAY) ORDER BY tanggal_titip ASC");
    $titipLama = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stokNol = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE stok <= 0')->fetchColumn();
    $mendesak = (int)($saran['summary']['jumlah_mendesak'] ?? 0);

    $ringkas = [
        ['aspek' => 'Barang stok habis (0)', 'jumlah' => $stokNol, 'tingkat' => $stokNol ? 'Tinggi' : 'Aman'],
        ['aspek' => 'Barang perlu dibeli MENDESAK', 'jumlah' => $mendesak, 'tingkat' => $mendesak ? 'Tinggi' : 'Aman'],
        ['aspek' => 'Permintaan bidang menunggu > 3 hari', 'jumlah' => count($permLama), 'tingkat' => $permLama ? 'Sedang' : 'Aman'],
        ['aspek' => 'Lonjakan pengeluaran bulan ini', 'jumlah' => count($lonj['rows']), 'tingkat' => $lonj['rows'] ? 'Sedang' : 'Aman'],
        ['aspek' => 'Barang tidak bergerak 90 hari', 'jumlah' => count($diam['rows']), 'tingkat' => $diam['rows'] ? 'Rendah' : 'Aman'],
        ['aspek' => 'Titipan > 30 hari belum diambil', 'jumlah' => count($titipLama), 'tingkat' => $titipLama ? 'Rendah' : 'Aman'],
        ['aspek' => 'Masalah kualitas data barang', 'jumlah' => (int)$data['summary']['jumlah_temuan'], 'tingkat' => $data['rows'] ? 'Rendah' : 'Aman'],
    ];

    $blocks = [[
        'title' => 'Ringkasan Audit Inventaris - ' . date('d M Y'),
        'rows' => $ringkas,
        'chart' => ['type' => 'bar', 'labels' => array_column($ringkas, 'aspek'), 'datasets' => [['label' => 'Jumlah temuan', 'values' => array_column($ringkas, 'jumlah')]]],
        'summary' => [
            'stok_habis' => $stokNol, 'perlu_dibeli_mendesak' => $mendesak,
            'permintaan_menunggu_lama' => count($permLama), 'lonjakan_pengeluaran' => count($lonj['rows']),
            'barang_tidak_bergerak' => count($diam['rows']), 'nilai_tertahan' => $diam['summary']['total_nilai_tertahan'],
            'titipan_lama' => count($titipLama), 'masalah_data' => $data['summary']['jumlah_temuan'],
            'perkiraan_biaya_pengadaan' => $saran['summary']['total_perkiraan_biaya'],
            'catatan_diam' => $diam['summary']['catatan'] ?? null,
        ],
    ]];
    if ($saran['rows'])  $blocks[] = $saran;
    if ($permLama)       $blocks[] = ['title' => 'Permintaan Bidang Menunggu > 3 Hari', 'rows' => $permLama, 'chart' => null, 'summary' => ['jumlah' => count($permLama)]];
    if ($lonj['rows'])   $blocks[] = $lonj;
    if ($diam['rows'])   $blocks[] = $diam;
    if ($titipLama)      $blocks[] = ['title' => 'Barang Titipan > 30 Hari Belum Diambil', 'rows' => $titipLama, 'chart' => null, 'summary' => ['jumlah' => count($titipLama)]];
    if ($data['rows'])   $blocks[] = $data;
    return $blocks;
}
