<?php
/**
 * Helper foto barang: thumbnail kecil (WebP) supaya halaman Data Barang ringan.
 * Thumbnail disimpan di uploads/barang/thumb/<nama-file-tanpa-ekstensi>.webp
 */

/** Lokasi file thumbnail untuk sebuah foto. */
function foto_thumb_path($fotoDir, $namaFile) {
    return rtrim($fotoDir, '/\\') . '/thumb/' . pathinfo(basename($namaFile), PATHINFO_FILENAME) . '.webp';
}

/**
 * Buat thumbnail WebP dari gambar $src ke $dst (sisi terpanjang maksimal $maxSide px).
 * Mengembalikan true jika berhasil. Jika GD/WebP tidak tersedia, mengembalikan false
 * (pemanggil tinggal memakai foto asli).
 */
function foto_buat_thumb($src, $dst, $maxSide = 480) {
    if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) return false;
    try { return foto_buat_thumb_aman($src, $dst, $maxSide); } catch (Throwable $e) { error_log('thumb: ' . $e->getMessage()); return false; }
}

function foto_buat_thumb_aman($src, $dst, $maxSide) {

    $info = @getimagesize($src);
    if (!$info) return false;
    $w = (int)$info[0];
    $h = (int)$info[1];
    $type = $info[2];
    if ($w < 1 || $h < 1 || ($w * $h) > 24000000) return false; // terlalu besar, hindari kehabisan memori

    @ini_set('memory_limit', '256M');

    switch ($type) {
        case IMAGETYPE_JPEG: if (!function_exists('imagecreatefromjpeg')) return false; $img = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  if (!function_exists('imagecreatefrompng')) return false;  $img = @imagecreatefrompng($src);  break;
        case IMAGETYPE_WEBP: if (!function_exists('imagecreatefromwebp')) return false; $img = @imagecreatefromwebp($src); break;
        default: return false;
    }
    if (!$img) return false;

    // Foto dari HP sering punya tanda "rotasi" (EXIF). GD tidak membacanya, jadi kita putar manual.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $orientasi = (int)($exif['Orientation'] ?? 1);
        $sudut = [3 => 180, 6 => -90, 8 => 90][$orientasi] ?? 0;
        if ($sudut !== 0) {
            $rot = imagerotate($img, $sudut, 0);
            if ($rot) {
                $img = $rot;
                $w = imagesx($img);
                $h = imagesy($img);
            }
        }
    }

    $r = min(1, $maxSide / $w, $maxSide / $h);
    $nw = max(1, (int)round($w * $r));
    $nh = max(1, (int)round($h * $r));

    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $dir = dirname($dst);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Tulis ke file sementara dulu lalu rename, supaya aman kalau 2 request membuat thumbnail bersamaan.
    $tmp = $dst . '.' . uniqid('', true) . '.tmp';
    $ok = @imagewebp($out, $tmp, 80);
    if ($ok && @rename($tmp, $dst)) return true;
    @unlink($tmp);
    return false;
}

/** Hapus foto asli beserta thumbnail-nya. */
function foto_hapus_file($fotoDir, $namaFile) {
    if (!$namaFile) return;
    $namaFile = basename($namaFile);
    $asli = rtrim($fotoDir, '/\\') . '/' . $namaFile;
    if (is_file($asli)) @unlink($asli);
    $thumb = foto_thumb_path($fotoDir, $namaFile);
    if (is_file($thumb)) @unlink($thumb);
}
