# Modul Student Activity & Extracurricular — Blueprint & Implementasi

> Bukan "daftar ekskul". Modul ini adalah **Student Development Platform**:
> dari penemuan minat, pendaftaran, keanggotaan, latihan, penilaian
> perkembangan, prestasi, sertifikat, sampai portofolio yang dibaca orang tua,
> SIAKAD, dan BK — dengan setiap modul lain tetap menjadi sumber kebenarannya.

---

## 1. Prinsip yang mengikat desain

| Prinsip | Artinya dalam kode |
|---|---|
| **Orchestrator, bukan duplikator** | Siswa, wali, pegawai, ruang, inventaris, LMS, kehadiran akademik, asrama semuanya dibaca/ditulis lewat kontrak (`Domain/Contracts`). Tidak ada salinan identitas siswa atau jadwal asrama. |
| **Kegiatan ≠ acara ≠ keanggotaan ≠ prestasi** | Empat entitas berbeda dengan daur hidup masing-masing (§2). Prestasi tidak pernah menjadi catatan tempelan pada program. |
| **Aturan, bukan kode** | Syarat penerimaan, batas jumlah aktivitas, kebijakan bentrok, kebijakan siswa belum membayar, aturan penyelesaian, dan kelayakan sertifikat semuanya data/konfigurasi. |
| **Kapasitas dijaga transaksi** | Kursi terakhir tidak dapat direbut dua siswa sekaligus (`lockForUpdate` pada baris program, `createFromRegistration` memeriksa ulang di dalam kunci). |
| **Idempoten di titik rawan** | Pendaftaran, kehadiran (QR/NFC), penilaian, prestasi, sertifikat, skor juri, dan notifikasi memakai `idempotency_key` + indeks unik. |
| **Riwayat tidak ditimpa** | Penangguhan, pengunduran diri, pindah aktivitas, perubahan peran, dan penyelesaian meninggalkan baris riwayat; keanggotaan lama tetap terbaca. |
| **Cakupan baris, bukan hanya izin** | Pembina Futsal tidak melihat anggota Robotics; orang tua A tidak melihat anak B; juri hanya menilai penampilan yang ditugaskan. |
| **Tidak ada sanksi otomatis** | Kehadiran di bawah minimum menghasilkan **peringatan + pembatas kelayakan**, bukan hukuman otomatis (§34). |
| **Verifikasi publik tanpa data pribadi** | Nomor sertifikat beredar luas; verifikasi hanya mengembalikan fakta sertifikat. |
| **Modul lain boleh gagal** | Setiap panggilan integrasi ditangkap & dicatat (`activity_integration_logs`); kegagalan sarpras tidak membatalkan jadwal latihan. |
| **Dapat berdiri sendiri** | Setiap binding dapat diganti penerapan mandiri (`NullEventProvider`, dll.) tanpa mengubah layanan maupun layar. |

---

## 2. Peta konsep

```
ActivityCategory (berjenjang)    ActivityObjective / ActivitySkill
        │                                   │
        └──────────────► ActivityProgram ◄───┘
                              │  (periode, kapasitas, syarat, aturan selesai/sertifikat)
                              ▼
   Student ──► Registration ──► (syarat & bentrok) ──► Consent orang tua
                    │                                      │
                    ├──► Selection (tes coba/audisi)         │
                    ├──► Waitlist ──► promosi saat kursi bebas│
                    ▼                                      ▼
              Membership ◄────────────────────────── approval panitia
                    │
        ┌───────────┼───────────┬──────────────┬───────────────┐
        ▼           ▼           ▼              ▼               ▼
    Schedule     Session    Assessment     Project        Leadership/role
        │           │           │              │
        │           ▼           ▼              ▼
        │      Attendance   Progress ───► Completion ──► Certificate
        │           │           │              │              │
        └───────────┴───────────┴──────────────┴──────────────┘
                              ▼
                   Achievement (individu/tim) ──► Portfolio & Transcript
                                                       │
                                    ┌──────────────────┼─────────────────┐
                                    ▼                  ▼                 ▼
                                 Orang tua          SIAKAD/BK        Universitas
```

Kategori/objektif/keterampilan disiapkan `ActivityMasterSeeder` (data, bukan kode).

---

## 3. Struktur modul

```
app/Modules/Activity/
├── Domain/Enums/            59 enum (status, tipe, level, aksi audit, …)
├── Domain/Contracts/        17 kontrak integrasi
├── Domain/Exceptions/       ActivityValidationException (422), ActivityConflictException (409)
├── Application/Services/    19 layanan
├── Infrastructure/Providers/ 17 penerapan kontrak
├── Models/                  55 model (prefix tabel `activity_`)
├── Http/Controllers/        10 controller
├── Database/Migrations/     6 migrasi · 45 tabel
├── Database/Seeders/        izin & peran, master aktivitas
├── Console/                 6 perintah
├── Notifications/           ActivityNotification
├── Resources/views/         template sertifikat
├── Routes/api.php           111 rute /api/v1/activities/*
└── Providers/ActivityServiceProvider.php
```

---

## 4. Basis data (45 tabel `activity_*`)

**Katalog** (migrasi 1): `activity_categories` (berjenjang), `activity_programs`,
`activity_periods`, `activity_objectives`, `activity_skills`,
`activity_program_skills`, `activity_program_objectives`,
`activity_registration_requirements`, `activity_program_coaches`,
`activity_roles`, `activity_registrations`, `activity_registration_histories`,
`activity_selection_results`.

**Keanggotaan** (2): `activity_consents`, `activity_memberships`,
`activity_membership_histories`, `activity_waitlist_entries`,
`activity_withdrawals`, `activity_transfers`.

**Operasional** (3): `activity_schedules`, `activity_sessions`,
`activity_session_materials`, `activity_attendance`, `activity_announcements`.

**Penilaian** (4): `activity_rubrics`, `activity_rubric_items`,
`activity_assessments`, `activity_assessment_results`,
`activity_skill_evidence`, `activity_progress`, `activity_completions`.

**Kompetisi** (5): `activity_competitions`,
`activity_competition_categories`, `activity_competition_participants`,
`activity_competition_teams`, `activity_competition_members`,
`activity_competition_rounds`, `activity_competition_matches`,
`activity_competition_judges`, `activity_competition_scores`,
`activity_competition_results`.

**Pengakuan & integrasi** (6): `activity_achievements`,
`activity_achievement_members`, `activity_certificates`,
`activity_certificate_verifications`, `activity_portfolios`,
`activity_projects`, `activity_project_members`, `activity_charges`,
`activity_equipment_requests`, `activity_facility_bookings`,
`activity_integration_logs`, `activity_audit_logs`, `activity_notification_logs`.

Pilihan yang disengaja:

- **Status sebagai `string`** yang diikat enum PHP → status baru tidak butuh `ALTER TABLE`.
- **Riwayat tanpa `updated_at`**: model append-only mematikan timestamps; tidak ada endpoint yang menyunting riwayat.
- **Indeks unik yang menjamin aturan**: satu pendaftaran hidup per (program, periode, siswa); satu keanggotaan per (program, periode, siswa); satu kehadiran per (sesi, siswa); satu nilai per (penilaian, siswa); satu skor per (pertandingan, juri, peserta).
- **Tidak ada perubahan pada tabel inti ERP** → migrasi aman di basis data produksi yang sudah berisi data.

---

## 5. Layanan (19)

| Layanan | Tanggung jawab |
|---|---|
| `ActivityCategoryService` | Kategori berjenjang, tujuan, keterampilan, peran; penjagaan penghapusan. |
| `ActivityPeriodService` | Periode akademik/musim; hanya satu periode berjalan (transaksional). |
| `ActivityProgramService` | Katalog, penyuntingan, **penerbitan berprasyarat**, kapasitas, keterampilan/pembina. |
| `ActivityRequirementService` | Aturan penerimaan dari 3 sumber (program, kebijakan sekolah, keadaan) + **deteksi bentrok**. |
| `ActivityRegistrationService` | Pendaftaran idempoten, seleksi, daftar tunggu, keputusan, tagihan, notifikasi. |
| `ActivityConsentService` | Permintaan & keputusan persetujuan orang tua, kedaluwarsa, penjagaan wewenang. |
| `ActivityMembershipService` | Keanggotaan, penangguhan, pengaktifan, peran, pengunduran diri, pindah aktivitas, **promosi daftar tunggu idempoten**. |
| `ActivityScheduleService` | Jadwal, pembuatan sesi idempoten, materi, pengumuman, pemesanan fasilitas. |
| `ActivityAttendanceService` | Kehadiran idempoten + cermin ke modul Attendance, rekap, peringatan kehadiran minimum. |
| `ActivityAssessmentService` | Rubrik, penilaian, bukti keterampilan, progres, **kelayakan penyelesaian**. |
| `ActivityCompetitionService` | Kompetisi, kategori, peserta, tim, babak, juri, skor terkunci + koreksi bersebab, hasil. |
| `ActivityAchievementService` | Prestasi individu/tim, verifikasi, bukti keterampilan, kredit anggota tim. |
| `ActivityCertificateService` | Kelayakan, penerbitan bernomor unik, pencabutan, **verifikasi publik tanpa PII**. |
| `ActivityPortfolioService` | Portofolio dirakit ulang, tautan berbagi eksplisit, transkrip aktivitas. |
| `ActivityAnalyticsService` | Dasbor admin/pembina/siswa/orang tua + 13 jenis laporan operasional. |
| `ActivityAccessService` | Cakupan baris: siswa, wali, pembina, koordinator, juri, panitia. |
| `ActivityAuditService` | Jejak audit append-only untuk seluruh peristiwa penting. |
| `ActivityIntegrationService` | Satu pintu panggilan integrasi + pencatatan hasil/kegagalan. |
| `ActivityNotificationService` | Notifikasi berbasis peristiwa dengan kunci pencegah pengiriman ganda. |

---

## 6. Kontrak integrasi (17)

`StudentProviderInterface`, `ParentProviderInterface`, `TeacherProviderInterface`,
`AttendanceProviderInterface`, `AcademicProviderInterface`, `LmsProviderInterface`,
`FinanceProviderInterface`, `InventoryProviderInterface`, `AssetProviderInterface`,
`FacilityProviderInterface`, `EventProviderInterface`, `TransportationProviderInterface`,
`NotificationProviderInterface`, `CertificateProviderInterface`,
`DocumentProviderInterface`, `BoardingProviderInterface`, `CounselingProviderInterface`.

Penerapan bawaan yang **jujur menyatakan keadaan**:

| Kontrak | Penerapan | Perilaku |
|---|---|---|
| Siswa/wali/pegawai | Eloquent (master ERP) | Membaca `students`, `guardians`, `student_guardians`, `employees`; tautan akun lewat `users.userable`. |
| Kehadiran | Eloquent + `mirror` config | Menulis cermin ke `student_attendances` **hanya** bila kelas/semester/status tersedia; bawaan mati. |
| Akademik | `LocalAcademicProvider` | Membaca konteks akademik; mengirim **usulan** pengakuan, tidak menulis nilai rapor. |
| Keuangan | `ConfigurableFinanceProvider` | Bawaan: hanya mencatat permintaan; `create_invoices = true` → membuat draf tagihan `finance_invoices`. |
| Fasilitas | Eloquent (`rooms`, `room_bookings`) | Ketersediaan & pemesanan; bentrok dilaporkan beserta judul pemesanan yang menghalangi. |
| Inventaris/Aset | Eloquent (`inventory_items`, `inventory_movements`) | Penyerahan & pengembalian dengan mutasi stok satu jalur. |
| LMS | Eloquent (`lms_courses`, `lms_enrollments`) | Pemetaan kursus & penyelarasan keanggotaan; materi tetap di LMS. |
| Asrama | Eloquent (`boarding_activity_schedules`) | Membaca kewajiban mingguan untuk deteksi bentrok; tidak mengubah jadwal asrama. |
| Event/Transportasi | `NullEventProvider`, `NullTransportationProvider` | Menyatakan **belum terpasang** dan mencatat permintaan sebagai rencana. |
| BK | `NullCounselingProvider` | Hanya sinyal perkembangan (kepemimpinan, prestasi); catatan konseling tidak pernah melewati modul ini. |

---

## 7. API (`/api/v1/activities/*`, 111 rute)

```
me · metadata · dashboard · health · settings

categories (GET/POST/PUT/DELETE) · periods (GET/POST, /current)
programs (GET/POST/PUT, /publish, /archive, /capacity, /eligibility) · discover · my-programs
programs/{id}/registrations · registrations (GET, /mine, {id}, /submit, /decide, /cancel, /selection)
programs/{id}/waitlist · waitlist/promote
memberships (GET/POST/{id}, /suspend, /reactivate, /role, /withdraw) · withdrawals/{id}/decide
memberships/{id}/transfer · transfers/{id}/decide · students/{id}/memberships
programs/{id}/schedules · schedules/{id} · sessions (GET/POST/PUT, /cancel, /generate)
programs/{id}/materials · programs/{id}/announcements
sessions/{id}/attendance (GET/POST, /bulk) · sessions/{id}/complete
programs/{id}/attendance/summary · students/{id}/programs/{id}/attendance · my-attendance/{program}
programs/{id}/rubrics · programs/{id}/assessments · assessments/{id} (/publish, /results, /results/bulk)
memberships/{id}/progress · memberships/{id}/completion · memberships/{id}/complete
competitions (GET/POST/{id}, /open, /participants, /teams, /rounds, /judges, /results/build, /results/publish)
competition-participants/{id}/verify · competition-teams/{id}/members · competition-rounds/{id}/matches
my-judge-assignments · competition-matches/{id}/scores (/lock) · competition-scores/{id}/correct
competition-results/{id}/verify
achievements (GET/POST) · achievements/{id}/verify · achievements/{id}/certificate
certificates (GET) · completions/{id}/certificate · certificates/{id}/revoke
students/{id}/portfolio · portfolios (POST) · portfolios/{id}/share · students/{id}/transcript
reports/{type}
portal/parent · portal/parent/children/{id} · portal/consents (+/{id}/decide)
counseling/students/{id}/summary
— publik tanpa login —
POST certificates/verify · GET portfolios/public/{token}
```

Seluruh endpoint terautentikasi memakai `auth:sanctum` + izin granular + batas laju;
verifikasi publik dibatasi 20 permintaan/menit per IP.

---

## 8. Peran & izin

| Peran | Inti kewenangan | Yang **tidak** boleh |
|---|---|---|
| `ACTIVITY_ADMIN` | katalog, periode, program, penilaian, sertifikat, pengaturan | **tidak** memutuskan pendaftaran siswa (perlu `ACTIVITY_MANAGER`/koordinator) maupun memverifikasi prestasi |
| `ACTIVITY_MANAGER` | operasional program: keputusan pendaftaran, keanggotaan, sesi, penilaian, kompetisi (termasuk koreksi skor & penerbitan hasil) | mengubah konfigurasi modul |
| `ACTIVITY_COORDINATOR` | koordinasi beberapa program di bawahnya | sertifikat & pengaturan |
| `ACTIVITY_ADVISOR` / `COACH` / `MENTOR` | anggota, sesi, kehadiran, penilaian, pembinaan pada **program yang ditugaskan** | program lain |
| `ACTIVITY_JUDGE` | menilai penampilan yang ditugaskan | mengubah data peserta, mengoreksi skor |
| `FINANCE` / `INVENTORY` / `FACILITY` | sisi dana, barang, ruang | data keanggotaan & penilaian |
| `siswa` / `wali-murid` | portal: mendaftar, melihat, menyetujui | kehadiran, penilaian, keanggotaan |
| `kepala-sekolah` | verifikasi prestasi, penerbitan hasil kompetisi, laporan | konfigurasi teknis |
| `auditor` | membaca laporan, analitik, audit | menulis apa pun |

---

## 9. Jaminan produksi

- **Pendaftaran idempoten** — indeks unik (program, periode, siswa) + `Idempotency-Key`; mengirim ulang mengembalikan baris yang sama.
- **Kursi terakhir** — keputusan diterima/daftar tunggu diambil di dalam kunci baris program; dua pendaftar bersamaan tidak dapat sama-sama diterima.
- **Promosi daftar tunggu sekali saja** — `promotion_key` unik per entri; sapuan berulang tidak menggandakan keanggotaan.
- **Kehadiran sekali saja** — indeks unik (sesi, siswa) + `idempotency_key`; pengiriman ulang QR/NFC aman.
- **Persentase kehadiran jujur** — penyebutnya **pertemuan yang sudah berlangsung**, bukan jumlah catatan yang sudah diisi (siswa hadir 1 dari 3 terbaca 33%, bukan 100%).
- **Bentrok dinyatakan lawannya** — "bentrok dengan kegiatan asrama Kajian Ba'da Ashar (Senin 15:30–16:30)", bukan sekadar "jadwal bentrok".
- **Skor juri terkunci** — penyuntingan setelah penguncian ditolak; koreksi mengubah baris yang sama tetapi **nilai sebelum + alasan + pelaku** tersimpan di audit.
- **Sertifikat beralasan** — penerbitan menyimpan snapshot kelayakan; pencabutan berjejak; verifikasi publik tidak memuat identitas siswa.
- **Kegagalan modul tetangga tercatat** — `activity_integration_logs` menyebut integrasi, aksi, status, durasi, dan galatnya.
- **Notifikasi tidak berulang** — kunci idempoten pada `activity_notification_logs`; pengiriman ganda tercatat `SUPPRESSED`.
- **Audit append-only** — setiap keputusan penting meninggalkan baris yang tidak dapat disunting lewat API.

---

## 10. Operasional

```bash
php artisan activity:generate-sessions          # sesi dari jadwal berulang (idempoten)
php artisan activity:generate-sessions --program=FUTSAL --days=30
php artisan activity:session-reminders          # pengingat pertemuan besok
php artisan activity:expire-consents            # persetujuan kedaluwarsa
php artisan activity:evaluate-completion        # kelayakan penyelesaian periode berakhir
php artisan activity:check-anomalies            # sesi tanpa kehadiran, pendaftaran menggantung, dsb.
php artisan activity:health                     # kesehatan modul & kesiapan integrasi
```

Jadwal yang sudah terpasang (`routes/console.php`): sesi 22:30 harian, pengingat 17:00
harian, kedaluwarsa persetujuan 23:20 harian, evaluasi penyelesaian Jumat 21:00,
pemeriksaan anomali Senin 07:30.

---

## 11. Pengujian

```
tests/Feature/Activity/                     55 tes · 184 asersi · hijau
├── ActivityTestCase.php                    dunia uji: tahun ajaran, kelas, siswa+wali, pembina, lapangan
├── ActivityCatalogTest.php                 kategori berjenjang, keterampilan, penerbitan berprasyarat, kapasitas
├── ActivityRegistrationTest.php            syarat, kursi penuh → daftar tunggu, promosi idempoten,
│                                           persetujuan orang tua menahan keanggotaan, batas aktivitas,
│                                           permintaan tagihan, keputusan tidak dapat ditimpa
├── ActivityConflictAttendanceTest.php      bentrok aktivitas ↔ aktivitas/akademik/asrama, cermin kehadiran,
│                                           persentase & peringatan, penjagaan cakupan pembina
├── ActivityAssessmentCompletionTest.php    rubrik, nilai → progres & bukti keterampilan, penyelesaian
│                                           menolak bila syarat belum terpenuhi, sertifikat + verifikasi publik
├── ActivityCompetitionAchievementTest.php  kuota kategori, jendela pendaftaran, batas anggota tim,
│                                           skor terkunci, koreksi berjejak, hasil → prestasi → sertifikat,
│                                           kredit tim, cakupan juri
└── ActivitySecurityIntegrationTest.php     401/403, isolasi siswa & anak, cakupan pembina, jejak audit,
                                            integrasi gagal tidak menjatuhkan alur, inventaris & fasilitas,
                                            modul belum terpasang dilaporkan apa adanya
```

**Verifikasi runtime** (bukan hanya tes) di basis data nyata:
program → jadwal → penerbitan → 3 pendaftaran (satu di antaranya memicu peringatan
bentrok asrama dan ditangani sesuai kebijakan sekolah) → 5 sesi → kehadiran →
penilaian → **penyelesaian tertolak** saat kehadiran 25% (aturan bekerja) →
setelah hadir penuh **COMPLETED 100% / skor 88** → sertifikat
`CERT-ACT-2026-000001` → verifikasi publik `VALID` tanpa PII → portofolio siswa.

---

## 12. Antarmuka pengguna

Frontend React/TypeScript: `frontend/src/features/activity/` (types, klien API,
7 halaman) + rute domain `routes/domains/activity.tsx` + grup menu
**Aktivitas Siswa**.

| Layar | Rute | Status | Isi |
|---|---|---|---|
| **Katalog Aktivitas** | `/activities` | tersedia | Penyaring kategori & pencarian, kartu berisi jadwal‑ringkas, pembina, kapasitas, biaya; tombol **Uji syarat** (menyebut syarat yang belum dipenuhi) dan **Daftar** dengan pemilih anak untuk orang tua. |
| **Detail Aktivitas** | `/activities/:id` | tersedia | Tujuan perkembangan, keterampilan yang dibina, jadwal, syarat penerimaan, kapasitas berjalan, dan uji kelayakan. |
| **Aktivitas Saya** | `/activities/my` | tersedia | Keanggotaan + peran, kehadiran, nilai, sertifikat, keterampilan terbukti, riwayat pendaftaran, dan permohonan berhenti **beralasan**. |
| **Ruang Kerja Pembina** | `/activities/workspace` | tersedia | Sesi terjadwal + pembuatan sesi dari jadwal, daftar hadir satu layar, penyelesaian sesi, penilaian (buat/terbitkan/isi), rekap kelengkapan catatan. |
| **Dasbor Aktivitas** | `/activities/dashboard` | tersedia | Angka pengelola (program, anggota, pendaftaran menunggu, daftar tunggu, kehadiran, prestasi, sertifikat), aktivitas terpopuler, peringatan kapasitas, 11 laporan operasional, kesehatan modul & log integrasi. |
| **Aktivitas Anak** | `/activities/parent` | tersedia | Daftar anak, ringkasan capaian, dan **persetujuan** (setujui/tolak + catatan) — tanpa kemampuan mengubah kehadiran/penilaian. |
| **Verifikasi Sertifikat** | `/activities/verify` | tersedia | Memeriksa nomor/kode sertifikat; hasil memuat fakta sertifikat saja. |

**Yang belum dibuat di layar (API-nya sudah ada):** penyusun kompetisi
(babak/pertandingan/juri) dan pencatatan hasil, layar prestasi & penerbitan
sertifikat massal, layar portofolio dengan tautan berbagi, serta halaman
portofolio publik. Semua dapat dibangun tanpa perubahan backend.
