# Blueprint Modul Supervisi (Supervisi Akademik & Manajerial / Kinerja Pegawai)

> Rancangan teknis untuk **Modul Supervisi**: *supervisor* menilai & membina
> **guru** dan **tenaga kependidikan/karyawan** dengan **template ber-versi,
> rubrik, bobot, penskoran, ambang predikat, RTL, dan pelaporan**.
> Dasar ilmiah & rujukannya: [`00-RESEARCH-SUPERVISI.md`](00-RESEARCH-SUPERVISI.md).
>
> Status: **rancangan untuk disetujui**. Belum ada kode yang ditulis.

---

## 0. Keputusan arsitektur

| Keputusan | Alasan |
|---|---|
| Modul baru `app/Modules/Supervisi` (pola sama seperti `app/Modules/LMS`) | Isolasi domain, service/controller/model sendiri, tidak menambah beban file `app/Services` yang sudah besar |
| Semua nama tabel berprefix **`sup_`** dan berbahasa Inggris (`sup_instruments`, `sup_visits`, `sup_action_plans`) | Konsisten dengan `lms_*`; menghindari tabrakan nama Indonesia–Inggris |
| **Tidak** membuat ulang data pegawai | Sumber kebenaran tetap `employees` + `users` (login) + `class_subjects` (mengajar) + `employee_attendances` |
| **Tidak** membuat ulang penilaian kinerja guru (PKG) sebagai mesin terpisah di dalam UI guru; PKG/NKKS adalah **kalkulator** di atas data supervisi + data yang diisi | Menghindari dua sistem angka yang saling bertentangan |
| Mode **terintegrasi** (data pegawai & jadwal dari ERP sekolah) dan **mandiri** (data dasar supervisi saja) dipilih sekali di provider — pola §4 modul LMS | Satu kode, dua konteks pemasangan |
| Semua hasil final **immutable** + snapshot rumus/versi instrumen | Audit akademik & kepegawaian; nilai lama harus tetap bisa dibaca setelah ambang berubah |
| Perhitungan skor **selalu di server**, frontend hanya menampilkan | Anti-manipulasi, konsisten dengan modul lain |

---

## 1. Aktor, peran, dan izin

### 1.1 Aktor
| Aktor | Peran dalam modul |
|---|---|
| **Kepala sekolah** | Supervisor utama guru & tendik; penyetuju program; penerima laporan; dinilai pengawas |
| **Wakil kepala / kepala unit / koordinator mapel** | Supervisor delegasi untuk lingkup yang ditetapkan (mis. mapel/unit) |
| **Pengawas sekolah** *(opsional, terintegrasi)* | Supervisor atas kepala sekolah & guru di sekolah binaan |
| **Mentor / guru senior (peer coach)** | Supervisi sejawat, *lesson study*, mentoring guru baru |
| **Guru / tenaga kependidikan / karyawan** | Yang disupervisi: melihat jadwal, refleksi diri, hasil, RTL, hak tanggapan |
| **Admin sekolah / HRD** | Mengelola instrumen, program, penugasan, kalender, kalibrasi laporan (bukan penilai) |
| **Auditor / pengawas internal** | Membaca + mengekspor riwayat, tanpa mengubah |

### 1.2 Izin baru (didaftarkan ke `RolePermissionSeeder`, modul `supervisi`)
```
supervisi:view                     melihat modul (dasbor sesuai peran)
supervisi.instrument.view|create|update|publish|archive
supervisi.program.view|create|update|publish        (program & jadwal tahunan/semester)
supervisi.assignment.manage        menugaskan supervisor ↔ yang disupervisi
supervisi.visit.view|create|update|execute|cancel    (walkthrough, observasi, klinis)
supervisi.conference.manage        pra/pasca-observasi (wawancara, kesepakatan)
supervisi.score.view|submit|finalize|reopen
supervisi.action_plan.view|create|update|verify      (RTL)
supervisi.appraisal.view|calculate|submit|publish    (PKG/NKKS/SKP/PIP)
supervisi.dispute.create|resolve                     (tanggapan/keberatan + moderator)
supervisi.report.view|export
supervisi.analytics.view           tren, distribusi, peta kebutuhan pelatihan
supervisi.settings.manage
supervisi.audit.view
```
Pembagian bawaan (dapat disesuaikan):
* **kepala-sekolah** — `supervisi:*` kecuali `settings.manage`.
* **admin-sekolah / super-admin** — seluruh `supervisi:*`.
* **wali-kelas/guru senior (mentor)** — `visit.view/execute` untuk penugasan peer, `action_plan.view/create`, tanpa `score.finalize`.
* **guru / staf** — `visit.view` (miliknya), `action_plan.view`, `dispute.create`, `report.view` terbatas pada dirinya.
* **auditor** — `visit.view`, `appraisal.view`, `report.view/export`, `analytics.view`, `audit.view` (baca saja).

**Aturan keadilan yang ditegakkan server:**
1. Tidak boleh menilai diri sendiri (`supervisor_id = supervisee_id` → 422).
2. Penilai harus punya peran yang cocok dengan **lingkup penugasan** (bukan sekadar punya izin).
3. Konflik kepentingan dapat ditandai (mis. masih kerabat) → wajib moderator kedua.
4. `finalize` mengunci jawaban; `reopen` wajib alasan + audit + (opsi) persetujuan kepala sekolah.

---

## 2. Ruang lingkup fitur

| Kode | Fitur | Ringkas |
|---|---|---|
| F-01 | **Pustaka instrumen ber-versi** | Instrumen akademik, manajerial/tendik, PKG, PIP, survei; versi immutable; impor/ekspor JSON |
| F-02 | **Pembangun instrumen** | Section → indikator → rubrik level; skala 0–4/1–4/1–5/Ya-Tidak/esai/berkas; wajib; bobot; “NA” |
| F-03 | **Program supervisi** | Program tahunan/semester: tujuan, sasaran, jadwal, teknik, instrumen, anggaran; publikasi & cetak |
| F-04 | **Penugasan supervisor** | Penugasan per guru/tendik + lingkup (mapel/unit/kelas) + pendekatan supervisi (directive→nondirective) |
| F-05 | **Jadwal & undangan** | Jadwal kunjungan, undangan ke yang disupervisi, pengecekan bentrok jam mengajar, pengingat |
| F-06 | **Pra-observasi** | Wawancara terstruktur (8 pertanyaan baku), kesepakatan fokus, instrumen, waktu, cara pencatatan |
| F-07 | **Observasi (mode cepat & lengkap)** | *Walkthrough* (beberapa butir, tanpa nilai tinggi/rendah) & observasi penuh (40/36//12 butir), catatan fakta bercap waktu |
| F-08 | **Penskoran** | Skor per indikator/section, bobot, konversi 100, predikat; komentar wajib untuk skor rendah |
| F-09 | **Pasca-observasi** | Wawancara 9 pertanyaan, analisis kelebihan/kelemahan/masalah/penyebab/prioritas, kesepakatan perbaikan |
| F-10 | **Rencana Tindak Lanjut (RTL)** | Langkah, PIC, tenggat, bukti, status, verifikasi; papan pemantauan |
| F-11 | **Pengembangan kompetensi** | Tindak lanjut diklat/coaching/mentoring/*lesson study*; tautan bukti & sertifikat; jam pengembangan |
| F-12 | **Penilaian kinerja (PKG/NKKS)** | Kalkulator 14/17 kompetensi, tugas tambahan, koreksi kehadiran, sebutan & % angka kredit |
| F-13 | **Penilaian pegawai (bobot 1–5, 360°, PIP)** | Kriteria berbobot, penilaian diri/atasan/rekan, PIP dengan target & jadwal review |
| F-14 | **Kuadran SKP/e-Kinerja** | Rating hasil kerja × perilaku kerja → predikat; periodik bulanan/triwulanan/tahunan |
| F-15 | **Tanda tangan & hak tanggapan** | Acknowledge digital, catatan keberatan, alur moderator (moderasi nilai) |
| F-16 | **Dasbor per peran** | Supervisor, kepala sekolah, guru/staf, admin, auditor |
| F-17 | **Analitik** | Tren indikator, distribusi predikat, peta kekuatan/kelemahan, kebutuhan pelatihan (TNA), kepatuhan jadwal |
| F-18 | **Laporan & dokumen wajib** | Program supervisi, laporan pelaksanaan/hasil guru & tendik, laporan evaluasi program (Permendikbud 15/2018), rapor supervisi per orang |
| F-19 | **Evaluasi program (CIPP)** | Instrumen evaluasi program supervisi (context/input/process/product) + hasil & rekomendasi |
| F-20 | **Integrasi** | Pegawai, jadwal kelas, absensi, LMS (kelas & bukti), notifikasi/kalender, penyimpanan berkas |
| F-21 | **Audit & tata kelola** | Audit trail, versi instrumen, jejak perubahan skor, log ekspor |

---

## 3. Alur kerja & status (state machine)

```text
                        ┌──────────────── PROGRAM ────────────────┐
                        │ draft → published → (berjalan) → closed │
                        └─────────────────────────────────────────┘
                                          │ melahirkan
                                          ▼
 DRAFT ──schedule──► SCHEDULED ──pre_conf──► PRE_CONFERENCE ──observe──► OBSERVED
                                                                          │
                                                                     score/analyze
                                                                          ▼
                                                                      ANALYZED
                                                                          │
                                                                    post_conf
                                                                          ▼
                                                                   POST_CONFERENCE
                                                                          │
                                                                    action plan
                                                                          ▼
                                            ACTION_PLANNED ──► ACTION_IN_PROGRESS
                                                                          │
                                                                     verified
                                                                          ▼
                                                                      COMPLETED
                                                                          │
                                                                    (opsional)
                                                                          ▼
                                                                      EVALUATED (dampak/CIPP)
Status alternatif: RESCHEDULED, CANCELLED, DISPUTED (bila ada keberatan), REOPENED
```

Aturan transisi:
* `OBSERVED` hanya boleh dari `PRE_CONFERENCE` **atau** dari `SCHEDULED` bila
  jenis kegiatannya *walkthrough* (tanpa pra-observasi formal).
* `finalize` (→ `COMPLETED` + snapshot) hanya bila: seluruh indikator wajib
  terisi, komentar untuk skor di bawah ambang minimum, RTL minimal 1 langkah.
* `DISPUTED` memblokir `COMPLETED` sampai moderator memutuskan
  (`RESOLVED_KEEP` / `RESOLVED_ADJUST` / `RESOLVED_REOPEN`).
* Setiap transisi dicatat di `sup_audit_logs` (aktor, sebelum, sesudah, alasan).

---

## 4. Model data (rancangan tabel)

> Semua tabel: `id` BIGINT, `timestamps()`, `softDeletes()` pada entitas
> dokumen; kolom uang/tanggal memakai tipe yang jelas; index untuk kolom
> pencarian & filter; kunci unik untuk anti-duplikasi.

### 4.1 Instrumen & template
| Tabel | Kolom kunci |
|---|---|
| `sup_instruments` | `code` (unik), `name`, `category` (`AKADEMIK`,`MANAJERIAL`,`KEPALA_SEKOLAH`,`PKG`,`PIP`,`EVALUASI_PROGRAM`,`SURVEI`), `target_type` (`GURU`,`TENDIK`,`KEPSEK`,`UMUM`), `description`, `is_active`, `current_version_id` |
| `sup_instrument_versions` | `instrument_id`, `version` (unik per instrumen), `status` (`DRAFT`,`PUBLISHED`,`ARCHIVED`), `max_score` (dihitung), `scoring_mode` (`WEIGHTED`,`SUM`,`AVERAGE`,`CUSTOM`), `grade_scale_id`, `scale` (`SCALE_0_4`,`SCALE_1_4`,`SCALE_1_5`,`YES_NO`,`SCALE_0_2`,`MIXED`), `published_at`, `published_by`, `checksum` |
| `sup_sections` | `version_id`, `code`, `title`, `weight`, `order`, `max_score`, `parent_id` (sub-bagian) |
| `sup_indicators` | `section_id`, `code`, `statement`, `guidance` (cara menilai), `input_type`, `scale_code`, `weight`, `is_required`, `allow_na`, `min_comment_below`, `max_score` |
| `sup_indicator_options` | `indicator_id`, `label`, `score`, `order`, `tone` (`green`,`amber`,`red`,`gray`) — untuk rubrik bertingkat |
| `sup_grade_scales` | `code`, `name`, `bands` (JSON: `[{min,max,label,tone,credit_percent}]`) — mis. A/B/C/K 90/80/70 dan sebutan PKG 91/76/61/51 |
| `sup_scale_levels` | (opsional) rubrik perilaku per level untuk pelatihan penilai (*calibration*) |

### 4.2 Program, penugasan, jadwal
| Tabel | Kolom kunci |
|---|---|
| `sup_programs` | `school_id`, `academic_year_id`, `semester`, `title`, `objective`, `scope` (`AKADEMIK`,`MANAJERIAL`,`GABUNGAN`), `status`, `starts_at`, `ends_at`, `budget_note`, `published_at`, `created_by` |
| `sup_program_targets` | `program_id`, `target_type`, `target_id` (department/subject/unit), `planned_count` |
| `sup_assignments` | `program_id`, `supervisor_employee_id`, `supervisee_employee_id`, `role` (`UTAMA`,`PENDAMPING`,`MODERATOR`,`MENTOR`), `scope_type`, `scope_id`, `approach` (`DIRECTIVE_CONTROL`,`DIRECTIVE_INFORMATIONAL`,`COLLABORATIVE`,`NONDIRECTIVE`), `instruments_allowed` (JSON), `min_visits`, `notes`, `active_from`, `active_to` |
| `sup_schedules` | `program_id`, `assignment_id`, `supervisee_employee_id`, `supervisor_employee_id`, `planned_at`, `window_end`, `activity_kind`, `instrument_version_id`, `location`, `focus` (JSON/teks), `status`, `created_by` |

### 4.3 Kegiatan supervisi (jantung modul)
| Tabel | Kolom kunci |
|---|---|
| `sup_visits` | `code` (mis. `SUP/2026/000123`), `program_id`, `schedule_id`, `assignment_id`, `supervisor_employee_id`, `supervisee_employee_id`, `activity_kind` (`WALKTHROUGH`,`CLASS_OBSERVATION`,`CLINICAL`,`PEER`,`LESSON_STUDY`,`COACHING`,`DOCUMENT_REVIEW`,`STAFF_REVIEW`,`PROGRAM_EVALUATION`), `instrument_version_id`, `status`, `approach`, `subject_id`, `class_id`, `lesson_topic`, `observed_at`, `duration_minutes`, `pre_conference_at`, `post_conference_at`, `raw_score`, `max_score`, `final_score`, `grade_band`, `grade_label`, `scored_at`, `finalized_at`, `finalized_by`, `snapshot` (JSON: instrumen, bobot, ambang, perhitungan), `summary`, `strengths`, `improvements`, `is_disputed` |
| `sup_visit_answers` | `visit_id`, `indicator_id`, `option_id`, `score`, `value_text`, `value_number`, `value_date`, `value_json`, `comment`, `evidence_note`, `answered_by`, `answered_at` (unik per `visit` + `indicator`) |
| `sup_visit_scores` | `visit_id`, `section_id`, `raw_score`, `max_score`, `weighted_score`, `percent`, `band`, `comment` — agregat per bagian (mempercepat laporan) |
| `sup_visit_evidence` | `visit_id`, `visit_answer_id?`, `kind` (`PHOTO`,`VIDEO`,`DOCUMENT`,`LINK`,`AUDIO`), `path`, `url`, `caption`, `visibility` (`SUPERVISOR`,`SUPERVISEE`,`INTERNAL`,`PUBLIC`), `uploaded_by` |
| `sup_visit_notes` | `visit_id`, `note_type` (`FACT`,`TIMESTAMP`,`RECOMMENDATION`,`PRIVATE`), `at_seconds`, `body`, `created_by` |
| `sup_conferences` | `visit_id`, `kind` (`PRE`,`POST`), `held_at`, `location`, `participants` (JSON), `answers` (JSON: pertanyaan baku + jawaban), `agreements` (JSON: fokus/kesepakatan), `reflection` (teks guru), `next_focus`, `created_by` |
| `sup_findings` | `visit_id`, `component` (`RPP`,`PELAKSANAAN`,`PENILAIAN`,`ADMINISTRASI`,…), `strength`, `weakness`, `problem`, `cause`, `priority` (`LOW`,`MEDIUM`,`HIGH`,`URGENT`), `recommendation` |
| `sup_acknowledgments` | `visit_id`, `acknowledged_by_employee_id`, `acknowledged_at`, `signature_hash`, `note`, `ip_address` |
| `sup_disputes` | `visit_id`, `raised_by_employee_id`, `reason`, `proposed_score`, `attachments` (JSON), `status` (`OPEN`,`REVIEWED`,`RESOLVED_KEEP`,`RESOLVED_ADJUST`,`RESOLVED_REOPEN`), `decided_by`, `decided_at`, `decision_note`, `moderator_employee_id` |

### 4.4 Tindak lanjut & pengembangan
| Tabel | Kolom kunci |
|---|---|
| `sup_action_plans` | `visit_id` (nullable bila program-level), `supervisee_employee_id`, `title`, `period_start`, `period_end`, `status` (`DRAFT`,`ONGOING`,`DONE`,`VERIFIED`,`OVERDUE`,`DROPPED`), `verified_by`, `verified_at`, `note` |
| `sup_action_items` | `action_plan_id`, `indicator_id?`, `statement` (SMART), `support_needed`, `due_date`, `owner_employee_id`, `status`, `progress_percent`, `evidence_visit_id?`, `evidence_path`, `completed_at` |
| `sup_action_progress` | `action_item_id`, `reported_by`, `reported_at`, `progress_percent`, `note`, `attachment_path` |
| `sup_developments` | `supervisee_employee_id`, `kind` (`DIKLAT`,`WORKSHOP`,`COACHING`,`MENTORING`,`LESSON_STUDY`,`MANDIRI`,`PEER_VISIT`), `title`, `provider`, `hours`, `started_at`, `finished_at`, `cost`, `certificate_no`, `certificate_path`, `status`, `linked_visit_id?`, `linked_action_item_id?` |
| `sup_coaching_sessions` | `supervisor_employee_id`, `supervisee_employee_id`, `visit_id?`, `session_at`, `focus`, `commitments` (JSON), `follow_up_at`, `status` |

### 4.5 Penilaian kinerja (PKG/SKP/PIP/tendik)
| Tabel | Kolom kunci |
|---|---|
| `sup_appraisals` | `supervisee_employee_id`, `appraisal_type` (`PKG`,`PKG_BK`,`NKKS`,`SKP_PERIODIK`,`SKP_TAHUNAN`,`STAFF_REVIEW`,`PIP`), `period_type` (`BULANAN`,`TRIWULAN`,`SEMESTER`,`TAHUNAN`), `period_start`, `period_end`, `academic_year_id`, `instrument_version_id`, `assessor_employee_id`, `reviewer_employee_id` (moderator), `raw_score`, `max_score`, `final_score`, `grade_band`, `grade_label`, `credit_percent`, `attendance_absence_days`, `attendance_correction_percent`, `behavior_rating`, `result_rating`, `final_predicate`, `status` (`DRAFT`,`SUBMITTED`,`REVIEWED`,`PUBLISHED`,`DISPUTED`,`ARCHIVED`), `snapshot`, `published_at` |
| `sup_appraisal_scores` | `appraisal_id`, `indicator_id`, `score`, `comment` |
| `sup_appraisal_sources` | `appraisal_id`, `source` (`SELF`,`SUPERVISOR`,`PEER`,`SUBORDINATE`,`STUDENT`,`PARENT`), `rater_employee_id`/`rater_user_id`, `weight`, `score`, `comment`, `submitted_at` (multi-sumber/360°) |
| `sup_pip_plans` | `appraisal_id?`, `supervisee_employee_id`, `reason`, `expected_standard`, `consequences_note`, `support_provided`, `start_date`, `final_review_date`, `review_cadence` (`WEEKLY`,`BIWEEKLY`,`MONTHLY`,`OTHER`), `status`, `outcome` (`IMPROVED`,`PARTIAL`,`NOT_IMPROVED`,`EXTENDED`), `acknowledged_at` |
| `sup_pip_items` | `pip_plan_id`, `statement`, `measure`, `target_value`, `target_unit`, `due_date`, `status`, `result_note` |
| `sup_pip_reviews` | `pip_plan_id`, `reviewed_at`, `reviewer_employee_id`, `progress_note`, `next_step`, `status` |

### 4.6 Infrastruktur modul
| Tabel | Fungsi |
|---|---|
| `sup_settings` | pengaturan per sekolah: frekuensi minimum, ambang komentar, kebijakan publikasi hasil, mode nilai, koreksi kehadiran aktif?, retardasi retensi |
| `sup_audit_logs` | jejak lengkap (aktor, aksi, entitas, before/after, alasan, ip, user agent, correlation id) — **tidak dapat diubah/dihapus** |
| `sup_notifications` / `sup_invitations` | undangan, pengingat jadwal, RTL jatuh tempo, pengumuman hasil |
| `sup_reports` | riwayat dokumen dihasilkan (jenis, periode, sasaran, berkas, dibuat oleh, hash) |
| `sup_program_evaluations` | evaluasi CIPP program supervisi (context/input/process/product, skor, temuan, rekomendasi) |
| `sup_calibration_sessions` + `sup_calibration_scores` | *moderasi/kalibrasi penilai*: beberapa supervisor menilai rekaman/kasus yang sama, lalu dibandingkan (varian antar penilai) — mencegah nilai “beda-beda antar penilai” |

### 4.7 Relasi penting (ringkas)
```
employees ──┬─< sup_assignments >──┐
            │                      │
            └─< sup_visits >───────┘ (supervisor & supervisee)
sup_instrument_versions ─< sup_sections ─< sup_indicators ─< sup_indicator_options
sup_visits ─< sup_visit_answers / sup_visit_scores / sup_visit_evidence /
              sup_conferences / sup_findings / sup_acknowledgments / sup_disputes
sup_visits ─< sup_action_plans ─< sup_action_items ─< sup_action_progress
sup_appraisals ─< sup_appraisal_scores / sup_appraisal_sources
                └─< sup_pip_plans ─< sup_pip_items / sup_pip_reviews
```

---

## 5. Mesin penilaian (paling penting — harus tepat sejak awal)

### 5.1 Perhitungan generik (semua instrumen non-khusus)
```text
Skor indikator   = skor opsi yang dipilih (atau nilai numerik/teks)
Skor section     = Σ (skor indikator × bobot indikator)
Skor maksimal    = Σ (skor maks opsi × bobot indikator)   ← dihitung, tidak disimpan manual
Nilai akhir (100) = skor perolehan / skor maksimal × 100
Bila mode WEIGHTED dan ada bobot section:
   Nilai akhir = Σ (persen_section × bobot_section) / Σ bobot_section
Indikator bertanda NA dikeluarkan dari pembilang dan penyebut (bukan dianggap 0)
```
Ambang bawaan (dapat diubah per sekolah per skala):
`90 < A ≤ 100`, `80 < B ≤ 90`, `70 < C ≤ 80`, `≤ 70 = K`
Skala 1–5 (HR): `≥4.5 Istimewa`, `3.5–4.49 Melebihi harapan`, `2.5–3.49 Sesuai harapan`, `1.5–2.49 Perlu perbaikan`, `<1.5 Tidak memenuhi`.

### 5.2 Kalkulator PKG
```text
PKG guru mapel/kelas  : Σ 14 kompetensi (skor 1–4) → maks 56 → ×100/56
PKG guru BK/konselor  : Σ 17 kompetensi → maks 68 → ×100/68
Tugas tambahan        : instrumen tersendiri (kepala sekolah maks 24, wakasek 20, dll.)
Koreksi kehadiran     : NPK = Nilai PKG × (100% − (hari alfa tanpa keterangan / 46) × 100%)
Sebutan & angka kredit: 91–100 Amat baik 125% | 76–90 Baik 100% | 61–75 Cukup 75%
                        | 51–60 Sedang 50% | ≤50 Kurang 25%
NKKS                  : total skor rata-rata / 24 × 100  → ambang 91/76/61/51
```
Sistem **menyimpan setiap langkah** (skor mentah, maksimum, konversi, koreksi,
sebutan, %) di `snapshot` agar dapat diaudit dan tidak berubah bila rumus diubah.

### 5.3 Kuadran SKP/e-Kinerja
```text
rating hasil kerja    ∈ {DI_ATAS_EKSPEKTASI, SESUAI_EKSPEKTASI, DI_BAWAH_EKSPEKTASI}
rating perilaku kerja ∈ {DI_ATAS_EKSPEKTASI, SESUAI_EKSPEKTASI, DI_BAWAH_EKSPEKTASI}
predikat              = matriks 3×3 (dapat dikonfigurasi sekolah)
```
Matriks bawaan mengikuti pedoman: kombinasi tertinggi → “Sangat Baik/Di atas
ekspektasi”, kombinasi terendah → “Kurang/Di bawah ekspektasi” + memicu
**pembinaan kinerja** (bimbingan/konseling) dan opsi membuat **PIP**.

### 5.4 Penilaian pegawai berbobot + multi-sumber
```text
Nilai kriteria = (Σ sumber: skor_sumber × bobot_sumber) → rata-rata berbobot
Nilai akhir    = Σ (nilai kriteria × bobot kriteria) / Σ bobot kriteria
```
Contoh bobot sumber: atasan 60%, rekan 20%, diri sendiri 10%, bawahan/siswa 10%
(dapat dikonfigurasi; sistem menolak bobot yang jumlahnya ≠ 100% kecuali
sekolah memilih mode “normalisasi otomatis”).

### 5.5 Immutabilitas & versi
* Menyimpan `instrument_version_id` + `snapshot` (definisi indikator, bobot,
  ambang, skala) pada saat `finalize`.
* Setelah final: jawaban terkunci. Perubahan hanya lewat `reopen` → tercatat
  sebagai revisi (`sup_visit_revisions`) dengan alasan dan penilai baru.
* Nilai lama tetap ditampilkan memakai snapshot → tren antar tahun tetap sahih.

---

## 6. Instrumen bawaan yang akan di-seed

| # | Instrumen | Item | Skala | Nilai maks | Ambang |
|---|---|---|---|---|---|
| 1 | Administrasi/Perangkat Pembelajaran Guru | 12 | 1–4 | 48 | 90/80/70 |
| 2 | Telaah RPP / Modul Ajar | 36 | 0–2 | 72 | 90/80/70 |
| 3 | Observasi Pelaksanaan Pembelajaran (lengkap) | 40 | Ya/Tidak (+ varian 1–4) | 40 | 90/80/70 |
| 4 | Supervisi Penilaian Hasil Belajar | 11 | 1–4 | 44 (varian 20) | 90/80/70 |
| 5 | Rubrik Penilaian Perangkat Penilaian (GTK) | 5 | 1–4 | 20 | 90/80/70 |
| 6 | Walkthrough Kelas (cepat, 5–8 butir fokus: tujuan tampak, keterlibatan siswa, umpan balik, iklim kelas, pemanfaatan media) | 5–8 | 1–4 + catatan | 32 | tanpa predikat (umpan balik) |
| 7 | Wawancara Pra-Observasi | 8 pertanyaan | teks | — | — |
| 8 | Wawancara Pasca-Observasi + refleksi diri | 9 pertanyaan | teks | — | — |
| 9 | Dampak/Outcome Supervisi | 5+ | 1–4 | 20 | 90/80/70 |
| 10 | Supervisi Tendik — Kepala Tenaga Administrasi | mengikuti tupoksi | 1–4 | — | 90/80/70 |
| 11 | Supervisi Tendik — Persuratan & Pengarsipan | tupoksi | 1–4 | — | 90/80/70 |
| 12 | Supervisi Tendik — Sarana & Prasarana | tupoksi | 1–4 | — | 90/80/70 |
| 13 | Supervisi Tendik — Perpustakaan | tupoksi | 1–4 | — | 90/80/70 |
| 14 | Supervisi Tendik — Laboratorium | tupoksi | 1–4 | — | 90/80/70 |
| 15 | Supervisi Tendik — Layanan Khusus (kebersihan/keamanan) | tupoksi | 1–4 | — | 90/80/70 |
| 16 | Supervisi Manajerial Sekolah (8 aspek administrasi) | 8 aspek | ada/tidak + skor | — | 90/80/70 |
| 17 | PKG — Pembelajaran (14 kompetensi) | 14 | 1–4 | 56 | 91/76/61/51 |
| 18 | PKG — BK/Konselor (17 kompetensi) | 17 | 1–4 | 68 | 91/76/61/51 |
| 19 | NKKS — Kinerja Kepala Sekolah | sesuai instrumen | 6–24 | 24 | 91/76/61/51 |
| 20 | Telaah Kisi-kisi & Soal (PG 18 aspek, uraian 14 aspek, matriks HOTS) | 18/14 | sesuai/tidak sesuai | — | komposisi HOTS |
| 21 | Evaluasi Program Supervisi (CIPP) | 4 dimensi | 1–4 + teks | — | deskriptif |
| 22 | Tinjauan Kinerja Pegawai (bobot, 1–5, multi-sumber) | bebas | 1–5 | — | ambang HR |
| 23 | PIP | item SMART | status/target | — | tercapai/tidak |

Setiap instrumen di-seed sebagai **versi 1 berstatus PUBLISHED**, dengan
panduan penilaian (rubrik) pada indikator yang membutuhkannya — bukan sekadar
daftar pertanyaan.

---

## 7. API (rancangan `/api/v1/supervisi/*`)

| Grup | Endpoint inti | Izin |
|---|---|---|
| Instrumen | `GET/POST /instruments`, `GET /instruments/{id}`, `POST /instruments/{id}/versions`, `POST /versions/{id}/publish`, `POST /versions/{id}/clone`, `GET /versions/{id}/export`, `POST /instruments/import` | `supervisi.instrument.*` |
| Program | `GET/POST /programs`, `GET /programs/{id}`, `POST /programs/{id}/publish`, `GET /programs/{id}/report` | `supervisi.program.*` |
| Penugasan | `GET/POST /assignments`, `PATCH /assignments/{id}` | `supervisi.assignment.manage` |
| Jadwal | `GET/POST /schedules`, `POST /schedules/{id}/reschedule`, `GET /schedules/conflicts` | `supervisi.visit.*` |
| Kegiatan | `GET /visits`, `POST /visits`, `GET /visits/{id}`, `POST /visits/{id}/pre-conference`, `POST /visits/{id}/answers`, `POST /visits/{id}/scores`, `POST /visits/{id}/post-conference`, `POST /visits/{id}/finalize`, `POST /visits/{id}/cancel`, `POST /visits/{id}/reopen` | `supervisi.visit.*` + `score.*` |
| Bukti | `POST /visits/{id}/evidence`, `DELETE /evidence/{id}` | `supervisi.visit.update` |
| RTL | `GET/POST /action-plans`, `PATCH /action-items/{id}`, `POST /action-items/{id}/progress`, `POST /action-plans/{id}/verify` | `supervisi.action_plan.*` |
| Kinerja | `GET/POST /appraisals`, `POST /appraisals/{id}/calculate`, `POST /appraisals/{id}/sources`, `POST /appraisals/{id}/publish`, `POST /appraisals/{id}/pip` | `supervisi.appraisal.*` |
| Tanggapan | `POST /visits/{id}/acknowledge`, `POST /visits/{id}/dispute`, `POST /disputes/{id}/resolve` | `supervisi.dispute.*` |
| Dasbor | `GET /dashboard/supervisor`, `/dashboard/teacher`, `/dashboard/head`, `/dashboard/admin` | `supervisi:view` |
| Analitik | `GET /analytics/trends`, `/analytics/distribution`, `/analytics/training-needs`, `/analytics/compliance` | `supervisi.analytics.view` |
| Laporan | `GET /reports/{key}`, `POST /reports/{key}/export`, `GET /reports/exports`, `GET /documents/{type}/{id}/print` | `supervisi.report.*` |
| Pengaturan | `GET/PATCH /settings`, `GET /audit`, `GET /health` | `supervisi.settings.manage`, `audit.view` |
| Publik/terbatas | `GET /verify/{code}` (verifikasi dokumen supervisi bertanda tangan) | — (rate limit) |

Kaidah umum: envelope respons `{success,data,message,meta}`; paginasi;
rate limit bertingkat (unggah bukti, tanda tangan, ekspor laporan, verifikasi);
`X-Correlation-Id` untuk audit; validasi FormRequest; otorisasi kepemilikan
(supervisor hanya untuk penugasannya; guru hanya data miliknya).

---

## 8. Antarmuka (layar utama)

| Layar | Pengguna | Isi |
|---|---|---|
| **Dasbor supervisor** | Kepala sekolah/waka/pengawas | Jadwal terdekat, kegiatan yang perlu difinalkan, RTL jatuh tempo, guru belum tersupervisi, distribusi predikat sementara |
| **Program & jadwal** | Admin/kepala sekolah | Daftar program, penugasan, kalender supervisi, deteksi bentrok, cetak program |
| **Mode observasi (HP/tablet)** | Supervisor | Lembar instrumen satu-layar, tombol skor besar, catatan fakta bercap waktu, unggah foto/video, **tahan offline** (simpan lokal → sinkron) |
| **Pra/pasca-observasi** | Supervisor & guru | Formulir wawancara baku, refleksi guru, kesepakatan fokus & tindak lanjut |
| **Analisis & temuan** | Supervisor | Kelebihan/kelemahan/masalah/penyebab/prioritas + rekomendasi otomatis dari indikator skor rendah |
| **RTL & papan pemantauan** | Supervisor/guru | Papan Kanban status (belum mulai/berjalan/selesai/terverifikasi) + tenggat |
| **Hasil supervisi saya** | Guru/tendik | Riwayat, skor per bagian, tren, RTL saya, unggah bukti, tombol **tanggapi/keberatan** |
| **Penilaian kinerja** | Kepala sekolah/HRD | PKG/NKKS/SKP/PIP: kalkulator, koreksi kehadiran, kuadran, publikasi, cetak form |
| **Analitik** | Kepala sekolah/auditor | Tren indikator antar waktu, peta kekuatan/kelemahan tim, kebutuhan pelatihan, kepatuhan jadwal, sebaran nilai |
| **Laporan** | Kepala sekolah/admin | Program supervisi, laporan hasil, laporan evaluasi (CIPP), rapor per orang, ekspor CSV/XLSX/PDF |
| **Pengaturan** | Admin | Ambang & skala, frekuensi minimum, kebijakan publikasi, bobot multi-sumber, hak akses, retensi |

Semua tabel dapat diekspor; setiap halaman menyertakan **keadaan kosong yang
mengajarkan** langkah berikutnya (mis. “Belum ada program semester — buat
program dulu”).

---

## 9. Laporan & analitik

**Dokumen wajib (Permendikbud 15/2018)**
1. **Program Supervisi Guru & Tenaga Kependidikan** (tahun/semester).
2. **Laporan Pelaksanaan & Hasil Supervisi Guru**.
3. **Laporan Pelaksanaan & Hasil Supervisi Tenaga Kependidikan**.
4. **Laporan Evaluasi Pelaksanaan & Hasil Supervisi** (termasuk CIPP).

**Laporan analitik**
5. Rekap nilai per guru/tendik + predikat; 6. Distribusi predikat per unit;
7. Tren indikator (per orang/unit/sekolah); 8. Peta kebutuhan pelatihan (TNA);
9. Kepatuhan: siapa belum disupervisi & kegiatan batal/tidak ditindaklanjuti;
10. RTL: tingkat penyelesaian, rata-rata waktu tuntas, temuan berulang;
11. PKG: sebutan, % angka kredit, koreksi kehadiran, tugas tambahan;
12. PIP: status pencapaian target; 13. Kalibrasi: sebaran nilai antar supervisor;
14. **Rapor Supervisi** per orang (dengan tanda tangan & QR verifikasi).

Format: layar (dengan filter), CSV, XLSX, PDF siap tanda tangan; laporan berat
dijalankan di antrean seperti pola modul LMS.

---

## 10. Integrasi

| Dengan | Pemanfaatan |
|---|---|
| `employees`, `users` | Subjek & penilai; jabatan menentukan instrumen yang relevan (`employee_type` guru/staf, `position`) |
| `class_subjects`, `school_classes`, `schedules` | Konteks observasi (mapel/kelas/jam), deteksi bentrok, daftar guru yang harus disupervisi |
| `employee_attendances` | Koreksi kehadiran PKG (hari alfa), dasar PIP kedisiplinan |
| **LMS** (modul yang sudah ada) | Mata pelajaran/kelas sebagai konteks; bukti pengembangan (sertifikat, pelatihan, capaian); tindak lanjut bisa menunjuk course LMS sebagai kegiatan pengembangan |
| Notifikasi & kalender | Undangan jadwal, pengingat H-1, pengingat RTL, publikasi hasil |
| Penyimpanan berkas | Foto/video observasi, dokumen RPP, sertifikat, laporan cetak |
| Modul `tahfiz`/`boarding` (opsional) | Supervisi musyrif/pembina asrama dengan instrumen manajerial khusus |

---

## 11. Keamanan, privasi, anti-gaming

1. **Otorisasi berlapis**: izin modul + kepemilikan penugasan + status dokumen
   (draft hanya pembuat; published sesuai kebijakan visibility).
2. **Audit tak terhapuskan** untuk: pembuatan/publikasi instrumen, penugasan,
   pengisian & perubahan skor, finalisasi, reopen, publikasi hasil, ekspor.
3. **Tanda tangan digital/acknowledge** (hash dokumen + waktu + akun).
4. **Hak tanggapan** dengan alur moderator — meniru mekanisme PKG.
5. **Anti-gaming**: komentar wajib untuk skor di bawah ambang; peringatan bila
   distribusi terlalu miring; kalibrasi penilai; larangan menilai diri sendiri;
   deteksi kegiatan “satu menit” (durasi tidak wajar) sebagai catatan kualitas.
6. **Privasi**: guru melihat detail miliknya; rekan sejawat hanya melihat
   ringkasan bila sekolah mengaktifkan; siswa/wali tidak melihat nilai guru,
   hanya laporan agregat bila dibuka sekolah.
7. **Retensi**: hasil supervisi & penilaian kinerja minimal 5 tahun (mengikuti
   kebutuhan angka kredit), lampiran video lebih pendek bila perlu; pembersihan
   hanya manual dengan persetujuan (tidak ada penghapusan otomatis bukti).

---

## 12. Kinerja & konkurensi

* Indeks wajib: `(supervisee_employee_id, observed_at)`, `(program_id,status)`,
  `(supervisor_employee_id, status)`, `(instrument_version_id, status)`,
  `(action_plan_id,status)`, `(appraisal_type, period_start)`.
* Agregat per bagian disimpan di `sup_visit_scores`; laporan besar memakai
  tabel ringkas/antrean.
* Mode observasi offline: simpan sementara di perangkat (IndexedDB), sinkron
  idempoten dengan `client_uuid` unik per jawaban → aman saat koneksi putus.
* `finalize` memakai transaksi + kunci baris (`lockForUpdate`) agar tidak
  dobel-final saat dua tab terbuka.
* Satu guru boleh dinilai dua supervisor berbeda pada periode yang sama
  (mis. kepala sekolah + pengawas) → kunci unik hanya pada
  `(visit, supervisor, observed_at)`, bukan pada supervisee.

---

## 13. Rencana fase

| Fase | Isi | Definisi selesai |
|---|---|---|
| **1 — Fondasi** | Migrasi 25+ tabel, model, enum, seeder instrumen bawaan (23 template), provider mode (terintegrasi/mandiri), izin & peran, service inti (instrumen versi, program, penugasan, jadwal) | `migrate` + `seed` bersih; unit test versi instrumen & izin lulus |
| **2 — Kegiatan & penskoran** | Visit state machine, pra/pasca, jawaban, mesin skor generik, bukti, temuan, acknowledge, dispute, dasbor supervisor | Uji alur end-to-end: jadwal → pra → observasi → skor → pasca → finalisasi; masuk akal & idempoten |
| **3 — Tindak lanjut & penilaian kinerja** | RTL, pengembangan kompetensi, coaching; kalkulator PKG/NKKS, koreksi kehadiran, SKP kuadran, tinjauan pegawai berbobot + 360°, PIP | Uji perhitungan dengan angka contoh dari pedoman resmi (56→100, koreksi kehadiran, kuadran) |
| **4 — Analitik, laporan & kalibrasi** | Laporan wajib & analitik, CIPP, kalibrasi penilai, ekspor CSV/XLSX/PDF, tanda tangan & verifikasi dokumen, penjadwalan pengingat | Uji laporan (angka cocok), uji cetak, uji hak akses baca |
| **5 — Pengerasan** | Audit menyeluruh, uji beban agregasi, mode offline, keamanan (IDOR, injeksi filter, kebocoran antar sekolah), dokumentasi API + OpenAPI, panduan deployment & pelatihan pengguna | Audit & uji hijau, dokumen lengkap, siap produksi |

---

## 14. Uji yang harus ada

* **Unit**: perhitungan skor (bobot, NA, konversi 100, ambang), PKG 56/68/24,
  koreksi kehadiran, kuadran SKP, bobot multi-sumber = 100%, PIP target.
* **Feature**: seluruh transisi status + aturan validasi (komentar wajib,
  tidak boleh menilai diri sendiri, finalisasi terkunci, reopen ber-audit).
* **Keamanan**: guru A tidak bisa melihat/mengubah hasil guru B; supervisor
  non-penugasan ditolak; ekspor terbatas izin; bypass lewat query param gagal.
* **Integritas data**: jawaban unik per indikator per visit; snapshot tersimpan;
  instrumen versi lama tidak dapat diubah setelah dipakai.
* **End-to-end** (mengikuti skenario nyata Permendikbud 15/2018): kepala sekolah
  menyusun program semester → menjadwalkan 12 guru & 4 tendik → melaksanakan
  1 walkthrough + 1 observasi penuh per orang → membuat RTL → memverifikasi →
  mencetak 4 dokumen wajib → menghitung PKG 2 guru.
* **Kalibrasi**: dua supervisor pada kasus yang sama menghasilkan selisih ≤ ambang
  yang ditetapkan.

---

## 15. Risiko & mitigasi

| Risiko | Mitigasi |
|---|---|
| Modul dianggap “menghukum” guru | Pemisahan jelas coaching vs penilaian; walkthrough tanpa predikat; bahasa UI suportif; hak tanggapan |
| Beban admin tinggi | Instrumen bawaan siap pakai, mode cepat, impor program dari tahun lalu, isi sekali pakai berkali-kali (RTL & paket laporan) |
| Nilai tidak konsisten antar penilai | Rubrik perilaku per level, panduan penilaian, kalibrasi, peringatan distribusi |
| Data lama tak terbaca setelah kebijakan berubah | Snapshot + versi instrumen |
| Supervisor tidak punya waktu | Target 6–10 jam/minggu dipetakan ke jumlah kegiatan; sistem mengusulkan jadwal & memantau kepatuhan |
| Kebocoran data kinerja pegawai | Izin granular, visibilitas lampiran, audit ekspor, retensi terkendali |
| Duplikasi dengan modul kepegawaian (bila nanti ada) | Ekspor API + kunci `sup_appraisals` sebagai sumber resmi supervisi; siap dihubungkan |

---

## 16. Keputusan yang menunggu persetujuan

1. Skala baku: **1–4** (Kemendikbud) saja, atau sediakan **1–5** untuk karyawan? *(Rancangan: keduanya, dipilih per instrumen.)*
2. Apakah hasil supervisi menjadi **komponen resmi penilaian kinerja** (mis. bobot 40%) atau hanya bukti pendukung? *(Rancangan: konfigurabel.)*
3. Siapa saja supervisor: kepala sekolah/waka/pengawas **dan** peer/mentor? *(Rancangan: ya, dengan penugasan eksplisit.)*
4. Multi-sumber (diri/sejawat/siswa) resmi atau tambahan? *(Rancangan: opsional per instrumen.)*
5. Apakah modul menangani **seluruh** penilaian kinerja pegawai (SKP/PIP) atau cukup supervisi + ekspor? *(Rancangan: PKG/NKKS/PIP ya; SKP penuh sebagai opsi fase 3.)*
6. Frekuensi minimum per semester (mis. 1 observasi penuh + 2 walkthrough untuk guru; 1 kunjungan untuk tendik)?
7. Kebijakan visibilitas: guru melihat rincian indikator miliknya? rekan sejawat melihat ringkasan?
8. Perlukah dwibahasa pada dokumen cetak?

**Setelah poin 1–2 disetujui, Fase 1 bisa langsung dieksekusi** (migrasi, model,
seeder instrumen bawaan, izin, service inti) mengikuti pola modul LMS yang sudah
berjalan di repo ini.
