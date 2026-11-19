# Laporan Perubahan Aplikasi ATK

Tanggal: 19 November 2026
Folder: `C:\a\atk`
Repo GitHub: https://github.com/gungrsyworkakun-tech/atk-update

## Masalah awal

Setelah aplikasi dipindah dari hosting ke komputer sendiri (Docker, port 8084), gambar barcode tidak tampil.

**Penyebab:** gambar barcode dibuat langsung oleh PHP memakai ekstensi **GD**. Container `web-php` belum punya GD, jadi `barcode_image.php` hanya membalas tulisan "Ekstensi GD belum aktif di server PHP."

## Yang dikerjakan

### 1. GD diaktifkan (bukan perubahan file)

GD dipasang ke container `web-php` lewat perintah `docker exec`.

> **Penting:** cara ini hilang kalau container dibuat ulang. Supaya permanen, tambahkan langkah pasang GD ke `Dockerfile` (lihat bagian "Yang masih perlu kamu lakukan").

### 2. Gambar rusak karena PHP 8.5

PHP 8.5 menampilkan peringatan "Deprecated" untuk fungsi `imagedestroy()`. Peringatan itu tercetak di depan data gambar sehingga gambar rusak.

### 3. Tampilan barcode diubah sesuai permintaan

- Tulisan **Stok** dihapus dari gambar barcode dan dari kartu di halaman Barcode Barang.
- Tulisan diganti memakai font tebal yang jelas (DejaVu Sans Bold). Nama barang bisa dua baris.
- QR diperbesar, ukuran gambar sekarang sekitar 580 x 900 piksel.
- Cetak semua barcode: **4 barcode per kertas A4** (2 kolom x 2 baris), gambar besar.

### 4. Gambar tidak lagi muncul satu per satu

Dua penyebab sudah diperbaiki:
- **Sesi login mengunci permintaan.** Setiap gambar menunggu gambar sebelumnya selesai. Sekarang kunci dilepas (`session_write_close()`), jadi gambar dimuat bersamaan.
- **Gambar dibuat ulang setiap halaman dibuka.** Sekarang hasilnya disimpan di folder `uploads/barcode-cache/`. Buka kedua kali dan seterusnya jauh lebih cepat, dan browser juga menyimpan gambarnya (cache 1 hari).

Cache otomatis diperbarui kalau nama, jenis, atau tahun barang berubah. Folder cache aman dihapus kapan saja, nanti dibuat lagi sendiri.

### 5. Keamanan sebelum ke GitHub

Di `includes/ai_helper.php` ada **API key AI yang tertulis langsung di kode**. Key itu dipindah ke `config.php`, yang tidak ikut ke GitHub.

## Daftar file

| File | Status | Isi perubahan |
|---|---|---|
| `includes/barcode.php` | Diubah (ditulis ulang) | Tanpa stok, font TTF, QR lebih besar, nama barang 2 baris |
| `barcode_image.php` | Diubah | Matikan tampilan error, hapus `imagedestroy`, logo lebih besar, lepas kunci sesi, cache gambar |
| `barcode.php` | Diubah | Hapus baris Stok di kartu, jendela cetak satu barcode lebih besar |
| `assets/style.css` | Diubah | Aturan cetak 4 per kertas A4, gambar tidak lagi dibuat kotak-kotak |
| `assets/fonts/DejaVuSans-Bold.ttf` | Baru | Font untuk nama dan label |
| `assets/fonts/DejaVuSansMono-Bold.ttf` | Baru | Font untuk kode barang |
| `includes/ai_helper.php` | Diubah | API key tidak lagi tertulis di kode |
| `config.php` | Diubah (hanya di komputermu) | Menyimpan API key. Tidak ada di GitHub |
| `config.example.php` | Baru | Contoh pengaturan, tanpa password |
| `.gitignore` | Baru | Daftar file yang tidak boleh ke GitHub |
| `LAPORAN_PERUBAHAN.md` | Baru | Laporan ini |

File cadangan (sebelum diubah) ada di komputermu dengan akhiran `.bak`: `barcode_image.php.bak`, `barcode.php.bak`, `includes/barcode.php.bak`, `includes/ai_helper.php.bak`, `assets/style.css.bak`. Hapus kalau semuanya sudah berjalan baik. (`.bak` tidak ikut ke GitHub.)

## Yang tidak ikut ke GitHub

`config.php`, `error_log`, folder `logs`, `data`, `uploads` (termasuk foto barang dan cache barcode), dump SQL data asli, dan file `.bak`.

## Pengujian

- Pembuatan gambar, cache, jawaban 304 (browser sudah punya), dan unduh PNG diuji di server uji dengan PHP 8.3.
- Semua file PHP lolos pemeriksaan sintaks.
- **Belum diuji:** tampilan cetak A4 di browser dan kecepatan di komputermu. Mohon dicoba.

## Yang masih perlu kamu lakukan

1. **Tambahkan GD ke `Dockerfile`** supaya tidak hilang saat container dibuat ulang:
   ```dockerfile
   RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
       && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
       && docker-php-ext-install gd pdo_mysql
   ```
   Pakai `--with-freetype` supaya tulisan barcode memakai font TTF. Tanpa itu tulisan jatuh ke font bawaan yang kecil.
2. **Ganti API key AI** kalau kode lama pernah bisa dilihat orang lain.
3. Coba "Cetak Semua Barcode" dan pastikan pas 4 per kertas.
