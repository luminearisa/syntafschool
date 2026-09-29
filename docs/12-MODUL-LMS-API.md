# Modul LMS — Dokumentasi API (§33–§34)

Base URL: `/api/v1/lms`. Spesifikasi mesin: [`openapi-lms.yaml`](openapi-lms.yaml)
(307 path, OpenAPI 3.0.3 — dapat dibuka di Swagger UI/Postman/Insomnia).

## 1. Format respons

Sukses:

```json
{ "success": true, "data": { }, "message": "OK", "meta": { } }
```

Error validasi (HTTP 422):

```json
{
  "success": false,
  "message": "Data yang diberikan tidak valid",
  "errors": { "title": ["Judul wajib diisi."] }
}
```

Error otorisasi (HTTP 403) menyertakan izin yang kurang agar dapat ditindaklanjuti:

```json
{ "success": false, "message": "Anda tidak memiliki izin untuk tindakan ini pada LMS.",
  "errors": { "permission": ["lms.grade.sync"] } }
```

Status yang dipakai: `200` OK · `201` dibuat · `401` belum terautentikasi ·
`403` tidak berwenang · `404` tidak ditemukan · `409` konflik (mis. nilai sudah
dikunci) · `422` validasi gagal · `429` melebihi batas laju.

## 2. Autentikasi

| Cara | Keterangan |
|---|---|
| Bearer token Sanctum | `POST /api/v1/lms/auth/login` → `token`; kirim `Authorization: Bearer <token>` |
| Akun ERP | Integrated mode: kredensial `users` ERP (peran Spatie menentukan izin `lms.*`) |
| Akun mandiri | Standalone mode: `LMS_STANDALONE=true` memakai `lms_users` |
| Publik | Rute `public/*` (katalog, halaman guru, verifikasi sertifikat) tanpa token |

Izin diperiksa **di server** pada setiap endpoint (`lms.permission:*`) dan
kepemilikan course diperiksa di controller melalui `CourseAccessService` — guru
hanya dapat mengelola course yang menjadi tanggung jawabnya, siswa hanya data
miliknya, wali hanya anak yang hubungannya sah.

## 3. Rate limit (§30)

| Limiter | Bawaan | Dipakai pada |
|---|---|---|
| `lms-quiz-attempt` | 10/menit | memulai percobaan kuis |
| `lms-quiz-answer` | 120/menit | menyimpan jawaban & mengumpulkan kuis |
| `lms-submission` | 20/menit | mengumpulkan tugas |
| `lms-forum-post` | 30/menit | menulis diskusi/balasan forum |
| `lms-file-upload` | 30/menit | unggah berkas & paket SCORM |
| `lms-certificate-verify` | 30/menit | verifikasi sertifikat publik (anti enumerasi token) |
| `lms-search` | 60/menit | pencarian |
| `lms-course-import` | 10/menit | impor course |
| `lms-report-export` | 20/menit | ekspor laporan |
| `lms-grade-sync` | 60/menit | antrean/retry/proses sinkronisasi nilai |
| `lms-scorm-tracking` | 300/menit | peluncuran & commit SCORM |
| `lms-message` | 60/menit | mengirim pesan ke siswa/wali |
| `lms-public-api` | 120/menit | endpoint publik & health |

Semua batas dapat diubah lewat `lms.rate_limits.*` tanpa deploy.

## 4. Idempotensi

Endpoint berikut menerima `idempotency_key` (opsional kecuali dinyatakan) dan
aman dipanggil ulang: impor course (`course-imports`), penerbitan sertifikat
(idempoten per pendaftaran secara desain), pemberian badge (idempoten per
siswa+course+course_id), sinkronisasi nilai (hash payload + antrean), commit
SCORM (`idempotency_key`), ekspor laporan, dan pemberitahuan (kunci internal).

## 5. Daftar endpoint


### Publik (tanpa token)

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/public/about` | — (kepemilikan course / terautentikasi) | — | `lms.public.about` |
| GET | `/lms/public/categories` | — (kepemilikan course / terautentikasi) | — | `lms.public.categories` |
| GET | `/lms/public/certificates/verify` | — (kepemilikan course / terautentikasi) | — | `lms.public.certificates.verify` |
| GET | `/lms/public/courses` | — (kepemilikan course / terautentikasi) | — | `lms.public.courses` |
| GET | `/lms/public/courses/{slug}` | — (kepemilikan course / terautentikasi) | — | `lms.public.courses.show` |
| GET | `/lms/public/home` | — (kepemilikan course / terautentikasi) | — | `lms.public.home` |
| GET | `/lms/public/teachers` | — (kepemilikan course / terautentikasi) | — | `lms.public.teachers` |

### Autentikasi LMS

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/auth/login` | — (kepemilikan course / terautentikasi) | — | `lms.auth.login` |
| POST | `/lms/auth/logout` | — (kepemilikan course / terautentikasi) | — | `lms.auth.logout` |
| GET | `/lms/auth/me` | — (kepemilikan course / terautentikasi) | — | `lms.auth.me` |
| POST | `/lms/auth/register` | — (kepemilikan course / terautentikasi) | — | `lms.auth.register` |
| GET | `/lms/auth/session` | — (kepemilikan course / terautentikasi) | — | `lms.auth.session` |

### Dashboard semua peran

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/dashboard` | — (kepemilikan course / terautentikasi) | — | `lms.dashboard` |
| GET | `/lms/dashboard/admin` | — (kepemilikan course / terautentikasi) | — | `lms.dashboard.admin` |
| GET | `/lms/dashboard/parent` | — (kepemilikan course / terautentikasi) | — | `lms.dashboard.parent` |
| GET | `/lms/dashboard/student` | — (kepemilikan course / terautentikasi) | — | `lms.dashboard.student` |
| GET | `/lms/dashboard/teacher` | — (kepemilikan course / terautentikasi) | — | `lms.dashboard.teacher` |

### Course, kurikulum & siklus hidup

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/courses` | — (kepemilikan course / terautentikasi) | — | `lms.courses.index` |
| POST | `/lms/courses` | — (kepemilikan course / terautentikasi) | — | `lms.courses.store` |
| GET | `/lms/courses/lookups` | — (kepemilikan course / terautentikasi) | — | `lms.courses.lookups` |
| DELETE | `/lms/courses/{course}` | — (kepemilikan course / terautentikasi) | — | `lms.courses.destroy` |
| GET | `/lms/courses/{course}` | — (kepemilikan course / terautentikasi) | — | `lms.courses.show` |
| PUT, PATCH | `/lms/courses/{course}` | — (kepemilikan course / terautentikasi) | — | `lms.courses.update` |
| GET | `/lms/courses/{course}/activities` | — (kepemilikan course / terautentikasi) | — | `lms.courses.activities.index` |
| GET | `/lms/courses/{course}/announcements` | — (kepemilikan course / terautentikasi) | — | `lms.courses.announcements.index` |
| POST | `/lms/courses/{course}/announcements` | — (kepemilikan course / terautentikasi) | — | `lms.courses.announcements.store` |
| POST | `/lms/courses/{course}/archive` | — (kepemilikan course / terautentikasi) | — | `lms.courses.archive` |
| POST | `/lms/courses/{course}/bookmark` | — (kepemilikan course / terautentikasi) | — | `lms.courses.bookmark` |
| GET | `/lms/courses/{course}/competencies` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.course` |
| POST | `/lms/courses/{course}/competencies/map` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.course.map` |
| GET | `/lms/courses/{course}/competencies/students/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.student` |
| GET | `/lms/courses/{course}/continue` | — (kepemilikan course / terautentikasi) | — | `lms.courses.continue` |
| POST | `/lms/courses/{course}/duplicate` | — (kepemilikan course / terautentikasi) | — | `lms.courses.duplicate` |
| GET | `/lms/courses/{course}/engagement` | — (kepemilikan course / terautentikasi) | — | `lms.engagement.course` |
| POST | `/lms/courses/{course}/enroll` | — (kepemilikan course / terautentikasi) | — | `lms.courses.enroll` |
| GET | `/lms/courses/{course}/enrollments` | — (kepemilikan course / terautentikasi) | — | `lms.courses.enrollments.index` |
| POST | `/lms/courses/{course}/enrollments` | — (kepemilikan course / terautentikasi) | — | `lms.courses.enrollments.store` |
| POST | `/lms/courses/{course}/enrollments/bulk` | — (kepemilikan course / terautentikasi) | — | `lms.courses.enrollments.bulk` |
| GET | `/lms/courses/{course}/enrollments/candidates` | — (kepemilikan course / terautentikasi) | — | `lms.courses.enrollments.candidates` |
| POST | `/lms/courses/{course}/enrollments/sync-academic` | — (kepemilikan course / terautentikasi) | — | `lms.courses.enrollments.sync` |
| GET | `/lms/courses/{course}/feedback` | — (kepemilikan course / terautentikasi) | — | `lms.feedback.index` |
| POST | `/lms/courses/{course}/feedback` | — (kepemilikan course / terautentikasi) | — | `lms.feedback.store` |
| GET | `/lms/courses/{course}/grade-scales` | — (kepemilikan course / terautentikasi) | — | `lms.grade-scales.index` |
| GET | `/lms/courses/{course}/gradebook` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.show` |
| PUT | `/lms/courses/{course}/gradebook` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.update` |
| POST | `/lms/courses/{course}/gradebook/calculate` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.calculate` |
| POST | `/lms/courses/{course}/gradebook/categories` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.categories.store` |
| GET | `/lms/courses/{course}/gradebook/export` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.export` |
| POST | `/lms/courses/{course}/gradebook/freeze` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.freeze` |
| GET | `/lms/courses/{course}/gradebook/items` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.items` |
| POST | `/lms/courses/{course}/gradebook/items` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.items.store` |
| POST | `/lms/courses/{course}/gradebook/release` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.release` |
| GET | `/lms/courses/{course}/gradebook/releases` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.releases` |
| GET | `/lms/courses/{course}/gradebook/sheet` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.sheet` |
| GET | `/lms/courses/{course}/gradebook/statistics` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.statistics` |
| POST | `/lms/courses/{course}/gradebook/sync-items` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.sync-items` |
| POST | `/lms/courses/{course}/gradebook/unfreeze` | — (kepemilikan course / terautentikasi) | — | `lms.gradebook.unfreeze` |
| GET | `/lms/courses/{course}/grades` | — (kepemilikan course / terautentikasi) | — | `lms.courses.grades` |
| GET | `/lms/courses/{course}/grades/payload/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.grades.payload` |
| POST | `/lms/courses/{course}/grades/sync` | — (kepemilikan course / terautentikasi) | — | `lms.grades.sync` |
| GET | `/lms/courses/{course}/groups` | — (kepemilikan course / terautentikasi) | — | `lms.courses.groups.index` |
| POST | `/lms/courses/{course}/groups` | — (kepemilikan course / terautentikasi) | — | `lms.courses.groups.store` |
| GET | `/lms/courses/{course}/health` | — (kepemilikan course / terautentikasi) | — | `lms.engagement.health` |
| GET | `/lms/courses/{course}/items/{item}` | — (kepemilikan course / terautentikasi) | — | `lms.courses.items.show` |
| DELETE | `/lms/courses/{course}/items/{item}/complete` | — (kepemilikan course / terautentikasi) | — | `lms.courses.items.uncomplete` |
| POST | `/lms/courses/{course}/items/{item}/complete` | — (kepemilikan course / terautentikasi) | — | `lms.courses.items.complete` |
| POST | `/lms/courses/{course}/items/{item}/view` | — (kepemilikan course / terautentikasi) | — | `lms.courses.items.view` |
| GET | `/lms/courses/{course}/learn` | — (kepemilikan course / terautentikasi) | — | `lms.courses.learn` |
| POST | `/lms/courses/{course}/messages` | — (kepemilikan course / terautentikasi) | — | `lms.engagement.message` |
| GET | `/lms/courses/{course}/messages/recipients` | — (kepemilikan course / terautentikasi) | — | `lms.engagement.recipients` |
| GET | `/lms/courses/{course}/outcomes` | — (kepemilikan course / terautentikasi) | — | `lms.outcomes.index` |
| POST | `/lms/courses/{course}/outcomes` | — (kepemilikan course / terautentikasi) | — | `lms.outcomes.store` |
| POST | `/lms/courses/{course}/outcomes/recalculate` | — (kepemilikan course / terautentikasi) | — | `lms.outcomes.recalculate` |
| GET | `/lms/courses/{course}/outcomes/students/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.outcomes.student` |
| GET | `/lms/courses/{course}/progress` | — (kepemilikan course / terautentikasi) | — | `lms.courses.progress` |
| POST | `/lms/courses/{course}/publish` | — (kepemilikan course / terautentikasi) | — | `lms.courses.publish` |
| GET | `/lms/courses/{course}/readiness` | — (kepemilikan course / terautentikasi) | — | `lms.courses.readiness` |
| POST | `/lms/courses/{course}/restore` | — (kepemilikan course / terautentikasi) | — | `lms.courses.restore` |
| GET | `/lms/courses/{course}/reviews` | — (kepemilikan course / terautentikasi) | — | `lms.reviews.index` |
| POST | `/lms/courses/{course}/reviews` | — (kepemilikan course / terautentikasi) | — | `lms.reviews.store` |
| GET | `/lms/courses/{course}/reviews/moderation` | — (kepemilikan course / terautentikasi) | — | `lms.reviews.moderation` |
| GET | `/lms/courses/{course}/rubrics` | — (kepemilikan course / terautentikasi) | — | `lms.rubrics.index` |
| GET | `/lms/courses/{course}/sections` | — (kepemilikan course / terautentikasi) | — | `lms.courses.sections.index` |
| POST | `/lms/courses/{course}/sections` | — (kepemilikan course / terautentikasi) | — | `lms.courses.sections.store` |
| POST | `/lms/courses/{course}/sections/reorder` | — (kepemilikan course / terautentikasi) | — | `lms.courses.sections.reorder` |
| GET | `/lms/courses/{course}/statistics` | — (kepemilikan course / terautentikasi) | — | `lms.courses.statistics` |
| POST | `/lms/courses/{course}/unpublish` | — (kepemilikan course / terautentikasi) | — | `lms.courses.unpublish` |

### Bagian course

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/sections/{section}` | — (kepemilikan course / terautentikasi) | — | `lms.sections.destroy` |
| PUT, PATCH | `/lms/sections/{section}` | — (kepemilikan course / terautentikasi) | — | `lms.sections.update` |
| POST | `/lms/sections/{section}/activities` | — (kepemilikan course / terautentikasi) | — | `lms.sections.activities.store` |
| POST | `/lms/sections/{section}/duplicate` | — (kepemilikan course / terautentikasi) | — | `lms.sections.duplicate` |
| POST | `/lms/sections/{section}/items` | — (kepemilikan course / terautentikasi) | — | `lms.sections.items.store` |
| POST | `/lms/sections/{section}/items/reorder` | — (kepemilikan course / terautentikasi) | — | `lms.sections.items.reorder` |

### Materi & item kurikulum

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/items/{item}` | — (kepemilikan course / terautentikasi) | — | `lms.items.destroy` |
| GET | `/lms/items/{item}` | — (kepemilikan course / terautentikasi) | — | `lms.items.show` |
| PUT, PATCH | `/lms/items/{item}` | — (kepemilikan course / terautentikasi) | — | `lms.items.update` |
| GET | `/lms/items/{item}/chapters` | — (kepemilikan course / terautentikasi) | — | `lms.items.chapters` |
| PUT | `/lms/items/{item}/chapters` | — (kepemilikan course / terautentikasi) | — | `lms.items.chapters.save` |
| POST | `/lms/items/{item}/duplicate` | — (kepemilikan course / terautentikasi) | — | `lms.items.duplicate` |
| POST | `/lms/items/{item}/move` | — (kepemilikan course / terautentikasi) | — | `lms.items.move` |
| POST | `/lms/items/{item}/upload` | — (kepemilikan course / terautentikasi) | — | `lms.items.upload` |

### Aktivitas pembelajaran

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/activities/{activity}` | — (kepemilikan course / terautentikasi) | — | `lms.activities.destroy` |
| GET | `/lms/activities/{activity}` | — (kepemilikan course / terautentikasi) | — | `lms.activities.show` |
| PUT, PATCH | `/lms/activities/{activity}` | — (kepemilikan course / terautentikasi) | — | `lms.activities.update` |
| POST | `/lms/activities/{activity}/archive` | — (kepemilikan course / terautentikasi) | — | `lms.activities.archive` |
| GET | `/lms/activities/{activity}/assignment` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.show` |
| PUT, PATCH | `/lms/activities/{activity}/assignment` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.update` |
| POST | `/lms/activities/{activity}/assignment/bulk-grade` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.bulk-grade` |
| GET | `/lms/activities/{activity}/assignment/overview` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.overview` |
| GET | `/lms/activities/{activity}/assignment/statistics` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.statistics` |
| GET | `/lms/activities/{activity}/choice` | — (kepemilikan course / terautentikasi) | — | `lms.choices.show` |
| PUT, PATCH | `/lms/activities/{activity}/choice` | — (kepemilikan course / terautentikasi) | — | `lms.choices.update` |
| POST | `/lms/activities/{activity}/choice/respond` | — (kepemilikan course / terautentikasi) | — | `lms.choices.respond` |
| GET | `/lms/activities/{activity}/choice/results` | — (kepemilikan course / terautentikasi) | — | `lms.choices.results` |
| POST | `/lms/activities/{activity}/competencies/map` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.activity.map` |
| POST | `/lms/activities/{activity}/duplicate` | — (kepemilikan course / terautentikasi) | — | `lms.activities.duplicate` |
| GET | `/lms/activities/{activity}/forum` | — (kepemilikan course / terautentikasi) | — | `lms.forums.show` |
| PUT, PATCH | `/lms/activities/{activity}/forum` | — (kepemilikan course / terautentikasi) | — | `lms.forums.update` |
| POST | `/lms/activities/{activity}/forum/discussions` | — (kepemilikan course / terautentikasi) | — | `lms.forums.discussions.store` |
| GET | `/lms/activities/{activity}/forum/moderation` | — (kepemilikan course / terautentikasi) | — | `lms.forums.moderation` |
| GET | `/lms/activities/{activity}/forum/statistics` | — (kepemilikan course / terautentikasi) | — | `lms.forums.statistics` |
| DELETE | `/lms/activities/{activity}/forum/subscribe` | — (kepemilikan course / terautentikasi) | — | `lms.forums.unsubscribe` |
| POST | `/lms/activities/{activity}/forum/subscribe` | — (kepemilikan course / terautentikasi) | — | `lms.forums.subscribe` |
| GET | `/lms/activities/{activity}/grade` | — (kepemilikan course / terautentikasi) | — | `lms.activities.grade` |
| GET | `/lms/activities/{activity}/grades` | — (kepemilikan course / terautentikasi) | — | `lms.activities.grades` |
| POST | `/lms/activities/{activity}/grades` | — (kepemilikan course / terautentikasi) | — | `lms.activities.grades.store` |
| POST | `/lms/activities/{activity}/grades/push` | — (kepemilikan course / terautentikasi) | — | `lms.activities.grades.push` |
| GET | `/lms/activities/{activity}/lesson` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.show` |
| PUT, PATCH | `/lms/activities/{activity}/lesson` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.update` |
| GET | `/lms/activities/{activity}/lesson/builder` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.builder` |
| POST | `/lms/activities/{activity}/lesson/pages` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.pages.store` |
| POST | `/lms/activities/{activity}/lesson/progress` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.progress` |
| GET | `/lms/activities/{activity}/lesson/statistics` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.statistics` |
| GET | `/lms/activities/{activity}/preview` | — (kepemilikan course / terautentikasi) | — | `lms.activities.preview` |
| POST | `/lms/activities/{activity}/publish` | — (kepemilikan course / terautentikasi) | — | `lms.activities.publish` |
| GET | `/lms/activities/{activity}/quiz` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.show` |
| PUT, PATCH | `/lms/activities/{activity}/quiz` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.update` |
| GET | `/lms/activities/{activity}/quiz/attempts` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.attempts.index` |
| POST | `/lms/activities/{activity}/quiz/attempts` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.attempts.store` |
| GET | `/lms/activities/{activity}/quiz/builder` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.builder` |
| POST | `/lms/activities/{activity}/quiz/questions` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.questions.store` |
| GET | `/lms/activities/{activity}/quiz/statistics` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.statistics` |
| GET | `/lms/activities/{activity}/quiz/student-attempts/{user_id}` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.attempts.student` |
| GET | `/lms/activities/{activity}/submission-status` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.status` |
| GET | `/lms/activities/{activity}/submissions` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.index` |
| POST | `/lms/activities/{activity}/submissions/draft` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.draft` |
| POST | `/lms/activities/{activity}/submissions/submit` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.submit` |
| GET | `/lms/activities/{activity}/survey` | — (kepemilikan course / terautentikasi) | — | `lms.surveys.show` |
| PUT, PATCH | `/lms/activities/{activity}/survey` | — (kepemilikan course / terautentikasi) | — | `lms.surveys.update` |
| GET | `/lms/activities/{activity}/survey/results` | — (kepemilikan course / terautentikasi) | — | `lms.surveys.results` |
| POST | `/lms/activities/{activity}/survey/submit` | — (kepemilikan course / terautentikasi) | — | `lms.surveys.submit` |
| POST | `/lms/activities/{activity}/unpublish` | — (kepemilikan course / terautentikasi) | — | `lms.activities.unpublish` |

### assignments

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/assignments/{assignment}` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.by-model` |
| GET | `/lms/assignments/{assignment}/submissions` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.submissions` |
| POST | `/lms/assignments/{assignment}/submit` | — (kepemilikan course / terautentikasi) | — | `lms.assignments.submit` |

### quizzes

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/quizzes/{quiz}` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.by-model` |
| POST | `/lms/quizzes/{quiz}/attempts` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.attempts.start` |
| GET | `/lms/quizzes/{quiz}/statistics` | — (kepemilikan course / terautentikasi) | — | `lms.quizzes.statistics.by-model` |

### questions

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/questions` | — (kepemilikan course / terautentikasi) | — | `lms.questions.index` |
| POST | `/lms/questions` | — (kepemilikan course / terautentikasi) | — | `lms.questions.store` |
| DELETE | `/lms/questions/{question}` | — (kepemilikan course / terautentikasi) | — | `lms.questions.destroy` |
| GET | `/lms/questions/{question}` | — (kepemilikan course / terautentikasi) | — | `lms.questions.show` |
| PUT, PATCH | `/lms/questions/{question}` | — (kepemilikan course / terautentikasi) | — | `lms.questions.update` |
| POST | `/lms/questions/{question}/duplicate` | — (kepemilikan course / terautentikasi) | — | `lms.questions.duplicate` |

### question-banks

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/question-banks` | — (kepemilikan course / terautentikasi) | — | `lms.question-banks.index` |
| POST | `/lms/question-banks` | — (kepemilikan course / terautentikasi) | — | `lms.question-banks.store` |
| DELETE | `/lms/question-banks/{bank}` | — (kepemilikan course / terautentikasi) | — | `lms.question-banks.destroy` |
| GET | `/lms/question-banks/{bank}` | — (kepemilikan course / terautentikasi) | — | `lms.question-banks.show` |
| PUT, PATCH | `/lms/question-banks/{bank}` | — (kepemilikan course / terautentikasi) | — | `lms.question-banks.update` |
| POST | `/lms/question-banks/{bank}/categories` | — (kepemilikan course / terautentikasi) | — | `lms.question-banks.categories.store` |
| GET | `/lms/question-banks/{bank}/questions` | — (kepemilikan course / terautentikasi) | — | `lms.question-banks.questions` |

### lessons

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/lessons/{lesson}` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.by-model` |
| POST | `/lms/lessons/{lesson}/progress` | — (kepemilikan course / terautentikasi) | — | `lms.lessons.progress.by-model` |

### forums

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/forums/{forum}` | — (kepemilikan course / terautentikasi) | — | `lms.forums.by-model` |
| POST | `/lms/forums/{forum}/discussions` | — (kepemilikan course / terautentikasi) | — | `lms.forums.discussions.by-model` |

### choices

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/choices/{choice}` | — (kepemilikan course / terautentikasi) | — | `lms.choices.by-model` |
| POST | `/lms/choices/{choice}/respond` | — (kepemilikan course / terautentikasi) | — | `lms.choices.respond.by-model` |

### surveys

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/surveys/{survey}` | — (kepemilikan course / terautentikasi) | — | `lms.surveys.by-model` |
| POST | `/lms/surveys/{survey}/submit` | — (kepemilikan course / terautentikasi) | — | `lms.surveys.submit.by-model` |

### enrollments

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/enrollments/{enrollment}` | — (kepemilikan course / terautentikasi) | — | `lms.enrollments.destroy` |
| PUT, PATCH | `/lms/enrollments/{enrollment}` | — (kepemilikan course / terautentikasi) | — | `lms.enrollments.update` |
| POST | `/lms/enrollments/{enrollment}/cancel` | — (kepemilikan course / terautentikasi) | — | `lms.enrollments.cancel` |
| POST | `/lms/enrollments/{enrollment}/complete` | — (kepemilikan course / terautentikasi) | — | `lms.enrollments.complete` |
| POST | `/lms/enrollments/{enrollment}/reactivate` | — (kepemilikan course / terautentikasi) | — | `lms.enrollments.reactivate` |
| POST | `/lms/enrollments/{enrollment}/suspend` | — (kepemilikan course / terautentikasi) | — | `lms.enrollments.suspend` |

### groups

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/groups/{group}` | — (kepemilikan course / terautentikasi) | — | `lms.groups.destroy` |
| PUT, PATCH | `/lms/groups/{group}` | — (kepemilikan course / terautentikasi) | — | `lms.groups.update` |
| DELETE | `/lms/groups/{group}/members` | — (kepemilikan course / terautentikasi) | — | `lms.groups.members.destroy` |
| POST | `/lms/groups/{group}/members` | — (kepemilikan course / terautentikasi) | — | `lms.groups.members.store` |

### Kelas langsung, kehadiran & rekaman

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/live-sessions/{session}` | — (kepemilikan course / terautentikasi) | — | `lms.live.destroy` |
| GET | `/lms/live-sessions/{session}` | — (kepemilikan course / terautentikasi) | — | `lms.live.show` |
| PUT | `/lms/live-sessions/{session}` | — (kepemilikan course / terautentikasi) | — | `lms.live.update` |
| GET | `/lms/live-sessions/{session}/attendance` | — (kepemilikan course / terautentikasi) | — | `lms.live.attendance` |
| POST | `/lms/live-sessions/{session}/attendance` | — (kepemilikan course / terautentikasi) | — | `lms.live.attendance.mark` |
| POST | `/lms/live-sessions/{session}/cancel` | — (kepemilikan course / terautentikasi) | — | `lms.live.cancel` |
| GET | `/lms/live-sessions/{session}/join` | — (kepemilikan course / terautentikasi) | — | `lms.live.join-info` |
| POST | `/lms/live-sessions/{session}/join` | — (kepemilikan course / terautentikasi) | — | `lms.live.join` |
| POST | `/lms/live-sessions/{session}/publish` | — (kepemilikan course / terautentikasi) | — | `lms.live.publish` |
| POST | `/lms/live-sessions/{session}/recordings` | — (kepemilikan course / terautentikasi) | — | `lms.live.recordings.store` |
| POST | `/lms/live-sessions/{session}/refresh` | — (kepemilikan course / terautentikasi) | — | `lms.live.refresh` |

### Konten interaktif

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/interactive/{content}` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.destroy` |
| GET | `/lms/interactive/{content}` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.show` |
| PUT | `/lms/interactive/{content}` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.update` |
| POST | `/lms/interactive/{content}/duplicate` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.duplicate` |
| GET | `/lms/interactive/{content}/progress` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.progress` |
| POST | `/lms/interactive/{content}/publish` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.publish` |
| POST | `/lms/interactive/{content}/respond` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.respond` |
| GET | `/lms/interactive/{content}/versions` | — (kepemilikan course / terautentikasi) | — | `lms.interactive.versions` |

### Badge & gamifikasi

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/badges` | — (kepemilikan course / terautentikasi) | — | `lms.badges.index` |
| POST | `/lms/badges` | — (kepemilikan course / terautentikasi) | — | `lms.badges.store` |
| DELETE | `/lms/badges/{badge}` | — (kepemilikan course / terautentikasi) | — | `lms.badges.destroy` |
| PUT | `/lms/badges/{badge}` | — (kepemilikan course / terautentikasi) | — | `lms.badges.update` |
| POST | `/lms/badges/{badge}/award` | — (kepemilikan course / terautentikasi) | — | `lms.badges.award` |
| PUT | `/lms/badges/{badge}/rules` | — (kepemilikan course / terautentikasi) | — | `lms.badges.rules` |

### Sertifikat

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/certificates/{certificate}/revoke` | — (kepemilikan course / terautentikasi) | — | `lms.certificates.revoke` |
| GET | `/lms/certificates/{certificate}/verifications` | — (kepemilikan course / terautentikasi) | — | `lms.certificates.verifications` |

### Template sertifikat

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/certificate-templates` | — (kepemilikan course / terautentikasi) | — | `lms.certificate.templates` |
| POST | `/lms/certificate-templates` | — (kepemilikan course / terautentikasi) | — | `lms.certificate.templates.store` |
| GET | `/lms/certificate-templates/placeholders` | — (kepemilikan course / terautentikasi) | — | `lms.certificate.placeholders` |
| PUT | `/lms/certificate-templates/{template}` | — (kepemilikan course / terautentikasi) | — | `lms.certificate.templates.update` |

### Ulasan & umpan balik

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/reviews/{review}` | — (kepemilikan course / terautentikasi) | — | `lms.reviews.destroy` |
| PUT | `/lms/reviews/{review}` | — (kepemilikan course / terautentikasi) | — | `lms.reviews.update` |
| POST | `/lms/reviews/{review}/helpful` | — (kepemilikan course / terautentikasi) | — | `lms.reviews.helpful` |
| POST | `/lms/reviews/{review}/moderate` | — (kepemilikan course / terautentikasi) | — | `lms.reviews.moderate` |

### Komponen nilai

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/grade-items/{item}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.delete` |
| PUT | `/lms/grade-items/{item}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.update` |
| POST | `/lms/grade-items/{item}/grade-reviews` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.store` |
| GET | `/lms/grade-items/{item}/grades` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.grades.index` |
| POST | `/lms/grade-items/{item}/grades/bulk` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.grades.bulk` |
| POST | `/lms/grade-items/{item}/grades/return` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.grades.return` |
| PUT | `/lms/grade-items/{item}/grades/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.grades.update` |
| DELETE | `/lms/grade-items/{item}/grades/{student}/exempt` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.grades.unexempt` |
| POST | `/lms/grade-items/{item}/grades/{student}/exempt` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.grades.exempt` |
| PUT | `/lms/grade-items/{item}/rubric` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.rubric.attach` |
| GET | `/lms/grade-items/{item}/rubric/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.rubric.show` |
| POST | `/lms/grade-items/{item}/rubric/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-items.rubric.assess` |

### Gradebook & antrean sinkronisasi nilai

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/gradebooks/{gradebook}/sync` | — (kepemilikan course / terautentikasi) | — | `lms.grade-sync.status` |
| POST | `/lms/gradebooks/{gradebook}/sync` | — (kepemilikan course / terautentikasi) | — | `lms.grade-sync.enqueue` |
| GET | `/lms/gradebooks/{gradebook}/sync/compare/{userId}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-sync.compare` |
| POST | `/lms/gradebooks/{gradebook}/sync/retry` | — (kepemilikan course / terautentikasi) | — | `lms.grade-sync.retry` |

### rubrics

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/rubrics` | — (kepemilikan course / terautentikasi) | — | `lms.rubrics.store` |
| DELETE | `/lms/rubrics/{rubric}` | — (kepemilikan course / terautentikasi) | — | `lms.rubrics.destroy` |
| PUT | `/lms/rubrics/{rubric}` | — (kepemilikan course / terautentikasi) | — | `lms.rubrics.update` |
| POST | `/lms/rubrics/{rubric}/archive` | — (kepemilikan course / terautentikasi) | — | `lms.rubrics.archive` |
| POST | `/lms/rubrics/{rubric}/duplicate` | — (kepemilikan course / terautentikasi) | — | `lms.rubrics.duplicate` |

### competencies

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| PUT | `/lms/competencies/{competency}` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.update` |
| POST | `/lms/competencies/{competency}/students/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.assess` |
| POST | `/lms/competencies/{competency}/students/{student}/evidence` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.evidence` |

### outcomes

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/outcomes/{outcome}` | — (kepemilikan course / terautentikasi) | — | `lms.outcomes.destroy` |
| PUT | `/lms/outcomes/{outcome}` | — (kepemilikan course / terautentikasi) | — | `lms.outcomes.update` |
| POST | `/lms/outcomes/{outcome}/map` | — (kepemilikan course / terautentikasi) | — | `lms.outcomes.map` |

### Template course

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/course-templates` | — (kepemilikan course / terautentikasi) | — | `lms.templates.index` |
| GET | `/lms/course-templates/{template}` | — (kepemilikan course / terautentikasi) | — | `lms.templates.show` |
| POST | `/lms/course-templates/{template}/apply` | — (kepemilikan course / terautentikasi) | — | `lms.templates.apply` |
| POST | `/lms/course-templates/{template}/create-course` | — (kepemilikan course / terautentikasi) | — | `lms.templates.create-course` |

### Impor course

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/course-imports` | — (kepemilikan course / terautentikasi) | — | `lms.imports.index` |
| POST | `/lms/course-imports` | — (kepemilikan course / terautentikasi) | — | `lms.imports.import` |
| POST | `/lms/course-imports/upload` | — (kepemilikan course / terautentikasi) | — | `lms.imports.upload` |
| POST | `/lms/course-imports/validate` | — (kepemilikan course / terautentikasi) | — | `lms.imports.validate` |
| GET | `/lms/course-imports/{import}` | — (kepemilikan course / terautentikasi) | — | `lms.imports.show` |

### Backup & pemulihan course

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/course-backups/restore` | — (kepemilikan course / terautentikasi) | — | `lms.backups.restore` |
| POST | `/lms/course-backups/verify` | — (kepemilikan course / terautentikasi) | — | `lms.backups.verify` |

### Interoperabilitas SCORM

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/scorm/activities/{activity}/launch` | — (kepemilikan course / terautentikasi) | — | `lms.scorm.launch` |
| GET | `/lms/scorm/activities/{activity}/tracking` | — (kepemilikan course / terautentikasi) | — | `lms.scorm.tracking` |
| POST | `/lms/scorm/attempts/{attempt}/commit` | — (kepemilikan course / terautentikasi) | — | `lms.scorm.commit` |
| GET | `/lms/scorm/packages` | — (kepemilikan course / terautentikasi) | — | `lms.scorm.index` |
| POST | `/lms/scorm/packages` | — (kepemilikan course / terautentikasi) | — | `lms.scorm.import` |
| GET | `/lms/scorm/packages/{package}` | — (kepemilikan course / terautentikasi) | — | `lms.scorm.show` |
| POST | `/lms/scorm/packages/{package}/activity` | — (kepemilikan course / terautentikasi) | — | `lms.scorm.activity` |

### Report center

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/reports` | — (kepemilikan course / terautentikasi) | — | `lms.reports.catalog` |
| GET | `/lms/reports/exports` | — (kepemilikan course / terautentikasi) | — | `lms.reports.exports` |
| GET | `/lms/reports/exports/{export}/download` | — (kepemilikan course / terautentikasi) | — | `lms.reports.download` |
| GET | `/lms/reports/{key}` | — (kepemilikan course / terautentikasi) | — | `lms.reports.run` |
| POST | `/lms/reports/{key}/export` | — (kepemilikan course / terautentikasi) | — | `lms.reports.export` |

### Audit trail

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/audit` | — (kepemilikan course / terautentikasi) | — | `lms.audit.index` |
| GET | `/lms/audit/entities/{type}/{id}` | — (kepemilikan course / terautentikasi) | — | `lms.audit.entity` |
| GET | `/lms/audit/summary` | — (kepemilikan course / terautentikasi) | — | `lms.audit.summary` |

### Integritas data

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/integrity` | — (kepemilikan course / terautentikasi) | — | `lms.integrity.index` |
| GET | `/lms/integrity/repair-suggestions` | — (kepemilikan course / terautentikasi) | — | `lms.integrity.suggestions` |
| POST | `/lms/integrity/run` | — (kepemilikan course / terautentikasi) | — | `lms.integrity.run` |

### Kesehatan layanan

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/health` | — (kepemilikan course / terautentikasi) | — | `lms.health` |

### Status integrasi

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/integration` | — (kepemilikan course / terautentikasi) | — | `lms.integration` |
| GET | `/lms/integration/academic` | — (kepemilikan course / terautentikasi) | — | `lms.integration.academic` |

### Ruang siswa & wali

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/my/certificates` | — (kepemilikan course / terautentikasi) | — | `lms.certificates.mine` |
| GET | `/lms/my/certificates/{certificate}` | — (kepemilikan course / terautentikasi) | — | `lms.certificates.show` |
| GET | `/lms/my/certificates/{certificate}/download` | — (kepemilikan course / terautentikasi) | — | `lms.certificates.download` |
| GET | `/lms/my/competencies` | — (kepemilikan course / terautentikasi) | — | `lms.my.competencies` |
| GET | `/lms/my/courses` | — (kepemilikan course / terautentikasi) | — | `lms.my.courses` |
| GET | `/lms/my/gamification` | — (kepemilikan course / terautentikasi) | — | `lms.gamification.mine` |
| GET | `/lms/my/grades` | — (kepemilikan course / terautentikasi) | — | `lms.my.grades` |
| GET | `/lms/my/learning-health` | — (kepemilikan course / terautentikasi) | — | `lms.engagement.mine` |
| GET | `/lms/my/live-sessions` | — (kepemilikan course / terautentikasi) | — | `lms.live.mine` |
| GET | `/lms/my/outcomes` | — (kepemilikan course / terautentikasi) | — | `lms.my.outcomes` |
| GET | `/lms/my/performance` | — (kepemilikan course / terautentikasi) | — | `lms.my.performance` |
| GET | `/lms/my/progress` | — (kepemilikan course / terautentikasi) | — | `lms.my.progress` |

### Pencarian

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/search` | — (kepemilikan course / terautentikasi) | — | `lms.search` |
| GET | `/lms/search/suggestions` | — (kepemilikan course / terautentikasi) | — | `lms.search.suggestions` |

### Pengaturan LMS

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/settings` | — (kepemilikan course / terautentikasi) | — | `lms.settings.show` |
| PUT, PATCH | `/lms/settings` | — (kepemilikan course / terautentikasi) | — | `lms.settings.update` |

### categories

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/categories` | — (kepemilikan course / terautentikasi) | — | `lms.categories.index` |
| POST | `/lms/categories` | — (kepemilikan course / terautentikasi) | — | `lms.categories.store` |
| POST | `/lms/categories/reorder` | — (kepemilikan course / terautentikasi) | — | `lms.categories.reorder` |
| DELETE | `/lms/categories/{category}` | — (kepemilikan course / terautentikasi) | — | `lms.categories.destroy` |
| PUT, PATCH | `/lms/categories/{category}` | — (kepemilikan course / terautentikasi) | — | `lms.categories.update` |

### announcements

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/announcements/{announcement}` | — (kepemilikan course / terautentikasi) | — | `lms.announcements.destroy` |
| PUT, PATCH | `/lms/announcements/{announcement}` | — (kepemilikan course / terautentikasi) | — | `lms.announcements.update` |
| POST | `/lms/announcements/{announcement}/pin` | — (kepemilikan course / terautentikasi) | — | `lms.announcements.pin` |

### bookmarks

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/bookmarks` | — (kepemilikan course / terautentikasi) | — | `lms.bookmarks.index` |
| DELETE | `/lms/bookmarks/{bookmark}` | — (kepemilikan course / terautentikasi) | — | `lms.bookmarks.destroy` |

### calendar

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/calendar` | — (kepemilikan course / terautentikasi) | — | `lms.calendar` |

### children

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/children/{child}/grades` | — (kepemilikan course / terautentikasi) | — | `lms.children.grades` |

### students

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/students/{enrollment}/health` | — (kepemilikan course / terautentikasi) | — | `lms.engagement.student` |

### discussions

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/discussions/{discussion}` | — (kepemilikan course / terautentikasi) | — | `lms.discussions.show` |
| POST | `/lms/discussions/{discussion}/lock` | — (kepemilikan course / terautentikasi) | — | `lms.discussions.lock` |
| POST | `/lms/discussions/{discussion}/pin` | — (kepemilikan course / terautentikasi) | — | `lms.discussions.pin` |
| POST | `/lms/discussions/{discussion}/posts` | — (kepemilikan course / terautentikasi) | — | `lms.discussions.posts.store` |

### forum-moderation

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/forum-moderation/{type}/{id}` | — (kepemilikan course / terautentikasi) | — | `lms.forums.moderate.destroy` |
| POST | `/lms/forum-moderation/{type}/{id}/approve` | — (kepemilikan course / terautentikasi) | — | `lms.forums.moderate.approve` |
| POST | `/lms/forum-moderation/{type}/{id}/hide` | — (kepemilikan course / terautentikasi) | — | `lms.forums.moderate.hide` |

### forum-posts

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/forum-posts/{post}` | — (kepemilikan course / terautentikasi) | — | `lms.forum-posts.destroy` |
| PUT, PATCH | `/lms/forum-posts/{post}` | — (kepemilikan course / terautentikasi) | — | `lms.forum-posts.update` |

### submission-files

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/submission-files/{file}` | — (kepemilikan course / terautentikasi) | — | `lms.submission-files.destroy` |
| GET | `/lms/submission-files/{file}` | — (kepemilikan course / terautentikasi) | — | `lms.submission-files.download` |

### submissions

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/submissions/{submission}` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.show` |
| POST | `/lms/submissions/{submission}/files` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.files.store` |
| POST | `/lms/submissions/{submission}/grade` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.grade` |
| POST | `/lms/submissions/{submission}/reject` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.reject` |
| POST | `/lms/submissions/{submission}/return` | — (kepemilikan course / terautentikasi) | — | `lms.submissions.return` |

### assessment-results

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/assessment-results/{result}/correct` | — (kepemilikan course / terautentikasi) | — | `lms.assessment-results.correct` |
| GET | `/lms/assessment-results/{result}/history` | — (kepemilikan course / terautentikasi) | — | `lms.assessment-results.history` |

### activity-grades

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/activity-grades/{grade}` | — (kepemilikan course / terautentikasi) | — | `lms.activity-grades.destroy` |

### attempts

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/attempts/{attempt}` | — (kepemilikan course / terautentikasi) | — | `lms.attempts.show` |
| POST | `/lms/attempts/{attempt}/answers` | — (kepemilikan course / terautentikasi) | — | `lms.attempts.answers.store` |
| GET | `/lms/attempts/{attempt}/detail` | — (kepemilikan course / terautentikasi) | — | `lms.attempts.detail` |
| GET | `/lms/attempts/{attempt}/result` | — (kepemilikan course / terautentikasi) | — | `lms.attempts.result` |
| POST | `/lms/attempts/{attempt}/submit` | — (kepemilikan course / terautentikasi) | — | `lms.attempts.submit` |

### grade-releases

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/grade-releases/{release}/lock` | — (kepemilikan course / terautentikasi) | — | `lms.grade-releases.lock` |
| POST | `/lms/grade-releases/{release}/revoke` | — (kepemilikan course / terautentikasi) | — | `lms.grade-releases.revoke` |
| POST | `/lms/grade-releases/{release}/unlock` | — (kepemilikan course / terautentikasi) | — | `lms.grade-releases.unlock` |

### grade-reviews

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/grade-reviews` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.index` |
| GET | `/lms/grade-reviews/{review}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.show` |
| POST | `/lms/grade-reviews/{review}/approve` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.approve` |
| POST | `/lms/grade-reviews/{review}/close` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.close` |
| POST | `/lms/grade-reviews/{review}/comments` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.comment` |
| POST | `/lms/grade-reviews/{review}/reject` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.reject` |
| POST | `/lms/grade-reviews/{review}/start` | — (kepemilikan course / terautentikasi) | — | `lms.grade-reviews.start` |

### grade-scales

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/grade-scales` | — (kepemilikan course / terautentikasi) | — | `lms.grade-scales.store` |

### competency-frameworks

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/competency-frameworks` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.frameworks` |
| POST | `/lms/competency-frameworks` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.frameworks.store` |
| POST | `/lms/competency-frameworks/{framework}/competencies` | — (kepemilikan course / terautentikasi) | — | `lms.competencies.store` |

### quiz-answers

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/quiz-answers/{answer}/grade` | — (kepemilikan course / terautentikasi) | — | `lms.quiz-answers.grade` |

### quiz-slots

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/quiz-slots/{slot}` | — (kepemilikan course / terautentikasi) | — | `lms.quiz-slots.remove` |
| PUT, PATCH | `/lms/quiz-slots/{slot}` | — (kepemilikan course / terautentikasi) | — | `lms.quiz-slots.update` |
| GET | `/lms/quiz-slots/{slot}/remove` | — (kepemilikan course / terautentikasi) | — | `lms.quiz-slots.remove.get` |

### lesson-pages

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/lesson-pages/{page}` | — (kepemilikan course / terautentikasi) | — | `lms.lesson-pages.destroy` |
| PUT, PATCH | `/lms/lesson-pages/{page}` | — (kepemilikan course / terautentikasi) | — | `lms.lesson-pages.update` |

### survey-questions

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/survey-questions/{question}` | — (kepemilikan course / terautentikasi) | — | `lms.survey-questions.destroy` |

### activity-types

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/activity-types` | — (kepemilikan course / terautentikasi) | — | `lms.activity-types` |

### badge-awards

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/badge-awards/{award}` | — (kepemilikan course / terautentikasi) | — | `lms.badges.revoke` |

### course-archives

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/course-archives/{archive}/restore` | — (kepemilikan course / terautentikasi) | — | `lms.archives.restore` |

### grade-categories

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/grade-categories/{category}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-categories.delete` |
| PUT | `/lms/grade-categories/{category}` | — (kepemilikan course / terautentikasi) | — | `lms.grade-categories.update` |

### grade-syncs

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| POST | `/lms/grade-syncs/{sync}/cancel` | — (kepemilikan course / terautentikasi) | — | `lms.grade-sync.cancel` |
| GET | `/lms/grade-syncs/{sync}/history` | — (kepemilikan course / terautentikasi) | — | `lms.grade-sync.history` |
| POST | `/lms/grade-syncs/{sync}/process` | — (kepemilikan course / terautentikasi) | — | `lms.grade-sync.process` |

### live-recordings

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/live-recordings/{recording}` | — (kepemilikan course / terautentikasi) | — | `lms.live.recordings.destroy` |
| POST | `/lms/live-recordings/{recording}/watch` | — (kepemilikan course / terautentikasi) | — | `lms.live.recordings.watch` |

### parent

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/parent/children` | — (kepemilikan course / terautentikasi) | — | `lms.parent.children` |
| GET | `/lms/parent/children/{student}` | — (kepemilikan course / terautentikasi) | — | `lms.parent.children.show` |

### question-categories

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| DELETE | `/lms/question-categories/{category}` | — (kepemilikan course / terautentikasi) | — | `lms.question-categories.destroy` |
| PUT, PATCH | `/lms/question-categories/{category}` | — (kepemilikan course / terautentikasi) | — | `lms.question-categories.update` |

### resources

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/resources/{resource}/download` | — (kepemilikan course / terautentikasi) | — | `lms.resources.download` |

### rubric-templates

| Method | Path | Permission | Rate limit | Nama rute |
|---|---|---|---|---|
| GET | `/lms/rubric-templates` | — (kepemilikan course / terautentikasi) | — | `lms.rubrics.templates` |

## 6. Contoh alur

### 6.1 Siswa menyelesaikan kuis lalu melihat nilainya

```bash
# 1) mulai percobaan (server-side timer & validasi jumlah percobaan)
POST /api/v1/lms/activities/12/quiz/attempts
# 2) kirim jawaban (idempotent terhadap klik ganda)
POST /api/v1/lms/attempts/98/answers   { "answers": [ { "quiz_question_id": 5, "option_id": 21 } ] }
# 3) kumpulkan — nilai otomatis dihitung server, kunci jawaban belum dikirim
POST /api/v1/lms/attempts/98/submit
# 4) lihat progres & nilai
GET  /api/v1/lms/my/grades
```

### 6.2 Guru menerbitkan course (validasi menahan course rusak)

```bash
GET  /api/v1/lms/courses/7/validation     # daftar periksa + temuan validator
POST /api/v1/lms/courses/7/publish        # 422 bila ada temuan ERROR / siklus prasyarat
```

### 6.3 Sekolah menyinkronkan nilai ke SIAKAD

```bash
POST /api/v1/lms/gradebooks/3/sync                     # antrekan (PENDING)
GET  /api/v1/lms/gradebooks/3/sync                     # status antrean per siswa
GET  /api/v1/lms/grade-syncs/41/history                # riwayat percobaan
POST /api/v1/lms/gradebooks/3/sync/retry               # coba ulang yang gagal
GET  /api/v1/lms/gradebooks/3/sync/compare/15          # bandingkan LMS vs SIAKAD
```

### 6.4 Impor course dari sekolah lain

```bash
POST /api/v1/lms/course-imports/upload     # berkas JSON hasil ekspor
POST /api/v1/lms/course-imports/validate   # validasi + pratinjau + konflik
POST /api/v1/lms/course-imports            # { payload, conflict_strategy, idempotency_key }
GET  /api/v1/lms/course-imports/{id}       # ringkasan + hasil VERIFY
```

### 6.5 Memasang paket SCORM

```bash
POST /api/v1/lms/scorm/packages                    # unggah .zip (imsmanifest.xml)
POST /api/v1/lms/scorm/packages/4/activity         # pasang ke section sebagai aktivitas
POST /api/v1/lms/scorm/activities/77/launch        # siswa membuka peluncur (resume state)
POST /api/v1/lms/scorm/attempts/12/commit          # peluncur melaporkan elemen CMI
```

## 7. Observability & korelasi

* Kirim `X-Correlation-Id` pada permintaan integrasi; id itu tersimpan pada
  setiap baris audit LMS sehingga satu operasi lintas service dapat ditelusuri.
* `GET /api/v1/lms/health` mengembalikan ringkasan aman; admin melihat detail
  pemeriksaan `database`, `cache`, `queue`, `storage`, `erp_integration`,
  `siakad_integration`, `notification`, `scheduler`.
* `GET /api/v1/lms/integration` menampilkan provider yang aktif (ERP vs mandiri)
  beserta ketersediaannya — tanpa membuka rahasia apa pun.

