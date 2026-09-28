# Modul LMS — Phase 1 (Foundation, Course, Enrollment & LMS Core)

> Status: **selesai** (Phase 1). Berkas ini merangkum arsitektur, kontrak, dan cara
> mengoperasikan modul LMS yang dibangun di atas ERP/SIAKAD yang sudah ada.
> Phase berikutnya melanjutkan ke **Assignment, Quiz, Question Bank, Forum,
> Gradebook, Assessment, Feedback, dan Learning Activities**.

---

## 1. Prinsip

1. **Tidak ada duplikasi entity ERP.** LMS memakai `users`, `students`,
   `employees`, `school_classes`, `subjects`, `academic_years`, `semesters`,
   `programs`, dan `filesystems` yang sudah ada. Di Integrated Mode tidak ada
   tabel `lms_users`/`lms_students` yang diisi.
2. **Modular & dapat dipisah.** Seluruh kode LMS berada di
   `backend/app/Modules/LMS/**` (Domain, Application, Infrastructure, Http,
   Models, Database, Routes, Providers) + `config/lms.php`. API-nya
   (`/api/v1/lms/*`) dan frontendnya (`/lms/*`, `/learn/*`) dapat dipisahkan
   tanpa mengubah modul lain.
3. **Dua mode operasi.** `LMS_STANDALONE=false` (default) → entity ERP.
   `LMS_STANDALONE=true` → LMS mandiri dengan identitasnya sendiri.
4. **Learning experience dulu.** Tampilan LMS bukan CRUD admin: kartu course,
   progress bar, silabus bertingkat, ruang belajar dengan Previous/Next, dan
   dashboard berbeda untuk guru, siswa, wali, serta admin.

---

## 2. Mode Operasi

```env
# .env
LMS_STANDALONE=false   # default: memakai entity ERP/SIAKAD
```

| Aspek | Integrated (`false`) | Standalone (`true`) |
|---|---|---|
| Identitas | `App\Models\User` + Spatie role/permission | `lms_users` (Sanctum token tetap) |
| Siswa | `students` + `class_students` | `lms_learners` (learner_type=STUDENT) |
| Guru | `employees` + `class_subjects` | `lms_learners` (learner_type=TEACHER) |
| Wali | `guardians` + `student_guardians` (+ `ppdb_parent_accounts`) | `lms_guardians` |
| "Course akademik" | `subjects`/`class_subjects` | course LMS itu sendiri |
| Kelas/tahun ajaran | `school_classes`, `academic_years` | label kelas pada profil peserta |

Perpindahan mode hanya mengganti binding kontrak di
`App\Modules\LMS\Providers\LmsServiceProvider`; kelas Application & Http tidak
berubah sama sekali.

---

## 3. Kontrak Domain (abstraksi terhadap ERP)

| Kontrak | Integrated | Standalone |
|---|---|---|
| `UserProviderInterface` | `ErpUserProvider` | `StandaloneUserProvider` |
| `StudentProviderInterface` | `ErpStudentProvider` | `StandaloneStudentProvider` |
| `TeacherProviderInterface` | `ErpTeacherProvider` | `StandaloneTeacherProvider` |
| `ClassProviderInterface` | `ErpClassProvider` | `StandaloneClassProvider` |
| `AcademicCourseProviderInterface` | `ErpAcademicCourseProvider` | `StandaloneAcademicCourseProvider` |
| `AcademicActivityFeedInterface` | `ErpAcademicActivityFeed` (tugas & nilai ERP) | `StandaloneAcademicActivityFeed` (kosong) |

`LmsActor` (DTO) adalah satu-satunya bentuk identitas yang dikenal service:
`id`, `name`, `roles`, `permissions`, `primaryRole` (`ADMIN|TEACHER|STUDENT|PARENT|GUEST`),
`employeeId`, `studentId`, `standalone`.

---

## 4. Struktur Database

| Tabel | Isi |
|---|---|
| `lms_categories` | kategori bertingkat (`parent_id`, `depth`, `sort_order`, `visibility`) |
| `lms_courses` | course + tautan akademik (`academic_course_id`, `class_id`, `academic_year_id`, …) |
| `lms_course_teachers` | pengajar course (guru utama & asisten) |
| `lms_course_sections` | bagian/pertemuan + ketersediaan |
| `lms_learning_items` | materi/aktivitas, aturan penyelesaian, prasyarat (`availability`) |
| `lms_resources` | isi RESOURCE (halaman HTML, berkas, URL, video, buku) |
| `lms_book_chapters` | bab resource BOOK |
| `lms_enrollments` | keikutsertaan + status + asal (`enrollment_method`) |
| `lms_groups` / `lms_group_members` | kelompok peserta |
| `lms_course_announcements` | pengumuman course |
| `lms_activity_completions` | penyelesaian item per peserta |
| `lms_course_progress` | ringkasan progress (denormalisasi untuk kueri cepat) |
| `lms_bookmarks` | bookmark course/materi |
| `lms_learning_views` | riwayat buka materi ("Recently Viewed") |
| `lms_users`, `lms_learners`, `lms_guardians` | **hanya** untuk Standalone Mode |

Catatan desain: kolom identitas (`user_id`, `enrolled_by`, `created_by`) dan
kolom tautan akademik (`academic_course_id`, `class_id`, …) sengaja berupa
integer ber-index **tanpa foreign key**, karena tabel yang ditunjuk bergantung
pada mode. Integritasnya dijaga kontrak domain, bukan skema — itulah harga agar
satu skema dapat dipakai di kedua mode.

---

## 5. Service (Application Layer)

`CourseService`, `CoursePublishService`, `CourseEnrollmentService`,
`CourseAccessService`, `CourseProgressService`, `CourseCompletionService`,
`CourseContentService`, `CourseGroupService`, `CourseAnnouncementService`,
`CourseSearchService`, `LmsCategoryService`, `LmsDashboardService`,
`LmsCalendarService`, `LmsBookmarkService`, `LmsIntegrationService`.

Alur penting:

```
Akses      : Authenticate → Enrollment → Visibility → Availability → Access Rule → Grant
Progress   : Completed countable items / required items × 100 (diperbarui segera)
Penerbitan : DRAFT → (checklist) → PUBLISHED → ARCHIVED → DRAFT
SIAKAD     : rombel → siswa aktif → akun siswa → enrollment LMS (ACADEMIC)
             KRS/rombel batal → enrollment SUSPENDED (riwayat tidak dihapus)
```

---

## 6. Permission Granular (§35)

`lms.view`, `lms.manage`,
`lms.category.{view,create,update,delete}`,
`lms.course.{view,create,update,delete,publish}`,
`lms.enrollment.{view,create,update,delete}`,
`lms.content.{view,create,update,delete}`,
`lms.group.{view,manage}`, `lms.announcement.{view,manage}`, `lms.bookmark.manage`,
`lms.teacher.manage`, `lms.student.view`, `lms.parent.view`,
`lms.report.view`, `lms.audit.view`, `lms.settings.manage`.

Dibuat oleh `App\Modules\LMS\Database\Seeders\LmsPermissionSeeder` (modul mandiri)
dan dibagikan ke role ERP: `admin-sekolah` (semua), `kepala-sekolah` (baca),
`guru` (kelola course miliknya), `wali-kelas` (baca), `siswa` (belajar),
`wali-murid` (pantau anak).

Guru hanya dapat mengubah course yang menjadi tanggung jawabnya
(`CourseAccessService::canManage`) kecuali memiliki `lms.manage`.

---

## 7. API Ringkas

```
# Publik (tanpa token)
GET    /api/v1/lms/public/home
GET    /api/v1/lms/public/courses
GET    /api/v1/lms/public/courses/{slug}
GET    /api/v1/lms/public/categories
GET    /api/v1/lms/public/teachers
GET    /api/v1/lms/public/about
GET    /api/v1/lms/auth/session
POST   /api/v1/lms/auth/login
POST   /api/v1/lms/auth/register      # hanya Standalone Mode

# Terautentikasi
GET    /api/v1/lms/categories                       POST /api/v1/lms/categories
GET    /api/v1/lms/courses            POST /api/v1/lms/courses
GET    /api/v1/lms/courses/lookups
GET    /api/v1/lms/courses/{course}   PUT/DELETE /api/v1/lms/courses/{course}
POST   /api/v1/lms/courses/{course}/duplicate
GET    /api/v1/lms/courses/{course}/readiness
POST   /api/v1/lms/courses/{course}/publish|unpublish|archive|restore
GET    /api/v1/lms/courses/{course}/statistics
GET    /api/v1/lms/courses/{course}/sections        POST .../sections
POST   /api/v1/lms/courses/{course}/sections/reorder
PUT    /api/v1/lms/sections/{section}               DELETE .../sections/{section}
POST   /api/v1/lms/sections/{section}/duplicate
POST   /api/v1/lms/sections/{section}/items         POST .../items/reorder
PUT    /api/v1/lms/items/{item}                     DELETE /api/v1/lms/items/{item}
POST   /api/v1/lms/items/{item}/duplicate|move|upload
GET    /api/v1/lms/items/{item}/chapters            PUT .../chapters
GET    /api/v1/lms/resources/{resource}/download
GET    /api/v1/lms/courses/{course}/learn
GET    /api/v1/lms/courses/{course}/items/{item}
POST   /api/v1/lms/courses/{course}/items/{item}/view|complete
GET    /api/v1/lms/courses/{course}/progress|continue
GET    /api/v1/lms/courses/{course}/enrollments      POST .../enrollments
POST   /api/v1/lms/courses/{course}/enrollments/bulk
GET    /api/v1/lms/courses/{course}/enrollments/candidates
POST   /api/v1/lms/courses/{course}/enrollments/sync-academic
POST   /api/v1/lms/courses/{course}/enroll           # pendaftaran mandiri
PUT    /api/v1/lms/enrollments/{enrollment}          DELETE ...
POST   /api/v1/lms/enrollments/{enrollment}/suspend|cancel|reactivate|complete
GET    /api/v1/lms/courses/{course}/groups           POST .../groups
POST   /api/v1/lms/groups/{group}/members            DELETE ...
GET    /api/v1/lms/courses/{course}/announcements    POST ...
PUT    /api/v1/lms/announcements/{announcement}      DELETE ...
GET    /api/v1/lms/my/courses         GET /api/v1/lms/my/progress
GET    /api/v1/lms/bookmarks          POST /api/v1/lms/courses/{course}/bookmark
GET    /api/v1/lms/calendar
GET    /api/v1/lms/search             GET /api/v1/lms/search/suggestions
GET    /api/v1/lms/dashboard          # sesuai peran
GET    /api/v1/lms/dashboard/student|teacher|parent|admin
GET    /api/v1/lms/parent/children    GET /api/v1/lms/parent/children/{student}
GET    /api/v1/lms/settings           PUT /api/v1/lms/settings
```

Semua respons memakai bentuk konsisten `{success, message, data, meta?}`.

---

## 8. Frontend

```
frontend/src/features/lms/
  api/lmsApi.ts               # seluruh endpoint LMS
  types.ts                    # tipe domain LMS
  lib/sanitizeHtml.ts         # pembersih HTML sisi klien (§40)
  lib/lmsLabels.ts            # label/tone/format tanggal Bahasa Indonesia
  components/                 # CourseCard, ProgressBar, CurriculumAccordion,
                              # ContentEditor, ContentBuilderPanels,
                              # CourseManagePanels, CourseFormWizard,
                              # AnnouncementList, CalendarAgendaList, dashboard/*
  pages/                      # dashboard, katalog, wizard, detail, learn,
                              # my-courses, calendar, bookmarks, search,
                              # categories, settings, parent child, publik (/learn)
routes/domains/lms.tsx        # domainRoutes + domainPublicRoutes + domainMenu
```

Rute terautentikasi: `/lms`, `/lms/courses`, `/lms/courses/new`,
`/lms/courses/:id`, `/lms/courses/:id/edit`, `/lms/courses/:id/learn[/:itemId]`,
`/lms/my-courses`, `/lms/calendar`, `/lms/bookmarks`, `/lms/search`,
`/lms/categories`, `/lms/settings`, `/lms/parent/:studentId`.
Rute publik: `/learn`, `/learn/courses`, `/learn/courses/:slug`, `/learn/teachers`.

---

## 9. Audit

Model LMS memakai `LogsLmsActivity` (log_name `lms`) dan service memakai
`LogsLmsAudit` untuk aksi agregat:

`LMS_CATEGORY_*`, `LMS_COURSE_CREATED/UPDATED/DELETED`, `LMS_COURSE_PUBLISHED`,
`LMS_COURSE_UNPUBLISHED`, `LMS_COURSE_ARCHIVED`, `LMS_COURSE_RESTORED`,
`LMS_COURSE_TEACHER_*`, `LMS_SECTION_*`, `LMS_ITEM_*`,
`LMS_ENROLLMENT_CREATED/SUSPENDED/CANCELLED/REACTIVATED/COMPLETED/DELETED/SYNCED`,
`LMS_COMPLETION_*`, `LMS_ANNOUNCEMENT_PUBLISHED`.

---

## 10. Pengujian

```bash
cd backend && php artisan test --filter=Lms        # 10 berkas, 66 kasus, 260+ assertion
```

| Berkas | Cakupan |
|---|---|
| `LmsCourseTest` | create/update/publish/archive/visibility/duplikasi |
| `LmsEnrollmentTest` | manual, massal, self-enroll, kuota, suspend/cancel |
| `LmsAccessTest` | siswa terdaftar/tidak, guru, wali, guest, course draf, jadwal |
| `LmsContentTest` | page (sanitasi), file (unggah+unduhan berotorisasi), URL, video embed, book |
| `LmsCompletionTest` | manual, view otomatis, prasyarat, nilai minimum, hitungan progress |
| `LmsParentTest` | pemilihan anak, isolasi anak, larangan submit/nilai |
| `LmsIntegrationTest` | SIAKAD → course → enrollment, suspend saat keluar rombel |
| `LmsDashboardTest` | dashboard 4 peran, kalender, bookmark, pencarian, notifikasi |
| `LmsPermissionTest` | permission granular + jejak audit |
| `LmsStandaloneModeTest` | perpindahan binding kontrak + alur belajar tanpa SIAKAD |

---

## 11. Peta Definition of Done (§45)

| Item | Status | Bukti |
|---|---|---|
| Arsitektur modul LMS | ✅ | `app/Modules/LMS/**` + `LmsServiceProvider` |
| Integrated mode | ✅ | `Erp*Provider`, `LmsIntegrationTest` |
| Arsitektur standalone | ✅ | `Standalone*Provider`, `LmsStandaloneModeTest` |
| Role guru/siswa/wali/admin/guest | ✅ | `LmsAccessTest`, dashboard per peran |
| Category, Course, Section, Learning item | ✅ | migrasi + service + API + UI |
| Resource (page/file/url/video/book) | ✅ | `LmsContentTest` |
| Enrollment, Group | ✅ | `LmsEnrollmentTest`, `CourseGroupService` |
| Availability dasar, Completion, Progress | ✅ | `LmsCompletionTest` |
| Announcement | ✅ | `CourseAnnouncementService` + notifikasi database |
| Kalender | ✅ | `LmsCalendarService` + `/lms/calendar` |
| Dashboard semua role | ✅ | `LmsDashboardService` + 4 tampilan |
| Public LMS, Search, Bookmark | ✅ | `/learn/*`, `CourseSearchService`, `LmsBookmarkService` |
| Integrasi berkas | ✅ | disk Laravel + unduhan ber-otorisasi |
| Permission granular, Audit | ✅ | `LmsPermissionSeeder`, `LogsLmsAudit` |
| API, Frontend, Responsive | ✅ | `routes/domains/lms.tsx`, uji viewport 390×844 |
| Testing | ✅ | 10 berkas tes khusus modul |

---

## 12. Menyiapkan Data Contoh

```bash
cd backend
php artisan migrate --force
php artisan db:seed --force      # termasuk LmsPermissionSeeder + LmsDemoSeeder
```

`LmsDemoSeeder` membuat kategori "Teknologi → Literasi Digital", satu course
publik dengan beragam resource, dan satu course akademik yang pesertanya
disinkronkan dari rombel SIAKAD.
