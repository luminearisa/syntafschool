# Modul LMS — Phase 4 (Advanced Learning Experience)

> Status: **fondasi selesai** (migrasi, model, enum, enum domain, dan application
> service). Lapisan HTTP (`Controllers`/route) dan UI menyusul pada berkas
> terpisah — sama seperti pola Phase 2–3.
> Berkas ini melengkapi [`07-MODUL-LMS.md`](07-MODUL-LMS.md) (Phase 1),
> [`08-MODUL-LMS-PHASE-2.md`](08-MODUL-LMS-PHASE-2.md) (Phase 2), dan
> [`09-MODUL-LMS-PHASE-3.md`](09-MODUL-LMS-PHASE-3.md) (Phase 3).

---

## 1. Prinsip utama

Phase 4 **tidak membuat mesin baru** untuk hal yang sudah ada. Empat aturan yang
ditegakkan:

1. **Mesin course tetap satu-satunya sumber kebenaran.** Konten interaktif,
   kelas langsung, rekaman, dan sertifikat semuanya menempel pada
   `lms_learning_items`. Penyelesaian, progress, prasyarat, dan akses tetap
   dihitung `CourseCompletionService` / `CourseProgressService` yang sama.
2. **Fitur opsional harus benar-benar opsional.** Gamifikasi, sertifikat,
   ulasan, dan leaderboard default NONAKTIF. Saat modulnya mati, service
   mengembalikan `null`/kosong tanpa error, dan course lama tetap berjalan.
3. **Tidak ada klaim yang tidak berdasar.** Kehadiran kelas langsung tidak
   pernah dianggap otomatis; kolom `source` (MANUAL/SELF/PROVIDER) menegaskan
   asal data. Papan peringkat menghormati pengaturan privasi nama.
4. **Anti-abuse & idempotensi lewat database.** XP memakai `dedupe_key` unik,
   badge memakai indeks unik `(badge_id, user_id, course_id)`, sertifikat
   memakai `enrollment_id` unik. Job dobel atau API berulang tidak menggandakan
   apa pun.

---

## 2. Cakupan

| Area | § | Tabel | Service |
|---|---|---|---|
| Jalur belajar, prasyarat, drip | §1–§4 | `lms_learning_paths`, `lms_learning_path_items`, `lms_item_prerequisites`, `lms_drip_rules` | `LearningPathService`, `PrerequisiteService`, `DripContentService` |
| Konten interaktif | §7–§12 | `lms_interactive_contents`, `lms_interactive_content_versions`, `lms_interactive_responses` | `InteractiveContentService` |
| Kelas langsung, kehadiran, rekaman | §13–§17 | `lms_live_sessions`, `lms_live_session_attendance`, `lms_live_session_recordings` | `LiveSessionService` (+ `LiveMeetingRegistry`) |
| Sertifikat | §21–§25 | `lms_certificate_templates`, `lms_certificates`, `lms_certificate_verifications` | `CertificateService` |
| Badge, XP, rentetan belajar | §26–§30 | `lms_badges`, `lms_badge_rules`, `lms_badge_awards`, `lms_gamification_points`, `lms_learning_streaks` | `BadgeService`, `GamificationService` |
| Ulasan & umpan balik | §31–§32 | `lms_course_reviews`, `lms_course_feedback` | `CourseReviewService` |
| Pesan & sebutan | §33–§35 | *(menumpang `notifications`)* | `LmsMessagingService` |
| Engagement & kesehatan belajar | §58–§61 | `lms_learning_events` (agregasi) | `LearningEventService`, `LearningHealthService` |
| Template, salin, arsip | §46–§51 | `lms_course_templates`, `lms_course_copies`, `lms_course_archives` | `CourseTemplateService`, `CourseCopyService`, `CourseArchiveService` |

Kolom tambahan Phase 4 pada tabel Phase 1–3 (semuanya opsional dengan default
aman): `lms_courses.completion_rule`, `certificate_*`, `gamification_enabled`,
`leaderboard_enabled`, `review_enabled`, `allow_retake`, `max_attempts`,
`rating_average`, `rating_count`, `learning_path_enabled`, `drip_enabled`;
`lms_enrollments.attempt_number`, `xp_points`, `last_event_at`, `health_status`,
`health_computed_at`.

---

## 3. Konvensi yang dipakai ulang

* **Aktor** tetap `LmsActor` (integrated/standalone) — tidak ada service yang
  menyentuh model ERP secara langsung.
* **Audit** tetap `LogsLmsAudit` → `activity('lms')`.
* **Peristiwa belajar** hanya lewat `LearningEventService` (satu tabel,
  agregasi sederhana; bukan platform analitik).
* **Notifikasi** hanya lewat notification center ERP: `LmsNotifier` dengan kanal
  `database` (dapat ditambah tanpa mengubah service).
* **Penyedia pertemuan daring** lewat `LiveMeetingProviderInterface` +
  `LiveMeetingRegistry`: provider bawaan hanya menyimpan tautan.

---

## 4. Service yang ditambahkan

### 4.1 `LiveSessionService` (§13–§17)

* `forCourse()`, `forStudent()`, `find()`, `liveNowForCourse()`, `nextSession()`.
* `create()`, `update()`, `publish()` (mengirim pemberitahuan), `cancel()`,
  `delete()`, `refreshStatus()` (SCHEDULED → LIVE → COMPLETED mengikuti waktu).
* `joinInfo()` mengembalikan **alasan** bila belum dapat digabung (belum
  diterbitkan, dibatalkan, bukan peserta, tautan kosong, di luar jendela
  gabung). `join()` mencatat kehadiran SELF + peristiwa `live_session_joined`.
* `markAttendance()` (MANUAL), `syncProviderAttendance()` (hanya bila provider
  benar-benar mengirim data), `attendanceRows()`, `attendanceSummary()`,
  `attendanceForUser()`, `attendanceOverview()`, `pendingAttendanceCount()`.
* `addRecording()`, `addCourseRecording()`, `updateRecording()`,
  `deleteRecording()`, `recordingsForCourse()`, `recordWatch()` (ambang tonton).
* Payload siswa tidak pernah memuat `host_url`, `passcode`, atau tautan sesi
  yang belum dibuka.

### 4.2 `GamificationService` (§28–§30)

* `enabled()` = modul dinyalakan admin **dan** course mengizinkannya.
* `award()` idempotent lewat `dedupe_key`; `revoke()` menarik poin dan
  menyelaraskan cache `lms_enrollments.xp_points` (`syncEnrollmentXp()`,
  `syncCourseXp()`).
* `touchStreak()` menghitung rentetan per zona waktu terkonfigurasi dan memberi
  bonus tiap 7 hari; `streakFor()` mengembalikan status hari ini.
* `leaderboardForCourse()` / `leaderboardForGroup()` default NONAKTIF dan
  memakai nama sesuai `lms.gamification.display` (`name`/`initials`/`anonymized`).

### 4.3 `BadgeService` (§26–§27)

* CRUD badge + `syncRules()` (beberapa aturan = beberapa jalan mendapat badge).
* `evaluateEnrollment()` / `evaluateCourse()` menilai aturan otomatis
  (COMPLETION, GRADE, ACTIVITY_COUNT, FORUM_PARTICIPATION, COMPETENCY) dengan
  metrik yang sudah dihitung sistem; `MANUAL` tidak pernah dipicu sistem.
* `awardManual()` memberi ke banyak siswa sekaligus dan melaporkan yang
  dilewati; `revoke()` menghapus riwayat + menarik XP + mencatat alasan.

### 4.4 `CertificateService` (§21–§25)

* `eligibility()` mengembalikan daftar syarat beserta status & detailnya
  (sertifikat aktif, ketuntasan course sesuai `completion_rule`, progress
  minimum, nilai minimum, kompetensi wajib).
* `issue()` idempoten per `enrollment_id`; `issueIfEligible()`,
  `issueForCourse()` (dengan daftar yang dilewati), `eligibleStudents()`.
* Nomor `CERT-<tahun>-000001` berurutan; `verification_token` acak; snapshot
  nama/course/nilai dibekukan saat terbit.
* `verify()` mencatat setiap pemeriksaan (VALID/REVOKED/NOT_FOUND) tanpa
  membuka data pribadi; `revoke()`, `registerDownload()`, `render()`
  (placeholder diganti), dan CRUD template sertifikat.

### 4.5 `CourseReviewService` (§31–§32)

* `submit()` (satu ulasan per siswa per course, default PENDING),
  `update()`, `delete()`, `moderate()`, `markHelpful()`.
* `summary()`, `publicFor()`, `forModeration()`, `refreshRatingCache()`
  (hanya ulasan APPROVED yang dihitung untuk `rating_average`/`rating_count`).
* `submitFeedback()`, `feedbackForCourse()`, `feedbackSummary()` untuk umpan
  balik kualitatif (terpisah dari rating bintang).

### 4.6 `LearningHealthService` (§58–§61)

* `statusFor()` / `computeForEnrollment()` / `computeForCourse()` dengan status
  ON_TRACK, AT_RISK, INACTIVE, COMPLETED.
* Ambang configurable: `inactive_days`, `at_risk_overdue`,
  `low_completion_percent`. Sumber data hanya yang sudah ada (aktivitas
  terakhir, progress, tenggat terlampaui, nilai).
* `summaryForCourse()`, `atRiskAcrossCourses()`, `trendForCourse()`,
  `dashboardForTeacher()`, `recentActivity()`.

### 4.7 Template, salin, arsip (§46–§51)

* `CourseTemplateService`: `createFromCourse()` (snapshot struktur & konten),
  `apply()` (APPEND, atau REPLACE hanya bila course belum punya peserta),
  `applyPayload()`, `preview()`.
* `CourseCopyService`: `copyContent()` mencatat `lms_course_copies` + statistik
  dan **tidak pernah** menyalin peserta, nilai, submission, atau diskusi;
  `createCourseFromTemplate()` membuat course DRAFT + riwayat salin;
  `history()` untuk penelusuran asal-usul.
* `CourseArchiveService`: `archive()` (course read-only + snapshot),
  `restore()` (status dikembalikan seperti semula), `history()`, `active()`,
  `prune()` (hanya baris arsip lama yang sudah dipulihkan).

### 4.8 `LmsMessagingService` (§33–§35)

* `sendToStudents()`, `broadcastToCourse()`, `sendToGuardians()` — semua lewat
  notification center (`driver = notification`), dibatasi panjang pesan dan
  hanya guru/admin course yang boleh mengirim.
* `guardianUserIdsFor()` memakai data wali yang ada (ERP: `student_guardians` +
  `ppdb_parent_accounts`; Standalone: `lms_guardians`) dan mengembalikan daftar
  kosong bila tidak ada — bukan error.

---

## 5. Permission (tambahan Phase 4)

Total izin `lms.*` bertambah dari 92 menjadi **138** (46 izin baru). Yang baru:

* Jalur belajar & konten: `learning_path.view/manage`, `drip.manage`,
  `prerequisite.manage`, `interactive.view/create/update/delete/publish/respond`.
* Kelas langsung: `live.view/manage/attendance/join/recording.manage`.
* Sertifikat: `certificate.view/manage/issue/revoke/template.manage`,
  `certificate.view_own/view_child/download_own`.
* Badge & gamifikasi: `badge.view/manage/award/view_own/view_child`,
  `gamification.view/manage/view_own`, `leaderboard.view`.
* Ulasan & pesan: `review.view/create/moderate`, `feedback.submit/view`,
  `message.view/send`.
* Struktur & pemantauan: `template.view/manage`, `course.copy`, `course.archive`,
  `engagement.view/view_own/view_child`.

Pembagiannya: kepala sekolah & wali kelas memantau (baca + moderasi ulasan),
guru mengelola course-nya sendiri, siswa hanya melihat capaian miliknya, dan
wali murid hanya membaca capaian anaknya.

---

## 6. Pengaturan (`lms.settings`)

Sakelar Phase 4 dapat diubah dari halaman pengaturan tanpa deploy
(`LmsSettingsController`, dibaca kembali `LmsServiceProvider`):

`enforce_sequential`, `drip_enabled`, `live_default_provider`,
`live_join_window_minutes`, `live_join_grace_minutes`,
`live_recording_threshold`, `certificate_enabled`, `certificate_passing_grade`,
`gamification_enabled`, `leaderboard_enabled`, `leaderboard_display`,
`streak_enabled`, `review_enabled`, `review_auto_approve`, `review_display_name`,
`engagement_enabled`, `health_inactive_days`, `health_low_completion_percent`.

Endpoint `GET /api/v1/lms/settings` juga mengembalikan `live_providers`
(provider mana yang sudah punya integrasi, dukungan kehadiran, dan rekaman).

---

## 7. Pengujian

Dua berkas tes service-level Phase 4 (37 skenario):

| Berkas | Fokus |
|---|---|
| `tests/Feature/Lms/LmsPhase4FoundationTest.php` | XP dedupe, rentetan belajar, papan peringkat, badge otomatis & manual, sertifikat (kelayakan, idempotensi, verifikasi, render, penerbitan massal), kelas langsung (jendela gabung, kehadiran, rekaman, pembatalan, penyedia tautan-saja) |
| `tests/Feature/Lms/LmsPhase4ExperienceTest.php` | Ulasan & moderasi + cache rating, umpan balik, kesehatan belajar, template & penerapannya, salin konten, arsip/pemulihan, pesan & wali |

```
cd backend && php vendor/bin/phpunit tests/Feature/Lms/LmsPhase4FoundationTest.php
cd backend && php vendor/bin/phpunit tests/Feature/Lms/LmsPhase4ExperienceTest.php
```

---

## 8. Yang belum dikerjakan (langkah berikutnya)

1. **HTTP layer**: controller + route untuk kelas langsung, konten interaktif,
   sertifikat, badge/leaderboard, ulasan, template/salin/arsip, pesan, dan
   dasbor engagement — memakai service di atas; tidak ada logika bisnis baru di
   controller.
2. **UI**: pemutar konten interaktif, halaman kelas langsung + rekap kehadiran,
   halaman sertifikat (siswa & guru, plus halaman verifikasi publik), galeri
   badge/leaderboard, moderasi ulasan, dan dasbor kesehatan belajar.
3. **Pekerjaan terjadwal**: penutupan status sesi, penerbitan sertifikat otomatis
   saat course tuntas, evaluasi badge berkala, dan penyegaran cache kesehatan
   belajar (`refreshActiveCourses()`).
4. **Ekspor & cetak**: PDF sertifikat mengikuti template (kolom `file_path`
   sudah disiapkan; renderer belum diaktifkan).
