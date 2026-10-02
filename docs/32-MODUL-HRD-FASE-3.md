# 32 — Modul HRD Phase 3: Strategic HCM, Workforce Intelligence & AI

Phase 3 adalah **lapisan strategis tertinggi** HRD. Ia tidak menambah CRUD baru:
ia membaca seluruh data Phase 1 (Core HR) dan Phase 2 (Workforce Operations &
Talent), lalu mengubahnya menjadi **perencanaan, prakiraan, wawasan, dan
rekomendasi yang dapat dieksekusi**.

```
Phase 1 → SYSTEM OF RECORD        Siapa pegawai kita?
Phase 2 → SYSTEM OF WORK          Bagaimana mereka bekerja, dibayar, belajar, berpindah?
Phase 3 → SYSTEM OF INTELLIGENCE  Workforce seperti apa yang kita butuhkan?
```

Rantai yang dijaga di seluruh modul:

```
DATA → METRIC → INSIGHT → RISK/OPPORTUNITY → RECOMMENDATION
     → HUMAN DECISION → WORKFLOW → ACTION → MEASUREMENT
```

---

## 1. Keputusan arsitektur yang menentukan

### 1.1 Membaca, bukan menduplikasi

Phase 3 tidak membuat master pegawai, posisi, gaji, presensi, atau siswa kedua.
Reuse yang penting:

| Kebutuhan | Sumber yang dipakai |
| --- | --- |
| Pegawai, posisi, organisasi, penugasan | Phase 1 (`hr_employees`, `hr_positions`, `hr_organizations`, `hr_assignments`) |
| Keterampilan pegawai (kanonik) | Phase 1 (`hr_skills`, `hr_employee_skills`, `hr_position_skill_requirements`) |
| Presensi, cuti, payroll, benefit | Phase 2 |
| Kinerja, tujuan, kompetensi, pembelajaran, karier | Phase 2 |
| Talenta (kumpulan, tinjauan, potensi, nine-box) | Phase 2 (`hr_talent_pools`, `hr_talent_review_results`, `hr_potential_assessments`) |
| Aturan kelayakan & otomasi | Phase 2 `RuleEngine` (`hr_rules`) dan `AlertService` |
| Approval | Workflow Engine Phase 1 (`HrdWorkflowService`) |
| Audit & timeline | Tabel yang sama: `hr_audit_logs`, `hr_workforce_events` |

Tabel Phase 3 yang sudah ada di Phase 2 **tidak dibuat ulang** (talent pool,
talent review, potential assessment); Phase 3 menambah taksonomi, bukti,
gap, permintaan, prakiraan, suksesi, marketplace, analitik, dan AI di atasnya.

### 1.2 Metrik adalah data, bukan kode

`hr_metric_definitions` menyimpan definisi **kanonik** (formula, dimensi,
pemilik, klasifikasi, silsilah). `MetricEngine` mengevaluasinya dan menolak
tabel/kolom di luar lapisan semantik — sehingga HR, Finance, dan AI tidak
pernah menghasilkan dua angka berbeda untuk istilah yang sama.

### 1.3 Lapisan semantik untuk AI

`hr_semantic_entities` + `hr_semantic_fields` mendaftarkan entitas yang boleh
diakses (Employee, Position, Payroll Item, Attendance, Leave, Performance,
Employee Skill, Skill Forecast, Critical Position, Succession Candidate,
Talent Review Result, Retention Signal) beserta izin, filter, dan dimensinya.
Natural language → rencana query → otorisasi → data → tabel. **Tidak ada SQL
bebas dari LLM.**

### 1.4 Simulasi tidak pernah menyentuh data aktual

Skenario workforce, desain organisasi, kenaikan gaji, bonus, dan kampus baru
semuanya menyimpan *proposal* + hasil simulasi. Setiap hasil memuat
`integrity.simulation_only` dan bukti bahwa jumlah pegawai tidak berubah.
Perubahan aktual tetap melalui mesin transaksi + workflow Phase 1/2.

### 1.5 Kesiapan & risiko selalu disertai bukti

Kesiapan suksesi (bobot konfigurasi: skill, kinerja, kompetensi, pengalaman,
aspirasi, pengembangan, sertifikasi) dan sinyal retensi
(Low/Medium/High) **tidak pernah** ditampilkan sebagai satu angka tanpa
komponen, faktor, dan periode datanya. Sistem secara eksplisit menyatakan
bahwa sinyal bukan prediksi pasti dan bukan dasar tindakan otomatis.

### 1.6 AI: permission-aware, explainable, human-in-the-loop

- Tool hanya boleh yang terdaftar dan berizin (`hr_ai_tools`, `hr_ai_tool_permissions`).
- **Field-level security**: kolom gaji hanya terlihat pemegang `hr.payroll.view`.
- **No source → no factual answer**: tanpa hasil tool, AI menolak menjawab.
- Aksi tulis selalu **draf → konfirmasi manusia → eksekusi** (masuk workflow).
- Aksi terlarang diblokir: terminasi, penolakan kandidat otomatis, penurunan
  gaji, demosi, penolakan cuti, penghapusan data pegawai.
- Setiap langkah dicatat: `hr_ai_runs`, `hr_ai_tool_calls`, `hr_ai_audit_logs`,
  `hr_ai_usage_logs`, `hr_ai_cost_logs`, `hr_ai_feedback`, `hr_bias_monitoring`.

### 1.7 Analitik dipisahkan dari beban transaksional

`hr_workforce_snapshots`, `hr_people_analytics_snapshots`, dan
`hr_metric_snapshots` adalah read-model; dashboard membaca snapshot + definisi
metrik, bukan query berat ke tabel transaksi. Kebijakan kesegaran data ada di
konfigurasi (transaksional real-time, dashboard near real-time, strategis
harian, prakiraan terjadwal, pengetahuan AI terkendali).

---

## 2. Basis data

9 migrasi `2026_12_24_300001…300009`, **103 tabel** `hr_*`:

| Migrasi | Isi |
| --- | --- |
| `300001` perencanaan | rencana + versi, permintaan, pasokan, gap, skenario + asumsi, prakiraan, proyeksi biaya, desain organisasi, rencana posisi, kebutuhan sumber daya |
| `300002` keterampilan | domain, kategori, relasi/ontologi, level, syarat (scope job/posisi/unit), posisi & job skill, profil pegawai, bukti, asesmen, gap, permintaan, prakiraan, tata kelola |
| `300003` suksesi | posisi kritis, rencana suksesi, kandidat, kesiapan, risiko, skenario, pipeline kepemimpinan |
| `300004` marketplace | jenis peluang, peluang, syarat peluang, hasil pencocokan, minat, ketersediaan, penugasan proyek, penugasan sementara |
| `300005` analitik | definisi metrik, dimensi, snapshot metrik, snapshot workforce, snapshot domain, dashboard, widget, saved view, silsilah metrik, katalog data, aturan kualitas, eksperimen + hasil, entitas & field semantik |
| `300006` prakiraan | model, run, input, output, skenario prakiraan, asumsi, registry model (ML/LLM) |
| `300007` AI | provider, model, agent, tool, izin tool, percakapan, run, tool call, rekomendasi, feedback, usage log, cost log, audit log, kebijakan, dokumen & chunk pengetahuan, pemantauan bias |
| `300008` tata kelola | risiko, sinyal risiko, bukti risiko, aksi risiko, riwayat risiko, rencana aksi strategis + item + hasil + tinjauan |
| `300009` people | sinyal retensi, snapshot attrition, definisi & tahap & instance & event perjalanan, snapshot pengalaman, snapshot engagement, analisis & temuan ekuitas upah, skenario kompensasi, simulasi insentif, insight layanan, relasi organisasi (dotted line) |

103 model dengan cast, relasi, dan namespace per domain:
`Strategic\Workforce|Skills|Succession|Talent|Analytics|Forecasting|Ai|Governance|People`.

---

## 3. Layanan (25)

**Perencanaan & organisasi** — `WorkforcePlanningService`, `ScenarioService`,
`OrganizationDesignService`, `AcademicWorkforceService`.

**Keterampilan & talenta** — `SkillsIntelligenceService`,
`TalentIntelligenceService`, `TalentMarketplaceService`,
`CareerIntelligenceService`, `SuccessionService`.

**Analitik & prakiraan** — `MetricEngine`, `PeopleAnalyticsService`,
`ForecastService`, `CompensationIntelligenceService`,
`RetentionAnalyticsService`, `EmployeeExperienceService`,
`JourneyAutomationService`.

**Tata kelola** — `RiskService`, `ScorecardService`.

**AI** — `AIOrchestratorService`, `AIToolRegistry`, `AIGovernanceService`,
`AiIntelligenceService`.

**Fondasi bersama** — `Phase3Context` (audit, event, scope, idempotensi, angka),
`PeopleFacts` (kinerja, potensi, kompetensi, sertifikasi, pembelajaran, pay,
masa kerja, rantai atasan).

---

## 4. Kontrak & API

11 kontrak di `Strategic\Domain\Contracts` (Workforce Planning, Workforce
Forecast, Skills Intelligence, Talent Intelligence, Succession, Talent
Marketplace, People Analytics, Compensation Intelligence, Retention Analytics,
AIHR, HR Recommendation) → implementasi didaftarkan di provider; modul lain
cukup bergantung pada kontraknya.

**286 rute** `/api/v1/hr/*`: strategic (workforce, demand, supply, gap, cost,
skenario, what-if, desain organisasi, span of control, network, risiko, aksi,
command center, scorecard, executive, foundation), skills (intelligence, gap,
demand, forecast, heatmap, critical, governance, evidence, assess,
build-buy-borrow), talent (intelligence, segmen, matriks, mobility,
marketplace, pencocokan, tinjauan, alokasi, proyek, karier), succession,
analytics (dashboard, metrik, katalog, kualitas, snapshot, prakiraan,
kompensasi, ekuitas, retensi, attrition, pengalaman, journey, layanan),
governance (risiko, aksi), dan AI (ask, agents, runs, recommendations, tools,
policy, semantic, query, knowledge, registry, cost, bias, feedback, confirm).

Semua rute memakai Sanctum + izin `hr.*` + rate limit `hrd-phase3`, dan
menghormati scope organisasi/departemen/self.

---

## 5. Data fondasi (seeder `HrdPhase3MasterSeeder`)

22 metrik kanonik · 12 entitas semantik + field · 9 dataset katalog data + 4
aturan kualitas · taksonomi keterampilan + level · 10 jenis peluang · 5 model
prakiraan + registry · 3 provider + 3 model AI · 10 agent · 16 tool + izin ·
kebijakan AI · 5 dashboard + widget · 3 perjalanan pegawai (onboarding,
promosi, offboarding) · 2 aturan otomasi (sertifikasi kedaluwarsa, promosi) ·
2 dokumen kebijakan untuk asisten pengetahuan.

Peran baru: `HR_STRATEGIST`, `WORKFORCE_PLANNER`, `PEOPLE_ANALYST`,
`TALENT_MANAGER`, `SUCCESSION_PLANNER`, `COMPENSATION_ANALYST`,
`HR_AI_STEWARD` (+ `HR_DIRECTOR`/`FOUNDATION_ADMIN` dengan akses penuh).

---

## 6. Bukti pengujian

`backend/tests/Manual/hrd_phase3_smoke.php` — dijalankan pada **basis data
kosong** (migrasi → 6 seeder → uji):

```
=== RINGKASAN UJI HRD PHASE 3 ===
lulus: 145 | gagal: 0        (EXIT=0, dari 1001 tabel hasil migrasi)
```

| Bagian | Bukti yang dicetak uji |
| --- | --- |
| **A. Workforce** | rencana `WFP-0001`; 14 kebutuhan headcount; pasokan aktual 7 aktif + attrition 13%; gap 6 temuan (HEADCOUNT, SKILL, COST, LOCATION) dengan rekomendasi build/buy/borrow; proyeksi biaya 12 bulan (varians anggaran); optimasi posisi (kritis 1, SPOF 3); kapasitas & utilisasi; kebutuhan aset dari headcount |
| **B. Skenario** | siswa 300→360 → guru 17→20 (gap 3) dengan dampak biaya; **integritas: pegawai sebelum 9 = sesudah 9**; what-if (5 berhenti + gaji +5%) memunculkan risiko & proyeksi tahunan; perbandingan skenario; Academic (rasio 17,25); boarding (6 musyrif, gap 5); klinik; kampus baru (51 staf); desain organisasi (layer, span, biaya) + span of control + jaringan organisasi |
| **C. Skills** | ontologi (complementary/prerequisite/related); sinkronisasi syarat Phase 1/2; bukti → keyakinan 100 dengan sertifikasi; daur hidup; gap 3 prioritas HIGH + 4 opsi pengembangan; heatmap 7 sel; keterampilan kritis (CLOUD, LEADERSHIP, MATEMATIKA); supply vs demand; prakiraan 7 keterampilan; build/buy/borrow (2,25 jt / 9,37 jt / 72 jt); permintaan pelatihan |
| **D. Talent** | portofolio lintas modul (skill, pelatihan, pengakuan, kinerja, potensi); profil menjawab 6 pertanyaan; segmen (HIGH_PERFORMER, CRITICAL_SPECIALIST, SME…); matriks kinerja × potensi; mobility; marketplace: `OPP-0001` → 7 kandidat dengan skor 75,92 + faktor + bukti, status PENDING → tinjauan manusia CONFIRMED; penugasan proyek 30%; over-allocation terdeteksi; karier: simulasi gap 3 + kesiapan 36,25 (NOT_READY) + rencana 6 bulan |
| **E. Suksesi** | posisi kritis (LEADERSHIP_CRITICAL); rencana `SUC-0001`; 2 kandidat dengan kesiapan READY_1_2Y (61,50) & NOT_READY (36,25) + komponen bukti; risiko WEAK_SUCCESSOR_POOL dengan tindakan; skenario kepergian incumbent; cakupan 100%; pipeline EMERGING_LEADER |
| **F. Analytics** | 22 metrik kanonik; HEADCOUNT = 8 (aktif); turnover 14,29%; biaya/pegawai; succession coverage; silsilah metrik; tren; snapshot workforce & domain; 10 domain analitik; drill-down; **row-level security: scope departemen 3 vs global 8**; prakiraan headcount (proyeksi −15%, keyakinan 84) idempoten; kompensasi (median, ekuitas, skenario 5%, insentif); retensi (5 MEDIUM, faktor & disclaimer); attrition 12,5%; **ambang privasi survei menolak menampilkan hasil (0 < 5)**; journey analytics; eksperimen (+7,5%); otomasi event → kondisi → aksi |
| **G. Tata kelola** | 17 sinyal risiko (contract risk HIGH + tindakan); risk register; insight → `ACT-0001` (2 item) → approve → ukur (varians 2) → tinjauan; command center; scorecard 10 area (4 on-target, 6 at-risk) dengan tren & sumber; executive dashboard 6 seksi; board/yayasan 4 unit |
| **H. AI** | saldo cuti dijawab dari data + sumber + explainability; **field-level security**: pemegang izin payroll melihat angka, tanpa izin ditolak (dan penolakan tercatat); workforce analyst (guru 20, gap 17); succession assistant; analytics turnover; talent pool (bukan keputusan); **draf → konfirmasi → eksekusi**; aksi terlarang ditolak; rekomendasi (bukti, opsi, biaya) → belum disetujui tidak boleh dieksekusi → approve → menjadi rencana aksi; feedback; **tanpa sumber → menolak menjawab**; entitas tak dikenal → query ditolak; NL query (EMPLOYEE, 2 baris, scope GLOBAL); asisten kebijakan dengan sitasi; registry 10 agent/3 model/1 kebijakan; biaya & pemakaian; pemantauan bias; 11 baris audit AI; 16 tool terdaftar |

Cara menjalankan ulang:

```bash
cd backend
rm -f /tmp/hrd3.sqlite && touch /tmp/hrd3.sqlite
export DB_CONNECTION=sqlite DB_DATABASE=/tmp/hrd3.sqlite CACHE_STORE=array
php artisan migrate --force
php artisan db:seed --force
php tests/Manual/hrd_phase3_smoke.php
```

---

## 7. Bug yang ditemukan pengujian (dan diperbaiki)

1. **Presedensi operator** pada evaluator aturan otomasi (`?? '=' === '='`)
   membuat setiap kondisi dianggap tidak cocok → otomasi tidak pernah jalan.
2. **Array dipakai sebagai key** pada `sources` lapisan semantik → TypeError
   saat natural-language query diproses.
3. **Field masking tidak sadar izin** → kolom gaji tertutup bahkan bagi
   pemegang izin payroll; kini masking mengikuti izin kolom.
4. **Row-level security belum diterapkan** pada drill-down analitik (hanya
   ditandai) → kini membatasi baris sesuai scope departemen/organisasi/self.
5. **Kelas model** `HrEmployeeOpportunityMatche` (singularisasi `-es` salah)
   → diubah menjadi `HrEmployeeOpportunityMatch`.
6. **Filter `worker_type` peka huruf besar** padahal referensi Phase 1 memakai
   nilai berkapital (`Teacher`) → filter dibuat case-insensitive.
7. **`verified_by` pada `hr_employee_skills` merujuk tabel `users`**, bukan
   `hr_employees` → layanan kini memetakan employee id ke user id (atau null).
8. **Skema payroll Phase 2** memakai `run_id`/`tax_total`/`gross_amount`, bukan
   `payroll_run_id`/`tax_amount`/`total_amount` → mesin metrik, biaya
   rata-rata, dan varians anggaran diselaraskan.
9. **Kelas tidak terdaftar di container** karena `class_exists()` selalu false
   untuk interface → binding 11 kontrak kini benar-benar terpasang (11/11).
10. **Sertifikasi pegawai** memakai tabel kepemilikan yang salah
    (`hr_certifications` adalah katalog) → memakai `hr_employee_certifications`
    bila tersedia.

---

## 8. Yang belum dikerjakan (jujur)

1. **UI/SPA** untuk seluruh halaman §156 (endpoint sudah tersedia).
2. **Prakiraan ML nyata**: metode saat ini statistik (trend historis, moving
   average, musiman, asumsi bisnis) dengan registry siap ML; belum ada
   pelatihan model.
3. **Provider eksternal belum terpasang**: Academic, Boarding, Clinic,
   Finance, Asset, Survey, Helpdesk dibaca lewat probe tahan-gagal; bila modul
   sumber belum ada, angka berasal dari asumsi skenario yang dinyatakan
   eksplisit di `evidence.assumptions`.
4. **LLM eksternal**: provider bawaan adalah `INTERNAL` (rule + tools), bukan
   panggilan jaringan; provider OpenAI/Local sudah terdaftar sebagai konfigurasi
   dan perutean model, tinggal diaktifkan.
5. **Controller CRUD penuh per entitas Phase 3**: yang tersedia adalah endpoint
   aksi/agregat (§135) — cukup untuk dashboard, copilot, dan integrasi.
6. **Suite PHPUnit**: verifikasi saat ini berupa uji manual 145 pemeriksaan;
   belum dipecah menjadi Feature/Unit Test CI.
7. **Registrasi perintah artisan terjadwal** untuk snapshot/prakiraan berkala
   (layanan & idempotensinya sudah siap).

Urutan lanjutan yang disarankan: (1) UI dashboard strategis + copilot,
(2) PHPUnit dari uji manual, (3) pemasangan provider eksternal (Academic/
Finance/Survey), (4) aktivasi LLM + evaluasi bias berkala, (5) pelatihan model
prakiraan pertama.

---

## 9. Prinsip akhir

> **Phase 1 = Know the People. Phase 2 = Manage the People.
> Phase 3 = Understand, Predict, Develop, Deploy & Strategically Plan the Workforce.**

Seluruh keluaran Phase 3 bersifat **evidence-based, permission-aware,
explainable, auditable, dan human-in-the-loop** — dan setiap wawasan dapat
bergerak dari data menjadi aksi yang diukur.
