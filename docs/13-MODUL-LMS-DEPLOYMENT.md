# Modul LMS — Deployment, Backup, Rollback & Retensi (§40, §49, §50)

Panduan ini khusus modul LMS. Instalasi aplikasi secara umum ada pada
[`README`](../README.md) dan [`docs/PROGRESS.md`](PROGRESS.md).

---

## 1. Prasyarat

| Komponen | Catatan |
|---|---|
| PHP | 8.3+ dengan ekstensi `zip`, `xml`/`simplexml` (SCORM & analisis paket), `fileinfo` |
| Basis data | MySQL 8 / MariaDB 10.6 (produksi) atau SQLite (pengembangan) |
| Antrean | `database`/`redis` — **jangan** `sync` di produksi (`lms:health` menandainya `DEGRADED`) |
| Penjadwal | cron `php artisan schedule:run` setiap menit |
| Penyimpanan | disk `local` (bawaan) atau `s3`; dapat diganti lewat `LMS_FILES_DISK` |

---

## 2. Konfigurasi minimum

```env
# Mode: integrated (default produksi) atau standalone
LMS_STANDALONE=false

# Integrasi akademik
LMS_GRADE_PROVIDER=siakad          # siakad | standalone

# Sakelar fitur (semuanya bisa diubah dari halaman Pengaturan LMS tanpa deploy)
LMS_CERTIFICATE_ENABLED=true
LMS_GAMIFICATION_ENABLED=true
LMS_LEADERBOARD_ENABLED=false
LMS_REVIEW_ENABLED=true
LMS_FINANCE_GATE=false             # true hanya bila sekolah ingin memeriksa kelayakan keuangan
LMS_ALLOW_LEARNING_WHEN_RESTRICTED=true

# Antrean & otomatisasi
LMS_GRADE_SYNC_MAX_ATTEMPTS=5
LMS_GRADE_SYNC_BACKOFF_MINUTES=10
LMS_CERTIFICATE_AUTO_ISSUE=true

# Berkas
LMS_FILES_DISK=local
LMS_MAX_UPLOAD_MB=25
LMS_TEMP_FILE_HOURS=24
```

Setelah mengubah `.env`: `php artisan config:clear && php artisan config:cache`.
Pengaturan yang disimpan admin di tabel `settings` (halaman Pengaturan LMS)
menimpa nilai `.env` saat *runtime* — tidak perlu deploy ulang.

---

## 3. Urutan instalasi / pembaruan

```bash
# 1) Dependensi
composer install --no-dev --optimize-autoloader

# 2) Migrasi (tidak destruktif: seluruh migrasi LMS hanya menambah tabel/kolom)
php artisan migrate --force

# 3) Peran & izin LMS (idempotent, aman dijalankan berulang)
php artisan db:seed --class="App\\Modules\\LMS\\Database\\Seeders\\LmsPermissionSeeder" --force

# 4) Penyimpanan berkas
php artisan storage:link              # bila memakai disk `public`
mkdir -p storage/app/lms/{exports,imports,reports,scorm,tmp}

# 5) Cache & rute
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 6) Pekerja antrean (supervisor/systemd, minimal 2 proses)
php artisan queue:work --queue=default --tries=2 --timeout=900

# 7) Penjadwal (cron)
* * * * * cd /path/app && php artisan schedule:run >> /dev/null 2>&1

# 8) Verifikasi
php artisan lms:health
php artisan lms:integrity
php artisan schedule:list | grep lms
```

### Urutan pengujian mode (§47, §48)

```bash
# Standalone
LMS_STANDALONE=true  php artisan config:clear
LMS_STANDALONE=true  php vendor/bin/phpunit tests/Feature/Lms/LmsStandaloneModeTest.php
LMS_STANDALONE=true  php artisan lms:health          # provider ERP dilaporkan "mode mandiri"

# Integrated (default produksi)
LMS_STANDALONE=false php artisan config:clear
php vendor/bin/phpunit tests/Feature/Lms
php artisan lms:health                                # memeriksa SIAKAD, ERP, pemberitahuan
```

---

## 4. Health check harian

```bash
php artisan lms:health --json | jq '.status, .checks'
```

| Status | Arti | Tindakan |
|---|---|---|
| `HEALTHY` | seluruh komponen siap | — |
| `DEGRADED` | sebagian komponen belum siap (mis. SIAKAD belum aktif, kanal email belum dikonfigurasi, antrean `sync`) | perbaiki komponen yang dilaporkan; LMS tetap melayani belajar |
| `UNAVAILABLE` | basis data/penyimpanan bermasalah | hentikan penerimaan tugas & nilai, perbaiki lebih dulu |

Endpoint `GET /api/v1/lms/health` memberi ringkasan aman untuk pemantau
eksternal (tanpa detail infrastruktur).

---

## 5. Backup

### 5.1 Backup konten course (aplikasi)

```bash
# Per sekolah: ekspor sebagai berkas (visibilitas ADMIN_ONLY)
php artisan tinker --execute="app(App\\Modules\\LMS\\Application\\Services\\CourseBackupService::class)
    ->backup(App\\Modules\\LMS\\Models\\LmsCourse::find(7), App\\Modules\\LMS\\Domain\\Support\\LmsSystemActor::make(), 'sebelum revisi');"

# Uji berkas TANPA memulihkan apa pun
php artisan tinker --execute="dump(app(App\\Modules\\LMS\\Application\\Services\\CourseBackupService::class)
    ->verify('lms/exports/courses/course-7-20260929-120000.json'));"
```

Backup berkas **tidak dianggap sah** sebelum `verify()` melaporkan `valid: true`.

### 5.2 Backup basis data & berkas (operasional)

```bash
# Harian 02:00 — basis data
mysqldump --single-transaction --quick school_db | gzip > /backup/db-$(date +%F).sql.gz

# Harian 02:30 — berkas unggahan LMS (konten course, submission, sertifikat)
rsync -a --delete storage/app/ /backup/storage/$(date +%F)/

# Mingguan — uji restore ke basis data sementara, lalu catat hasilnya
mysql restore_test < /backup/db-$(date +%F).sql.gz && php artisan lms:integrity
```

Simpan 30 hari harian + 12 bulan bulanan; simpan di lokasi berbeda dari server
aplikasi. **Retensi:**

| Data | Kebijakan | Penghapus |
|---|---|---|
| Ekspor laporan | 7 hari | `lms:prune --force` |
| Catatan notifikasi | 180 hari | `lms:prune --force` |
| Berkas sementara | 24 jam | `lms:prune --force` |
| Arsip course | dilaporkan setelah 365 hari | admin (dengan alasan) |
| Submission / percobaan kuis / nilai / sertifikat | ≥ 1095 hari | **hanya** lewat kebijakan + persetujuan |
| Audit log | 1095 hari | operator basis data |

`php artisan lms:prune` tanpa `--force` hanya melapor.

---

## 6. Rollback

### 6.1 Aplikasi

```bash
# Kembalikan ke rilis sebelumnya
git fetch --all && git checkout <tag-sebelumnya>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart          # pekerja memuat kode baru
```

### 6.2 Migrasi basis data

Seluruh migrasi LMS bersifat menambah (`Schema::create`, `Schema::table` dengan
`hasColumn`), sehingga **tidak perlu** rollback basis data untuk kembali ke
Phase 1–4: kode lama mengabaikan tabel/kolom yang tidak dikenal. Bila rollback
memang diperlukan:

```bash
php artisan migrate:rollback --step=1 --force     # hanya batch terakhir
```

> **Jangan** menjalankan `migrate:fresh` atau menjatuhkan tabel `lms_*` pada
> produksi: itu menghapus bukti akademik. Migrasi Phase 5 **tidak** menghapus
> kolom Phase 1–4 pada jalur `up()`.

### 6.3 Konfigurasi

```bash
php artisan config:clear                 # kembali ke .env
```

Pengaturan runtime di tabel `settings` tetap tersimpan; hapus hanya bila memang
diinginkan (kunci `lms.*` pada tabel tersebut).

### 6.4 Antrean & cache saat rollback

```bash
php artisan queue:restart                # hentikan pekerja lama
php artisan queue:failed                 # daftar job gagal
php artisan queue:retry all              # atau flush bila kode berubah drastis
php artisan cache:forget lms:health
php artisan cache:forget lms:scheduler:last_run
```

Cache LMS hanya menyimpan data stabil (katalog, konfigurasi, health) — tidak ada
status percobaan kuis, keputusan izin, atau nilai yang sedang berubah. Karena itu
invalidasi pasca-rollback cukup menghapus kunci `lms:*` di atas.

### 6.5 Setelah rollback apa pun

```bash
php artisan lms:health
php artisan lms:integrity --fail-on-critical
php artisan lms:grade-sync --retry-failed        # pastikan tidak ada nilai menggantung
```

---

## 7. Pekerjaan terjadwal (ringkasan)

| Jadwal | Perintah | Tujuan |
|---|---|---|
| 21:00 harian | `lms:automation` | kesehatan belajar, sertifikat otomatis, evaluasi badge |
| 07:00 harian | `lms:reminders` | pengingat tenggat |
| 07:15 harian | `lms:reminders --overdue` | pengingat keterlambatan |
| setiap 10 menit | `lms:grade-sync --retry-failed` | antrean nilai & percobaan ulang |
| 02:30 harian | `lms:prune` | sapu berkas/catatan sementara (lapor; `--force` menghapus) |
| 03:00 Senin | `lms:integrity` | pemeriksaan integritas data |

Jalankan `lms:automation --now` setelah pemulihan bencana agar capaian
(sertifikat/badge) dan indikator kesehatan belajar mengejar ketertinggalan.
