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

## Tahap 2: Asisten AI dihapus dan tampilan baru

### Asisten AI dihapus
- Menu **Asisten AI** hilang dari sidebar, dari kartu pintasan dashboard, dan dari daftar hak akses di Kelola Admin.
- File dipindah ke folder cadangan `_cadangan_sebelum_tampilan_baru/` (ditutup dari akses web dan tidak ikut ke GitHub): `asisten_ai.php`, `export_ai_hasil.php`, `includes/ai_helper.php`, `includes/ai_analisis.php`. Di GitHub file-file itu dihapus.
- API key AI dihapus dari `config.php` lokal. Dengan begitu poin 5 di atas ("API key dipindah ke config.php") sudah tidak berlaku. Kalau di **hosting** masih ada key itu di `config.php`, hapus juga.
- Halaman **Dashboard Analitik** tidak memakai AI, jadi tetap ada.
- Catatan lama dari hak akses admin yang berisi `asisten_ai` di database tidak mengganggu, hanya diabaikan.

### Tampilan baru (mengikuti contoh yang kamu kirim)
- Satu tema **terang**. Tombol "Pilih Tema" dan tema gelap dihapus.
- **Sidebar putih**, label kelompok kecil, menu yang sedang dibuka berwarna **oranye** dengan garis oranye di tepi kiri.
- **Topbar putih**: judul halaman di kiri; lonceng, kolom cari, dan **bulatan hijau berisi inisial** di kanan. Nama, peran, dan tombol Keluar ada di menu bulatan itu.
- **Kartu statistik** di dashboard dengan ikon kotak berwarna dan angka besar.
- **Tabel** lebih lega, judul kolom kecil huruf besar, status berupa pil dengan titik warna.
- Font diganti **Plus Jakarta Sans**. Label huruf kecil monospasi serba kapital, emoji, dan efek bayangan gelap dihapus.
- Warna cadangan gelap di beberapa halaman (Bast, Progres, Kelola Permintaan, Input BAST, Opname) disesuaikan agar terbaca di tema terang.
- **Halaman login tidak diubah**: masih desain gelap dengan foto kantor.

### Tambahan daftar file (tahap 2)
| File | Status |
|---|---|
| `assets/style.css` | Diubah: tema terang, sidebar, topbar, kartu, tabel (bagian 5 di akhir file) |
| `includes/header.php` | Diubah: sidebar, topbar baru, menu pengguna, tanpa tema gelap dan menu AI |
| `includes/footer.php` | Diubah: warna jendela pratinjau, tutup menu pengguna, tanpa tombol tema |
| `includes/functions.php` | Diubah: menu AI dibuang dari daftar modul |
| `index.php` | Diubah: kartu statistik baru, tanpa emoji |
| `config.example.php` | Diubah: contoh API key AI dibuang |
| `data_barang.php`, `harga.php`, `katalog.php`, `analitik.php`, `barcode.php`, `penitipan.php`, `kirim_notifikasi.php`, `opname.php`, `bast.php`, `bidang.php`, `progres.php`, `riwayat_ambil.php`, `kelola_permintaan.php`, `includes/riwayat_helper.php` | Diubah kecil: font dan warna yang menyesuaikan tema terang |
| `asisten_ai.php`, `export_ai_hasil.php`, `includes/ai_helper.php`, `includes/ai_analisis.php` | Dihapus dari proyek |

### Pengujian tahap 2
- Dashboard dan halaman tabel diperiksa lewat screenshot di lebar komputer dan HP.
- Semua file PHP lolos pemeriksaan sintaks.
- **Belum diuji di komputermu:** halaman lain (Transaksi, Permintaan, BAST, Penitipan, dan lainnya) memakai gaya sendiri-sendiri. Kalau ada yang warnanya janggal, kirim screenshot-nya.

## Tahap 3: catatan aktivitas, gambar cepat, tema gelap/terang

### 1. Catatan aktivitas semua akun
- Setiap perubahan data kini tercatat: siapa (akun), melakukan apa, kapan. Tabel `activity_log` dibuat otomatis saat pertama dipakai.
- Yang dicatat: **barang masuk/keluar** (tambah, ubah, hapus), data barang, harga, stok minimum, permintaan bidang, penitipan, BAST, akun admin, bidang, notifikasi, oper ke bidang.
- Panel **Aktivitas** di dashboard menampilkan 8 catatan terbaru sesuai periode yang dipilih, lengkap dengan nama akun dan jam.
- Halaman baru **Riwayat Aktivitas** (menu Administrasi, hanya super admin) dengan filter akun/jenis dan halaman-halaman.
- Catatan lama sebelum tahap ini tidak ada; selama belum ada catatan baru, panel dashboard masih menampilkan transaksi terakhir seperti sebelumnya.

### 2. Gambar lebih cepat
- Foto barang di Katalog, Data Barang, dan Harga memakai thumbnail kecil, dimuat bertahap (lazy), dan disimpan di cache browser.
- Gambar latar login dikecilkan dari 10 MB menjadi sekitar 370 KB (aslinya dicadangkan).
- `.htaccess`: cache gambar 30 hari dan kompresi teks.

### 3. Tema terang & gelap
- Tombol matahari/bulan di bar atas. Pilihan disimpan di browser, bawaan terang, dan diterapkan sebelum halaman tampil (tanpa kedipan).
- Warna dipindah ke variabel sehingga dua tema konsisten. Cetak (barcode, BAST) selalu terang.

### File tahap 3
Baru: `aktivitas.php`, `includes/activity_log.php`. Diubah: `index.php`, `includes/bootstrap.php`, `header.php`, `footer.php`, `functions.php`, `assets/style.css`, `assets/kantor.jpg`, `.htaccess`, `login.php`, `transaksi.php`, `data_barang.php`, `harga.php`, `realisasi.php`, `kelola_permintaan.php`, `permintaan.php`, `penitipan.php`, `kelola_admin.php`, `kelola_bidang.php`, `kirim_notifikasi.php`, `oper_bidang.php`, `bast.php`, `input_bast.php`, `katalog.php`, dan beberapa halaman dengan penyesuaian warna.

### Pengujian tahap 3
- Pencatatan diuji dengan database uji (beberapa akun, masuk/keluar/hapus). Screenshot dashboard, tabel, dan Riwayat Aktivitas di kedua tema, komputer dan HP. Semua PHP lolos sintaks.
- Belum diuji di MySQL aslimu: coba catat satu barang masuk lalu lihat dashboard.

### 4. Notifikasi waktu-nyata & tampilan admin bidang
- Saat bidang mengajukan permintaan ATK, admin/super admin yang punya akses "Permintaan ATK Bidang" langsung mendapat pop-up di pojok kanan atas (plus bunyi singkat), tanpa muat ulang. Halaman dicek tiap 5 detik. Lencana merah di menu menunjukkan jumlah permintaan yang menunggu, dan judul tab ikut berubah, misalnya "(2) Pendataan ATK".
- Admin bidang: banner judul berwarna, kartu ringkasan (total/menunggu/diproses/selesai), dan kartu permintaan dengan alur status Diajukan → Diproses → Selesai.
- File: `notifikasi.php` (aksi `live`), `includes/header.php`, `progres.php`, `permintaan.php`, `assets/style.css`.

### 5. Kalender pengambilan, foto di permintaan, pengingat BAST
- Menu baru **Kalender Pengambilan** untuk admin bidang, admin gudang (yang punya akses Permintaan ATK Bidang), dan super admin. Tiap tanggal diberi titik warna: hijau selesai, merah belum upload BAST, biru diproses, kuning menunggu, abu-abu ditolak.
- Klik tanggal: terlihat permintaan hari itu, barangnya (dengan foto dan jumlah), status, dan apakah BAST sudah diunggah.
- Admin bidang hanya melihat bidangnya. Admin gudang dan super admin melihat semua bidang, bisa disaring per bidang, dan ada rekap per bidang (total, belum selesai, belum upload BAST).
- Pengingat BAST untuk admin bidang: spanduk merah di semua halaman dan pop-up tiap 10 menit selama permintaan berstatus Diproses/Selesai belum punya BAST.
- Halaman Permintaan ATK Bidang (admin) dan Input BAST (bidang) sekarang menampilkan foto serta rincian barang.
- File: `kalender.php` (baru), `includes/header.php`, `includes/functions.php`, `kelola_permintaan.php`, `input_bast.php`, `assets/style.css`.

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

## Tahap 3 – Bagian 6: Penitipan ATK dirapikan
- Tabel diganti kartu per titipan: penitip + kontak, status, daftar barang dalam 2 kolom (maks 6, sisanya "Lihat barang lainnya"), keterangan, foto/berkas, info pengambilan.
- Ringkasan jadi 3 kartu statistik; tombol aksi rapi (Tandai Diambil / Ubah / Hapus).
- Form Catat/Ubah titipan dibuat 3 langkah (Data penitip, Barang, Dokumentasi) dengan header berwarna dan area unggah foto.

## Perbaikan keamanan (branch uji-coba)
- Semua tombol Hapus yang berupa link kini membawa token CSRF dan dicek di server (`csrf_check_get`), jadi tidak bisa dipicu dari situs lain.
- Semua upload file kini dicek dari ISI file (`sanitize_uploads` di bootstrap): hanya JPG/PNG/WebP/PDF asli; file menyamar (mis. .php) ditolak.
- Header `X-Content-Type-Options: nosniff` ditambah di penayang file Penitipan dan BAST.

## Keamanan login sampai dashboard (branch uji-coba)
- Cookie sesi sekarang HttpOnly + SameSite + (Secure bila HTTPS) dan diatur SEBELUM sesi mulai (sebelumnya terlambat, tidak berefek). Mode strict session aktif.
- Header keamanan (nosniff, frame, referrer, HSTS bila HTTPS) kini berlaku di semua halaman, bukan hanya login.
- Keluar otomatis setelah 8 jam tidak aktif. Data akun dicek ulang ke database tiap 60 detik: akun dihapus langsung terlempar, izin yang dicabut langsung berlaku.
- Logout kini menghapus token "ingat saya" dan cookie-nya (sebelumnya setelah logout bisa otomatis masuk lagi lewat halaman login).
- Hash pembanding waktu di login diperbaiki (sebelumnya tidak valid sehingga username yang ada/tidak ada bisa ditebak dari waktu respons).
- .htaccess: blokir akses browser ke folder data/, logs/, includes/, _cadangan_*, .git dan file .sql .bak .log .md error_log config*.php.

## Fitur baru (branch uji-coba)
- **Backup Database** (khusus superadmin, menu Administrasi): unduh semua tabel sebagai .sql (dibuat lewat PHP, tanpa mysqldump) dan unduh foto & berkas sebagai .zip. Dilindungi token CSRF dan tercatat di Riwayat Aktivitas.
- **Peringatan stok menipis**: panel "Perlu segera dibeli" di dashboard (urut paling kritis, bar sisa stok, label Habis), lencana merah di menu "Realisasi & Limit Stok". Batas stok tetap diatur di Realisasi & Limit Stok.
- **Permintaan berulang**: tombol "Ajukan lagi" di Progres ATK mengisi form permintaan baru dengan barang & jumlah dari permintaan lama (barang yang sudah dihapus dilewati; hanya permintaan milik akun sendiri).

## Perkiraan habis (branch uji-coba)
- Panel "Perlu segera dibeli" di dashboard kini menampilkan perkiraan kapan barang habis ("Habis ±10 hari lagi (30 Nov)") dan rata-rata pemakaian per minggu, dihitung dari barang keluar 90 hari terakhir (minimal jendela 14 hari).
- Barang yang diperkirakan habis dalam 30 hari ikut tampil walau belum di bawah batas minimum; yang sisa ≤7 hari berwarna merah. Barang yang belum pernah keluar tidak punya perkiraan.

## Panel stok bisa disembunyikan & riwayat pengambilan dipisah (branch uji-coba)
- Dashboard: panel "Perlu segera dibeli" punya tombol Sembunyikan/Tampilkan; pilihannya diingat di browser.
- Bidang Pengambilan (admin) dan Riwayat Pengambilan (akun bidang): riwayat tiap bidang dipisah jadi (1) "Diminta lewat admin bidang" (permintaan selesai + penanggung jawab) dan (2) "Diambil langsung di gudang" dikelompokkan per nama pengambil (kolom penerima). Tiap bagian/orang punya tombol "Pilih bagian ini/orang ini", jadi admin bidang bebas memilih barang mana yang masuk BAST.
- Kiriman dari admin kini menampilkan kolom Pengambil. BAST tetap bisa dibuat superadmin (dari Bidang Pengambilan) dan admin bidang (dari Riwayat Pengambilan).

## Tampilan HP + Pengaman isi halaman (CSP)
- **CSP** ditambah di `includes/bootstrap.php`: script/gambar/font hanya boleh dari situs sendiri (+ Google Fonts untuk huruf). Situs luar lain diblokir browser.
- Chart.js dan html5-qrcode sekarang dimuat dari folder `assets/` (tidak lagi dari CDN luar): `assets/chart.umd.js`, `assets/html5-qrcode.min.js`.
- **HP**: semua halaman admin dicek di lebar 390px, tidak ada yang meluber ke samping. Ditambah aturan di akhir `assets/style.css`: kartu barang & statistik 2 kolom, judul bar atas tidak terpotong, tombol lebih besar, input 16px (tidak zoom di iPhone), modal muat di layar.
