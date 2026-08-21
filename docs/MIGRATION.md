# Catatan Migrasi PHP Native ke Laravel 13

## Pemetaan fitur

| Fitur lama | Implementasi Laravel |
|---|---|
| `cek_login.php` / session manual | `AuthController` dan guard session Laravel |
| `require_role()` | middleware `role` (`EnsureRole`) |
| query dashboard procedural | Eloquent pada `DashboardController` |
| proses pegawai terpisah | `EmployeeController` |
| `proses_tambah_spt.php` | `TravelOrderController` + `TravelCostCalculator` + transaksi DB |
| `proses_lapor.php` | `RealizationController` |
| `proses_approve.php` | `VerificationController` |
| beberapa jalur cetak | `DocumentController` dan service generator dokumen |
| template di folder top-level | `resources/documents` |

URL lama dipertahankan di `routes/web.php`, sehingga tautan seperti `dashboard_user.php`, `form_verifikasi.php`, dan `documents/perjadin.php` tetap bekerja.

## Kompatibilitas database

Nama tabel dan kolom tidak diganti. Model Laravel memakai:

- `users`
- `transaksi_perjadin`
- `master_tarif`
- `settings`

Tabel lama hanya memiliki `created_at` dan tidak memiliki `updated_at`; karena itu model menonaktifkan timestamp otomatis. Nilai tiket yang disetujui tetap disimpan pada `biaya_tiket_real`, sama seperti proses lama, karena belum tersedia kolom `biaya_tiket_approved`.

## Perbaikan tanpa mengubah alur

- Validasi request dilakukan di server.
- Perhitungan lama perjalanan dilakukan ulang dari tanggal, bukan mempercayai input browser.
- Pembuatan SPT kolektif dibungkus transaksi database.
- Pegawai hanya dapat mengirim realisasi miliknya sendiri.
- Password MD5 lama didukung; password baru memakai hash Laravel.
- Query memakai Eloquent/query binding.
- Konversi LibreOffice memakai proses dengan timeout, profil sementara unik di `storage`, dan proses CLI internal saat dipanggil melalui web Windows.
- Jalur cetak HTML lama disatukan menjadi PDF berbasis template.
- Nomor contoh dan tujuan contoh dari template tidak lagi bocor ke dokumen hasil.
- Sheet Lumpsum tidak lagi mencetak blok Rampung.

## Hal yang sengaja belum diubah

- Nama role dan status.
- Rumus estimasi serta total pencairan.
- Nama tabel/kolom database.
- Isi tekstual tetap yang berada di dalam template resmi. Jika tahun anggaran atau pejabat pada teks tetap berubah, template DOCX/XLSX perlu diperbarui oleh pemilik dokumen resmi.
