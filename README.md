# Absensi Sosialisasi HPV DWP Kota Padang

Formulir mencatat nama lengkap, utusan OPD, nomor WhatsApp, dan nama suami di MySQL. Setelah data tersimpan, peserta dapat mengunduh sertifikat PNG yang dibuat dari `sertifikat.jpeg`. Pratinjau dan unduhan personal tersedia di sesi browser yang mengirim formulir.

## Instalasi lokal lewat browser

1. Siapkan PHP 8.1 atau lebih baru dengan `pdo_mysql`, `mbstring`, dan `gd` dengan dukungan JPEG, PNG, dan FreeType. Jalankan MySQL atau MariaDB.
2. Jalankan perintah ini dari folder proyek.

   ```powershell
   php -S 127.0.0.1:8000 -t public
   ```

3. Buka [installer](http://127.0.0.1:8000/install.php), isi pengaturan MySQL, lalu klik **Jalankan instalasi**. Installer membuat database bila belum ada dan akun punya izin, menyiapkan tabel dari `schema.sql`, lalu menyimpan `config.local.php`.
4. Klik **Buka formulir absensi** setelah instalasi selesai. Installer terkunci selama `config.local.php` tersedia.

## Instalasi di hosting

1. Arahkan document root ke `public/` dan aktifkan HTTPS. Folder proyek di atas `public/` harus dapat ditulis oleh PHP saat instalasi.
2. Buat database dan akun MySQL lewat panel hosting. Akun perlu izin `SELECT`, `INSERT`, dan `UPDATE` untuk tabel absensi, serta `CREATE` jika tabel belum tersedia.
3. Buat kunci instalasi menggunakan perintah berikut. Simpan hasilnya di `install.key` pada folder proyek di atas `public/`.

   ```powershell
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```

4. Buka `/install.php` melalui HTTPS, isi pengaturan MySQL dan kunci instalasi, lalu jalankan instalasi. Hapus `install.key` setelah selesai.

## Instalasi manual

Impor `schema.sql` ke database `utf8mb4` melalui phpMyAdmin atau klien MySQL. Salin `config.example.php` menjadi `config.local.php`, lalu isi pengaturan MySQL. Akun aplikasi memerlukan izin `SELECT`, `INSERT`, dan `UPDATE` pada tabel `absensi`.

Installer dan konfigurasi manual mendukung path absolut ke font `.ttf` bila diperlukan. Aplikasi mencari Georgia Bold di Windows dan Liberation Serif Bold atau DejaVu Serif Bold di Linux.

## Rekap kehadiran

Panel `/admin.php` menampilkan total kehadiran, jumlah utusan OPD, rekap per OPD, dan daftar peserta. Pilih rentang tanggal dan OPD bila diperlukan, lalu klik **Unduh CSV** untuk membuat laporan lengkap sesuai filter. Tanggal dan waktu mengikuti WIB. CSV menggunakan UTF-8 dan pemisah titik koma.

### Mengaktifkan akun admin

1. Di Ubuntu, jalankan perintah berikut dari Bash. Ketik kata sandi admin yang kuat sepanjang 12–72 byte saat diminta.

   ```bash
   read -rsp 'Kata sandi admin: ' ADMIN_PASSWORD
   printf '\n'
   printf '%s' "$ADMIN_PASSWORD" | php -r '$p = stream_get_contents(STDIN); if (strlen($p) < 12 || strlen($p) > 72) { fwrite(STDERR, "Gunakan 12–72 byte.\n"); exit(1); } echo password_hash($p, PASSWORD_BCRYPT), PHP_EOL;'
   unset ADMIN_PASSWORD
   ```

2. Tambahkan nama pengguna dan hash dari perintah tadi ke array di `config.local.php`.

   ```php
   'admin_username' => 'admin',
   'admin_password_hash' => 'GANTI_DENGAN_HASH',
   ```

3. Jika vhost Nginx sudah aktif, tambahkan `admin` pada pola `location` untuk halaman PHP di blok HTTPS. Vhost yang sudah dipasang Certbot bisa diedit langsung. Contoh terbaru ada di `deploy/nginx/dw.pdg.web.id.conf`.

   ```nginx
   location ~ ^/(index|install|admin)\.php$ {
   ```

   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   ```

4. Buka `/admin.php` melalui HTTPS dan masuk dengan akun tersebut. Panel membaca tabel `absensi` yang sudah ada.

### Rekap langsung dari database

Buka tabel `absensi` di phpMyAdmin atau jalankan kueri berikut di database `dwp_absensi`.

```sql
SELECT nama_lengkap, utusan_opd, no_whatsapp, nama_suami, waktu_hadir
FROM absensi
ORDER BY waktu_hadir, id;
```
