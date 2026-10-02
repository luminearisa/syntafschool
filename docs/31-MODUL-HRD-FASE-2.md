# 31 — Modul HRD Phase 2: Workforce Operations, Talent & Development

Phase 2 mengubah HRD dari *system of record* menjadi **platform operasi tenaga
kerja**: rekrutmen, jadwal & presensi, cuti, lembur, payroll, benefit, kinerja,
pembelajaran, karier, dan talenta — semuanya bertumpu pada data Phase 1
(Person, Employee, Organization, Position, Assignment, Skills, Documents,
Workflow, Audit). Tidak ada satu pun master pegawai baru di Phase 2.

```
Employee → Position → Skills → Schedule → Attendance → Leave
        → Performance → Learning → Career → Talent
Recruitment → Candidate → Offer → Employee → Onboarding → Employment
```

---

## 1. Keputusan arsitektur yang menentukan

### 1.1 Satu modul, banyak domain (bukan banyak modul HR)

Seluruh Phase 2 berada di dalam modul yang sudah ada, `app/Modules/Hrd`, dibagi
per domain (`Recruitment`, `Workforce`, `Leave`, `Payroll`, `Benefits`,
`Performance`, `Learning`, `Career`, `Talent`, `Core`) dengan submappa
`Models / Services / Providers / Http / Database`.

Alasannya bukan estetika: memecah HRD menjadi sepuluh modul terpisah membuat
siklus cuti harus mengimpor presensi, payroll harus mengimpor cuti, dan karier
harus mengimpor kinerja — menciptakan ketergantungan melingkar yang berakhir
pada duplikasi aturan. Yang dipakai lintas modul adalah **kontrak**, bukan
namespace.

### 1.2 HRD mengatur, modul lain mengerjakan

| Kebutuhan | Modul lain | Peran HRD |
| --- | --- | --- |
| Konten kursus | LMS | menyusun pelatihan wajib, mendaftarkan, menutup lingkaran ke keterampilan |
| Tiket layanan | Helpdesk ERP | membuat tiket, mengirim ke Helpdesk, menyimpan nomornya |
| Survei | Survey & Feedback | menerbitkan & membaca agregat |
| Jurnal & anggaran | Finance/Accounting | menyiapkan jurnal payroll & biaya pelatihan |
| Mesin approval | Workflow (Phase 1) | memakainya, tidak menyalinnya |

Semua titik ini berupa antarmuka dengan implementasi bawaan **"belum
terpasang"** (`Core/Infrastructure/Null*Provider`). Modul HRD tetap berjalan
penuh; peristiwanya tetap tercatat di outbox Phase 1 dan terkirim saat modul
tujuan tersedia. Mengganti implementasi cukup satu baris binding.

### 1.3 Kontrak, bukan tabel, yang dipakai modul lain (§99)

16 antarmuka di `Core/Domain/Contracts`: Recruitment, Attendance, Leave,
Payroll, Benefits, Performance, Learning, Competency, Career, Talent, plus
LearningDelivery (LMS), Helpdesk, Survey, FinancePosting, PayrollTaxRule, AIHR.
Implementasi konkretnya didaftarkan di `HrdPhase2ServiceProvider::$bindings`.

### 1.4 Aturan bisnis adalah data, bukan `if`

`hr_rules` + `hr_rule_conditions` menyimpan kelayakan (benefit, cuti, pelatihan
wajib, karier) dengan prioritas dan masa berlaku. `RuleEngine` mengevaluasi
konteks pegawai (jenis kepegawaian, masa kerja, golongan, unit, usia, gender)
dan menyimpan jejak di `hr_rule_evaluations`. Pertanyaan "mengapa pegawai ini
dianggap layak?" dijawab `RuleEngine::explain()` dari data.

`EligibilityEngine` memakai mesin yang sama untuk cuti, benefit, pelatihan,
karier, lowongan internal, dan kompensasi — sehingga satu aturan ditulis sekali.

### 1.5 Keterampilan sebagai lapisan bersama (§108)

Keterampilan tetap milik Phase 1 (`hr_skills`, `hr_employee_skills`). Phase 2
menambah **standar** (`hr_position_skill_requirements`) dan memakai keduanya di
empat tempat sekaligus: kelayakan kandidat, kesenjangan karier
(`CareerService::readiness`), pelatihan yang disarankan, dan pembaruan
keterampilan setelah pelatihan selesai (§65). Rekrutmen, kinerja, pembelajaran,
dan karier karena itu berbicara tentang keterampilan yang sama.

### 1.6 Payroll: snapshot beku, penyesuaian, pemisahan tugas

- Perhitungan membaca struktur gaji + **presensi + cuti + lembur + benefit +
  pajak** pada saat itu juga, lalu menyimpan masukan itu di
  `hr_payroll_items.snapshot`.
- Finalisasi membuat `hr_payroll_snapshots` (payload + checksum) dan slip gaji;
  item dikunci. Angka yang sudah final tidak berubah — koreksi lewat
  `hr_payroll_adjustments` (§42).
- Pemisahan tugas ditegakkan: penyusun ≠ pemeriksa ≠ pengesah ≠ finalis (§80).
- Rekonsiliasi membandingkan jumlah pegawai, bruto, potongan, pajak, neto, dan
  biaya pemberi kerja dengan periode final sebelumnya; selisih di luar ambang
  ditandai (`variance_snapshot`) dan memunculkan peringatan payroll (§81).

### 1.7 Tarif pajak berversi, tidak pernah di kode

`hr_tax_rules` menyimpan lapisan PPh 21 dan tarif BPJS dengan `version` serta
tanggal berlaku. `TaxCalculationService` membacanya lewat
`PayrollTaxRuleProviderInterface`; hanya bila tabelnya kosong ia memakai lapisan
bawaan. Mengubah peraturan berarti menambah baris baru — periode lalu tidak
berubah.

### 1.8 Presensi: satu jalur masuk, banyak sumber

`AttendanceProviderRegistry` mendaftarkan penyedia (`manual`, `web`, `mobile`,
`qr`, `nfc`, `rfid`, `fingerprint`, `face`, `device_api`). Perangkat hanya
menyeragamkan muatan mentah; aturan keterlambatan, **shift lintas tengah malam**
(22:00 → 06:00), geofence, lembur, dan status kehadiran dihitung sekali di
`AttendanceService`. Idempotensi perangkat dijaga `idempotency_key`.

Cuti dan dinas menyetel kalender presensi (`LEAVE`, `BUSINESS_TRIP`) sehingga
payroll tidak pernah memotong hari cuti yang disetujui atau hari dinas.

### 1.9 Lembur dibayar dari kenyataan, bukan dari pengajuan

`OvertimeService::verifyActual()` menghitung jam nyata dari selisih jam kerja
terhadap jadwal, membulatkan sesuai konfigurasi, menerapkan pengali menurut
jenis hari, dan menolak verifikasi bila presensinya belum ada. Payroll adalah
satu-satunya pihak yang menetapkan nilai rupiahnya.

### 1.10 Status dapat dikonfigurasi, keadaan mesin terbatas

Jenis cuti, komponen gaji, corong rekrutmen, kumpulan talenta, dan jenis
transaksi semuanya data. Keadaan yang mengikat alur (mis. `FINALIZED`,
`HIRED`) tetap terbatas dan diperiksa di layanan.

### 1.11 Jejak & peringatan

Setiap perubahan penting menulis tiga hal sekaligus lewat `HrdPhase2Events`:
baris `hr_workforce_events` (dibaca Employee 360), jejak audit Phase 1
(`hr_audit_logs`, tidak dapat diubah), dan pesan outbox untuk modul lain.
Kegagalan audit membatalkan transaksi; kegagalan outbox tidak —
integritas bisnis lebih penting daripada kenyamanan integrasi.

### 1.12 Prefiks `hr_` dipakai untuk seluruh tabel Phase 2

Modul lain ternyata sudah memakai `leave_types`, `assessments`, dan
`notifications`. Seluruh tabel Phase 2 karena itu berprefiks `hr_` (124 tabel),
dan `hr_leave_types` Phase 1 **diperluas**, bukan digandakan.

---

## 2. Basis data

9 migrasi baru (`2026_12_23_200001` … `200010`), 124 tabel `hr_*`:

| Migrasi | Isi |
| --- | --- |
| `200001` governance | aturan & kondisi & evaluasi, peristiwa tenaga kerja, otomasi, peringatan, tiket layanan + komentar, permintaan AI, snapshot analitik |
| `200002` recruitment | requisition, lowongan, kandidat + dokumen, lamaran, tahap & riwayat, screening, jadwal & pewawancara, template & scorecard & skor, definisi asesmen & hasil, tawaran & persetujuannya, sumber kandidat |
| `200003` workforce | jadwal & harinya, shift, rotasi & polanya, jadwal pegawai, lokasi & perangkat presensi, catatan presensi, peristiwa presensi, koreksi, timesheet & entri, pengajuan & catatan lembur, perjalanan dinas & peserta, impor |
| `200004` leave | kebijakan, hak, akrual, saldo, pengajuan & harinya, persetujuan, penyesuaian, kalender libur & libur (+ kolom tambahan pada `hr_leave_types`) |
| `200005` payroll | komponen, struktur & barisnya, penetapan gaji pegawai, komponen pegawai, periode, proses, item & komponennya, snapshot, penyesuaian, pajak, aturan pajak berversi, slip, persetujuan, posting |
| `200006` benefits | jenis, rencana, kelayakan, pendaftaran, tanggungan, transaksi |
| `200007` performance | skala & levelnya, siklus & peserta, sasaran & key result & pembaruan, definisi & hasil KPI, katalog & level & syarat kompetensi, syarat keterampilan posisi, penilaian kompetensi, tinjauan, umpan balik, kalibrasi & entrinya, rencana pengembangan & tindakannya, PIP & tinjauannya |
| `200008` learning | katalog, kebutuhan, permintaan, penugasan, biaya, penyelesaian, asesmen, profil pembelajaran |
| `200009` career & talent | jalur & langkahnya, rencana karier pegawai, lamaran internal, program mentoring, penugasan mentor, sesi, tinjauan talenta & hasilnya, kumpulan talenta & anggotanya, penilaian potensi, penghargaan, apresiasi |

124 model dengan `$table` eksplisit, cast yang benar, dan relasi untuk entitas
yang dipakai layanan.

---

## 3. Layanan

**Domain (20):** Requisition, CandidatePipeline, Offer, RecruitmentAnalytics,
Schedule, Attendance, Overtime, Timesheet, BusinessTrip, LeavePolicy,
LeaveRequest, HolidayCalendar, Payroll, SalaryStructure, TaxCalculation,
Benefit, Performance, Learning, Career, Talent.

**Lintas domain (9):** RuleEngine, EligibilityEngine, AlertService,
WorkforceAnalyticsService, AIHRService, HRServiceCenterService, HrdPhase2Events,
HrdAutomationRunner, AttendanceProviderRegistry.

---

## 4. Yang sudah diverifikasi jalan (bukan klaim)

Uji end-to-end: `backend/tests/Manual/hrd_phase2_smoke.php`
(basis data bersih → migrasi → 5 seeder → 96 pemeriksaan).

```
=== RINGKASAN UJI PHASE 2 ===
lulus: 96 | gagal: 0        (EXIT=0)
```

| Alur | Bukti yang dicetak uji |
| --- | --- |
| **Rekrutmen → pegawai** | permintaan `REQ-2026-0001` disetujui & lowongan terbuka otomatis; kandidat tidak digandakan; screening memindahkan ke `SHORTLISTED` (skor 100); scorecard pewawancara lain tetap tertutup; asesmen 85; tawaran diterima; kandidat menjadi pegawai `EMP-0002`; formasi berkurang |
| **Jadwal → presensi** | jadwal reguler teresolusi (mulai 07:00); shift malam `02 22:00 → 03 06:00` (lintas tengah malam); jam masuk/keluar tercatat; idempotensi perangkat; koreksi disetujui & diterapkan |
| **Lembur** | 425 menit terdeteksi dari presensi; diverifikasi **120 menit** (pengali 1,50) |
| **Timesheet & dinas** | 7 jam (5 dapat ditagih, utilisasi 71,43%); dinas disetujui → presensi menjadi `BUSINESS_TRIP` |
| **Rekonsiliasi presensi** | temuan terklasifikasi (duplikat, lembur tak terduga, dll.) |
| **Cuti** | akrual 1 hari (idempoten); saldo 12 hari; pengajuan 3 hari; pending naik; disetujui → saldo terpakai + kalender presensi tersinkron; konflik terdeteksi; cuti tanpa upah 2 hari terbaca payroll |
| **Payroll** | pokok diprorata 5.000.000; **lembur 85.227**; pajak 246.761; neto **6.188.466**; bruto 6.435.227; komponen EARNING/DEDUCTION/TAX; penyusun ditolak saat menelaah sendiri; final → snapshot + slip; jurnal 3 baris menunggu Finance; penyesuaian `ADJ-2026-0001` |
| **Benefit** | kelayakan diperiksa; `ENR-2026-0001`; tanggungan tercatat; iuran menjadi komponen gaji; potongan 50.000 |
| **Kinerja** | siklus dibuka (2 peserta); OKR 2 KR berbobot 100 → sasaran 54%; kompetensi 3 → target 5 (gap 2); penilaian akhir **3,10 (Meets Expectations)**; kalibrasi → `HIGH_HIGH` dan dapat ditelusuri; rencana pengembangan & PIP |
| **Pembelajaran** | 2 pelatihan wajib terdeteksi; permintaan → pendaftaran (idempoten); selesai skor 92 → **keterampilan Phase 1 diperbarui** (`source=training`); kepatuhan & analitik |
| **Karier** | jalur karier; kesiapan (skor 24, gap 1); lamaran internal `MANAGER_NOTIFIED` memakai **pegawai yang sama**; perpindahan dijalankan mesin transaksi Phase 1 → posisi menjadi `KADEP-P2`, riwayat 2 penugasan |
| **Talenta** | potensi 4,5; hasil tinjauan `HIGH_HIGH`; kumpulan `HIGH_POTENTIAL`; kesiapan suksesi; apresiasi `REC-2026-00001` |
| **Tata kelola** | aturan kelayakan & penjelasannya; peringatan dipindai; tiket HR `HRT-2026-00001` selesai (SLA); dasbor 8 cakupan; 7 snapshot analitik; AI menolak kolom sensitif (`salary`) dan tidak pernah memutuskan |

Cara menjalankan ulang:

```bash
cd backend
rm -f /tmp/hrd2.sqlite && touch /tmp/hrd2.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/hrd2.sqlite CACHE_STORE=array php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE=/tmp/hrd2.sqlite CACHE_STORE=array php artisan db:seed --force
DB_CONNECTION=sqlite DB_DATABASE=/tmp/hrd2.sqlite CACHE_STORE=array php tests/Manual/hrd_phase2_smoke.php
```

---

## 5. API

26 endpoint baca/aksi terdaftar di `/api/v1/hrd/phase2/*`
(`Routes/phase2.php`), semuanya di belakang `auth:sanctum`, izin per kelompok,
dan pembatas laju `hrd-phase2`: meta & kemampuan penyedia, dasbor & analitik
(`{scope}`), peringatan & acknowledge, antrean layanan, analitik rekrutmen,
presensi pegawai & rekonsiliasi tanggal, saldo cuti & kalender libur, payroll
(proses, rekonsiliasi, slip), benefit (ringkasan & kelayakan), kinerja
(ringkasan, nine-box), pembelajaran (matriks, analitik), karier (jalur,
kesiapan), talenta, kompetensi/keterampilan, layanan mandiri pegawai (`self`),
dan saran AI.

Controller tidak pernah menyentuh tabel langsung: penulisan tetap di layanan
domain, sehingga aturan bisnis tidak dapat dilewati dari luar.

---

## 6. Otomasi terjadwal (§79) dan idempotensinya

| Perintah | Kegunaan | Kunci idempotensi |
| --- | --- | --- |
| `hrd:accrue-leave --period=YYYY-MM` | akrual cuti | `leave-accrual:<pegawai>:<jenis>:<periode>` |
| `hrd:scan-alerts` | kontrak, sertifikasi, pelatihan, tinjauan, sasaran, lembur, presensi, payroll | `alerts:<tanggal>:<jam>` |
| `hrd:snapshot-analytics` | snapshot analitik harian | `analytics-snapshot:<tanggal>:<unit>` |
| `hrd:mark-overdue-training` | pelatihan wajib terlewat | `training-overdue:<tanggal>` |

Selain itu: akrual cuti, impor presensi, sinkronisasi perangkat, penugasan
pelatihan, membuat proses payroll, dan posting jurnal semuanya memakai kunci
idempotensi (§117) sehingga penjadwal boleh terpanggil ulang tanpa
menggandakan data.

---

## 7. Keamanan & privasi

- **Izin**: 114 izin `hr.*` dan 18 peran (`HR_ADMIN`, `HR_MANAGER`,
  `RECRUITER`, `PAYROLL_ADMIN/REVIEWER/APPROVER`, `TIME_ADMIN`, `LEAVE_ADMIN`,
  `LND_ADMIN`, `PERFORMANCE_ADMIN`, `TALENT_ADMIN`, `MANAGER`, `EMPLOYEE`,
  `INTERVIEWER`, `TRAINER`, `MENTOR`, `PERFORMANCE_REVIEWER`).
- **Pemisahan tugas**: penyusun payroll tidak dapat menelaah/mengesah/memfinalkan
  proses yang sama; pelanggaran ditolak dengan pesan yang jelas.
- **Gaji & kinerja tidak bocor**: analitik membaca agregat; endpoint payroll
  berada di balik `hr.payroll.view`; layanan mandiri hanya data pegawai sendiri.
- **AI sadar izin (§110)**: permintaan AI menyaring kolom sensitif lebih dulu
  (masking gaji, NIK, kinerja, medis, disiplin bila izin tidak dimiliki), hasil
  disimpan sebagai **draf** dengan `human_reviewed=false`, dan
  `may_decide_hiring=false` — AI hanya menyarankan, tidak memutuskan.
- **Audit**: seluruh perubahan gaji, payroll, koreksi presensi, penyesuaian cuti,
  rating kinerja, keputusan kandidat, tawaran, benefit, penilaian keterampilan,
  dan hasil tinjauan talenta masuk jejak audit Phase 1 yang tidak dapat diubah.

---

## 8. Kesiapan Phase 3 (§119)

| Fondasi Phase 3 | Sudah tersedia di Phase 2 |
| --- | --- |
| Succession planning | `TalentService::successionReady()`, `hr_talent_pool_members.readiness` |
| Talent intelligence | `hr_talent_review_results` (kinerja, potensi, risiko, dampak, aspirasi) |
| Skills intelligence | standar keterampilan posisi + kesenjangan lintas domain |
| People analytics | 8 cakupan analitik + snapshot harian + biaya tenaga kerja |
| Compensation intelligence | struktur gaji, komponen, biaya pemberi kerja, varians antar periode |
| Workforce forecasting | siklus kinerja/turnover/headcount per unit dari waktu ke waktu |
| AI HR agent | `AIHRProviderInterface` + jejak `hr_ai_requests` |
| Advanced engagement | `SurveyProviderInterface` + apresiasi & pengakuan |

Tidak ada tabel atau kontrak yang perlu dibongkar untuk menambah kemampuan itu —
yang perlu ditambah adalah layanan analitiknya.

---

## 9. Yang belum dikerjakan (jujur)

1. **Controller tulis penuh** untuk seluruh domain. Saat ini tersedia 26 endpoint
   baca/aksi agregat + layanan domain lengkap; endpoint CRUD per entitas
   (mis. `POST /recruitment/requisitions`) belum dibuat, sehingga pemakaian saat
   ini lewat layanan (dan uji manual).
2. **Form Request & API Resource** khusus per domain.
3. **Suite PHPUnit**: verifikasi saat ini berupa uji manual
   (`tests/Manual/hrd_phase2_smoke.php`, 96 pemeriksaan) — belum menjadi
   sekumpulan Feature/Unit Test yang dijalankan CI.
4. **Frontend SPA** seluruh layar §115.
5. **Render PDF slip gaji**: data slip, checksum, dan tautan privat sudah siap
   (`hr_payslips.pdf_path`), tetapi belum ada perender PDF yang dipanggil.
6. **Integrasi luar masih implementasi bawaan**: LMS, Helpdesk, Survey, dan
   Finance memakai `Null*Provider` (kontrak siap, outbox menunggu); binding
   tinggal diganti saat modulnya terpasang.
7. **Turnover** memakai riwayat status Phase 1 bila tabelnya tersedia, dan jatuh
   ke perkiraan dari status pegawai bila belum — angka pastinya menunggu data
   keluar yang lengkap.

Urutan lanjutan yang disarankan: (1) controller tulis + Form Request + Resource
untuk 6 alur utama (rekrutmen, presensi, cuti, payroll, kinerja, pembelajaran),
(2) suite PHPUnit dari uji manual yang sudah ada, (3) frontend, (4) perender PDF
slip dan integrasi modul tujuan.
