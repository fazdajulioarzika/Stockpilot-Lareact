# StockPilot

Sistem manajemen persediaan multi-gudang untuk distributor dan perusahaan menengah. StockPilot mencatat saldo stok per produk dan gudang, penerimaan dan pengeluaran barang, transfer antar-gudang, purchase order, stock opname, dan riwayat mutasi yang bisa ditelusuri sampai ke dokumen sumber dan pelaksananya.

Prinsip utamanya: **akurasi dulu**. Saldo dan ledger berubah dalam satu transaksi database, stok tidak pernah negatif, dan setiap perubahan bisa diaudit.

## Fitur

-   **Master data:** produk, kategori, satuan, supplier, gudang. Data yang sudah dipakai transaksi dinonaktifkan, bukan dihapus.
-   **Stok per gudang** dengan indikator stok rendah, serta **ledger mutasi** (filter tanggal, produk, gudang, tipe).
-   **Barang Masuk dan Barang Keluar** dengan alur draft, posting, dan konfirmasi.
-   **Purchase Order (opsional):** approval dengan pemisahan tugas, penerimaan parsial, penutupan PO, dan pembukaan kembali saat penerimaan dibalik.
-   **Transfer antar-gudang:** approval, kirim, stok dalam perjalanan, terima sebagian, dan retur sisa tanpa hitungan ganda.
-   **Stock opname:** snapshot saldo, kunci gudang selama penghitungan, selisih beralasan, persetujuan, dan penolakan.
-   **Pembalikan dokumen** yang sudah diposting, lewat mutasi baru yang terlacak (dokumen asli tidak diubah).
-   **Valuasi rata-rata tertimbang** per produk dan gudang, serta laporan nilai persediaan.
-   **Laporan periode** (stok awal, masuk, keluar, akhir), tren harian atau bulanan, dan **ekspor CSV** (format Excel Indonesia dan internasional).
-   **Dashboard** yang dibatasi gudang pengguna, **audit log**, dan **manajemen pengguna** dengan penugasan gudang.

## Peran

| Peran           | Ringkasan hak akses                                                                       |
| --------------- | ----------------------------------------------------------------------------------------- |
| Admin           | Semua gudang, kelola master data dan pengguna, lihat nilai persediaan dan audit log       |
| Manager         | Semua gudang, setujui PO/transfer/opname, balik dokumen, lihat nilai dan audit log        |
| Warehouse Staff | Hanya gudang yang ditugaskan, buat dan posting transaksi, tanpa akses ke nilai persediaan |

Otorisasi dijaga di backend (middleware, Gate, dan Policy). Menyembunyikan tombol di React bukan kontrol keamanan.

## Aturan stok yang selalu benar

1. Saldo per produk dan gudang tidak boleh negatif (dijaga di aplikasi dan constraint database).
2. Draft tidak mengubah saldo. Hanya posting, kirim, terima, retur, dan penyesuaian yang mengubahnya.
3. Perubahan saldo dan pembuatan ledger terjadi dalam **satu transaksi database**.
4. Dokumen yang sudah diposting tidak bisa diubah atau dihapus. Koreksi dilakukan lewat pembalikan.
5. Ledger dan audit log bersifat **append-only**.
6. Saldo dan nilai stok selalu sama dengan jumlah ledger-nya (bisa direkonsiliasi).
7. Posting berulang tidak menggandakan mutasi (kunci baris dan pengecekan status).
8. Barang dalam perjalanan dihitung dari `dikirim - diterima - diretur`, tidak disimpan sebagai saldo gudang.

Satu-satunya pintu yang boleh mengubah saldo adalah `app/Services/StockService.php`.

## Teknologi

-   **Backend:** Laravel 13, PHP 8.3 atau lebih baru
-   **Frontend:** React, TypeScript, Inertia.js, Tailwind CSS, shadcn/ui
-   **Database:** MySQL 8.0.16 atau lebih baru (constraint `CHECK` dan row-level locking)
-   **Pengujian:** Pest
-   **Ekstensi PHP wajib:** `bcmath` (aritmetika desimal presisi), `pdo_mysql`, `mbstring`

## Arsitektur singkat

```
React (tampilan)  <-- Inertia -->  Route -> Middleware -> Form Request -> Controller -> Policy
                                                                              |
                                                          Action (satu transaksi database)
                                                                              |
                                              StockService -> inventory_stocks + stock_movements
                                                                              |
                                                                          AuditLog
```

Aplikasi berupa monolit Laravel tanpa REST API terpisah.

```
app/
  Actions/Inventory, Actions/Purchasing   logika bisnis (posting, kirim, terima, opname, PO)
  Services/StockService.php               satu-satunya pengubah saldo stok
  Policies/                               otorisasi per aksi dan resource
  Support/                                Money, Period, CsvExport
  Enums/ Models/ Http/ Console/
resources/js/
  pages/ components/                      halaman Inertia dan komponen UI
tests/
  Feature/Inventory/                      pengujian perilaku end-to-end
  Concurrency/ Support/                   tes proses serentak sungguhan
```

## Menjalankan secara lokal

Prasyarat: PHP 8.3+ (dengan `bcmath`), Composer, Node.js 20+, dan MySQL 8.0.16+.

```bash
git clone <url-repositori> stockpilot
cd stockpilot

composer install
cp .env.example .env
php artisan key:generate
npm install
```

Buat database dan atur kredensialnya di `.env`:

```sql
CREATE DATABASE stockpilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```env
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

DB_CONNECTION=mysql
DB_DATABASE=stockpilot
DB_USERNAME=root
DB_PASSWORD=

TRANSFER_REQUIRES_APPROVAL=true
PO_REQUIRES_APPROVAL=true
```

Lalu:

```bash
php artisan migrate
php artisan db:seed
composer run dev
```

Aplikasi berjalan di `http://localhost:8000`.

### Akun demo

`db:seed` membuat akun berikut (hanya di luar production), semuanya berpassword `password`:

| Email                     | Peran                    |
| ------------------------- | ------------------------ |
| `admin@stockpilot.test`   | Admin                    |
| `manager@stockpilot.test` | Manager                  |
| `staff@stockpilot.test`   | Warehouse Staff (GD-JKT) |

**Ganti password sebelum aplikasi dibuka ke internet.**

### Data demo 3 bulan

Untuk mengisi riwayat operasional yang tampak nyata (tiga gudang, puluhan produk, PO, transfer, dan opname):

```bash
php artisan stockpilot:reset-demo
```

Perintah ini **menghapus** seluruh transaksi dan master data (akun pengguna dipertahankan), meminta konfirmasi dua langkah, dan menolak berjalan di production. Buat backup dulu.

Untuk mengisi biaya pada stok yang sudah ada:

```bash
php artisan stockpilot:set-costs biaya.csv      # CSV berkolom sku,unit_cost
php artisan stockpilot:set-costs --demo         # biaya contoh (tidak untuk production)
```

## Pengujian

Tes memakai MySQL, bukan SQLite, karena constraint `CHECK` dan locking baris perlu dibuktikan di database yang sama dengan production. Buat database `stockpilot_test` dan arahkan `phpunit.xml` ke sana.

```bash
php artisan test                        # seluruh suite
php artisan test --group=concurrency    # tes proses serentak
```

Tes konkurensi menjalankan beberapa proses PHP terpisah dengan koneksi database sendiri, lalu memastikan stok tidak terjual melebihi saldo, posting ganda hanya berhasil sekali, dan urutan kunci tidak menimbulkan deadlock. Tes ini hanya mau berjalan di database yang namanya memuat kata `test`.

## Keputusan desain

| Topik             | Keputusan                                                                                                                                                                |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Valuasi           | Rata-rata tertimbang per produk dan gudang. Barang keluar pada rata-rata saat itu, transfer membawa nilai asalnya, pembalikan barang keluar mengembalikan nilai aslinya. |
| Pembulatan        | Empat desimal, setengah ke atas, dengan bcmath (bukan float).                                                                                                            |
| Koreksi dokumen   | Pembalikan lewat mutasi baru. Dokumen asli tetap tersimpan.                                                                                                              |
| Opname            | Mengunci gudang selama penghitungan, dan yang mengajukan tidak boleh menyetujui hasilnya sendiri.                                                                        |
| Akun              | Tidak dihapus, hanya dinonaktifkan, supaya riwayat tetap utuh. Selalu ada minimal satu Admin aktif.                                                                      |
| Penomoran dokumen | Diturunkan dari ID, sehingga unik tanpa race condition.                                                                                                                  |

## Deployment

-   Atur `APP_ENV=production` dan `APP_DEBUG=false`, gunakan HTTPS, dan pakai `SESSION_DRIVER=database` (fitur pemutusan sesi saat akun dinonaktifkan membutuhkannya).
-   Tutup pendaftaran publik (`Features::registration()` di `config/fortify.php`), karena akun dibuat Admin lewat menu Users.
-   Jalankan `php artisan config:cache route:cache` di server yang mendukung SSH. Jangan menjalankan seeder demo di production.
-   Jadwalkan backup database berkala dan uji prosedur pemulihannya.
-   Server harus memiliki ekstensi `bcmath`, dan zona waktu aplikasi harus konsisten dengan data yang diimpor.

## Di luar lingkup saat ini

Serial number dan lot/batch, konversi multi-satuan, barcode dan lokasi rak, notifikasi email, approval berjenjang, multi-tenant, dan integrasi ERP atau marketplace.
