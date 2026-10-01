# Modul Bimbingan & Konseling (BK) — Phase 1

**Status:** selesai · 30 tabel · 35 enum · 31 model · 19 layanan · 12 kontrak integrasi ·
**79 rute** `/api/v1/bk/*` · **7 halaman** · **41 tes / 133 asersi hijau**

Phase 1 membangun **operational case management**: dari kekhawatiran yang pertama
terucap sampai kasus dinyatakan selesai — dengan kerahasiaan sebagai bagian dari
fondasi, bukan tambahan di akhir.

---

## 1. Alur utama

```
Student → Referral → Screening → Case → Assignment → Assessment
      → Counseling Plan → Counseling Session → Intervention
      → Follow Up → Review → Resolution → Closure (dapat REOPENED)
```

Setiap perpindahan status dicatat pada **timeline kasus** (tabel `bk_case_events`,
append-only) dan **jejak audit** (`bk_audit_logs`), lengkap dengan pelaku, peran,
waktu, dan status sebelum/sesudah.

Referral yang masuk melalui portal (siswa/orang tua) tetap melewati jalur yang
sama: masuk antrean BK → ditelaah koordinator → menjadi referral → screening →
kasus.

---

## 2. Peran & izin

| Peran | Kewenangan | Yang **tidak** dapat dilakukannya |
|---|---|---|
| `BK_ADMIN` | seluruh modul, termasuk pengaturan konselor & label | — |
| `BK_COORDINATOR` | seluruh kasus, menerima referral, penugasan & penggantian, monitoring beban kerja, eskalasi, tinjauan, penutupan/pembukaan kembali | mengelola pengaturan modul (`bk.manage`) |
| `COUNSELOR` | kasus yang ditugaskan/peserta, screening, asesmen, sesi, rencana, intervensi, tindak lanjut, penutupan | melihat kasus konselor lain, menugaskan orang lain |
| `wali-kelas` (HOMEROOM) | membuat referral, melihat **bagian** kasus yang dibagikan, melihat pertemuan orang tua yang melibatkannya | **membuka catatan konseling** (izinnya memang tidak diberikan) |
| `guru` | membuat referral, melihat status referralnya sendiri | melihat kasus/catatan |
| `pengasuh-asrama` | membuat referral + observasi asrama, melihat profil dukungan (tanpa bagian BK) | melihat kasus/catatan |
| `siswa` | mengajukan permintaan konseling, melihat janji temu sendiri, mengisi asesmen yang diberikan | melihat kasus, catatan, atau konferensi internal |
| `wali-murid` | mengajukan dukungan untuk anaknya, melihat janji temu anak, **memutuskan persetujuan**, melihat yang dibagikan | melihat anak orang lain, catatan konseling |
| `auditor` / `kepala-sekolah` | jejak audit, laporan agregat, beban kerja | menulis apa pun |
| `super-admin` / `admin-sekolah` | seluruh izin modul (**aditif**, tidak menghapus izin modul lain) | — |

Guru **sengaja tidak** memperoleh akses ke catatan konseling hanya karena ia wali
kelas: catatan konseling bukan informasi operasional pengajaran.

---

## 3. Kerahasiaan (fondasi, bukan tambahan)

Enam tingkat pada `ConfidentialityLevel`:

| Tingkat | Peringkat | Contoh pemakaian |
|---|---|---|
| `PUBLIC_INTERNAL` | 1 | informasi umum sekolah |
| `STAFF_ONLY` | 2 | hal yang boleh diketahui wali kelas/pengasuh |
| `RESTRICTED` | 3 | **referral** (bawaan) |
| `COUNSELOR_ONLY` | 4 | **sesi konseling**, kasus individual (bawaan) |
| `CONFIDENTIAL` | 5 | kekhawatiran perlindungan anak, krisis |
| `HIGHLY_CONFIDENTIAL` | 6 | catatan konselor yang paling sensitif |

Aturan yang ditegakkan `BkAccessService` — **seluruhnya di server**:

- **Langit-langit kerahasiaan**: kasus tidak boleh lebih terbuka daripada
  referralnya; turunan (sesi, catatan, dokumen) tidak boleh lebih terbuka daripada
  kasusnya. Pelonggaran yang tidak sah ditolak `BkValidationException`.
- **Tidak ada catatan rahasia yang "tidak sengaja terkirim"**: endpoint daftar sesi
  hanya mengirim `counselor_note` kepada pemegang akses konseling, dan hanya
  mengirim penanda `has_counselor_note` kepada yang lain.
- **Pembacaan data rahasia selalu tercatat** (`VIEW_CONFIDENTIAL`), begitu pula
  **penolakan akses** — sehingga pertanyaan "siapa mencoba membuka catatan anak
  saya?" dapat dijawab.
- **Jejak audit tidak memuat isi catatan**: `BkAuditService::diff()` hanya mencatat
  nama kolom untuk kolom naratif (`counselor_note`, `discussion`, `body`, …).

Uji privasi wajib (§28) semuanya ada di `BkPrivacySecurityTest`:

| Uji | Menegaskan |
|---|---|
| guru × catatan konseling | 403 pada catatan & sesi; `canViewNote()` tetap `false` walau kasus dibagikan |
| guru × kasus yang dibagikan | boleh membuka header setelah dibagikan, **tetapi tidak** catatannya |
| orang tua × kasus anak lain | 403 walau menebak ID; profil dukungan pun ditolak |
| orang tua × konferensi kasus internal | 403 pada pertemuan internal |
| siswa × catatan konselor | 403; portal tetap berjalan untuk yang dibagikan |
| laporan/ekspor | tidak memuat penanda rahasia apa pun |
| dokumen rahasia | tidak dapat diunduh pihak lain; unduhan berwenang dicatat |
| jejak audit | hanya pemegang `bk.audit.view`; percobaan akses tercatat |

---

## 4. Basis data (30 tabel)

| Kelompok | Tabel |
|---|---|
| Inti | `bk_counselors`, `bk_counselor_availability`, `bk_tags`, `bk_referrals`, `bk_cases`, `bk_case_tags`, `bk_case_assignments`, `bk_case_participants`, `bk_case_events` |
| Layanan | `bk_screenings`, `bk_assessment_templates`, `bk_assessment_sections`, `bk_assessment_questions`, `bk_assessments`, `bk_assessment_responses`, `bk_appointments`, `bk_counseling_sessions`, `bk_counseling_plans`, `bk_goals`, `bk_interventions`, `bk_follow_ups`, `bk_support_requests` |
| Rekaman | `bk_consents`, `bk_parent_meetings`, `bk_parent_meeting_participants`, `bk_parent_meeting_actions`, `bk_case_documents`, `bk_case_notes`, `bk_audit_logs`, `bk_notification_logs` |

Semua tabel berprefix `bk_` dan **tambahan** — tidak ada tabel inti yang diubah.
ERP ini satu sekolah per instalasi (tanpa kolom tenant), sehingga isolasi berbasis
tenant belum diperlukan; yang ditegakkan adalah isolasi **peran & baris**.

Catatan penting: **`session_summary` vs `counselor_note`** dipisah di skema, bukan
hanya di kode — supaya "ringkasan yang boleh dibagikan" dan "catatan pribadi
konselor" tidak pernah tertukar karena kelalaian.

---

## 5. API (`/api/v1/bk`)

| Kelompok | Endpoint penting |
|---|---|
| Konteks | `GET me`, `metadata`, `dashboard`, `dashboard/today`, `dashboard/aging-cases`, `health`, `audit` |
| Profil dukungan | `GET students/{student}/support-profile` |
| Referral | `GET/POST referrals`, `GET referrals/{id}`, `POST referrals/{id}/receive`, `/screen`, `/open-case`, `/reject`, `/cancel` |
| Kasus | `GET/POST cases`, `GET/PATCH cases/{id}`, `POST cases/{id}/transition`, `/assign`, `/assignments/{user}/end`, `/decline`, `/review`, `/escalate`, `/close`, `/reopen`, `/tags`, `/participants` |
| Timeline | `GET cases/{id}/timeline` |
| Rekaman | `POST cases/{id}/screenings`, `GET/POST cases/{id}/assessments`, `/sessions`, `/plans`, `/interventions`, `/follow-ups`, `/notes`, `/meetings`, `/documents` (+ unduhan terproteksi) |
| Janji temu | `GET/POST appointments`, `POST appointments/check-conflicts`, `PATCH appointments/{id}/reschedule`, `POST …/cancel`, `…/attendance` |
| Tindak lanjut | `GET follow-ups`, `POST follow-ups/{id}/complete`, `/reschedule`, `/miss` |
| Laporan | `GET reports`, `GET reports/{type}` (12 jenis, semua agregat) |
| Portal | `GET portal/summary`, `GET/POST portal/requests/*`, `GET portal/appointments`, `GET portal/consents`, `POST portal/consents/{id}/decide` |

Semua endpoint memakai: Form Request → Policy → Service → transaksi → Resource →
audit → paginasi/penyaringan/pengurutan, dengan pembatasan laju per pengguna
(`bk-module`, `bk-search`, `bk-write`, `bk-report`, `bk-download`).

---

## 6. Integrasi (12 kontrak)

BK menjadi **sumber kebenaran hanya** untuk: kasus, referral, screening, konseling,
asesmen, rencana, intervensi, tindak lanjut. Selebihnya dibaca dari modul pemiliknya:

| Kontrak | Implementasi bawaan | Yang dibaca |
|---|---|---|
| `StudentProviderInterface` | Eloquent | identitas, kelas, wali kelas |
| `ParentProviderInterface` | Eloquent | relasi wali (dicek ke `student_guardians`) |
| `AcademicProviderInterface` | Eloquent | rata-rata nilai, mata pelajaran di bawah ambang |
| `AttendanceProviderInterface` | Eloquent | hadir/terlambat/sakit/izin/alpa + persentase |
| `DisciplineProviderInterface` | Eloquent | poin & kategori pelanggaran (tanpa narasi) |
| `BoardingProviderInterface` | Eloquent | kamar, status, komitmen kegiatan asrama |
| `ClinicSupportProviderInterface` | Eloquent | **penanda** kunjungan & tindak lanjut (tanpa diagnosis) |
| `StudentActivityProviderInterface` | delegasi ke Modul Activity | sinyal perkembangan yang memang boleh dibagikan |
| `NotificationProviderInterface` | database | notifikasi aman (tanpa isi catatan) |
| `DocumentProviderInterface` | disk lokal privat | isi berkas dokumen kasus |
| `WorkflowProviderInterface` | opsional | alur persetujuan eksternal (bila Workflow terpasang) |
| `AuditProviderInterface` | NullAuditProvider | penerusan ke audit ERP bila kelak ada |

Modul tetap berjalan penuh bila modul lain belum terpasang — `bk:health` melaporkan
apa yang tersedia dan apa yang tidak, tanpa membuka data pribadi.

---

## 7. Penutupan kasus yang tidak asal tutup

`POST cases/{id}/close` menolak penutupan selama masih ada:

- tindak lanjut belum selesai, intervensi masih berjalan, sasaran belum dinilai,
- sesi masih terjadwal, pertemuan orang tua belum tuntas, kesepakatan pertemuan
  belum ditindaklanjuti, atau kasus belum pernah ditinjau.

Daftar penghalang dikirim ke layar (`closure_blockers`) supaya konselor tahu apa
yang harus dituntaskan. Koordinator dapat `force` — dan pemaksaan itu tercatat di
timeline beserta alasannya. Menutup kasus juga menutup tindak lanjut menggantung
menjadi `CANCELLED` agar tidak muncul sebagai keterlambatan palsu.

---

## 8. Operasional

```bash
php artisan migrate --seed        # 3 migrasi BK + BkPermissionSeeder + BkMasterSeeder
php artisan bk:flag-overdue-follow-ups    # tandai tindak lanjut terlambat + beri tahu
php artisan bk:send-appointment-reminders # pengingat janji temu
php artisan bk:expire-consents            # tutup persetujuan kedaluwarsa
php artisan bk:review-overdue-cases       # kasus yang lewat tenggat tinjauan
php artisan bk:health                     # integrasi, antrean, penegakan privasi
```

Jadwal yang disarankan: pengingat janji setiap pagi, penandaan keterlambatan
tindak lanjut setiap pagi, penutupan persetujuan kedaluwarsa harian, tinjauan
kasus mingguan.

---

## 9. Pengujian

```
tests/Feature/Bk/                     41 tes · 133 asersi
├── BkReferralLifecycleTest           8 tes  — alur referral → screening → kasus, cakupan pelapor
├── BkPrivacySecurityTest            12 tes  — uji privasi wajib §28 + jejak audit
├── BkCaseClosureTest                 7 tes  — penghalang penutupan, pembukaan kembali, eskalasi
├── BkAppointmentFollowUpTest         8 tes  — deteksi bentrok (6 sumber), pengingat, keterlambatan
└── BkPortalTest                      6 tes  — portal siswa/orang tua, persetujuan, kedaluwarsa
```

Frontend: `tsc -b` bersih, `vite build` sukses, dan **7 halaman** diverifikasi di
browser sungguhan (dasbor, referral, kasus, detail kasus, janji temu, laporan,
portal) dengan grup menu **Bimbingan & Konseling**.

---

## 10. Yang sengaja belum dikerjakan (Phase 2)

- **Kelompok/group counseling** dengan kehadiran massal dan dinamika kelompok.
- **Surat rujukan eksternal** ber-kop dengan tanda tangan digital.
- **Asesmen berulang** (pre/post) dan grafik perubahan antar waktu.
- **Penjadwalan otomatis** berbasis preferensi siswa + beban konselor.
- **Integrasi e-raport** untuk menyuntik catatan perkembangan non-sensitif.
- **Aplikasi mobile** khusus siswa untuk permintaan cepat.
