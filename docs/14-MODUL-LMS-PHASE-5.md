# Modul LMS — Phase 5 (Finalization, Integration & Production Hardening)

> Melanjutkan Phase 1–4 **tanpa membuat mesin kedua**: tidak ada engine progress,
> completion, grading, enrollment, notification, atau authorization yang baru.
> Setiap fitur Phase 5 memanggil service yang sudah ada. Dokumen ini merangkum
> apa yang ditambahkan, bukti ujinya, dan **status akhir yang jujur** (bagian
> *Final Audit*).

Berkas terkait:
[`07-MODUL-LMS.md`](07-MODUL-LMS.md) (Phase 1) ·
[`08-MODUL-LMS-PHASE-2.md`](08-MODUL-LMS-PHASE-2.md) ·
[`09-MODUL-LMS-PHASE-3.md`](09-MODUL-LMS-PHASE-3.md) ·
[`10-MODUL-LMS-PHASE-4.md`](10-MODUL-LMS-PHASE-4.md) ·
[`11-LMS-AUDIT-PHASE-5.md`](11-LMS-AUDIT-PHASE-5.md) (audit total) ·
[`12-MODUL-LMS-API.md`](12-MODUL-LMS-API.md) ·
[`13-MODUL-LMS-DEPLOYMENT.md`](13-MODUL-LMS-DEPLOYMENT.md).

---

## 1. Yang ditambahkan pada fase ini

### 1.1 Gerbang integrasi (`LmsGateway`, §4)

Sebelumnya sudah ada 10 kontrak; Phase 5 melengkapinya menjadi **18 kontrak**
dan menyatukan aksesnya lewat satu gerbang:

| Kontrak baru | ERP (integrated) | Standalone |
|---|---|---|
| `ParentProviderInterface` | `ErpParentProvider` (`guardians`, `student_guardians`, `ppdb_parent_accounts`) | `StandaloneParentProvider` (`lms_guardians`) |
| `EnrollmentProviderInterface` | `ErpAcademicEnrollmentProvider` (`class_students`; `aktif→ACTIVE`, `pindah/keluar→SUSPENDED`) | `StandaloneEnrollmentProvider` (`isAuthoritative()=false`) |
| `NotificationProviderInterface` | `DatabaseNotificationProvider` (kanal `database`/`mail`; `whatsapp`/`push` hanya bila penyedianya ada) | sama (mode tidak mengubah kanal) |
| `CalendarProviderInterface` | `ErpCalendarProvider` (`academic_events`) | `StandaloneCalendarProvider` (kosong) |
| `FileStorageProviderInterface` | `LocalFileStorageProvider` (disk terkonfigurasi, visibilitas per berkas) | sama |
| `AttendanceProviderInterface` | `ErpAttendanceProvider` (`student_attendances`, kode `H/A/I/S/T`) | `StandaloneAttendanceProvider` (kosong) |
| `FinancialEligibilityProviderInterface` | `ErpFinancialEligibilityProvider` (tagihan lewat jatuh tempo) | `StandaloneFinancialEligibilityProvider` (selalu layak) |
| `InteractiveContentProviderInterface` | `LocalInteractiveContentProvider` (tipe bawaan + validasi paket `.h5p`) | sama |

**Aturan yang ditegakkan:** tidak ada `if (standalone)` di business logic. Mode
dipilih **sekali** di `LmsServiceProvider`; kelas dapat diganti lewat
`lms.providers.*` (mis. penyimpanan S3 atau wali dari sistem sekolah).

### 1.2 Antrean sinkronisasi nilai (§6)

`GradeSyncQueueService` menambahkan jalur antrean di atas jalur sinkron Phase 3:

```text
enqueue()  → PENDING   → ProcessGradeSyncJob → process() → PROCESSING
           → SYNCED | FAILED → RETRYING (backoff) | CANCELLED | SKIPPED
```

* `lms_grade_sync_attempts` menyimpan **riwayat percobaan** (request, respons,
  galat, durasi) — permintaan “View History”.
* Idempotensi: `idempotency_key` unik per (gradebook, siswa, hash payload);
  kiriman dengan isi identik tidak diulang, dan saat provider belum tersedia
  (SIAKAD belum aktif) antrean tidak diisi berulang.
* `retryFailed()`, `cancel()`, `compare()` (LMS vs SIAKAD) tersedia lewat API
  dan perintah `lms:grade-sync`.
* Penguncian nilai setelah kirim tetap memakai `GradeReleaseService::lock()`
  Phase 3 — bukan aturan baru.

### 1.3 Audit trail (§23)

`lms_audit_logs` + `LmsAuditService` menyimpan **aktor, aksi, entitas, before,
after, konteks, ip, user agent, correlation id**. Correlation id berasal dari
header `X-Correlation-Id` (atau dibuat sekali per permintaan) sehingga satu aksi
pengguna yang menyentuh beberapa service tetap dapat ditelusuri sebagai satu
rangkaian. Baris audit **immutable** (update/delete ditolak di lapisan model);
rahasia (`password`, `quiz_password`, token) tidak pernah masuk.

### 1.4 Mesin validasi konten & deteksi siklus (§17–§18)

Sembilan validator (`CourseValidator`, `SectionValidator`, `ActivityValidator`,
`ResourceValidator`, `QuizValidator`, `QuestionValidator`, `CompletionValidator`,
`AvailabilityValidator`, `CertificateValidator`) digabung
`ContentValidationService`, dan **penerbitan course sekarang menolak** temuan
`ERROR`.

`PrerequisiteGraphService` mendeteksi siklus pada dua graf:

* **item** (`lms_item_prerequisites`) — A→B→C→A ditolak;
* **course** (`lms_course_prerequisites`, tabel baru) — rantai kurikulum
  A→B→C→A ditolak sebelum aturan disimpan (`CoursePrerequisiteService::attach()`
  mengembalikan jalur siklusnya untuk ditampilkan).

Gerbang prasyarat course ikut diputuskan `CourseAccessService` (satu pintu akses
yang sudah ada) dengan alasan `course_prerequisite` + `blocked_by_course_id`,
**tanpa menghapus** data belajar siapa pun.

### 1.5 Ekspor, impor, backup, pemulihan (§14–§15)

```text
UPLOAD → VALIDATE → PREVIEW → RESOLVE CONFLICT → IMPORT → VERIFY
```

* Ekspor memakai `CourseTemplateService::snapshotCourse()` (struktur yang sudah
  terbukti) + soal, kategori soal, struktur penilaian, capaian, kompetensi,
  konfigurasi sertifikat — **tanpa** kata sandi, token, data siswa, nilai,
  submission, progress, atau jejak audit.
* `conflict_strategy`: `SKIP` (bawaan) · `REPLACE` (ditolak bila course tujuan
  sudah punya peserta) · `DUPLICATE` · `MERGE`.
* `verify()` membandingkan janji berkas dengan hasil nyata (`VERIFIED`/`PARTIAL`).
* Backup = ekspor tersimpan (visibilitas `ADMIN_ONLY`); pemulihan = impor menjadi
  course baru; backup hanya dianggap sah setelah `verify()` lolos.
* Idempotensi impor lewat `idempotency_key` unik (`lms_course_imports`).

### 1.6 Interoperabilitas SCORM (§12)

`ScormPackageService` mengimpor paket 1.2/2004: baca `imsmanifest.xml`, deteksi
versi, petakan `identifierref` → berkas peluncuran, tolak arsip dengan path
traversal, ekstrak ke penyimpanan LMS, catat organisasi/SCO, lalu
`createActivity()` memasang item + aktivitas bertipe **SCORM** (`ActivityType::SCORM`
dengan `ScormActivityService` sebagai konfigurator — kontrak yang sama dengan
Assignment/Quiz/Forum/Lesson/Choice/Survey).

`ScormTrackingService` melacak CMI:

* attempt unik per (siswa, SCO);
* `commit()` idempotent (kunci unik per elemen);
* CMI mutakhir disimpan → siswa dapat **melanjutkan** setelah koneksi putus;
* capaian diteruskan ke `ActivityGradeService` (nilai) dan
  `CourseCompletionService` (ketuntasan) dan `CourseProgressService` (progres) —
  **tidak ada** pelacakan kedua.

### 1.7 Report Center (§22)

15 laporan (`COURSE`, `ENROLLMENT`, `COMPLETION`, `ASSIGNMENT`, `QUIZ`, `GRADE`,
`ATTENDANCE`, `COMPETENCY`, `OUTCOME`, `CERTIFICATE`, `BADGE`,
`STUDENT_PROGRESS`, `TEACHER_ACTIVITY`, `AT_RISK`, `ENGAGEMENT`) membaca tabel
yang sudah ada, dengan filter course/kelas/guru/siswa/rentang tanggal. Ekspor CSV
selalu tersedia (XLSX/PDF dibuka lewat `lms.reports.allow_rich_formats`); laporan
yang melebihi `lms.reports.sync_row_limit` otomatis dijalankan di antrean.

### 1.8 Integritas, kesehatan, otomatisasi (§11, §24, §42)

* `LmsIntegrityService`: 13 pemeriksaan (orphan enrollment/activity/submission/
  grade/completion/certificate, berkas rusak, rujukan course rusak, nilai tidak
  sah, pendaftaran ganda, tautan wali tidak sah, guru tidak sah, prasyarat
  melingkar) + **saran perbaikan**; tidak ada perbaikan destruktif otomatis.
* `SystemHealthService`: basis data, cache, antrean, penyimpanan, ERP, SIAKAD,
  pemberitahuan, penjadwal → `HEALTHY`/`DEGRADED`/`UNAVAILABLE`; ringkasan publik
  (`/lms/health`) tidak membuka detail infrastruktur.
* Job: `ProcessGradeSyncJob`, `GenerateLmsReportJob`, `RefreshLearningHealthJob`,
  `SendLearningRemindersJob`, `RunLmsAutomationJob`, `PruneLmsTemporaryDataJob`.
* Perintah: `lms:health`, `lms:integrity`, `lms:grade-sync`, `lms:reminders`,
  `lms:automation`, `lms:prune`, `lms:report` — semuanya terdaftar di
  penjadwal (`routes/console.php`) dengan `withoutOverlapping()`.

### 1.9 Lapisan HTTP Phase 4–5 (§33)

Sepuluh controller baru + 94 rute baru (total 373 rute `api/v1/lms/*`):

| Controller | Cakupan |
|---|---|
| `LmsInteractiveContentController` | pemutar & pengelolaan konten interaktif |
| `LmsLiveSessionController` | jadwal, gabung, kehadiran, rekaman |
| `LmsCertificateController` | sertifikat siswa, penerbitan, pencabutan, template, verifikasi publik |
| `LmsGamificationController` | badge, XP, rentetan, papan peringkat |
| `LmsReviewController` | ulasan, moderasi, umpan balik |
| `LmsEngagementController` | kesehatan belajar, keterlibatan, pesan |
| `LmsCourseLifecycleController` | template, salin, arsip, prasyarat, validasi |
| `LmsImportExportController` | ekspor/impor/backup/pemulihan |
| `LmsScormController` | paket SCORM, pemasangan, peluncuran, commit |
| `LmsReportController` + `LmsGovernanceController` + `LmsGradeSyncQueueController` | laporan, audit, integritas, kesehatan, antrean nilai |

Rate limit (§30): 15 limiter bernama (percobaan kuis, jawaban kuis, submission,
unggah berkas, posting forum, verifikasi sertifikat, pencarian, impor, ekspor
laporan, sinkronisasi nilai, SCORM, pesan, API publik) — batas dapat diubah
sekolah lewat `lms.rate_limits.*`.

---

## 2. Integritas data & konkurensi (ringkasan)

| Risiko | Penangkal |
|---|---|
| Job/permintaan dobel | `idempotency_key` unik (`lms_grade_syncs`, `lms_grade_sync_attempts`, `lms_scorm_tracking_events`, `lms_notification_logs`, `lms_course_imports`, `lms_report_exports`) |
| Dua percobaan SCORM bersamaan | kunci unik `(user_id, sco_id)` |
| Dua sertifikat untuk satu pendaftaran | kunci unik `enrollment_id` |
| Nilai ganda saat retry | hash payload + status terminal (`SYNCED`/`CANCELLED`) diperiksa lebih dulu |
| Audit hilang/diubah | tabel immutable + tolak update/delete |
| Impor menimpa produksi | strategi konflik wajib; `REPLACE` ditolak bila ada peserta |
| Publikasi course rusak | validator + deteksi siklus menahan penerbitan |
| Nilai pribadi bocor | payload siswa tanpa kunci jawaban/`host_url`/`passcode`; health publik tanpa detail |

---

## 3. Pengujian

| Berkas | Fokus |
|---|---|
| `LmsPhase5GovernanceTest` (18 tes, 120 assertion) | validasi & publikasi, siklus prasyarat item/course, gerbang prasyarat, antrean nilai (riwayat, retry, cancel, compare, idempotensi), audit trail immutable, report center + CSV, integritas, health & ringkasan publik, ekspor–impor–verifikasi–idempotensi, backup & pemulihan, SCORM (impor→pasang→lacak→commit idempotent), gateway (kebijakan keuangan & notifikasi idempotent), penyimpanan aman, HTTP baru (otorisasi + verifikasi publik), endpoint antrean/report/integration |
| Suite LMS penuh | 253 tes / ±1894 assertion (19 berkas Phase 1–5) — dijalankan sebelum rilis; lihat *Final Audit* |

Dua bug **nyata** ditemukan oleh tes ini dan langsung diperbaiki:

1. `LmsGateway::financialDecision()` memakai penggabungan array (`+`) sehingga
   nilai `allowed` lama tetap menang — pembatasan keuangan tidak pernah berlaku.
2. `LmsGradeSync` tidak meng-cast `queued_at`/`next_attempt_at`/`cancelled_at`
   ke `datetime` sehingga penjadwalan retry dan penampilan tanggal salah.

---

## 4. Retensi data (§39)

| Data | Kebijakan bawaan | Penghapusan |
|---|---|---|
| Arsip course | dilaporkan setelah `lms.retention.archive_after_days` (365) | manual, dengan alasan tercatat |
| Submission | disimpan ≥ 1095 hari | hanya lewat kebijakan + persetujuan |
| Percobaan kuis | disimpan ≥ 1095 hari | idem |
| Audit log | 1095 hari | hanya oleh operator basis data |
| Catatan notifikasi | 180 hari | `lms:prune --force` |
| Ekspor laporan | 7 hari (`expires_at`) | `lms:prune --force` |
| Berkas sementara | 24 jam | `lms:prune --force` |

`lms:prune` **tanpa** `--force` hanya melapor. Tidak ada penghapusan bukti
akademik otomatis.

---

## 5. Final Audit

```text
LMS FINAL AUDIT

Modules:            [PASS]      Catalog, Courses, Sections, Activities, Resources,
                                Enrollment, Groups, Completion, Progress, Assignments,
                                Quizzes, Question Bank, Forums, Lessons, Surveys,
                                Gradebook, Rubrics, Competencies, Learning Outcomes,
                                Learning Paths, Interactive Content, Live Classes,
                                Attendance, Certificates, Badges, Gamification,
                                Messaging, Notifications, Calendar, Analytics,
                                Reports, Content Interoperability (SCORM),
                                Import/Export, Search, Files, Audit, Settings
Integrations:       [PASS]      18 kontrak + LmsGateway; ERP/SIAKAD & standalone
                                tersedia; KRS→enrollment (aktif→ACTIVE,
                                pindah/keluar→SUSPENDED); nilai LMS→SIAKAD via
                                antrean + retry + compare
Security:           [PASS]      otorisasi server-side di setiap endpoint baru,
                                kepemilikan course, rate limit 15 limiter,
                                audit trail immutable (ip/user_agent/correlation),
                                sanitasi nama berkas, visibilitas berkas,
                                payload siswa tanpa kunci jawaban
Performance:        [PASS]      rekap kehadiran massal (bukan N+1), indeks tambahan
                                pada events/live/certificates/reviews, paginasi,
                                laporan berat di antrean, cache hanya pada data stabil
Accessibility:      [PARTIAL]   setiap enum punya `label()` + `tone()` sehingga status
                                tidak pernah disampaikan lewat warna saja; halaman
                                baru memakai label sr-only, aria-hidden pada ikon,
                                dan tombol berteks. Audit aksesibilitas menyeluruh
                                (screen reader, kontras) masih perlu dijalankan
                                pada halaman baru
Testing:            [PASS]      18 tes Phase 5 (120 assertion) + suite LMS penuh (253 tes);
                                unit, feature, integrasi, keamanan, E2E alur
                                service Phase 1–4
Production Readiness:[PARTIAL]  migrasi/seed/perintah/penjadwal/health/backup/
                                rollback terdokumentasi dan berjalan; `npm run build`
                                hijau. Yang belum: halaman pemutar konten interaktif,
                                moderasi ulasan, impor/ekspor & SCORM di UI, serta
                                uji restore basis data nyata (baru uji berkas backup)

Remaining Issues:
1. Frontend Phase 4–5 baru sebagian: sudah ada halaman Kantor Kelas Langsung,
   Sertifikat (+ verifikasi publik), Capaian/Badge, Laporan, dan Tata Kelola
   (audit/integritas/kesehatan) beserta klien API-nya (`phase4Api.ts`). Belum ada
   halaman untuk pemutar konten interaktif, rekap kehadiran kelas langsung,
   moderasi ulasan, impor/ekspor & SCORM, serta dasbor engagement guru.
2. PDF/XLSX laporan & sertifikat belum dirender (`lms.reports.allow_rich_formats`
   masih false, kolom `file_path` sertifikat sudah disiapkan).
3. Uji restore *basis data* (bukan hanya berkas backup course) belum dijalankan
   pada lingkungan nyata — prosedurnya sudah didokumentasikan di
   `13-MODUL-LMS-DEPLOYMENT.md`.
4. Kanal WhatsApp/push belum ada penyedianya; LMS melaporkannya sebagai kanal
   tidak tersedia dan tetap mengirim in-app (sesuai §10).
5. `lms:prune` dan `LmsIntegrityService` sengaja tidak memperbaiki data sendiri;
   perbaikan menunggu keputusan admin.
```

Tidak ada temuan CRITICAL yang tersisa; seluruh temuan **HIGH** pada
[`11-LMS-AUDIT-PHASE-5.md`](11-LMS-AUDIT-PHASE-5.md) sudah ditutup di fase ini.
