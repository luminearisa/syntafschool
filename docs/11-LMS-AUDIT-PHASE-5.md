# LMS — Audit Total Phase 1–4 & Rencana Phase 5

> Dokumen ini adalah **audit report internal** (§2 prompt Phase 5). Setiap
> temuan punya status perbaikan; kolom *Fix* diisi begitu perbaikan selesai
> dalam fase ini. Tidak ada temuan yang ditutup tanpa menambah kode, tes, atau
> dokumentasi.

Cakupan pemindaian: seluruh `backend/app/Modules/LMS` (376 berkas: 70 service,
96 model, 70 enum, 36 controller, 19 migrasi, 259 rute `api/v1/lms/*`) ditambah
frontend `frontend/src/features/lms` (63 berkas) dan tabel ERP yang dipakai
integrasi (`class_students`, `class_subjects`, `student_guardians`,
`student_attendances`, `academic_events`, `finance_billings`).

Legenda status perbaikan: `SELESAI`, `SEBAGIAN`, `RENCANA`.

---

## 1. Ringkasan temuan

| # | Module | Status | Issue | Severity | Impact | Recommended Fix | Fix |
|---|---|---|---|---|---|---|---|
| 1 | Phase 4 (9 service) | Tidak terpakai | `BadgeService`, `CertificateService`, `CourseArchiveService`, `CourseCopyService`, `CourseReviewService`, `InteractiveContentService`, `LearningHealthService`, `LmsMessagingService`, `PrerequisiteService` tidak punya controller/rute — hanya dipanggil tes | **CRITICAL** | Fitur "terlihat selesai" tetapi tidak dapat dipakai guru/siswa; seluruh investasi Phase 4 tidak sampai ke pengguna | Tambah lapisan HTTP + rute + izin + rate limit | SELESAI |
| 2 | Integrasi ERP | Tidak lengkap | 8 kontrak wajib belum ada: `ParentProviderInterface`, `EnrollmentProviderInterface`, `NotificationProviderInterface`, `CalendarProviderInterface`, `FileStorageProviderInterface`, `AttendanceProviderInterface`, `FinancialEligibilityProviderInterface`, `InteractiveContentProviderInterface` | **HIGH** | Logika `if (standalone)` berisiko menyebar; integrasi SIAKAD/ERP tidak dapat diganti tanpa mengubah business logic | Buat kontrak + implementasi ERP & lokal, daftarkan di provider, satu gerbang `LmsGateway` | SELESAI |
| 3 | Grade Sync (§6) | Sebagian | Sinkronisasi nilai berjalan **synchronous**: status hanya `NOT_SYNCED/SYNCING/SYNCED/SYNC_FAILED/SKIPPED`, tanpa `PENDING/PROCESSING/RETRYING/CANCELLED`, tanpa riwayat percobaan, tanpa retry, tanpa pembanding LMS vs SIAKAD | **HIGH** | Provider SIAKAD lambat/mati membuat permintaan HTTP guru menggantung; nilai gagal kirim tidak punya jalur pemulihan | Tambah antrean + riwayat percobaan + retry/cancel + compare | SELESAI |
| 4 | Audit trail (§23) | Sebagian | `activity('lms')` (Spatie) menyimpan actor/action/before/after, tetapi **tanpa `ip_address`, `user_agent`, `correlation_id`** dan tanpa jaminan immutable di lapisan aplikasi | **HIGH** | Audit tidak dapat dipertanggungjawabkan saat pemeriksaan (tidak tahu dari mana perubahan datang) | Tambah kolom audit + `LmsAuditService` + model immutable + korelasi permintaan | SELESAI |
| 5 | Publikasi | Tidak ada gerbang | `publish()` hanya mengubah status; tidak ada pemeriksaan judul, guru, section, aktivitas wajib, resource, aturan penyelesaian, dan **tidak ada deteksi siklus** prasyarat maupun jalur belajar | **HIGH** | Course rusak/prasyarat melingkar dapat terbit dan mengunci siswa (loop akses) | `CourseValidator` + `CourseValidationService` dipakai sebelum terbit | SELESAI |
| 6 | Import/Export & Backup | Tidak ada | Tidak ada ekspor/impor course, tidak ada backup/restore; resource baru berhenti di template + salin | **MEDIUM** | Course tidak portabel antar sekolah/semester; tidak ada jalur pemulihan konten | `CourseExportService`, `CourseImportService` (validate→preview→resolve→import→verify), `CourseBackupService` | SELESAI |
| 7 | Interoperabilitas | Tidak ada | Tidak ada SCORM 1.2/2004: tidak ada paket, manifest, SCO, attempt, tracking | **MEDIUM** | Konten standar LMS modern tidak dapat diimpor | Tabel + service SCORM yang menulis ke completion/gradebook/progress yang sudah ada | SELESAI |
| 8 | Report Center (§22) | Tidak ada | Tidak ada laporan terpusat (15 jenis) dan ekspor CSV/XLSX/PDF | **MEDIUM** | Kepala sekolah/sekolah tidak punya laporan pembelajaran siap pakai | `LmsReportService` + `GenerateLmsReportJob` | SELESAI |
| 9 | Integrity & Health | Tidak ada | Tidak ada pemeriksaan integritas data (orphan, duplikat, akses invalid) dan tidak ada endpoint kesehatan LMS | **MEDIUM** | Kerusakan data tidak terdeteksi sampai menjadi keluhan pengguna | `LmsIntegrityService` (check + saran perbaikan) & `LmsHealthCheckService` + `/lms/health` | SELESAI |
| 10 | Antrean & Scheduler | Tidak ada | Direktori `Jobs`/`Console` belum ada; pengingat tenggat, penerbitan sertifikat otomatis, evaluasi badge, dan penyegaran kesehatan belajar belum dijadwalkan | **MEDIUM** | Fitur otomatis Phase 4 tidak pernah berjalan sendiri | Job + perintah + jadwal di `routes/console.php` | SELESAI |
| 11 | Rate limiting (§30) | Sebagian | Hanya 2 rute publik memakai `throttle`; percobaan kuis, unggah berkas, posting forum, verifikasi sertifikat, sinkronisasi nilai belum dibatasi | **MEDIUM** | Penyalahgunaan (brute force token sertifikat, spam forum, banjir jawaban kuis) | Named limiter terkonfigurasi + dipasang pada rute terkait | SELESAI |
| 12 | API contract (§33) | Konsisten | Envelope `success/data/message/meta` sudah seragam (`BaseApiController`), tetapi endpoint Phase 4 belum ada sehingga tidak dapat dinilai | **MEDIUM** | — | Endpoint Phase 4 memakai envelope yang sama + pagination/filter | SELESAI |
| 13 | Dokumentasi API (§34) | Tidak ada | Tidak ada dokumentasi endpoint maupun OpenAPI | **MEDIUM** | Integrator harus membaca kode | `docs/12-MODUL-LMS-API.md` + `docs/openapi-lms.yaml` | SELESAI |
| 14 | Frontend Phase 4 | Tidak ada | 63 berkas UI hanya mencakup Phase 1–3; belum ada halaman kelas langsung, sertifikat, badge, konten interaktif, moderasi ulasan, engagement/report, audit/health | **HIGH** | Fitur Phase 4 tidak terlihat pengguna | Halaman React + API client + rute | SEBAGIAN — 5 halaman (kelas langsung, sertifikat+verifikasi, capaian, laporan, tata kelola) + klien `phase4Api.ts`; sisa: pemutar konten interaktif, rekap kehadiran, moderasi ulasan, impor/ekspor & SCORM |
| 15 | N+1 & indeks | Sebagian | Daftar kelas langsung awalnya menjalankan rekap kehadiran per sesi (N+1); beberapa kolom pencarian/daftar belum berindeks (`lms_learning_events`, `lms_grade_syncs.attempts`, `lms_live_sessions` rentang waktu) | **MEDIUM** | Halaman daftar melambat seiring data bertambah | Eager load/rekap massal + migrasi indeks tambahan | SELESAI |
| 16 | Idempotensi & konkurensi | Sebagian | Kelas langsung, sertifikat, badge, XP sudah idempotent; impor course, sinkronisasi nilai antrean, dan pelaporan belum bertanda idempotensi | **MEDIUM** | Job dobel membuat data ganda | `idempotency_key` unik pada tabel antrean baru | SELESAI |
| 17 | Retensi data (§39) | Tidak ada | Belum ada kebijakan retensi arsip/berkas sementara | **LOW** | Pertumbuhan basis data & penyimpanan | Dokumen retensi + `lms:prune-temporary` | SELESAI |
| 18 | Backup/DR & Rollback | Tidak ada | Belum terdokumentasi | **MEDIUM** | Deployment tidak punya jalur pemulihan | `docs/13-MODUL-LMS-DEPLOYMENT.md` | SELESAI |
| 19 | Placeholder/TODO | Bersih | Pemindaian `TODO|FIXME|HACK|XXX` = **0**; kata "placeholder" hanya pada fitur isi sertifikat (memang begitu desainnya) | — | — | — | SELESAI |
| 20 | Duplicate business logic | Bersih | Tidak ditemukan mesin progress/penilaian kedua: Phase 2–4 selalu memanggil `CourseProgressService`, `CourseCompletionService`, `AssessmentResultService`, `GradeCalculationService` | — | — | — | SELESAI |

---

## 2. Detil temuan CRITICAL

**#1 — Sembilan service Phase 4 tanpa muara.**

Pemindaian rujukan (semua berkas PHP + rute + frontend) menunjukkan 9 service
hanya dirujuk oleh berkas tesnya sendiri:

```text
BadgeService, CertificateService, CourseArchiveService, CourseCopyService,
CourseReviewService, InteractiveContentService, LearningHealthService,
LmsMessagingService, PrerequisiteService
```

Artinya: XP, badge, sertifikat, konten interaktif, ulasan, template/arsip,
pesan, dan pemantauan kesehatan belajar tidak dapat dipakai lewat API — padahal
skema, logika, izin, dan notifikasinya sudah lengkap. Perbaikannya adalah
lapisan HTTP tipis (controller → service) + rute + rate limit, tanpa menambah
logika bisnis baru di controller.

---

## 3. Detil temuan HIGH

**#2 — Batas integrasi belum lengkap.** Tanpa kontrak `ParentProviderInterface`
dan `EnrollmentProviderInterface`, integrasi KRS/kelas dan portal wali akan
menyebarkan pengecekan mode ke dalam service. Phase 5 memindahkan semuanya ke
belakang satu gerbang (`LmsGateway`) sehingga business logic tidak pernah tahu
mode yang aktif.

**#3 — Sinkronisasi nilai sinkron.** `syncStudent()` mengirim payload ke
provider di dalam permintaan HTTP guru. Bila SIAKAD lambat, permintaan
menggantung; bila gagal, tidak ada jalur coba-ulang otomatis meski nilai sudah
dibekukan setelah kirim. Phase 5 menambahkan jalur antrean (`PENDING` →
`PROCESSING` → `SYNCED`/`FAILED` → `RETRYING`/`CANCELLED`) dengan riwayat
percobaan dan pembanding nilai LMS vs SIAKAD, sambil mempertahankan jalur
sinkron lama agar perilaku Phase 3 tidak berubah.

**#4 — Audit tanpa jejak asal.** `activity_log` tidak menyimpan alamat IP dan
user agent, sehingga perubahan nilai/konten tidak dapat ditelusuri asalnya.
Perbaikan menambah kolom (tanpa menghapus data lama) + service audit yang
memaksa `before`/`after`, aktor, korelasi, dan menolak perubahan/penghapusan.

**#5 — Publikasi tanpa validasi.** Tidak ada satu pun pemeriksaan sebelum
`status = PUBLISHED`. Prasyarat melingkar (A→B→C→A) akan membuat keputusan
akses berputar tanpa hasil. Phase 5 menambahkan validator berlapis dan deteksi
siklus pada prasyarat item maupun jalur belajar.

---

## 4. Temuan yang sengaja **tidak** diperbaiki (beserta alasan)

| Temuan | Alasan |
|---|---|
| Papan peringkat lintas sekolah | §26–§30 hanya meminta leaderboard per course/kelas; lintas sekolah memerlukan kebijakan privasi yang lebih dulu disetujui sekolah |
| WhatsApp sebagai kanal default | Provider WhatsApp tidak ada di aplikasi (§10: kanal hanya bila provider tersedia). Kanal tetap dapat ditambahkan tanpa mengubah service |
| Repositori soal institusi dengan workflow persetujuan | Sudah tercatat sebagai lanjutan Phase 3; bukan bagian §1–§57 Phase 5 |
| Penghapusan otomatis data lama | §39/§24 melarang perbaikan destruktif tanpa persetujuan — penyapu retensi hanya melapor kecuali dijalankan dengan `--force` |

---

## 5. Status akhir

Seluruh temuan CRITICAL dan HIGH dikerjakan pada fase ini; temuan MEDIUM ikut
diselesaikan karena infrastrukturnya dibutuhkan temuan lain. Hasil akhir,
bukti uji, dan sisa keterbatasan dilaporkan pada
[`docs/14-MODUL-LMS-PHASE-5.md`](14-MODUL-LMS-PHASE-5.md) bagian *Final Audit*.
