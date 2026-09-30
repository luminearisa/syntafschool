# 16 — Modul Clinic / UKS (Ruang Kerja Klinis)

Status: **implementasi inti selesai & teruji** · 52 tes / 342 asersi hijau · build frontend bersih

Dokumen ini menjelaskan modul **Clinic / UKS** sebagai *ruang kerja klinis*: alur
layanan kesehatan dari pasien datang sampai pulang, termasuk obat, rujukan, dan
pemberitahuan ke orang tua. Modul ini berdiri sebagai domain sendiri
(`App\Modules\Clinic`, tabel `clinic_*`, API `/api/v1/clinic/*`) dengan batas
layanan yang jelas terhadap modul lain.

---

## 1. Hubungan dengan modul UKS yang sudah ada

Repository ini memiliki **dua permukaan modul kesehatan** yang sengaja dibedakan:

| Aspek | `App\Modules\UKS` (`uks_*`, `/api/v1/uks`) | `App\Modules\Clinic` (`clinic_*`, `/api/v1/clinic`) |
|---|---|---|
| Sasaran | UKS ringan: petugas UKS mencatat kunjungan & obat | Klinik dengan tenaga medis: triase, vital, SOAP, diagnosis, resep, rujukan |
| Obat | Buku stok sendiri (`uks_medicines.stock` + `uks_stock_movements`) | **Tanpa buku stok** — saldo di `inventory_items`, lewat `InventoryProviderInterface` |
| Alergi | Satu kolom teks pada profil | Tabel alergi terstruktur + peringatan saat peresepan |
| Riwayat klinis | Catatan teks pada kunjungan | Asesmen SOAP ber-versi, diagnosis berkode, tindakan, observasi, MAR |
| Peran | `petugas-kesehatan` | `dokter`, `perawat`, `petugas-uks`, `petugas-farmasi`, `resepsionis-klinik`, `admin-klinik` |

Keduanya tidak saling menulis data: **kunjungan UKS bukan kunjungan Clinic**.
Sekolah memilih salah satu sebagai alur resmi, atau memakai UKS untuk pencatatan
cepat dan Clinic untuk layanan berkewenangan medis. Yang tidak dilakukan adalah
menyalin data di antara keduanya, karena itu akan menciptakan dua sumber
kebenaran untuk rekam medis.

---

## 2. Arsitektur

```
App\Modules\Clinic
├── Domain
│   ├── Enums/           53 enum (satu kelas per file, disimpan sebagai string)
│   └── Contracts/       10 kontrak integrasi
├── Infrastructure/Providers/   implementasi kontrak (Eloquent/Inventory/Lokal)
├── Application
│   ├── Services/        17 layanan domain
│   └── Support/         penomoran dokumen
├── Http
│   ├── Controllers/     11 controller
│   └── Requests/        (validasi inline dengan Rule::in(enum) — lihat §9)
├── Models/              40 model Eloquent (+1 base abstrak)
├── Database/Migrations/ 3 migrasi (40 tabel `clinic_*`)
├── Database/Seeders/    izin + master data
├── Routes/              api.php + public.php (96 rute terdaftar)
├── Console/             2 perintah artisan terjadwal
└── Notifications/       satu kelas notifikasi (database + mail)
```

### Lapisan layanan

| Layanan | Tanggung jawab |
|---|---|
| `ClinicAccessService` | **Cakupan baris** (siapa boleh melihat rekam medis siapa) — inti privasi |
| `ClinicAuditService` | Jejak audit (VIEW/CREATE/UPDATE/EXPORT/…/AMEND) + amendment bernilai lama- baru |
| `PatientService` | Rekam medis induk, nomor MRN, alergi, kondisi khusus, riwayat, peringatan alergi |
| `VisitService` | Pendaftaran, antrean, triase, tanda vital, **mesin status** kunjungan, peringatan ambang |
| `ClinicalRecordService` | Asesmen SOAP ber-versi, diagnosis, tindakan, observasi, tindak lanjut |
| `PrescriptionService` | Penerbitan resep + pemeriksaan alergi + override beralasan |
| `DispensingService` | Penyerahan obat: FEFO, blokir kedaluwarsa, integrasi Inventory, idempotensi |
| `MedicationAdministrationService` | MAR: pemberian obat + koreksi non-destruktif |
| `EmergencyService` / `InjuryService` | Gawat darurat (lewati antrean) & cedera dengan mekanisme kejadian |
| `ReferralService` | Rujukan wajib tujuan+alasan, pelacakan status, tindak lanjut otomatis |
| `DischargeService` | Pemulangan, tindak lanjut, presensi (berkebijakan), saran asrama |
| `ScreeningService` | Program skrining massal (idempotent per siswa+tanggal+jenis) & imunisasi |
| `CertificateService` | Surat keterangan: diminta → ditinjau → terbit + dokumen ber-versi |
| `ClinicNotificationService` | Notifikasi ber-kebijakan privasi + pencatatan yang ditahan |
| `ClinicReportService` | Dashboard, 8 laporan, ekspor CSV |
| `ClinicHealthService` | `GET /clinic/health` tanpa membocorkan data pasien |

---

## 3. Kontrak integrasi (tanpa duplikasi sumber kebenaran)

| Kontrak | Implementasi bawaan | Sumber kebenaran |
|---|---|---|
| `StudentProviderInterface` | `EloquentStudentProvider` | `students`, `school_classes`, `class_students` |
| `ParentProviderInterface` | `EloquentParentProvider` | `student_guardians`, `guardians`, `users` |
| `InventoryProviderInterface` | `InventoryItemProvider` | `inventory_items`, `inventory_movements` (**satu-satunya penulis saldo**) |
| `FinanceProviderInterface` | `NullFinanceProvider` | — (layanan kesehatan tidak ditagihkan; dapat diganti tanpa mengubah alur klinis) |
| `NotificationProviderInterface` | `DatabaseNotificationProvider` | `notifications` + `clinic_notification_logs` |
| `DocumentProviderInterface` | `LocalDocumentProvider` | disk privat `clinic` (`storage/app/private/clinic`) |
| `AssetProviderInterface` | `InventoryAssetProvider` | `inventory_items` (serial number) |
| `AttendanceProviderInterface` | `EloquentAttendanceProvider` | `student_attendances` — **hanya menulis bila kebijakan mengizinkan** |
| `BoardingProviderInterface` | `EloquentBoardingProvider` | `boarding_placements` (baca saja; status asrama tetap milik modul Boarding) |
| `DiagnosisCodeProviderInterface` | `InternalDiagnosisCodeProvider` | `clinic_diagnoses_master` (dapat diganti ICD-10/11) |

Semua binding berada di `ClinicServiceProvider::bindContracts()` — mengganti
integrasi cukup satu baris, tanpa menyentuh layar maupun logika klinis.

---

## 4. Alur utama (end-to-end, teruji)

```
Cari siswa (master ERP)
   ↓  belum ada rekam medis → buat MRN (MR-2026-000001, imun)
Pasien
   ↓  daftar kunjungan (idempoten: idempotency_key)
Kunjungan + antrean (gawat darurat → langsung pemeriksaan)
   ↓  triase → prioritas (EMERGENCY/URGENT/SEMI/NON)
Tanda vital (IMT otomatis + peringatan ambang konfigurasi)
   ↓  asesmen SOAP (ber-versi)
Diagnosis (kode master) → Tindakan
   ↓  resep (periksa alergi; override wajib alasan)
Penyerahan obat (FEFO, blokir kedaluwarsa, stok Inventory berkurang)
   ↓  pemberian obat (MAR: pelaku + waktu)
Observasi (bila perlu, dengan catatan berkala)
   ↓  pemulangan (kembali ke kelas/asrama/dipulangkan/dirujuk)
Notifikasi orang tua (disaring kebijakan privasi)
   ↓  tindak lanjut terjadwal + portal orang tua + laporan
```

Alur gawat darurat: `buka peristiwa → tindakan segera → kontak orang tua →
rujukan → transport → hasil → tindak lanjut → tutup`.
Alur asrama: `siswa → UKS → observasi → obat → kembali ke asrama (saran, bukan
perubahan status) → notifikasi → tindak lanjut`.

---

## 5. Aturan bisnis yang ditegakkan

| # | Aturan | Di mana |
|---|---|---|
| 1 | Pasien berasal dari provider valid | `PatientService::forStudent()` |
| 2 | Akses hanya pada lingkup yang sah | `ClinicAccessService` (setiap endpoint) |
| 3 | Penyerahan obat memeriksa alergi | `PrescriptionService::create()` |
| 4 | Obat kedaluwarsa tidak diserahkan | `DispensingService::expiryBlocker()` |
| 5 | Stok tidak boleh negatif (default) | `InventoryItemProvider::consume()` |
| 6 | Pengiriman ulang tidak menggandakan transaksi | `idempotency_key` pada kunjungan & dispensing |
| 7 | Riwayat klinis tidak dihapus destruktif | versi + `clinic_record_amendments`; tanpa endpoint DELETE |
| 8 | Orang tua hanya melihat anak yang sah | `ParentProviderInterface` + `visiblePatientIds()` |
| 9 | Guru tidak otomatis mendapat rekam medis | tidak ada izin `clinic.record.view` untuk guru/wali-kelas |
| 10 | Rujukan wajib tujuan + alasan | `ReferralService::create()` |
| 11 | Gawat darurat melewati antrean | `VisitService::register()` + `EmergencyService::open()` |
| 12 | Kunjungan tidak selesai tanpa pemulangan | `VisitService::advanceStatus()` (mesin status) |
| 13 | Resep hanya oleh peran berwenang | `ClinicAccessService::canPrescribe()` |
| 14 | Pemberian obat menyimpan pelaku & waktu | `clinic_medication_administrations` |
| 15 | Clinic tidak mengubah stok langsung | tidak ada kolom saldo di `clinic_medications` (diuji) |
| 16 | Clinic tidak membuat jurnal akuntansi | `FinanceProviderInterface` (null secara bawaan) |
| 17 | Perubahan klinis penting dapat ditelusuri | `ClinicAuditService` |

---

## 6. Privasi & keamanan

**Dua lapis, keduanya di server:**

1. **Izin** (`clinic.*`, 41 izin) menentukan layar/aksi yang boleh dibuka.
2. **Cakupan baris** (`ClinicAccessService`) menentukan data siapa yang terlihat.

| Peran | Yang boleh dilihat |
|---|---|
| Admin klinik / dokter / perawat / petugas UKS | Seluruh pasien sekolah |
| Kepala sekolah / admin sekolah | Seluruh sekolah (baca & laporan) |
| Guru / wali kelas | **Tidak otomatis** — bukan pemegang izin rekam medis |
| Orang tua / wali | Hanya anak yang tertaut di `student_guardians` |
| Siswa | Hanya dirinya (lewat portal baca-saja) |
| Auditor | Laporan & jejak akses, bukan rekam medis pasien |

**Kebijakan privasi notifikasi & portal** (`config/clinic.php`):

```php
'privacy' => [
    'parent_visible_notes' => false,        // catatan internal petugas
    'parent_visible_assessment' => false,   // asesmen rinci
    'parent_visible_diagnosis' => true,
    'parent_visible_medication' => true,
    'mandatory_events' => ['EMERGENCY', 'SENT_HOME', 'REFERRAL'],
],
```

Konsekuensinya nyata dan diuji: pesan ke orang tua berbunyi *"Anak Anda mendapat
layanan kesehatan pada [tanggal]…"* — bukan salinan diagnosis. Yang ditahan
dicatat di `clinic_notification_logs` beserta alasannya, sehingga sekolah dapat
membuktikan kepatuhannya.

**Dokumen kesehatan**: disk privat `clinic`, tanpa URL publik, unduhan hanya
lewat endpoint ber-otorisasi yang memeriksa peran + pemilik + tingkat visibilitas
(`CLINICAL`/`PARENT`/`STAFF`/`PUBLIC`). Setiap unduhan tercatat sebagai `SHARE`.

**Verifikasi surat publik** (`GET /clinic/public/certificates/{code}/verify`)
hanya mengembalikan keabsahan, jenis, dan masa berlaku — tanpa identitas pasien
maupun isi klinis (diuji).

---

## 7. API (ringkas)

Prefix `/api/v1/clinic` · 96 rute berizin & berbatas laju · respons mengikuti
`ApiResponse` ERP (`success/data/message/meta`).

```
me · metadata · dashboard · health
patients (list/search/register/show/update/vitals/visits/operational-notes)
patients/{id}/allergies|conditions|histories · allergies/{id}
visits (list/register/show) · visits/{id}/triage|vitals|assessment|diagnoses|
        procedures|observations|follow-ups|discharge|status|referrals|prescriptions|administrations
diagnoses/{id} · discharges/{id} · observations/{id}/records|end
queue · queue/call-next · queue/{visit}/stage
medications (+batches) · pharmacy/allergy-check
prescriptions (list/pending/show/cancel/dispense)
emergencies · injuries (+update/close)
referrals · follow-ups/{id}/complete
screening-programs (+targets/screenings) · screenings · vaccinations
certificates (+review/issue) · documents (+download)
reports/{overview|daily|medications|referrals|emergencies|screenings|follow-ups|
         staff-activity|access-log} · reports/export/{report}
portal/children · portal/patients/{id}(/visits|/certificates|/documents)
public: clinic/public/certificates/{code}/verify
```

Batas laju (`config/clinic.php`, per pengguna per menit): `clinic-module` 300,
`clinic-search` 120, `clinic-write` 120, `clinic-report` 20, `clinic-download` 60.

---

## 8. Frontend

Halaman (menu **Clinic / UKS**, 11 entri ber-`PermissionGuard`):

| Rute | Isi |
|---|---|
| `/clinic` | Dasbor: 8 kartu operasional, papan antrean (ubah tahap + panggil berikutnya), peringatan stok/kedaluwarsa, tren 14 hari, pintasan |
| `/clinic/patients` | Pencarian siswa master → daftarkan rekam medis → buka kunjungan; panel peringatan alergi/kondisi khusus |
| `/clinic/visits` | Daftar + filter (tanggal, status, jenis, prioritas, kata kunci) + paginasi |
| `/clinic/visits/:id` | **Workspace klinis**: header pasien (identitas dari master + banner alergi), triase, tanda vital, SOAP, diagnosis, tindakan, resep + penyerahan + MAR, observasi, rujukan, pemulangan, timeline |
| `/clinic/pharmacy` | Resep menunggu, penyerahan obat (hasil Inventory per baris), master obat + stok, batch kedaluwarsa |
| `/clinic/emergency` | Buka gawat darurat/catatan cedera per siswa, tandai kontak orang tua, tutup peristiwa |
| `/clinic/referrals` | Buat rujukan, lacak status perjalanan, selesaikan dengan hasil, tindak lanjut |
| `/clinic/screening` | Program, sasaran per kelas, catat hasil (IMT otomatis), imunisasi |
| `/clinic/certificates` | Ajukan → tinjau → terbitkan surat; unggah/unduh dokumen |
| `/clinic/reports` | Ringkasan 4 kartu, keluhan & diagnosis tersering, jejak akses, ekspor CSV 7 jenis |
| `/clinic/portal` | Portal orang tua/siswa: anak terdaftar vs belum, riwayat layanan tersaring, surat, penjelasan kebijakan privasi |

Dua perbaikan pada komponen bersama saat modul ini dibuat:

- `Badge` menerima **alias semantik** (`danger`/`warning`/`success`/`info`/`neutral`)
  di samping nama warna lama, sehingga modul menyatakan maksud, bukan warna.
- `vite.config.ts` menambahkan `server.allowedHosts` agar dev server dapat dibuka
  melalui URL tunnel pada lingkungan pratinjau.

---

## 9. Keputusan desain yang perlu diketahui

1. **Siswa & orang tua memakai portal, bukan workspace petugas.** Endpoint
   `/clinic/visits/{id}` sengaja tidak diberikan kepada peran `siswa`; mereka
   memakai `/clinic/portal/*` yang isinya sudah disaring kebijakan. Ini batas
   yang disengaja, bukan kekurangan.
2. **Validasi ditulis inline** dengan `Rule::in(<Enum>::values())` di controller
   (bukan FormRequest terpisah seperti modul CBT). Alasannya: aturan validasi
   modul ini sangat bergantung pada enum domain, dan memusatkannya di controller
   membuat satu layar = satu tempat aturan. Bila jumlah endpoint bertambah besar,
   pemindahan ke FormRequest dapat dilakukan tanpa mengubah perilaku.
3. **FEFO hanya memilih batch layak.** Karena batch kedaluwarsa tidak pernah
   terpilih, ada pemeriksaan tambahan: bila obat **punya** catatan batch tetapi
   tidak satu pun dapat dipakai, penyerahan ditolak dengan status `EXPIRED`
   meskipun saldo item di Inventory masih positif. Tanpa ini, obat kedaluwarsa
   bisa keluar dari saldo yang tercatat.
4. **Presensi tidak ditulis secara bawaan.** `clinic.attendance.auto_mark`
   default `false`; bila diaktifkan, baris presensi dibuat berstatus *belum
   terverifikasi* dan tidak menimpa presensi yang sudah ada.
5. **Finance bersifat opsional.** `NullFinanceProvider` mengembalikan
   `NOT_BILLABLE`; tidak ada klaim integrasi yang belum ada.
6. **Integrasi rumah sakit tidak diklaim.** Status rujukan diisi manual oleh
   petugas; tidak ada API eksternal yang dipanggil.

---

## 10. Menjalankan & mengoperasikan

```bash
# Migrasi + izin + master data (aman diulang)
php artisan migrate --force
php artisan db:seed --class="App\Modules\Clinic\Database\Seeders\ClinicPermissionSeeder"
php artisan db:seed --class="App\Modules\Clinic\Database\Seeders\ClinicMasterSeeder"

# Operasional
php artisan clinic:expire-batches      # tandai batch kedaluwarsa (harian 05:30)
php artisan clinic:expire-follow-ups   # tandai tindak lanjut terlambat + ingatkan (harian 06:00)
# keduanya terjadwal di routes/console.php

# Pemeriksaan kesehatan integrasi
curl -H "Authorization: Bearer <token>" /api/v1/clinic/health

# Pengujian
php artisan test --filter=Clinic       # 52 tes, 342 asersi
```

### Konfigurasi penting

| Kunci `config/clinic.php` | Bawaan | Arti |
|---|---|---|
| `vitals.*` | ambang klinis | Peringatan suhu/nadi/napas/SpO₂/TD/nyeri/IMT (peringatan, bukan diagnosis) |
| `expiry_warning_days` | 60 | Ambang peringatan kedaluwarsa batch |
| `dispensing.allow_negative_stock` | false | Bila true, stok boleh minus (mis. obat titipan) |
| `dispensing.block_expired` | true | Blokir keras obat kedaluwarsa |
| `dispensing.require_override_reason` | true | Wajib alasan bila melanjutkan meski ada peringatan alergi |
| `privacy.*` | lihat §6 | Apa yang boleh dilihat orang tua |
| `attendance.auto_mark` | false | Presensi otomatis dari kunjungan |
| `retention.*` | 5/10 tahun | Usulan pengarsipan/pembatasan (lihat §12) |
| `document_disk` | `clinic` | Disk privat dokumen kesehatan |

---

## 11. Pengujian

| Berkas | Fokus |
|---|---|
| `ClinicVisitFlowTest` (7 tes) | Alur lengkap sampai pemulangan, idempotensi, versi asesmen, gawat darurat, mesin status, header pasien, riwayat vital |
| `ClinicPrivacyTest` (12 tes) | Orang tua A vs anak B, guru tanpa akses, siswa hanya diri sendiri, IDOR, dokumen, ekspor, audit, penyembunyian diagnosis, tanpa penghapusan |
| `ClinicPharmacyRulesTest` (14 tes) | Alergi & override, kedaluwarsa, stok kurang, tanpa pemetaan Inventory, idempotensi dispensing, FEFO, penyerahan sebagian, koreksi MAR, wewenang |
| `ClinicEmergencyReferralTest` (11 tes) | Gawat darurat & kontak orang tua, cedera (ringan vs sedang), rujukan berjenjang, tindak lanjut terlambat, perintah artisan, health tanpa bocoran, dashboard, papan antrean |
| `ClinicScreeningCertificateTest` (8 tes) | Skrining massal & idempotensi, imunisasi, alur surat, verifikasi publik, surat kedaluwarsa, unggah dokumen & versi |

Yang **belum** tercakup otomatis (lihat audit di dokumen terpisah): alur
observasi panjang, ekspor laporan besar, uji beban, dan uji integrasi dengan
modul Finance/Asrama pada instalasi yang mengaktifkannya.

---

## 12. Batasan yang diakui

- **Retensi data** (`retention.*`) baru berupa konfigurasi; belum ada perintah
  pengarsipan/anonymization otomatis.
- **Offline mode** belum ada: aplikasi memerlukan jaringan. Idempotensi sudah
  disiapkan (`idempotency_key`) sehingga mode draf luring dapat ditambahkan
  tanpa mengubah skema.
- **Laporan besar** dihitung sinkron; pemindahan ke antrean (queue job) belum
  dilakukan karena volume sekolah tipikal masih memadai.
- **Notifikasi WhatsApp/push** belum ada; kanal tersedia: in-app (`database`) dan
  `mail` bila mailer dikonfigurasi.
- **Integrasi Finance & Asrama** hanya berupa kontrak; implementasi nyata
  menunggu kebijakan sekolah.
- **Sidebar** kini lebih panjang (11 entri Clinic) sehingga sebagian entri
  terbawah perlu digulir pada layar pendek — isu tata letak yang sudah ada
  sebelumnya dan tercatat pada audit UI.
