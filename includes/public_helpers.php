<?php
// Fungsi bantu untuk fitur "Cek barang" yang bisa diakses TANPA login.

// Ubah ke false kalau berkas BAST tidak boleh dilihat publik.
// Dengan false, tombol "Lihat BAST" otomatis tidak muncul dan berkas tidak bisa dibuka.
const PUBLIC_BAST_ENABLED = true;

/**
 * Pembatasan laju per IP. Mengembalikan true kalau masih boleh, false kalau sudah kebanyakan.
 * $name : nama bucket (satu file per fungsi), $max : maksimal per jendela, $win : detik.
 */
function pub_rate_ok(string $name, int $max, int $win = 60): bool {
    $dir = dirname(__DIR__) . '/data/';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . 'public_rate_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.json';
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();

    $fh = @fopen($file, 'c+');
    if (!$fh) return true;               // gagal buka file: jangan blokir pengguna sah
    if (!flock($fh, LOCK_EX)) { fclose($fh); return true; }

    $data = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($data)) $data = [];
    foreach ($data as $k => $v) {
        if (($v['start'] ?? 0) + $win < $now) unset($data[$k]);
    }
    $entry = $data[$ip] ?? ['start' => $now, 'count' => 0];
    $entry['count']++;
    $data[$ip] = $entry;

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $entry['count'] <= $max;
}
