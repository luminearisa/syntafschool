# Modul LMS — Phase 2 (Assignment, Quiz, Question Bank, Forum & Learning Activities)

> Status: **selesai**. Berkas ini melengkapi [`07-MODUL-LMS.md`](07-MODUL-LMS.md)
> (Phase 1: course, section, learning item, enrollment, konten, progress).
> Phase 2 **tidak membuat ulang** apa pun dari Phase 1 — semua aktivitas
> menempel pada `lms_learning_items` sehingga otomatis mengikuti course,
> section, urutan, prasyarat, visibilitas, penyelesaian, dan progress.

---

## 1. Arsitektur Aktivitas

```
Course → Section → Learning Item → Activity → (Assignment | Quiz | Forum | Lesson | Choice | Survey)
                                        ↓
                              Submission / Attempt / Response
                                        ↓
                                    Grade → Completion → Progress
```

- `lms_learning_items` tetap sumber kebenaran untuk judul, urutan, visibilitas,
  prasyarat (`availability`), dan aturan selesai (`completion_type`).
- `lms_activities` menyimpan atribut umum aktivitas (status, jendela waktu,
  instruksi, aturan penyelesaian, penanda `is_graded`).
- Tabel per tipe menyimpan konfigurasi khusus: `lms_assignments`, `lms_quizzes`,
  `lms_forums`, `lms_lessons`, `lms_choices`, `lms_surveys`.
- `ActivityService::typeService()` memetakan tipe → konfigurator
  (`ActivityConfiguratorInterface`); `WORKSHOP` sudah disiapkan untuk phase
  berikutnya tanpa mengubah mesin aktivitas.

### Mode tetap dua (Phase 1)
`LMS_STANDALONE=false` (default) memakai entity ERP; `true` memakai identitas
mandiri. Tidak ada satu pun tabel aktivitas yang berubah antar mode karena
`user_id` selalu berupa integer ber-index (bukan foreign key).

---

## 2. Assignment (§3–§11, §44)

| Fitur | Bukti |
|---|---|
| Konfigurasi (submission_type, grading_type, maximum/passing grade, attempts, penalti) | `lms_assignments` + `AssignmentService` |
| Draf → kirim → kirim ulang tanpa menimpa riwayat | `AssignmentSubmissionService::saveDraft/submit`, attempt bertambah |
| Cutoff keras & keterlambatan | `close_at ?? due_at`, `allow_late_submission` |
| Penalti menyimpan nilai asli | `original_grade` + `late_penalty_percent`, `grade` = setelah penalti |
| Berkas submission | `lms_assignment_submission_files` + disk Laravel, unduhan ber-otorisasi |
| Penilaian + umpan balik (teks & berkas) | `AssignmentGradingService::grade` |
| Penilaian massal | `POST /lms/activities/{id}/assignment/bulk-grade` |
| Statistik guru | `AssignmentService::statistics` (submitted, late, graded, rata-rata, tingkat lulus) |
| Duplikasi tanpa riwayat | `ActivityService::duplicate` (§44) |

Validasi: `0 ≤ nilai ≤ maximum_grade`; `passing_grade` menentukan `passed`;
status akademik SIAKAD **tidak** berubah (lihat §8 di bawah).

---

## 3. Quiz, Question Bank & Question Versioning (§12–§31)

- **Bank soal reusable**: `lms_question_banks` → `lms_question_categories`
  (bertingkat) → `lms_questions` → `lms_question_options`.
- **Tipe soal**: pilihan ganda tunggal/ganda, benar-salah, jawaban singkat
  (case/exact/partial match), numerik (toleransi), esai (manual), menjodohkan.
  Tipe `DRAG_DROP`, `CLOZE`, `ORDERING` sudah terdaftar sebagai "belum aktif"
  sehingga penambahannya cukup mendaftarkan penilai baru pada
  `QuestionTypeRegistry` — mesin kuis tidak disentuh.
- **Versioning**: setiap perubahan soal menaikkan `version` dan menyimpan
  snapshot pada `lms_question_versions`; attempt menyimpan snapshot sendiri di
  `lms_quiz_attempt_questions.snapshot`, sehingga hasil lama tetap sah.
- **Pemilihan soal**: manual (`question_id`) atau slot acak per kategori
  (`category_id` + `question_count`) — kombinasi soal berbeda per siswa.
- **Percobaan**: `lms_quiz_attempts` + `lms_quiz_attempt_questions` +
  `lms_quiz_answers` (audit jawaban per soal, bukan hanya skor akhir).
- **Timer server-side**: `expires_at` dihitung server; setiap permintaan
  memeriksa kedaluwarsa dan melakukan *auto submit* + *auto grade* (§27).
- **Penilaian**: otomatis per tipe soal, esai menunggu guru
  (`MANUAL_GRADING`), normalisasi `raw_score → final_grade` ke `maximum_grade`,
  dan strategi lintas percobaan (HIGHEST/LATEST/AVERAGE/FIRST).
- **Keamanan**: payload siswa tidak pernah memuat `is_correct`/kunci sebelum
  percobaan selesai; review mengikuti `review_options`.
- **Statistik**: percobaan, rata-rata, tertinggi/terendah, tingkat lulus,
  penyelesaian, dan statistik per soal (benar/salah/rata-rata mark).

---

## 4. Forum (§32–§36)

- `lms_forums` (tipe, moderasi, langganan, syarat partisipasi, batas kedalaman).
- `lms_forum_discussions` → `lms_forum_posts` (balasan bersarang dengan
  `depth` dibatasi `max_depth`) → `lms_forum_subscriptions`.
- Moderasi penuh: setujui, sembunyikan, hapus, pin, kunci — siswa tidak pernah
  melihat kiriman berstatus PENDING/HIDDEN.
- Notifikasi memakai kanal `database` yang sudah ada (tidak ada sistem
  notifikasi baru).
- Guru pengampu dapat ikut berdiskusi **tanpa** enrollment; siswa wajib
  terdaftar aktif.

---

## 5. Lesson, Choice & Survey (§37–§39)

- **Lesson**: `lms_lessons` → `lms_lesson_pages` (materi/pertanyaan) →
  `lms_lesson_branches` (jawaban benar/salah menuju halaman berbeda) +
  `lms_lesson_progress` (halaman yang dikunjungi). Penyelesaian ketika seluruh
  halaman wajib telah dibuka.
- **Choice**: opsi, batas pilihan, polling tunggal/ganda, visibilitas hasil
  (setelah menjawab / setelah ditutup / selalu / tidak pernah), mode anonim,
  serta rekap jumlah & persentase.
- **Survey**: pertanyaan bertipe RATING, MULTIPLE_CHOICE, YES_NO, TEXT; mode
  anonim benar-benar tidak menyimpan `user_id` (hanya `submission_key` acak);
  rekap rata-rata, distribusi, dan komentar.

---

## 6. Penyelesaian, Ketersediaan & Nilai

- **§40 Integrasi penyelesaian**: setiap aktivitas memetakan peristiwa
  (`submitted`, `posted`, `completed`, `responded`) ke mesin penyelesaian
  Phase 1 (`lms_activity_completions`), sehingga progress course, prasyarat,
  dan kalender otomatis bergerak.
- **§41 Ketersediaan**: status terbit → jendela waktu → prasyarat item
  (Phase 1) → prasyarat aktivitas + nilai minimum → pembatasan kelompok.
  Alasan penolakan selalu disertai pesan siap tampil ("🔒 Selesaikan Kuis 1
  dengan nilai minimal 70%").
- **§42 Navigasi**: Previous/Next antar aktivitas pada course yang sama.
- **§46–§47 Nilai**: `lms_activity_grades` menyimpan `grade`, `original_grade`,
  `raw_grade`, `maximum_grade`, `passed`, `feedback`, `graded_by`, `graded_at`
  — sumber data Gradebook Phase 3 melalui `GradeProviderInterface`
  (`LmsGradeProvider`). **Nilai rapor SIAKAD tidak pernah berubah otomatis**:
  `ActivityGradeSyncService::push()` hanya menyiapkan payload dan memerlukan
  permintaan eksplisit + izin `lms.grade.manage`.

---

## 7. Permission (tambahan Phase 2)

`lms.activity.*`, `lms.assignment.{view,create,update,delete,grade,submit}`,
`lms.quiz.{view,create,update,delete,attempt,grade}`,
`lms.question.{view,create,update,delete}`, `lms.questionbank.manage`,
`lms.forum.{view,create,reply,moderate}`,
`lms.lesson.{view,create,update,delete}`,
`lms.choice.{view,create,respond}`, `lms.survey.{view,create,respond}`,
`lms.grade.{view,manage}`, `lms.submission.{view,grade,upload}`.

Pembagian: admin-sekolah (semua), kepala-sekolah (baca), guru (mengelola &
menilai course-nya), wali-kelas (baca + lihat pengumpulan), siswa (mengerjakan
miliknya sendiri), wali-murid (hanya membaca).

---

## 8. API Ringkas (Phase 2)

```
GET    /api/v1/lms/activity-types
GET    /api/v1/lms/courses/{course}/activities
POST   /api/v1/lms/sections/{section}/activities
GET|PUT|DELETE /api/v1/lms/activities/{activity}
POST   /api/v1/lms/activities/{activity}/{publish|unpublish|archive|duplicate}
GET    /api/v1/lms/activities/{activity}/preview

GET    /api/v1/lms/activities/{activity}/assignment
PUT    /api/v1/lms/activities/{activity}/assignment
GET    /api/v1/lms/activities/{activity}/assignment/overview|statistics
POST   /api/v1/lms/activities/{activity}/assignment/bulk-grade
GET    /api/v1/lms/assignments/{assignment} | /submissions | POST /submit
POST   /api/v1/lms/activities/{activity}/submissions/draft|submit
GET    /api/v1/lms/activities/{activity}/submissions | /submission-status
GET    /api/v1/lms/submissions/{submission} | POST /files | /grade | /return | /reject
GET    /api/v1/lms/submission-files/{file}   (unduhan ber-otorisasi)

GET    /api/v1/lms/activities/{activity}/quiz | /builder | /statistics | /attempts
POST   /api/v1/lms/activities/{activity}/quiz/questions
POST   /api/v1/lms/quizzes/{quiz}/attempts | /activities/{activity}/quiz/attempts
GET    /api/v1/lms/attempts/{attempt} | /result | /detail
POST   /api/v1/lms/attempts/{attempt}/answers | /submit
POST   /api/v1/lms/quiz-answers/{answer}/grade
DELETE /api/v1/lms/quiz-slots/{slot} | PUT /api/v1/lms/quiz-slots/{slot}

GET    /api/v1/lms/question-banks | POST /question-banks
GET    /api/v1/lms/question-banks/{bank} | POST /categories | GET /questions
PUT|DELETE /api/v1/lms/question-categories/{category}
GET|POST /api/v1/lms/questions, GET|PUT|DELETE /questions/{question}, POST /duplicate

GET    /api/v1/lms/activities/{activity}/forum | POST /discussions | /subscribe
GET    /api/v1/lms/forums/{forum} | POST /forums/{forum}/discussions
GET    /api/v1/lms/discussions/{discussion} | POST /discussions/{discussion}/posts
PUT|DELETE /api/v1/lms/forum-posts/{post}
POST   /api/v1/lms/discussions/{discussion}/{pin|lock}
GET    /api/v1/lms/activities/{activity}/forum/moderation|statistics
POST   /api/v1/lms/forum-moderation/{type}/{id}/{approve|hide} | DELETE ...

GET    /api/v1/lms/activities/{activity}/lesson | /builder | /statistics
POST   /api/v1/lms/activities/{activity}/lesson/progress | PUT /lesson
POST   /api/v1/lms/activities/{activity}/lesson/pages
PUT|DELETE /api/v1/lms/lesson-pages/{page}
GET    /api/v1/lms/lessons/{lesson} | POST /lessons/{lesson}/progress

GET    /api/v1/lms/activities/{activity}/choice | PUT | POST /respond | GET /results
GET    /api/v1/lms/choices/{choice} | POST /choices/{choice}/respond

GET    /api/v1/lms/activities/{activity}/survey | PUT | POST /submit | GET /results
GET    /api/v1/lms/surveys/{survey} | POST /surveys/{survey}/submit

GET    /api/v1/lms/courses/{course}/grades        (siswa/guru/wali)
GET    /api/v1/lms/activities/{activity}/grades   (guru)
POST   /api/v1/lms/activities/{activity}/grades   (nilai manual, mis. forum)
POST   /api/v1/lms/activities/{activity}/grades/push   (eksplisit ke SIAKAD)
```

---

## 9. Frontend

```
src/features/lms/
  types.phase2.ts                     # tipe aktivitas, submission, attempt, bank soal
  api/activityApi.ts                  # seluruh endpoint Phase 2
  components/activities/
    ActivityHeader.tsx                # judul, status, tenggat, alasan terkunci, nav
    ActivityWizard.tsx                # wizard 4 langkah (§43)
    AssignmentPlayer.tsx              # siswa: draf, unggah, kirim, nilai+feedback
    QuizPlayer.tsx                    # timer server, simpan jawaban, hasil & pembahasan
    ForumPlayer.tsx                   # diskusi, balasan bersarang, langganan, moderasi
    LessonPlayer.tsx                  # halaman, cabang, progres
    ChoicePlayer.tsx / SurveyPlayer.tsx
    manage/AssignmentManage.tsx       # pengumpulan, penilaian, statistik, pengaturan
    manage/QuizManage.tsx             # soal (manual/acak), pengaturan, statistik, esai
    manage/EngagementManage.tsx       # forum, lesson, polling, survei
    manage/QuestionEditor.tsx         # editor 7 tipe soal + riwayat versi
  pages/
    LmsActivityPlayerPage.tsx         # /lms/activities/:id
    LmsActivityManagePage.tsx         # /lms/activities/:id/manage
    LmsQuestionBankPage.tsx           # /lms/question-banks
```

Integrasi: silabus course menautkan item aktivitas langsung ke halaman
pengerjaan; ruang belajar memakai tautan yang sama; dasbor siswa memuat
"Segera dikerjakan" & "Baru dinilai"; dasbor guru memuat "Menunggu penilaian
Anda"; halaman anak pada portal wali menampilkan status aktivitas (read-only).

---

## 10. Pengujian

```bash
cd backend && php artisan test tests/Feature/Lms
```

| Berkas | Cakupan |
|---|---|
| `LmsAssignmentTest` | draf, kirim, kirim ulang, terlambat + penalti, cutoff, berkas, penilaian, statistik, duplikasi, validasi nilai |
| `LmsQuizTest` | 7 tipe soal, versioning, pemilihan acak, auto & manual grading, timer server, review, strategi nilai, navigasi berurutan, statistik |
| `LmsForumTest` | diskusi, balasan bersarang + batas kedalaman, moderasi, langganan + notifikasi, otorisasi |
| `LmsLessonTest` | navigasi halaman, cabang jawaban, penyelesaian, statistik |
| `LmsChoiceSurveyTest` | polling tunggal/ganda, batas & visibilitas hasil, survei wajib, agregasi, mode anonim |
| `LmsActivitySecurityTest` | isolasi submission & attempt, larangan menilai, wali read-only, guru course lain, guest, aktivitas draf, prasyarat nilai |
| `LmsActivityFlowTest` | alur menyeluruh tugas, kuis, forum, lesson, serta dasbor guru & siswa (§56) |

---

## 11. Peta Definition of Done (§57)

| Item | Status | Bukti |
|---|---|---|
| Activity architecture | ✅ | `lms_activities` + `ActivityConfiguratorInterface` |
| Assignment & submission | ✅ | `LmsAssignmentTest` |
| Berkas submission | ✅ | unggah + unduhan ber-otorisasi |
| Penilaian & umpan balik | ✅ | `AssignmentGradingService` |
| Late submission & cutoff | ✅ | penalti + `close_at` |
| Quiz & quiz attempt | ✅ | `lms_quizzes` + `lms_quiz_attempts` |
| Timer server-side | ✅ | `expires_at` + auto submit |
| Auto grading & manual grading | ✅ | `QuizGradingService`, esai manual |
| Question bank & versioning | ✅ | `lms_question_versions` + snapshot attempt |
| Multiple question types | ✅ | 7 tipe aktif, 3 tipe disiapkan |
| Random question | ✅ | slot acak per kategori |
| Quiz review & statistics | ✅ | `review_options`, statistik per soal |
| Forum, discussion, reply, moderation | ✅ | `LmsForumTest` |
| Lesson & branching | ✅ | `LmsLessonTest` |
| Choice & survey | ✅ | `LmsChoiceSurveyTest` |
| Activity completion & availability | ✅ | `ActivityCompletionService`, `ActivityAvailabilityService` |
| Grade record & GradeProviderInterface | ✅ | `lms_activity_grades`, `LmsGradeProvider` |
| Parent read-only | ✅ | `LmsActivitySecurityTest`, `LmsParentChildPage` |
| Teacher & student dashboard | ✅ | `activity_pending_grading`, `due_soon`, `recently_graded` |
| API, permission, security | ✅ | 204 rute LMS, 72 izin `lms.*` |
| Unit/feature/integration/E2E test | ✅ | 7 berkas tes Phase 2 |

---

## 12. Menyiapkan Data Contoh

```bash
cd backend
php artisan migrate --force
php artisan db:seed --force     # termasuk LmsPermissionSeeder, LmsDemoSeeder, LmsActivityDemoSeeder
```

`LmsActivityDemoSeeder` membuat satu set aktivitas lengkap (tugas, kuis dengan
bank soal, forum, lesson bercabang, polling, survei) pada course contoh.
