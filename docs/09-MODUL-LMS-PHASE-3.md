# Modul LMS — Phase 3 (Gradebook, Assessment, Rubric, Competency & Learning Outcomes)

> Status: **selesai**. Berkas ini melengkapi [`07-MODUL-LMS.md`](07-MODUL-LMS.md) (Phase 1)
> dan [`08-MODUL-LMS-PHASE-2.md`](08-MODUL-LMS-PHASE-2.md) (Phase 2).
> Phase 3 **tidak membuat ulang** apa pun: course, section, learning item,
> aktivitas, tugas, kuis, forum, lesson, bank soal, submission, percobaan kuis,
> penyelesaian, progress, user, siswa, guru, wali, dan sistem berkas tetap dipakai.

---

## 1. Prinsip utama

> Semua aktivitas pembelajaran yang menghasilkan nilai masuk Gradebook secara
> konsisten, dapat dihitung, dapat diaudit, dapat dijelaskan kepada siswa/wali,
> dan dapat diintegrasikan ke SIAKAD **tanpa membuat sumber nilai ganda**.

Empat aturan yang menegakkan prinsip tersebut:

1. **Satu penulis hasil penilaian.** Hanya `AssessmentResultService` yang menulis
   `lms_assessment_results`. Assignment, quiz, rubric, maupun penilaian manual
   semuanya melewati service ini.
2. **Satu mesin perhitungan.** `GradeCalculationService` (dibungkus
   `FinalGradeService`) adalah satu-satunya tempat bobot, kategori, kebijakan
   nilai kosong, pembebasan, dan extra credit dihitung. Controller tidak
   menghitung apa pun.
3. **Satu lapisan evidence.** Nilai aktivitas (`lms_activity_grades`, Phase 2)
   tetap menjadi bukti penilaian; gradebook menyimpannya sebagai
   `lms_assessment_results.activity_grade_id` sehingga rantai
   *Assignment → Submission → Grade → Assessment Result → Grade Item → Gradebook*
   dapat ditelusuri.
4. **SIAKAD tetap sumber resmi nilai akademik.** LMS mengirim nilai akhir lewat
   kontrak `AcademicGradeProviderInterface` sebagai **satu penilaian akademik**
   (bukan KHS/Rapor baru), dan hanya bila sekolah mengaktifkannya.

---

## 2. Arsitektur

```
Course
  └── Gradebook (1 per course)
        ├── Grade Category (boleh bersarang)
        │     └── Grade Item (ASSIGNMENT | QUIZ | FORUM | LESSON | MANUAL | EXTERNAL)
        │           └── Assessment Result  ← satu baris per siswa per komponen
        └── Final Grade (dihitung) → Grade Release (snapshot) → Grade Sync (SIAKAD)
```

Contoh hasil yang ditampilkan (contoh §15 dokumentasi Phase 3):

| Kategori | Bobot | Nilai | Kontribusi |
|---|---|---|---|
| Assignments | 30% | 80 | 24 |
| Quiz | 20% | 90 | 18 |
| Midterm | 20% | 85 | 17 |
| Final Exam | 30% | 90 | 27 |
| **Nilai akhir** | 100% | **86.00** | **86** |

Rincian ini disimpan apa adanya pada `lms_grade_releases.component_snapshot`
sehingga nilai yang sudah dirilis tetap dapat dijelaskan walau konfigurasi
gradebook berubah kemudian (`config_hash` menandai bila sudah usang).

---

## 3. Gradebook (§2–§11)

| Fitur | Implementasi |
|---|---|
| Satu gradebook per course | `GradebookService::ensureForCourse()` (idempoten) |
| Status DRAFT/ACTIVE/LOCKED/FROZEN/ARCHIVED | `GradebookStatus`; FROZEN & LOCKED menolak perubahan tanpa `lms.grade.lock` |
| Grading method RAW/PERCENTAGE/LETTER/POINTS | `GradingMethod` (perhitungan internal selalu numerik) |
| Agregasi WEIGHTED_MEAN, SIMPLE_MEAN, SUM, HIGHEST, LOWEST, MEDIAN | `AggregationMethod` (per gradebook, per kategori, per komponen) |
| Bobot + normalisasi | `WeightPolicy` STRICT (wajib 100%) / NORMALIZE (dibagi total aktual) |
| Nilai kosong | `MissingGradePolicy` PENDING (default), EXCLUDE, MISSING_AS_ZERO — **bukan** otomatis nol |
| Pembebasan | `AssessmentResultService::exempt()` + alasan, guru, waktu, riwayat |
| Extra credit | `ExtraCreditPolicy` CAP / UNCAPPED / IGNORE |
| Skala nilai | `lms_grade_scales` + entries; resolusi course → tahun ajaran → sekolah |
| Statistik & distribusi | `GradeStatisticsService` (rata-rata, median, tertinggi/terendah, tingkat lulus, bucket 90–100 … <60) |
| Ranking | **nonaktif** secara default (`is_ranking_visible`) |

### Tabel nilai guru (§34–§35, §55)

`GET /lms/courses/{course}/gradebook/sheet` mengembalikan matriks siswa ×
komponen dengan nilai akhir, status rilis, statistik course, dan filter
(`student`, `category_id`, `grade_item_id`, `only_missing`, `below_passing`).

---

## 4. Assessment Result & alur penilaian (§12–§19)

- Status hasil: `PENDING → GRADED → RETURNED → RELEASED → LOCKED`, ditambah
  `EXEMPT`.
- Nilai dari aktivitas masuk otomatis lewat observer
  `LmsActivityGradeObserver` (dipanggil setiap nilai aktivitas disimpan) dan
  dapat ditarik manual lewat `POST /lms/courses/{course}/gradebook/sync-items`.
- Penilaian manual/kolom penuh: `PUT /lms/grade-items/{item}/grades/{student}`
  dan `POST /lms/grade-items/{item}/grades/bulk`.
- Validasi: `0 ≤ nilai ≤ maximum_grade` (kecuali komponen extra credit).
- Setiap perubahan menulis `lms_grade_histories` (previous → new, alasan, pelaku,
  waktu). Model riwayat **menolak update & delete** di level Eloquent.
- Koreksi nilai: `POST /lms/assessment-results/{result}/correct` dengan izin
  `lms.grade.correct`; nilai terkunci harus dibuka lebih dulu (§42).

---

## 5. Rilis, kunci & banding (§16–§20, §42, §59)

| Aksi | Endpoint | Catatan |
|---|---|---|
| Rilis individu / sebagian / semua | `POST /lms/courses/{course}/gradebook/release` | menyimpan snapshot perhitungan |
| Batalkan rilis | `POST /lms/grade-releases/{release}/revoke` | siswa kembali tidak melihat nilai |
| Kunci nilai | `POST /lms/grade-releases/{release}/lock` | status hasil → LOCKED |
| Buka kunci | `POST /lms/grade-releases/{release}/unlock` | wajib alasan + `lms.grade.correct` |
| Banding siswa | `POST /lms/grade-items/{item}/grade-reviews` | hanya untuk nilai yang sudah dirilis |
| Keputusan guru | `/start`, `/approve`, `/reject`, `/close`, `/comments` | persetujuan mengeksekusi koreksi berjejak |

Sebelum dirilis, siswa **tidak melihat** nilai apa pun (payload mengosongkan
skor, umpan balik, dan status item).

---

## 6. Rubric (§22–§25, §58)

- Template institusi (`course_id` null, `is_template`) dapat dipakai ulang dan
  disalin; rubric course hanya untuk course tersebut.
- Kriteria + level berskor; `maximum_score` kriteria dihitung dari level
  tertinggi bila tidak diisi.
- Metode penilaian POINTS / WEIGHTED / PERCENTAGE.
- Total dihitung sistem, dinormalisasi ke `maximum_grade` grade item, lalu
  dikirim ke gradebook melalui `AssessmentResultService` (§61).
- Rubric yang sudah dipakai menilai tidak dapat dihapus — hanya diarsipkan.

---

## 7. Learning Outcome & Competency (§26–§31)

- `lms_learning_outcomes` per course dengan ambang batas capaian yang dapat
  dikonfigurasi (`developing`, `achieved`, `mastered`).
- Pemetaan aktivitas → outcome (`lms_activity_outcomes`), satu aktivitas dapat
  memetakan banyak outcome.
- `OutcomeAssessmentService` menghitung capaian dari rata-rata nilai aktivitas
  yang dipetakan, lalu menyimpan status `NOT_ASSESSED / NOT_YET / DEVELOPING /
  ACHIEVED / MASTERED` beserta rincian bukti.
- Competency framework → competency → course → activity
  (`lms_competency_frameworks`, `lms_competencies`, `lms_course_competencies`,
  `lms_activity_competencies`).
- Status kompetensi (`NOT_STARTED / DEVELOPING / COMPETENT / MASTERED`)
  ditetapkan **guru**, tidak diturunkan otomatis dari angka (§30), dan selalu
  disertai bukti (`lms_competency_evidence`: aktivitas, hasil penilaian, rubric,
  guru, tanggal).

---

## 8. Integrasi akademik (§39–§42, §63)

Kontrak: `AcademicGradeProviderInterface` (`key`, `label`, `isAvailable`, `push`).

Payload (`academic_grade.v1`) memuat `gradebook`, `course`, `academic`
(kelas, mapel, tahun ajaran, semester, guru), `student` (user, student, NIS),
`result` (nilai akhir, persentase, huruf, predikat, lulus), `assessment_breakdown`,
dan `components`.

| Mode | Provider | Perilaku |
|---|---|---|
| Integrated + `LMS_INTEGRATION_PUSH_GRADES=true` | `SiakadAcademicGradeProvider` | menulis satu baris `assessments` (ditandai `extra->lms_gradebook_id`) + `scores` per siswa; status penilaian tetap `draft` agar SIAKAD yang memutuskan finalisasi KHS |
| Integrated tanpa izin push | `SiakadAcademicGradeProvider` | `isAvailable()` false → status `SKIPPED`, nilai tetap sah di LMS |
| Standalone (`LMS_STANDALONE=true`) | `StandaloneAcademicGradeProvider` | gradebook LMS menjadi sumber nilai resmi; tidak ada penulisan ke SIAKAD |

- Idempotent: `payload_hash` disimpan; pengiriman ulang dengan isi sama tidak
  membuat penilaian baru.
- Setelah berhasil, nilai **dikunci** (§42). Perubahan berikutnya mengikuti alur
  unlock → koreksi → (re-sync) → lock, semuanya berjejak di `lms_grade_histories`
  dan `lms_grade_syncs`.
- LMS **tidak** membuat KHS/Rapor kedua dan **tidak** menyentuh modul keuangan
  (§62, §39).

---

## 9. Permission (tambahan Phase 3)

```
lms.gradebook.view            lms.gradebook.manage
lms.grade.edit                lms.grade.release     lms.grade.lock     lms.grade.correct
lms.grade.review              lms.grade.approve     lms.grade.sync
lms.grade.view_own            lms.grade.request_review
lms.grade.view_child          lms.grade.view_child_feedback
lms.rubric.create             lms.rubric.manage     lms.rubric.assess
lms.outcome.manage            lms.outcome.assess
lms.competency.manage         lms.competency.assess
```

Pembagian peran:

| Peran | Hak nilai |
|---|---|
| guru | gradebook course **miliknya**, menilai, rilis, meninjau banding, rubric, outcome/kompetensi |
| wali-kelas / kepala-sekolah | baca nilai lintas course + meninjau banding (pengawas) |
| siswa | `view_own` + `request_review` |
| wali-murid | `view_child` (+ `view_child_feedback`) untuk anak yang sah |
| admin-sekolah | `lms.manage` (termasuk sinkronisasi `lms.grade.sync`) |

> Kepemilikan course selalu diperiksa di controller
> (`LmsController::canAccessCourseGrades`). Permission tingkat peran hanya
> menyatakan kapabilitas, bukan izin lintas course (§53).

---

## 10. API ringkas

```
GET    /lms/courses/{course}/gradebook
PUT    /lms/courses/{course}/gradebook
POST   /lms/courses/{course}/gradebook/{freeze|unfreeze|calculate|sync-items|release}
GET    /lms/courses/{course}/gradebook/{items|sheet|statistics|export|releases}
POST   /lms/courses/{course}/gradebook/{items|categories}
PUT|DELETE /lms/grade-categories/{category}
PUT|DELETE /lms/grade-items/{item}
GET    /lms/courses/{course}/grade-scales      POST /lms/grade-scales

GET    /lms/grade-items/{item}/grades
PUT    /lms/grade-items/{item}/grades/{student}
POST   /lms/grade-items/{item}/grades/{bulk|return}
POST   /lms/grade-items/{item}/grades/{student}/exempt    DELETE …
POST   /lms/assessment-results/{result}/correct
GET    /lms/assessment-results/{result}/history

POST   /lms/grade-releases/{release}/{revoke|lock|unlock}
POST   /lms/courses/{course}/grades/sync
GET    /lms/integration/academic
GET    /lms/courses/{course}/grades/payload/{student}

GET    /lms/my/grades          GET /lms/my/performance
GET    /lms/my/outcomes        GET /lms/my/competencies
GET    /lms/courses/{course}/grades
GET    /lms/children/{child}/grades

GET    /lms/courses/{course}/rubrics     GET /lms/rubric-templates
POST   /lms/rubrics   PUT|DELETE /lms/rubrics/{rubric}
POST   /lms/rubrics/{rubric}/{archive|duplicate}
PUT    /lms/grade-items/{item}/rubric
GET|POST /lms/grade-items/{item}/rubric/{student}

GET|POST /lms/courses/{course}/outcomes
PUT|DELETE /lms/outcomes/{outcome}       POST /lms/outcomes/{outcome}/map
POST   /lms/courses/{course}/outcomes/recalculate
GET    /lms/courses/{course}/outcomes/students/{student}

GET    /lms/competency-frameworks        POST /lms/competency-frameworks
POST   /lms/competency-frameworks/{framework}/competencies
PUT    /lms/competencies/{competency}
GET    /lms/courses/{course}/competencies
POST   /lms/courses/{course}/competencies/map
POST   /lms/activities/{activity}/competencies/map
POST   /lms/competencies/{competency}/students/{student}[/evidence]
GET    /lms/courses/{course}/competencies/students/{student}

GET    /lms/grade-reviews
POST   /lms/grade-items/{item}/grade-reviews
GET    /lms/grade-reviews/{review}
POST   /lms/grade-reviews/{review}/{start|approve|reject|close|comments}
```

Total: **279 rute** LMS (Phase 1 + 2 + 3).

---

## 11. Frontend

```
src/features/lms/
  types.phase3.ts                  # tipe gradebook, hasil, rilis, rubric, outcome, competency, review
  api/gradebookApi.ts              # seluruh endpoint Phase 3
  components/grades/
    GradebookSheet.tsx             # tabel nilai guru: kolom siswa melekat, header melekat,
                                   # sunting langsung + simpan otomatis, filter, ekspor CSV
    GradeDistributionChart.tsx     # distribusi nilai per bucket (§37)
    GradeBreakdownCard.tsx         # rincian komponen + umpan balik (siswa & wali)
    RubricAssessmentPanel.tsx      # pilih level per kriteria, total dihitung server (§58)
    GradeReviewPanel.tsx           # form banding siswa + kartu keputusan guru (§59)
    OutcomeCompetencyPanel.tsx     # outcome, pemetaan aktivitas, status kompetensi + bukti
  pages/
    LmsGradebookPage.tsx           # /lms/courses/:courseId/gradebook (5 tab)
    LmsMyGradesPage.tsx            # /lms/grades (siswa; §32, §56)
    LmsMyPerformancePage.tsx       # /lms/performance (§47)
    LmsChildGradesPage.tsx         # /lms/children/:childId/grades (wali; §33, §57)
```

Tab gradebook guru: **Tabel nilai**, **Komponen & bobot**, **Rubric & penilaian**
(termasuk antrean banding), **Outcome & kompetensi**, **Rilis & integrasi**.

---

## 12. Pengujian

```bash
cd backend && php artisan test tests/Feature/Lms
```

| Berkas | Cakupan |
|---|---|
| `LmsGradebookTest` | gradebook otomatis, grade item (aktivitas & manual), bobot kategori + kontribusi, 6 metode agregasi, tiga kebijakan nilai kosong, pembebasan, extra credit (CAP/UNCAPPED/IGNORE), drop lowest, STRICT vs NORMALIZE, skala per course, pembekuan, statistik + distribusi |
| `LmsGradeReleaseTest` | nilai belum dirilis vs dirilis, rilis individu/sebagian/semua, snapshot, pembatalan rilis, penguncian, koreksi berjejak, riwayat immutable, rilis ditolak saat bobot tidak valid, gradebook beku, ekspor CSV, format tak tersedia, kembalikan nilai + umpan balik |
| `LmsRubricTest` | pembuatan rubric + kriteria/level, perhitungan total & normalisasi, rubric berbobot, level kriteria lain ditolak, template reuse & salin, arsip tidak dapat menilai, pemasangan ke komponen, larangan hapus rubric terpakai |
| `LmsOutcomeCompetencyTest` | outcome + pemetaan, ambang batas capaian, rekap kelas, framework & kompetensi, penetapan status + bukti aktivitas, kompetensi ≠ nilai, rekap kemajuan, akses siswa |
| `LmsGradeReviewTest` | pengajuan banding, larangan untuk nilai belum dirilis, larangan ganda, mulai tinjau, setujui (koreksi + riwayat), tolak, larangan memutuskan sendiri, catatan, visibilitas wali tanpa catatan privat |
| `LmsAcademicIntegrationTest` | mode mandiri, integrasi nonaktif, sinkronisasi menulis penilaian + skor SIAKAD, penguncian pasca sync, idempotensi, kontrak payload, course tanpa tautan akademik, larangan siswa, jejak audit |
| `LmsGradebookSecurityTest` | isolasi nilai siswa, larangan menulis nilai, wali bukan anak sah, guru course lain, tamu, item tersembunyi, nilai terkunci, koreksi massal, batas maksimum, extra credit |
| `LmsGradebookFlowTest` | alur tugas → submission → penilaian → gradebook → rilis → siswa & wali; kuis → auto grade → gradebook; rubric → nilai akhir; outcome & kompetensi dari aktivitas; rilis → kunci → sync → koreksi → re-sync; matriks nilai guru + filter |

### Data contoh & akun demo

```bash
cd backend
php artisan migrate --force
php artisan db:seed --force
php artisan db:seed --class="App\Modules\LMS\Database\Seeders\LmsGradebookDemoSeeder" --force
```

`LmsGradebookDemoSeeder` bersifat idempoten: ia menyiapkan kategori + bobot,
komponen manual, nilai aktivitas, penilaian rubric, learning outcome, kompetensi,
serta merilis nilai sebagian siswa (satu siswa dibiarkan belum dirilis agar status
"menunggu" dapat dilihat). Bila course belum punya peserta, seeder membuat akun
demo `siswa<n>@syntaf.sch.id` (peran `siswa`) dan `wali<n>@syntaf.sch.id`
(peran `wali-murid`) dengan kata sandi `password` — berguna untuk memeriksa
tampilan nilai siswa maupun wali tanpa menyiapkan data manual.

Halaman yang dapat langsung diperiksa:

| Peran | Halaman |
|---|---|
| guru/admin | `/lms/courses/{courseId}/gradebook` (5 tab) |
| siswa | `/lms/grades`, `/lms/performance` |
| wali | `/lms/children/{childUserId}/grades` |

---

## 13. Peta Definition of Done (§64)

| Item | Status | Bukti |
|---|---|---|
| Gradebook & grade item | ✅ | `GradebookService`, `GradeItemService`, `LmsGradebookTest` |
| Grade category, weighting, aggregation | ✅ | `lms_grade_categories`, 6 metode agregasi |
| Grade scale & missing policy | ✅ | `lms_grade_scales`, `MissingGradePolicy` (PENDING default) |
| Exemption & extra credit | ✅ | `AssessmentResultService::exempt`, `ExtraCreditPolicy` |
| Assessment result & grade source | ✅ | `lms_assessment_results` (+ `activity_grade_id`) |
| Calculation engine & final grade | ✅ | `GradeCalculationService`, `FinalGradeService` |
| Snapshot perhitungan | ✅ | `lms_grade_releases.component_snapshot` + `calculation_snapshot` |
| Release, lock, correction, history | ✅ | `GradeReleaseService`, `GradeCorrectionService`, `lms_grade_histories` (immutable) |
| Grade review | ✅ | `GradeReviewService` + `lms_grade_review_events` |
| Feedback (umum/privat + saran) | ✅ | `feedback`, `feedback_private`, `feedback_meta` |
| Rubric, rubric assessment, template | ✅ | `RubricService`, `RubricAssessmentService` |
| Learning outcome & mapping & capaian | ✅ | `LearningOutcomeService`, `OutcomeAssessmentService` |
| Competency framework, assessment, evidence | ✅ | `CompetencyService`, `CompetencyAssessmentService` |
| Student / teacher / parent view | ✅ | `/lms/my/grades`, `/lms/gradebook/sheet`, `/lms/children/{id}/grades` |
| Statistics, distribution, export | ✅ | `GradeStatisticsService`, `GradeExportService` (CSV) |
| Assessment calendar & notification | ✅ | memakai kalender Phase 1 + `GradeNotifier` (kanal `database`) |
| SIAKAD integration & standalone | ✅ | `AcademicGradeProviderInterface`, `SiakadAcademicGradeProvider`, `StandaloneAcademicGradeProvider` |
| Permission & security | ✅ | 89 izin `lms.*`, `canAccessCourseGrades` (kepemilikan course) |
| Audit & idempotensi sync | ✅ | `lms_grade_histories`, `lms_grade_syncs.payload_hash` |
| API & frontend | ✅ | 279 rute, 10 berkas frontend Phase 3 |
| Unit/feature/integration/E2E test | ✅ | 8 berkas tes Phase 3; suite LMS **198 tes / 1559 assertion** hijau |

Belum termasuk Phase 3 (disiapkan untuk phase berikutnya): Gradebook penuh
dengan ekspor Excel/PDF, Workshop, bank soal institusi dengan workflow
persetujuan, dan analitik pembelajaran lanjutan.

---

## 14. Konfigurasi

```env
LMS_INTEGRATION_PUSH_GRADES=false   # nilai hanya dikirim ke SIAKAD bila true
LMS_GRADING_METHOD=PERCENTAGE
LMS_GRADE_AGGREGATION=WEIGHTED_MEAN
LMS_MISSING_GRADE_POLICY=PENDING    # PENDING | EXCLUDE | MISSING_AS_ZERO
LMS_WEIGHT_POLICY=NORMALIZE         # STRICT | NORMALIZE
LMS_EXTRA_CREDIT_POLICY=CAP         # CAP | UNCAPPED | IGNORE
LMS_MAXIMUM_GRADE=100
LMS_PASSING_GRADE=70
LMS_OUTCOME_DEVELOPING=60
LMS_OUTCOME_ACHIEVED=75
LMS_OUTCOME_MASTERED=90
```

Semuanya juga dapat diubah per course melalui gradebook (nilai default hanya
dipakai saat gradebook pertama kali dibuat).
