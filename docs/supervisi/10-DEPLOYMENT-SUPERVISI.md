# Modul Supervisi — Pemasangan, Operasi & Pemulihan

> Untuk tim teknis sekolah. Latihan pemulihan pada §6 **dijalankan sungguhan**
> pada basis data contoh; angkanya hasil pengukuran, bukan perkiraan.

## 1. Kebutuhan

| Hal | Ketentuan |
|---|---|
| PHP | 8.3+ dengan ekstensi `dom`, `mbstring`, `pdo`, `zip` (untuk XLSX), `gd` (untuk QR pada PDF — tanpa `gd` dokumen tetap tercetak, hanya tanpa QR) |
| Basis data | SQLite (bawaan pengembangan) atau MySQL 8 / PostgreSQL 14+ (disarankan produksi) |
| Penyimpanan | Berkas dokumen: 1 PDF ≈ 0,4–0,9 MB, CSV/XLSX < 0,1 MB. Hitung 20 dokumen/bulan ≈ 10 MB/bulan |
| Penjadwal | `php artisan schedule:work` (atau cron `* * * * * php artisan schedule:run`) |
| Antrean | Tidak wajib; perintah pengingat & kesehatan berjalan sinkron dan cepat |

## 2. Pemasangan baru

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate

# basis data (pilih salah satu)
touch database/database.sqlite              # SQLite
# atau isi DB_CONNECTION=mysql DB_DATABASE=... di .env

php artisan migrate --force                 # 93 tabel modul `sup_*` + tabel aplikasi
php artisan db:seed --force                 # peran, izin, 22 instrumen bawaan (280 indikator)
php artisan storage:link
php artisan optimize
```

Pengukuran nyata pada sandbox (basis data kosong): `migrate` **3,8 detik**
(93 tabel `sup_*`), `db:seed` **53 detik** (termasuk seeder demo modul lain;
seeder supervisi sendiri ≈ 6 detik untuk 22 instrumen/280 indikator).

Izin yang dibuat modul: `supervisi.*` (42 izin) dan dipetakan ke peran
`super-admin`, `admin-sekolah`, `kepala-sekolah`, `kepala-unit`, `auditor`,
`guru`, `staf`. Seeder aman dijalankan ulang (memakai `findOrCreate`).

## 3. Penjadwalan

| Perintah | Jadwal | Sifat |
|---|---|---|
| `supervisi:remind` | harian 06.30 | Mengirim pengingat (kegiatan H-3, kegiatan belum final, RTL jatuh tempo/terlambat, coaching, tinjauan PIP). **Idempoten** per hari |
| `supervisi:health` | Senin 07.00 | Memeriksa integritas, **hanya melapor**; `--fail-on-issue` untuk pemantauan otomatis, `--json` untuk dikirim ke sistem pemantauan |
| `supervisi:prune` | tanggal 1, 07.15 | Melaporkan berkas ekspor melewati retensi (bawaan 24 bulan). **Tanpa `--force` tidak menghapus apa pun** |

Pemeriksaan berkala yang disarankan:

```bash
php artisan supervisi:health --json | jq '.status, .summary.needs_attention'   # harian
php artisan supervisi:health --fail-on-issue                                   # gerbang rilis
php artisan supervisi:prune                                                    # laporan bulanan
php artisan supervisi:prune --force --months=36                                # bersih-bersih terjadwal manual
```

## 4. Konfigurasi

`config/supervisi.php`:

```php
'rate_limits' => [
    'module' => 300,          // jaring pengaman seluruh endpoint (per pengguna/menit)
    'public_verify' => 30,    // verifikasi dokumen publik (per IP)
    'report_generate' => 20,
    'analytics' => 60,
    'answer_save' => 240,
    'evidence_upload' => 30,
    'visit_finalize' => 20,
    'search' => 90,
],
'report_retention_months' => 24,
```

Pengaturan sekolah (tanpa deploy, melalui API `PUT /supervisi/settings`):
frekuensi supervisi minimum per jenis pegawai, ambang komentar wajib,
kewajiban wawancara pasca-observasi & RTL sebelum finalisasi, jendela sanggahan,
bobot sumber penilaian 360°, ambang kuadran SKP, dan
**`peer_summary_enabled`** (bawaan `false` — guru hanya melihat datanya
sendiri; lihat panduan privasi §11.6 blueprint).

> Catatan penting: guru/staf memang memegang `supervisi.report.view` agar dapat
> membaca hasil sendiri. Cakupan baris ditentukan `DataScopeService`, bukan izin.
> Jangan mencabut izin itu untuk "menutup" akses — yang benar adalah membiarkan
> cakupan bekerja, dan mengaktifkan `peer_summary_enabled` hanya bila sekolah
> memang ingin ringkasan agregat terbuka bagi rekan sejawat.

## 5. Pemantauan rutin

| Yang dipantau | Cara |
|---|---|
| Kesehatan data | `supervisi:health --json` (jadwal mingguan) atau `GET /api/v1/supervisi/health` |
| Kegiatan terbuka & RTL terlambat | Ringkasan perintah `supervisi:health`; kartu pada dasbor analitik |
| Berkas dokumen hilang | Pemeriksaan `report_file_missing` pada laporan kesehatan — baris riwayat tetap ada, jadi dokumen yang beredar akan tampak **tidak sah** saat diverifikasi |
| Batas laju kena | Balasan `429` pada log web server; sesuaikan `config/supervisi.php` bila sekolah besar |
| Ukuran penyimpanan | `du -sh storage/app/private/supervisi` (atau disk yang dipakai) |

## 6. Pencadangan & pemulihan (hasil latihan nyata)

Latihan dijalankan pada basis data contoh berisi **241 kegiatan, 2.887 jawaban,
244 butir RTL, 2 dokumen, 60 baris audit** (ukuran 8,5 MB).

```bash
# 1) Cadangan aman saat aplikasi melayani permintaan (SQLite .backup)
sqlite3 database/database.sqlite ".backup '/backup/live-$(date +%F-%H%M).sqlite'"

# 2) Untuk MySQL/PostgreSQL
# mysqldump --single-transaction --routines --triggers db > backup.sql
# pg_dump -Fc -f backup.dump dbname
```

| Langkah | Hasil terukur |
|---|---|
| Cadangan | **73 ms**, 8,5 MB, `sha256=7acb854aa237…` |
| Uji integritas berkas cadangan | `PRAGMA integrity_check` → `ok` |
| Musibah pada salinan (tabel jawaban di-`DROP`, sebagian butir terhapus) | data jawaban hilang — persis keadaan yang ingin dihindari |
| Pemulihan dari cadangan | **28 ms**, jumlah baris **identik** (241/2887/244/2/60), `integrity_check` → `ok` |
| Kode & sidik digital dokumen setelah pemulihan | utuh (`2LZP8JWS … PDF baris=1 sha=d0e72dc1ee09`, `OC6QMOFT … XLSX baris=7 sha=3e2af5132527`) — verifikasi publik tetap menyatakan **sah** |
| Pemulihan ke versi lebih lama | Aman: kode verifikasi dokumen yang dibuat **sesudah** titik pemulihan akan hilang, sehingga salinannya dilaporkan **tidak sah** — perilaku yang benar (bukti tidak dapat dipalsukan), dan dicatat sebagai risiko operasional |
| Pemasangan baru dari nol | `migrate` 3,8 s (93 tabel `sup_*`), `db:seed` 53 s (22 instrumen, 280 indikator) |

Prosedur pemulihan lengkap:

1. Hentikan penjadwal (`Ctrl+C` pada `schedule:work`) agar pengingat tidak
   berjalan pada basis data yang setengah pulih.
2. Aktifkan mode pemeliharaan: `php artisan down --retry=60`.
3. Pulihkan basis data (`cp` berkas cadangan, atau `mysql < backup.sql` /
   `pg_restore -c`).
4. Pulihkan berkas dokumen (`storage/app/private/supervisi`) dari cadangan
   penyimpanan; tanpa langkah ini verifikasi dokumen lama akan menyatakan berkas
   hilang.
5. `php artisan migrate --force` (bila cadangan lebih lama daripada kode),
   `php artisan optimize:clear`, lalu `php artisan supervisi:health`.
6. Periksa tiga hal sebelum membuka layanan: `supervisi:health` tanpa temuan
   **critical**, jumlah kegiatan/jawaban sesuai ekspektasi, dan verifikasi satu
   dokumen lama menyatakan **sah**.
7. `php artisan up`, jalankan ulang penjadwal, dan `supervisi:remind --dry-run`
   untuk memastikan pengingat berjalan.

**Rencana pemulihan bencana (RPO/RTO) yang disarankan:** cadangan penuh harian
(01.00) + penyimpanan berkas dokumen disertakan; tambahan `sqlite3 .backup`
setiap 4 jam untuk SQLite. Sasaran: **RPO ≤ 4 jam**, **RTO ≤ 30 menit** —
latihan di atas menyelesaikan pemulihan basis data dalam hitungan detik, jadi
waktu terbesar ada pada pengembalian berkas dan verifikasi.

## 7. Kenaikan versi (upgrade) & rollback

1. Cadangkan basis data **dan** catat commit yang sedang berjalan.
2. `git pull` → `composer install --no-dev` → `npm ci && npm run build`.
3. `php artisan migrate --force` (migrasi modul bersifat menambah; tidak ada
   `DROP` data pada migrasi Fase 5).
4. `php artisan optimize:clear && php artisan optimize`.
5. Verifikasi: `php artisan supervisi:health --fail-on-issue`, lalu **satu
   uji asap** lewat API — lihat §8.
6. Rollback: `git checkout <commit sebelumnya>`, `php artisan migrate:rollback
   --step=<n>` hanya bila migrasi baru memang perlu dibatalkan, pulihkan
   cadangan bila data sudah berubah bentuk.

## 8. Uji asap setelah pemasangan/kenaikan versi

```bash
# 1) kesehatan
php artisan supervisi:health --fail-on-issue

# 2) verifikasi dokumen publik (tanpa token) — harus 404 dengan valid:false
curl -s "$APP_URL/api/v1/supervisi/reports/verify/UJICOBA" | head -c 200

# 3) batas laju aktif — permintaan ke-31 harus 429
for i in $(seq 1 31); do curl -s -o /dev/null -w "%{http_code} " "$APP_URL/api/v1/supervisi/reports/verify/C$i"; done; echo

# 4) formulir luring & sinkronisasi (butuh token)
curl -s -H "Authorization: Bearer $TOKEN" "$APP_URL/api/v1/supervisi/visits/$VISIT/blank-form" | head -c 8   # %PDF-1.4
```

Hasil yang diharapkan: langkah 1 `SUCCESS`; langkah 2 `"valid":false`; langkah 3
30 kode non-429 diikuti `429`; langkah 4 `%PDF-1.4`.

## 9. Retensi & kepatuhan

* Hasil supervisi & penilaian kinerja disimpan **minimal 5 tahun** (kebutuhan
  angka kredit) — barisnya tidak pernah dihapus otomatis.
* Yang disapu hanya **berkas ekspor** melewati `report_retention_months`
  (bawaan 24 bulan), dan tetap menyisakan baris riwayat + kode verifikasi +
  sidik digital. Penghapusan menuntut `--force`; penjadwal tidak pernah
  menghapus sendiri.
* Bukti (foto/dokumen) mengikuti kebijakan sekolah; perintah penyapu tidak
  menyentuhnya.
* Jejak audit (`sup_audit_logs`, `sup_visit_revisions`) bersifat menambah saja
  dan tidak dibersihkan oleh modul.
