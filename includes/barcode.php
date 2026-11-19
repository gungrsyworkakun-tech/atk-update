<?php
/**
 * Pembungkus tipis di atas qrcode-lib.php (library QR Code pihak ketiga,
 * single-file, tanpa dependency, lisensi MIT: psyon/php-qrcode).
 *
 * Tulisan memakai font TTF (assets/fonts) supaya tajam & mudah dibaca.
 * Kalau font / FreeType tidak ada, otomatis pakai font bawaan GD.
 */
require_once __DIR__ . '/qrcode-lib.php';

final class ItemQr {

    /** QR polos tanpa label. */
    public static function render($kode, $scale = 6, $padding = 10) {
        $qr = new QRCode((string)$kode, ['s' => 'qrm', 'sf' => $scale, 'p' => $padding]);
        return $qr->render_image();
    }

    private static function fontFile($mono = false) {
        if (!function_exists('imagettftext')) return null;
        $f = dirname(__DIR__) . '/assets/fonts/' . ($mono ? 'DejaVuSansMono-Bold.ttf' : 'DejaVuSans-Bold.ttf');
        return is_file($f) ? $f : null;
    }

    /** Lebar teks (px). */
    private static function tw($text, $size, $font) {
        if ($font === null) return imagefontwidth(5) * strlen($text);
        $b = imagettfbbox($size, 0, $font, $text);
        return (int)abs($b[2] - $b[0]);
    }

    /** Tinggi huruf kapital (px), dipakai untuk menengahkan teks secara vertikal. */
    private static function capH($size, $font) {
        if ($font === null) return imagefontheight(5);
        $b = imagettfbbox($size, 0, $font, 'H');
        return (int)abs($b[7] - $b[1]);
    }

    /** Gambar teks dengan titik tengah horizontal $cx dan tengah vertikal $cy. */
    private static function textCenter($im, $text, $size, $font, $color, $cx, $cy) {
        $w = self::tw($text, $size, $font);
        $h = self::capH($size, $font);
        if ($font === null) {
            imagestring($im, 5, (int)($cx - $w / 2), (int)($cy - $h / 2), $text, $color);
            return;
        }
        imagettftext($im, $size, 0, (int)round($cx - $w / 2), (int)round($cy + $h / 2), $color, $font, $text);
    }

    /** Pecah nama jadi maksimal $maxLines baris sesuai lebar $maxW. */
    private static function wrap($text, $size, $font, $maxW, $maxLines = 2) {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if (self::tw($try, $size, $font) <= $maxW || $cur === '') {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $w;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = $lines[$maxLines - 1];
        } else {
            $last = end($lines);
        }
        // Potong baris terakhir bila masih terlalu lebar.
        while ($last !== '' && self::tw($last, $size, $font) > $maxW) {
            $last = mb_substr($last, 0, mb_strlen($last) - 2) . '...';
            if (mb_strlen($last) < 4) break;
        }
        if ($lines) $lines[count($lines) - 1] = $last;
        return $lines ?: [''];
    }

    /**
     * QR + label: nama barang, kode, tahun, dan jenis.
     * ($stok sengaja tidak ditampilkan lagi, parameternya dipertahankan agar pemanggil lama tetap jalan.)
     */
    public static function render_with_label($kode, $nama, $tahun, $stok = null, $jenis = '', $scale = 16, $padding = 4) {
        $qrImg = self::render($kode, $scale, $padding);
        $qrW = imagesx($qrImg);
        $qrH = imagesy($qrImg);

        $fontB = self::fontFile(false);
        $fontM = self::fontFile(true);

        $sizeName = 24; $sizeKode = 22; $sizeBadge = 19;

        $kodeText  = (string)$kode;
        $tahunText = 'Tahun ' . $tahun;
        $jenisText = trim((string)$jenis);

        $margin = 30;
        $maxTextW = max($qrW, 420);

        $nameLines = self::wrap((string)$nama, $sizeName, $fontB, $maxTextW, 2);
        $nameLineH = self::capH($sizeName, $fontB) + 16;

        $kodeW = self::tw($kodeText, $sizeKode, $fontM);
        $kodePadX = 24; $kodePadY = 13;
        $kodeBadgeW = $kodeW + $kodePadX * 2;
        $kodeBadgeH = self::capH($sizeKode, $fontM) + $kodePadY * 2;

        $badgePadX = 20; $badgePadY = 11; $badgeGap = 12;
        $badgeH = self::capH($sizeBadge, $fontB) + $badgePadY * 2;
        $tahunBadgeW = self::tw($tahunText, $sizeBadge, $fontB) + $badgePadX * 2;
        $jenisBadgeW = $jenisText !== '' ? self::tw($jenisText, $sizeBadge, $fontB) + $badgePadX * 2 : 0;
        $statRowW = $tahunBadgeW + ($jenisText !== '' ? $badgeGap + $jenisBadgeW : 0);

        // Kalau baris tahun+jenis terlalu lebar, turunkan jenis ke baris sendiri.
        $stackJenis = $jenisText !== '' && $statRowW > $maxTextW;
        $rows = 1 + ($stackJenis ? 1 : 0);
        $statBlockH = $rows * $badgeH + ($rows - 1) * $badgeGap;
        if ($stackJenis) $statRowW = max($tahunBadgeW, $jenisBadgeW);

        $accentH = 10;
        $gap1 = 20; $gap2 = 18; $gap3 = 14; $gap4 = 14;

        $contentW = max($qrW, $maxTextW, $kodeBadgeW, $statRowW);
        $canvasW = $contentW + $margin * 2;
        $canvasH = $margin + $accentH + $gap1 + $qrH + $gap2
                 + count($nameLines) * $nameLineH + $gap3
                 + $kodeBadgeH + $gap4 + $statBlockH + $margin;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        $white  = imagecolorallocate($canvas, 255, 255, 255);
        $ink    = imagecolorallocate($canvas, 15, 20, 28);
        $border = imagecolorallocate($canvas, 205, 210, 218);
        $accent = imagecolorallocate($canvas, 13, 148, 136);
        $badgeBg   = imagecolorallocate($canvas, 232, 235, 240);
        $badgeText = imagecolorallocate($canvas, 40, 48, 60);
        $jenisBg   = imagecolorallocate($canvas, 207, 225, 253);
        $jenisTxt  = imagecolorallocate($canvas, 20, 50, 150);

        imagefill($canvas, 0, 0, $white);
        imagerectangle($canvas, 1, 1, $canvasW - 2, $canvasH - 2, $border);
        imagerectangle($canvas, 2, 2, $canvasW - 3, $canvasH - 3, $border);

        $cx = $canvasW / 2;

        self::frr($canvas, $margin, $margin, $canvasW - $margin, $margin + $accentH, 5, $accent);

        $y = $margin + $accentH + $gap1;
        imagecopy($canvas, $qrImg, (int)(($canvasW - $qrW) / 2), $y, 0, 0, $qrW, $qrH);
        $y += $qrH + $gap2;

        foreach ($nameLines as $ln) {
            self::textCenter($canvas, $ln, $sizeName, $fontB, $ink, $cx, $y + $nameLineH / 2 - 4);
            $y += $nameLineH;
        }
        $y += $gap3;

        // Badge kode
        $bx1 = (int)(($canvasW - $kodeBadgeW) / 2);
        self::frr($canvas, $bx1, $y, $bx1 + $kodeBadgeW, $y + $kodeBadgeH, 10, $badgeBg);
        self::textCenter($canvas, $kodeText, $sizeKode, $fontM, $ink, $cx, $y + $kodeBadgeH / 2);
        $y += $kodeBadgeH + $gap4;

        // Badge tahun (+ jenis)
        if ($stackJenis) {
            $x1 = (int)(($canvasW - $tahunBadgeW) / 2);
            self::frr($canvas, $x1, $y, $x1 + $tahunBadgeW, $y + $badgeH, 10, $badgeBg);
            self::textCenter($canvas, $tahunText, $sizeBadge, $fontB, $badgeText, $x1 + $tahunBadgeW / 2, $y + $badgeH / 2);
            $y += $badgeH + $badgeGap;
            $x2 = (int)(($canvasW - $jenisBadgeW) / 2);
            self::frr($canvas, $x2, $y, $x2 + $jenisBadgeW, $y + $badgeH, 10, $jenisBg);
            self::textCenter($canvas, $jenisText, $sizeBadge, $fontB, $jenisTxt, $x2 + $jenisBadgeW / 2, $y + $badgeH / 2);
        } else {
            $rowX = (int)(($canvasW - $statRowW) / 2);
            self::frr($canvas, $rowX, $y, $rowX + $tahunBadgeW, $y + $badgeH, 10, $badgeBg);
            self::textCenter($canvas, $tahunText, $sizeBadge, $fontB, $badgeText, $rowX + $tahunBadgeW / 2, $y + $badgeH / 2);
            if ($jenisText !== '') {
                $jx = $rowX + $tahunBadgeW + $badgeGap;
                self::frr($canvas, $jx, $y, $jx + $jenisBadgeW, $y + $badgeH, 10, $jenisBg);
                self::textCenter($canvas, $jenisText, $sizeBadge, $fontB, $jenisTxt, $jx + $jenisBadgeW / 2, $y + $badgeH / 2);
            }
        }

        return $canvas;
    }

    private static function frr($im, $x1, $y1, $x2, $y2, $r, $color) {
        $r = (int)min($r, floor(($x2 - $x1) / 2), floor(($y2 - $y1) / 2));
        if ($r < 1) { imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color); return; }
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }
}
