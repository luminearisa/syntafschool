# 30 — Modul HRD / Kepegawaian (Fase 1: Core HR & Employee Lifecycle)

Modul HRD adalah **sumber kebenaran data SDM** untuk seluruh ERP. Modul lain tidak
membuat master pegawai sendiri; mereka membacanya lewat kontrak di
`app/Modules/Hrd/Domain/Contracts` atau lewat REST API `/api/v1/hrd/*`.

```
Employee → Employment → Organization → Position → Assignment
        → Lifecycle → Documents → HR Transactions → History → Analytics
```

---

## 1. Keputusan arsitektur yang menentukan

### 1.1 Person ≠ Employee ≠ User

| Entitas | Tabel | Perannya |
| --- | --- | --- |
| **Orang** | `hr_people` | identitas kependudukan; tetap ada meski hubungan kerja berakhir |
| **Pegawai** | `hr_employees` | hubungan kerja dengan yayasan: nomor pegawai, unit, posisi, status |
| **Akun** | `users` (+ `hr_employees.user_id`) | login; **tidak** dibuat otomatis dari pegawai |

Konsekuensi nyata: pegawai yang berhenti 2024 lalu kembali 2026 **tidak**
menggandakan orangnya (satu `hr_people`, dua `hr_employment_records`), dan satu
guru yang mengajar di dua unit tidak digandakan (satu pegawai, banyak penugasan).

### 1.2 Kolom penempatan pegawai adalah *ringkasan*, bukan sumber

`hr_employees.organization_id / department_id / position_id / location_id /
manager_employee_id` diperbarui oleh mesin transaksi dari penugasan utama. Tidak
ada formulir yang menulisnya langsung — sehingga bagan organisasi, daftar
pegawai, dan laporan formasi tidak pernah saling bertentangan.

### 1.3 Data berlaku-waktu (*effective-dated*)

Penugasan, kontrak, status, dan organisasi menyimpan rentang berlaku. Yang lama
**ditutup**, tidak ditimpa:

```php
HrEmployeeAssignment::query()->where('employee_id', $id)->effectiveOn('2026-04-02')->get();
HrEmployeeAssignment::query()->activeOn('2025-07-01')->get();
```

Pertanyaan §19 ("siapa posisinya pada 1 April lalu?") dan ("siapa kepala
departemen saat itu?") karena itu dapat dijawab dari data, bukan dari ingatan.

### 1.4 Semua perubahan organisasi lewat mesin transaksi

Tidak ada jalur yang boleh menyunting penugasan langsung dari controller.
Transfer, promosi, demosi, mutasi, perubahan status, dan pengakhiran hubungan
kerja berbentuk `hr_transactions` dengan daftar keadaan terbatas:

```
draft → submitted → review → approved → completed
   ↘ cancelled         ↘ rejected
```

Empat jaminan yang dijaga `LifecycleTransactionService`:

1. **Atomic** — penugasan lama ditutup, penugasan baru dibuat, ringkasan pegawai
   diperbarui, keterisian posisi dihitung ulang, linimasa + audit ditulis, semua
   dalam satu transaksi basis data (§77).
2. **Berurutan** — `SELECT … FOR UPDATE` menahan dua approver yang menekan
   "setujui" bersamaan (§78).
3. **Idempoten** — `idempotency_key` menahan pengajuan ganda; menerapkan transaksi
   dua kali tidak menggandakan penugasan.
4. **Tidak melompati persetujuan** — efek hanya berjalan pada keadaan `approved`.

### 1.5 Efek transaksi dapat diperluas tanpa menyentuh mesin

`hr_transaction_types.effect_handler` menunjuk handler terdaftar:

| Alias | Menangani |
| --- | --- |
| `hire` | `HIRE`, `REHIRE` (masa kerja baru tanpa menggandakan orang) |
| `assignment_change` | `TRANSFER`, `MUTATION`, `PROMOTION`, `DEMOTION`, `POSITION_CHANGE`, `DEPARTMENT_CHANGE`, `LOCATION_CHANGE`, `ASSIGNMENT_CHANGE` |
| `manager_change` | `MANAGER_CHANGE` (ikut mengubah resolusi approver) |
| `status_change` | `STATUS_CHANGE`, `LEAVE`, `RETURN_FROM_LEAVE`, `SUSPENSION`, `CONFIRMATION`, keputusan percobaan |
| `separation` | `RESIGNATION`, `TERMINATION`, `RETIREMENT`, `CONTRACT_EXPIRED` |
| `contract_change` | `CONTRACT_EXTENSION`, `CONTRACT_RENEWAL`, `CONTRACT_TERMINATION` |

Menambah jenis transaksi baru = satu kelas efek + satu baris di
`hr_transaction_types`.

### 1.6 Status konfigurabel, keadaan mesin terbatas

`hr_employees.employment_status` berisi **kode referensi** yang dapat diubah
sekolah ("Pegawai Tetap", "Guru Kontrak Yayasan" — apa pun). `lifecycle_state`
berisi keadaan mesin terbatas (`draft … archived`) yang diperiksa
`EmployeeLifecycleStateMachine`. Sekolah bebas menyebut statusnya, mesin tetap
punya aturan transisi yang terverifikasi.

### 1.7 Keterisian posisi dihitung, bukan diketik

`hr_positions.filled_headcount` dihitung ulang dari penugasan aktif setiap kali
penugasan dibuat/ditutup; `vacancy_status` adalah turunannya. Karena itu laporan
formasi tidak pernah berbeda dari daftar pegawai, dan transaksi yang akan
melebihi formasi **ditolak** (§49, §57).

### 1.8 Jejak audit tidak dapat diubah

`hr_audit_logs` menolak `update`/`delete` pada level model; tidak ada endpoint
yang menyuntingnya. Yang dicatat hanya kolom yang berubah, dengan `before`,
`after`, alasan, alamat IP, `transaction_id`, dan `batch_id` (§51).

### 1.9 Integrasi lewat outbox, bukan asumsi modul terpasang

Peristiwa yang perlu diketahui modul lain (perangkat onboarding, clearance aset
dan keuangan, penonaktifan presensi, pembuatan/penangguhan akun, pelatihan)
ditulis lebih dulu ke `hr_integration_outbox`. Modul tujuan yang belum dipasang
**tidak membuat peristiwa hilang** — barisnya menunggu dan terlihat pada layar
kesehatan modul. Modul HRD tetap berjalan penuh tanpa modul Aset/Keuangan.

### 1.10 Jembatan ke tabel `employees` lama

Modul akademik, presensi, jurnal mengajar, aset, dan koperasi masih menunjuk
`employees.id`. HRD menuliskan **proyeksi ringkas** (nama, NIP, unit, status,
aktif) ke tabel lama lewat `hr_employees.legacy_employee_id` — HRD tetap satu-satunya
tempat data diubah. Dapat dimatikan: `hrd.legacy_bridge.enabled = false`.
Kegagalan memproyeksikan tidak pernah menggagalkan transaksi HRD.

---

## 2. Cakupan otorisasi (§52)

| Tingkat | Siapa | Melihat apa |
| --- | --- | --- |
| `global` | `hr.employee.view.all` | seluruh yayasan, seluruh kolom |
| `organization` | `hr.employee.view` | unit yang dikelolanya + sub-unit |
| `team` | `hr.team.view` | dirinya + bawahan (langsung & fungsional, kedalaman terkonfigurasi) |
| `self` | `hr.self.view` | dirinya, kolom non-sensitif |

`hr.employee.sensitive.view` terpisah: NIK dan nomor rekening disamarkan
(`3271**********0001`) bila tidak dimiliki. Administrator teknis tidak otomatis
mendapat akses bisnis. Berkas dokumen berada di disk privat; tautan unduh
bertanda tangan berbatas waktu.

---

## 3. Basis data

62 tabel `hr_*` dalam 14 migrasi terkelompok:

| Migrasi | Isi |
| --- | --- |
| `100001` | referensi konfigurabel (`hr_reference_groups`, `hr_reference_items`) |
| `100002` | organisasi, departemen, lokasi, riwayat struktur, penomoran, setelan |
| `100003` | katalog pekerjaan (job family, level, grade, job) |
| `100004` | posisi + riwayat posisi |
| `100005` | orang, pegawai, identitas |
| `100006` | kontak, alamat, kontak darurat, tanggungan, pendidikan |
| `100007` | keterampilan & sertifikasi (master + milik pegawai) |
| `100008` | dokumen + versi dokumen |
| `100009` | masa kerja, percobaan, jenis kontrak, kontrak, jenis cuti |
| `100010` | penugasan, hubungan atasan |
| `100011` | jenis transaksi, transaksi, rincian, riwayat status, linimasa, penggabungan |
| `100012` | onboarding & offboarding (template → proses → tugas, clearance) |
| `100013` | permintaan HR + rincian perubahan |
| `100014` | aturan validasi, kualitas data, catatan, impor, audit, notifikasi, outbox |

---

## 4. Layanan (Application layer)

| Layanan | Tanggung jawab |
| --- | --- |
| `EmployeeService` | pembuatan, perubahan, status, arsip, penggabungan duplikat, kunci optimistis |
| `AssignmentService` | penugasan & atasan: satu penugasan utama, periode tidak tumpang tindih, deteksi lingkaran |
| `LifecycleTransactionService` | mesin transaksi: pengajuan → persetujuan → penerapan atomik |
| `OrganizationService` | pohon organisasi (materialized path), reparent, riwayat, departemen, lokasi |
| `PositionService` | formasi, keterisian, lowongan, riwayat posisi, katalog jabatan |
| `EmploymentService` | masa kerja, percobaan, kontrak, total masa kerja, riwayat posisi pada tanggal |
| `DocumentService` | unggah, versi, verifikasi, kedaluwarsa, tautan unduh berizin |
| `CompetencyService` | keterampilan, sertifikasi, pendidikan, kesenjangan keterampilan |
| `LifecycleProcessService` | onboarding, pre-boarding, offboarding, clearance |
| `HrRequestService` | permintaan HR & perubahan data (diminta → disetujui → diterapkan) |
| `DataQualityService` | 22 pemeriksa, skor, temuan ber-fingerprint |
| `HrdAccessService` | cakupan baris/kolom |
| `DashboardService`, `HrdReportService` (laporan), `HrQueryService` (pertanyaan sadar izin) | dasbor, laporan, §83 AI-ready |
| `HrdTimelineService`, `HrdAuditService`, `HrdNotificationService` | linimasa, audit, notifikasi |

---

## 5. API

Seluruh endpoint di bawah `/api/v1/hrd/*` dengan `auth:sanctum` + `permission:hr.*`
+ pembatas laju. Bentuk respons seragam: `{ data, meta }`.

Kelompok utama: `me`, `metadata`, `references/*`, `dashboard`, `health`, `calendar`,
`audit`, `settings`, `employees*` (termasuk `profile`, `timeline`, `history`,
`merge`, `import`), `organizations/tree/org-chart`, `departments`, `locations`,
`jobs`, `job-catalog`, `positions*`, `employment/*`, `contracts`, `documents/*`,
`skills`, `certifications`, `transactions/*` (submit → review → approve → reject →
apply), `onboarding`, `offboarding` (+ `clearance`), `requests`, `reports/*`,
`directory`, `data-quality/*`, `validation-rules`, `self/*`, `team/*`.

---

## 6. Otomasi terjadwal (§70)

| Perintah | Jadwal | Kegunaan |
| --- | --- | --- |
| `hrd:scan-expiries` | harian 06:30 | kontrak, dokumen, sertifikasi, percobaan, tenggat tugas, SLA permintaan |
| `hrd:apply-due-transactions` | harian 00:15 | menerapkan transaksi yang tanggal berlakunya tiba |
| `hrd:dispatch-outbox` | tiap 10 menit | mengirim peristiwa ke modul lain |
| `hrd:scan-data-quality` | Senin 05:00 | skor & temuan kualitas data |

---

## 7. Verifikasi yang sudah dijalankan

1. `php artisan migrate` — 14 migrasi HRD, 62 tabel.
2. `HrdPermissionSeeder` + `HrdMasterSeeder` — izin, 13 peran, referensi, jenis
   transaksi, jenis dokumen, aturan validasi, template onboarding/offboarding.
3. Uji end-to-end pada basis data sementara:
   organisasi berjenjang → posisi (formasi 1) → pegawai (`EMP-0001`) → penugasan
   → penolakan penugasan melebihi formasi → transaksi **TRANSFER**
   (`TRX-2026-0001`, 5 rincian perubahan) → persetujuan → **penerapan**: penugasan
   lama tertutup, penugasan baru dibuat, posisi A menjadi `vacant`, posisi B
   `fully_filled`, ringkasan pegawai berpindah ke `KOORD-MTK-SMOKE-001`,
   7 peristiwa linimasa, 26 baris audit.
4. Penerapan ulang transaksi: `already_applied = true`, jumlah penugasan tidak
   bertambah (idempoten).
5. Kueri berlaku-waktu `2026-04-02` mengembalikan `GURU-MTK-SMOKE-001`.
6. Pemindaian kualitas data: 22 aturan dievaluasi, skor 51/100 (data sintetis).
7. Upaya mengubah baris audit ditolak: *"Jejak audit HRD tidak dapat diubah (§51)"*.

---

## 8. Yang belum dikerjakan (jujur, agar tidak terlewat)

Bagian berikut **belum** ada pada commit ini dan menjadi pekerjaan lanjutan:

1. **Controller** untuk 9 kelompok endpoint yang sudah terdaftar di
   `Routes/api.php` (`HrdEmployeeController`, `HrdOrganizationController`,
   `HrdPositionController`, `HrdEmploymentController`, `HrdLifecycleController`,
   `HrdDocumentController`, `HrdReportController`, `HrdDataQualityController`,
   `HrdSelfServiceController`). Endpoint `HrdMetaController` sudah berfungsi
   (konteks, metadata, dasbor, health, kalender, audit, setelan, pertanyaan
   terstruktur). Berkas route berfungsi sebagai spesifikasi kontrak API.
2. **Form Request & API Resource** kelas khusus (validasi domain sudah ditegakkan
   di layanan; `RespondsWithHrd` menyediakan amplop respons).
3. **Pengujian otomatis** (`tests/Feature/Hrd*`) — verifikasi saat ini dilakukan
   melalui skrip end-to-end, belum sebagai suite PHPUnit.
4. **Frontend** SPA (halaman dasbor, direktori pegawai, profil 360°, bagan
   organisasi, posisi, transaksi) — belum dibuat.

Urutan lanjutan yang disarankan: controller + Form Request + Resource (2),
suite PHPUnit (3), lalu frontend (4) — karena frontend membutuhkan kontrak API
yang stabil lebih dulu.
