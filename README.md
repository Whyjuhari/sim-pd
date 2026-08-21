# SIM-PD BPVP Pangkep

SIM-PD adalah sistem pengelolaan perjalanan dinas berbasis Laravel 13 dan Bootstrap 5. Alur utama mencakup SPT kolektif, laporan kegiatan satu kali, maksimal dua foto dokumentasi, realisasi biaya, revisi, verifikasi, serta pembuatan dokumen PDF.

## Persyaratan

- PHP 8.3 atau lebih baru dengan ekstensi GD, Fileinfo, PDO MySQL, dan Zip.
- Composer 2 dan Node.js/npm.
- MySQL 8 atau MariaDB yang kompatibel.
- LibreOffice pada server yang akan membuat PDF.

## Instalasi lokal

```powershell
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Buka `http://127.0.0.1:8000`. Akun awal hasil seeder menggunakan username `admin` dan password `123456`; password tersebut wajib diganti saat login pertama.

## Role dan alur

- `admin`: statistik, pengguna, foto/tanda tangan, dan pemeriksaan kesehatan sistem.
- `officer`: membuat dan mengelola SPT kolektif selama seluruh anggota masih berstatus siap berjalan.
- `user`: mengisi laporan satu kali, mengirim realisasi, serta memperbaiki nominal jika dikembalikan.
- `verifikator`: menyetujui atau mengembalikan realisasi dengan catatan dan riwayat keputusan.
- `program`: monitoring anggaran serta pengelolaan tarif tujuan, batas biaya, dan MAK.
- `head`: monitoring read-only tanpa tahap persetujuan tambahan.

Status utama adalah `siap_jalan`, `pending_verif`, `rejected`, dan `approved`. Nilai tiket yang diajukan dan disetujui disimpan terpisah. Batas hotel dihitung per hari, sedangkan tarif dan batas biaya disimpan sebagai snapshot ketika SPT dibuat.

## Keamanan

- Login dibatasi lima kegagalan per kombinasi username dan IP per menit.
- Hash MD5 lama otomatis ditingkatkan ke hash Laravel setelah login berhasil.
- Pengguna baru memakai password awal `123456` dan wajib menggantinya.
- Perubahan password meminta password saat ini dan konfirmasi password baru.
- Logout hanya menerima `POST`.
- Admin terakhir tidak dapat dihapus atau diturunkan rolenya; admin juga tidak dapat menghapus akun sendiri.
- Foto profil dan tanda tangan divalidasi, didekode, dikodekan ulang, dan diberi nama UUID. Tanda tangan disimpan privat.
- Detail exception, path server, dan pesan internal generator dokumen hanya dicatat di log.

## URL aplikasi

Tampilan memakai route Laravel modern seperti `/admin`, `/officer`, `/pegawai`, `/verifikator`, `/program`, dan `/head`. URL `.php` lama tetap tersedia sebagai alias sementara, memakai middleware dan controller yang sama, lalu mengarahkan pengguna ke URL modern.

## Dokumen dan PDF

Atur LibreOffice di `.env`:

```dotenv
LIBREOFFICE_BINARY="C:\Program Files\LibreOffice\program\soffice.exe"
LIBREOFFICE_TIMEOUT=60
```

Template berada di `resources/documents`. File sementara dan hasil PDF berada di `storage/app/private/documents` dan dibersihkan setelah respons dikirim. Menu admin **Kesehatan Sistem** memeriksa database, storage privat, template, dan binary LibreOffice tanpa menampilkan path internal.

## Migrasi database lama

Selalu buat backup database dan file upload sebelum migrasi:

```powershell
php artisan migrate:status
php artisan migrate --force
```

Migrasi stabilisasi menambahkan snapshot tarif, nilai tiket disetujui, pembuat/verifikator, riwayat status, constraint unik, indeks pencarian, utf8mb4, dan master MAK tanpa menormalisasi ulang struktur transaksi lama.

## Pengujian

```powershell
php artisan test --compact
npm run build
php artisan view:cache
composer validate --no-check-publish
```

Suite mencakup autentikasi dan throttling, alias lama, matriks role/policy dokumen, keamanan upload, SPT kolektif, laporan satu kali, dua dokumentasi, snapshot keuangan, batas hotel per hari, penolakan–revisi–kirim ulang, verifikasi, serta generator DOCX/PDF.

## Produksi

- Gunakan `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, cookie aman, dan kredensial database khusus aplikasi.
- Jalankan `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`, `php artisan optimize`, dan `php artisan storage:link` bila diperlukan.
- Pastikan `storage` dan `bootstrap/cache` dapat ditulis, tetapi file privat tidak dapat diakses langsung dari web.
- Siapkan backup terjadwal untuk database dan file upload serta uji proses pemulihannya.
- Gunakan VPS/container yang menyediakan LibreOffice untuk produksi PDF. Shared hosting tanpa binary/proses sistem tetap dapat menjalankan pelaporan, tetapi tidak dapat menjamin fitur PDF.
- Untuk trafik PDF tinggi, pindahkan generasi dokumen ke worker queue pada host yang memiliki LibreOffice; alur sinkron saat ini dipertahankan agar unduhan yang sudah berjalan tidak berubah.
