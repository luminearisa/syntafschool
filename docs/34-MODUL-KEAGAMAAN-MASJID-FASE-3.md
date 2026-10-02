# 34 — MODUL KEAGAMAAN & MASJID (SCHOOL OPERATIONS FASE 3)

> **Religious & Mosque Management** — mengelola seluruh siklus kehidupan
> keagamaan di lingkungan sekolah/pesantren: masjid, jadwal shalat, imam,
> muadzin, khatib, shalat Jumat & khutbah, kajian, kegiatan keagamaan,
> presensi shalat, roster tugas, Ramadan, hari raya & qurban, prestasi,
> analitik, dan mode layar masjid.

---

## 1. Posisi Modul

Modul ini **bukan** kumpulan CRUD `Masjid + Jadwal Shalat + Kajian`. Ia adalah
**Religious Life Management Platform** dengan alur:

```
Kalender Keagamaan
        ↓
Jadwal Shalat / Kegiatan
        ↓
Peserta / Jamaah
        ↓
Penugasan (Imam, Muadzin, Khatib)
        ↓
Presensi
        ↓
Pembelajaran / Kegiatan
        ↓
Penilaian / Prestasi
        ↓
Riwayat
        ↓
Analitik
```

Lokasi kode: `backend/app/Modules/SchoolOperations/Religious/`
API: `/api/v1/religious/*` (144 route)

---

## 2. Keputusan Arsitektur

| Keputusan | Alasan |
|---|---|
| **Tidak ada master siswa/pegawai kedua** | Imam, muadzin, khatib, dan pengajar agama selalu merujuk ke Student/HRD lewat kontrak (§16). |
| **Tidak ada mesin presensi kedua** | Presensi shalat menulis catatan operasional lalu memantulkannya ke Attendance Provider institusi (§22). |
| **Tidak ada master aset kedua** | Peralatan masjid menaut ke `asset_id` Asset Management (§7). |
| **Tidak ada ledger keuangan** | Kotak donasi & kontribusi qurban diteruskan ke Finance (§52, §63). |
| **Tidak ada master makanan** | Iftar/Suhoor diteruskan ke Catering sebagai kebutuhan makan (§46, §47, Aturan 11). |
| **Tahfidz tetap sumber kebenarannya** | Halaqah & hafalan hanya dibaca, tidak diduplikasi (§43, §83). |
| **Presensi ≠ disiplin** | Penerusan ke kedisiplinan nonaktif secara bawaan (Aturan 12, §26). |
| **Jadwal shalat multi-sumber** | Rantai penyedia: Manual → Konfigurasi → Kalkulasi → API eksternal (§12). |
| **Simulasi tidak menyentuh data nyata** | Pemesanan, roster, dan kegiatan selalu berupa permintaan bersetatus. |

---

## 3. Basis Data — 72 Tabel

Sembilan migrasi (`2026_12_27_500001` … `500009`):

| Grup | Tabel | Isi |
|---|---|---|
| **Mosque** | 11 | `mosques`, `mosque_areas`, `mosque_access_rules`, `mosque_equipment`, `mosque_asset_assignments`, `mosque_bookings`, `mosque_cleaning_tasks`, `mosque_maintenance_requests`, `mosque_utilization_logs`, `mosque_donation_collections`, `mosque_display_settings` |
| **Prayer** | 7 | `prayer_types`, `prayer_time_sources`, `prayer_schedules`, `prayer_schedule_versions`, `prayer_schedule_overrides`, `congregational_prayers`, `prayer_attendance_policies` |
| **Personnel** | 9 | `religious_personnel_profiles`, `imam_profiles`, `imam_assignments`, `muadzin_profiles`, `muadzin_assignments`, `khatib_profiles`, `khatib_assignments`, `religious_personnel_availability`, `religious_teacher_profiles` |
| **Roster** | 3 | `religious_duty_rosters`, `religious_duty_assignments`, `religious_substitutions` |
| **Attendance** | 5 | `prayer_attendance_records`, `prayer_attendance_adjustments`, `religious_daily_closings`, `religious_attendance_windows`, `religious_boarding_prayer_rules` |
| **Activity** | 10 | `religious_activities`, `religious_activity_sessions`, `religious_activity_registrations`, `religious_activity_participants`, `religious_activity_attendance`, `religious_study_sessions`, `religious_study_references`, `religious_assessment_types`, `religious_activity_assessments`, `religious_participant_profiles` |
| **Friday** | 5 | `friday_prayers`, `friday_prayer_assignments`, `khutbah_categories`, `khutbahs`, `khutbah_versions` |
| **Ramadan & Eid** | 13 | `ramadan_programs`, `ramadan_days`, `ramadan_activities`, `tarawih_sessions`, `tahajjud_sessions`, `iftar_events`, `suhoor_events`, `itikaf_programs`, `itikaf_participants`, `eid_events`, `qurban_programs`, `qurban_animals`, `qurban_distributions` |
| **Ops** | 9 | `religious_achievements`, `religious_certificates`, `religious_announcements`, `religious_adjustments`, `religious_audit_logs`, `religious_events`, `religious_idempotency_keys`, `religious_import_batches`, `religious_import_rows` |

**Tidak ada konflik nama** dengan 1.045 tabel modul lain (diperiksa otomatis saat
generasi migrasi). Modul Tahfidz (`tahfiz_*`) dipertahankan sebagai sumber
kebenarannya dan tidak disentuh.

---

## 4. Kontrak Integrasi — 26 Interface

### 4.1 Dibutuhkan dari modul lain (23)

`StudentGatewayInterface`, `ParentGatewayInterface`, `EmployeeGatewayInterface`,
`TeacherGatewayInterface`, `BoardingGatewayInterface`, `AttendanceGatewayInterface`,
`TahfidzGatewayInterface`, `StudentActivityGatewayInterface`, `CalendarGatewayInterface`,
`EventGatewayInterface`, `FacilityGatewayInterface`, `AssetGatewayInterface`,
`InventoryGatewayInterface`, `CateringGatewayInterface`, `TransportationGatewayInterface`,
`FinanceGatewayInterface`, `NotificationGatewayInterface`, `WorkflowGatewayInterface`,
`DocumentGatewayInterface`, `CertificateGatewayInterface`, `DisciplineGatewayInterface`,
`PrayerTimeProviderInterface`, `ReligiousAccessProviderInterface`

Seluruh gateway **tahan-gagal**: bila sumber tidak tersedia, gateway menyatakan
`available() === false` beserta alasannya — modul tidak mengarang data.

Integrasi nyata yang bekerja hari ini (bukan stub): delegasi ke
`Activity\Domain\Contracts\{TransportationProviderInterface, FacilityProviderInterface,
CertificateProviderInterface, EventProviderInterface, DocumentProviderInterface,
NotificationProviderInterface, BoardingProviderInterface, AttendanceProviderInterface}`,
`Asset\Domain\Contracts\AssetProviderInterface`, dan `Hrd\Domain\Contracts\EmployeeProviderInterface`.

### 4.2 Disediakan untuk modul lain (3)

| Kontrak | Kegunaan |
|---|---|
| `ReligiousLifeProviderInterface` | Modul lain membaca jadwal shalat, shalat berikutnya, kegiatan hari ini, status Ramadan, dan daftar masjid. |
| `ReligiousPersonnelProviderInterface` | Ketersediaan dan bentrok penugasan imam/muadzin/khatib. |
| `MosqueResourceProviderInterface` | Ketersediaan masjid/area dan daftar pemesanan (untuk Event/Facility/Boarding). |

---

## 5. Layanan — 20 Service

`ReligiousScopeService` (izin, peran, cakupan) · `MosqueService` ·
`MosqueBookingService` · `MosqueOperationsService` · `PrayerScheduleService` ·
`PersonnelService` · `CongregationService` · `DutyRosterService` ·
`PrayerAttendanceService` · `FridayPrayerService` · `KhutbahService` ·
`ReligiousActivityService` · `ReligiousLearningService` · `RamadanService` ·
`EidService` · `ReligiousAchievementService` · `ReligiousDashboardService` ·
`ReligiousAnalyticsService` · `ReligiousNotificationService` ·
`ReligiousImportService`

Fondasi bersama `Domain/Support/ReligiousContext.php`: jejak audit, event bus,
**idempotensi** (scan ganda tidak menggandakan catatan presensi), pembatasan
cakupan, pembangkit kode dokumen, dan utilitas waktu.

---

## 6. Penyedia Waktu Shalat (§12)

`PrayerTimeProviderInterface` dengan rantai `CompositePrayerTimeProvider`:

| Penyedia | Perilaku |
|---|---|
| `ManualPrayerTimeProvider` | Membaca jadwal yang dimasukkan operator. |
| `ConfigPrayerTimeProvider` | Jadwal statis dari konfigurasi institusi (per bulan/Ramadan). |
| `CalculatedPrayerTimeProvider` | Titik integrasi kalkulator hisab; **tidak** memuat mesin astronomi kompleks di inti (§100). |
| `ExternalApiPrayerTimeProvider` | Layanan luar, nonaktif bawaan, hasilnya di-cache. |

Urutan prioritas dapat diubah lewat `religious.prayer_time.priority`.

---

## 7. Aturan Bisnis yang Ditegakkan

| # | Aturan | Implementasi |
|---|---|---|
| 1 | Jadwal shalat wajib terikat masjid/kampus | `PrayerScheduleService::generate()` |
| 2 | Imam tidak boleh bertumpang | `PersonnelService::conflictFor()` → tabel roster **dan** penugasan imam |
| 3 | Muadzin tidak boleh bertumpang | idem |
| 4 | Khatib Jumat harus tersedia | `FridayPrayerService::assign()` + `availability()` |
| 5 | Presensi hanya dalam jendela sah | `PrayerAttendanceService::windowCheck()` |
| 6 | Kegiatan selesai tidak diubah destruktif | status/`completion_status`, bukan hapus |
| 7 | Kapasitas penuh → daftar tunggu | `ReligiousActivityService::register()` |
| 8/9 | Pemesanan masjid/area bebas bentrok | `MosqueBookingService::detectConflict()` |
| 10 | Aktivitas Ramadan wajib terikat program | `RamadanService::addActivity()` |
| 11 | Iftar/Suhoor → Catering, bukan transaksi baru | `forwardMealRequirement()` |
| 12 | Presensi **tidak** otomatis jadi sanksi | `ConfigurableDisciplineGateway` nonaktif bawaan |
| 13 | Data keagamaan sesuai cakupan | `ReligiousScopeService` + `ReligiousAccessProviderInterface` |
| 14 | Wali hanya melihat anaknya | `ReligiousDashboardService::parent()` |
| 15 | Riwayat historis tidak dihapus destruktif | `ReligiousImportService::rollback()` menandai status |

---

## 8. API — 144 Route `/api/v1/religious/*`

Kelompok utama: `meta`, `integrations`, `dashboard`, `mosques`, `bookings`,
`maintenance`, `prayer-schedules`, `congregations`, `duty-rosters`, `imams`,
`muadzins`, `khatibs`, `prayer-attendance`, `attendance-policies`, `friday`,
`khutbahs`, `activities`, `study-sessions`, `assessments`, `ramadan`, `eid`,
`qurban`, `achievements`, `certificates`, `analytics`, `imports`, `exports`.

Skema: Sanctum + izin `religious.*` + pembatas laju `religious` (180/menit),
`religious-write` (90/menit), dan `religious-attendance` (300/menit untuk
perangkat QR/RFID/NFC).

---

## 9. Role & Izin

**48 izin** `religious.*` dan **9 peran baru**:

| Kelompok | Peran |
|---|---|
| Manajemen | `RELIGIOUS_ADMIN`, `RELIGIOUS_MANAGER`, `MOSQUE_ADMIN` |
| Personel | `IMAM`, `MUADZIN`, `KHATIB`, `USTADZ`, `USTADZAH`, `RELIGIOUS_TEACHER`, `RELIGIOUS_ACTIVITY_COORDINATOR` |
| Sekolah | `SCHOOL_ADMIN`, `TEACHER`, `BOARDING_STAFF`, `MUSYRIF` |
| Siswa | `STUDENT`, `PARENT` (portal) |
| Pendukung | `FACILITY_MANAGER`, `ASSET_MANAGER`, `INVENTORY_MANAGER`, `CATERING_MANAGER`, `EVENT_MANAGER`, `TRANSPORTATION_MANAGER`, `FINANCE`, `AUDITOR` |

---

## 10. Pengujian

**`backend/tests/Manual/religious_smoke.php`** — 177 pemeriksaan, **0 gagal**,
reproduksibel dari database kosong:

```bash
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\RolePermissionSeeder"
php artisan db:seed --class="App\Modules\SchoolOperations\Religious\Database\Seeders\ReligiousMasterSeeder"
php tests/Manual/religious_smoke.php
```

Cakupan: masjid & area & akses & aset & pemesanan & operasional (18) · jadwal
shalat & versi & penimpaan (11) · imam/muadzin/khatib & bentrok & pengganti (14) ·
roster & tugas siswa (10) · presensi shalat & jendela & idempotensi & asrama &
penyesuaian & penutupan (16) · kegiatan & pendaftaran & daftar tunggu & presensi &
penilaian (17) · prestasi & sertifikat & portofolio (5) · Jumat & khutbah &
pustaka (14) · Ramadan & tarawih & tahajjud & iftar & suhoor & i'tikaf (16) ·
hari raya & qurban (8) · dashboard & layar & mobile (8) · analitik & laporan (8) ·
notifikasi & impor & event bus & audit (12) · keamanan & privasi (7) ·
integrasi lintas modul (9).

### Sepuluh bug yang ditemukan & diperbaiki saat pengujian

| # | Bug | Dampak | Perbaikan |
|---|---|---|---|
| 1 | `ReligiousContext::audit()` memakai **nama tabel sebagai nama kelas** | Seluruh jejak audit gagal tersimpan, tertelan oleh penjaga kesalahan | Memakai model `ReligiousAuditLog` / `DB::table()` untuk tabel yang ditimpa |
| 2 | Tabel append-only tanpa `updated_at` | Eloquent menulis kolom yang tidak ada → audit & idempotensi gagal | `public $timestamps = false;` |
| 3 | `whereBetween` pada kolom ber-cast `date` | Laporan analitik & ringkasan operasional kosong | Memakai `whereDate()` |
| 4 | `conflictFor()` hanya memeriksa roster | Aturan 2/3 tidak menangkap penugasan imam/muadzin tetap | Memeriksa roster **dan** tabel penugasan |
| 5 | Nama relasi `belongsTo` snake_case | `assessmentType`, `program`, `mosque` tidak ada | Generator camelCase + `Ref` hanya bila bentrok kolom |
| 6 | Gateway Tahfidz mengasumsikan `student_id` | Dashboard siswa gagal | Gateway schema-aware (`participant_id` → `tahfiz_participants`) |
| 7 | Gateway asrama peka kapitalisasi status | Penghuni asrama tidak dikenali (`active` vs `ACTIVE`) | `lower(status)` + toleransi status |
| 8 | Penerusan prestasi tidak mengisi kolom wajib | Prestasi gagal diteruskan ke Student Activity | Pengisi skema (`activity_achievements.code`) |
| 9 | Alur khutbah `APPROVED → DELIVERED` ditolak | Presensi Jumat gagal | Jumat menjadwalkan khutbah lebih dulu + transisi diperluas |
| 10 | Binding kontrak memakai `class_exists()` pada interface | Kontrak tidak terpasang | `interface_exists() \|\| class_exists()` |

---

## 11. Batas Fase 3 (§100)

Sengaja **tidak** diimplementasikan penuh, hanya disiapkan titik integrasinya:

advanced Islamic knowledge graph · AI religious advisor · automated fatwa system ·
advanced Quran semantic analysis · AI imam/khatib generation · automated religious
judgment · advanced astronomical calculation engine · real-time mosque IoT
automation · advanced mosque access control hardware · complete donation accounting ·
complete qurban accounting.

Arsitektur akhir siap melanjutkan ke Fase 4 tanpa refactor besar.

---

## 12. Menambah Modul Baru

1. Daftarkan `ReligiousServiceProvider` di `bootstrap/providers.php` (sudah).
2. Untuk mengganti implementasi integrasi, ubah `config/religious.php` bagian
   `gateways` — tidak perlu menyentuh kode modul.
3. Untuk mengaktifkan penerusan ke kedisiplinan:
   `religious.integrations.discipline_enabled = true` (**Aturan 12**).
4. Untuk memasang kalkulator hisab: isi `religious.prayer_time.calculator`.
5. Untuk memasang penyedia catering: isi `religious.integrations.catering_provider`.
