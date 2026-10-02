# 33 — Modul School Operations Phase 2: Transportation Management (TMS)

Transportation Management adalah **fase kedua School Operations**: sistem
transportasi sekolah/pesantren yang menangani siklus penuh dari permintaan
sampai analitik, tanpa menduplikasi master modul lain.

```
Demand → Route Planning → Route & Stop → Vehicle Assignment → Driver Assignment
      → Student Assignment → Schedule → Trip → Manifest → Pickup/Drop-off
      → Attendance → Incident → Fuel/Cost → Maintenance → Trip Closing → Analytics
```

Modul berada di `backend/app/Modules/SchoolOperations/Transportation` dengan
struktur domain:

```
Transportation
├── Domain/{Contracts,Support,TransportStatus}
├── Application/Services
├── Infrastructure/{Gateways,Providers}
├── Http/Controllers        Routes/api.php
└── Database/{Migrations,Seeders}   Models
```

---

## 1. Keputusan arsitektur

### 1.1 Tidak ada master kedua

Kendaraan selalu berasal dari **Asset Management** (`vehicle_profiles.asset_id`),
pengemudi dari **HRD** (`driver_profiles.employee_id`), penumpang dari
**Academic/Student**, penagihan dari **Finance**, presensi dari modul
**Attendance**, pemeliharaan dari **Maintenance**, kegiatan dari **Activity**.
Modul ini hanya menyimpan atribut operasional transportasi.

### 1.2 Provider architecture (§3)

Kontrak yang **dikonsumsi** (17, semuanya terdaftar di container):

| Kontrak | Sumber nyata | Perilaku bila modul sumber belum ada |
| --- | --- | --- |
| `StudentGatewayInterface` | `students` / Student Provider | menyatakan tidak tersedia (`available()`) |
| `ParentGatewayInterface` | `guardians` + `student_guardians`, delegasi ke Activity | jatuh ke pembacaan langsung, lalu kosong |
| `EmployeeGatewayInterface` | `hr_employees` + `hr_people`, delegasi ke HRD | null + alasan |
| `AttendanceGatewayInterface` | Activity/HRD Attendance Provider | bukti presensi tidak dititipkan (jujur) |
| `BoardingGatewayInterface` | `boarding_placements` / `boarding_homecomings` | status mondok dianggap bukan penghuni |
| `AssetGatewayInterface` | `assets` | kendaraan tanpa tautan aset |
| `MaintenanceGatewayInterface` | `maintenance_work_orders`/`maintenance_schedules`/`maintenance_requests` | tidak ada blokir pemeliharaan |
| `FinanceGatewayInterface` | Activity/Asset/Clinic/HRD Finance Provider | biaya tetap tercatat lokal `PENDING` |
| `NotificationGatewayInterface` | provider modul, fallback tabel `notifications` | tercatat `SKIPPED` + alasan |
| `FacilityGatewayInterface`, `EventGatewayInterface`, `DocumentGatewayInterface`, `ClinicGatewayInterface`, `LocationGatewayInterface`, `VendorGatewayInterface` | modul terkait / config | degradasi eksplisit |
| `RouteOptimizationProviderInterface` | belum ada mesin (fondasi §66) | `optimized: false`, data & batasan tetap disiapkan |
| `WorkflowGatewayInterface` | Workflow Engine | persetujuan internal tetap diaudit |

Kontrak yang **disediakan** modul ini:

```php
TransportationProviderInterface {
    getStudentTransportationAssignment(), getActiveRoutes(), getStudentTrips(),
    getVehicleStatus(), getDriverStatus(), getTripManifest(), getTripHistory(),
    request(), cancel(), isAvailable()
}
TransportationAccessProviderInterface {   // §37/§38 RFID/NFC/QR
    scan(), deviceAvailable(), issueBoardingToken(), verifyBoardingToken()
}
RouteOptimizationProviderInterface { capabilities(), optimize(), isAvailable() }
```

Modul **Activity** yang tadinya memakai `NullTransportationProvider` kini
otomatis memakai implementasi nyata (`ActivityTransportationAdapter`) tanpa
mengubah berkas modul Activity.

### 1.3 Simulasi & validasi, bukan asumsi

Semua keputusan operasional divalidasi di backend: kapasitas, kelayakan
kendaraan/pengemudi, dokumentasi, pemeliharaan, checklist pra-trip, urutan
halte, dan bentrok jadwal. Selain itu setiap aksi material dicatat pada
`transportation_audit_logs`, `transportation_events`, dan
`transportation_idempotency_keys`.

---

## 2. Peran & izin

45 izin `transportation.*` dan peran: `TRANSPORTATION_ADMIN`,
`TRANSPORTATION_MANAGER`, `TRANSPORTATION_DISPATCHER`, `FLEET_MANAGER`,
`FLEET_COORDINATOR`, `DRIVER`, `DRIVER_SUPERVISOR`, `TRANSPORTATION_AUDITOR`,
`MAINTENANCE_STAFF`, `FINANCE`, `SECURITY`, `EVENT_COORDINATOR`, `TEACHER`,
`BOARDING_STAFF`, `SCHOOL_ADMIN`, `STUDENT`, `PARENT` (peta izin
didefinisikan di `TransportationScopeService` dan dipasang seeder).

Cakupan baris ditegakkan di service, bukan hanya UI:

```php
canAccessStudent(user, studentId)   // wali hanya anaknya (Rule 14)
canOperateTrip(user, trip)          // pengemudi hanya tripnya (Rule 15)
```

---

## 3. Basis data

8 migrasi, **51 tabel** `transportation_*`, `vehicle_*`, `driver_*`, `route_*`,
`trip_*`, `boarding_events`, `dropoff_events`, `incident_*`,
`pre_trip_inspections`, `post_trip_inspections`, `authorized_pickup_persons`,
`lost_found_items`, `passenger_transfers`, `schedule_exceptions`,
`student_transport_profiles`, `parent_transport_authorizations` — termasuk
tabel operasional yang sering terlupakan: `transportation_daily_closings`,
`transportation_adjustments`, `transportation_devices`,
`transportation_idempotency_keys`, `transportation_audit_logs`,
`transportation_events`.

Perhatikan: rute bersifat **non-destruktif** (`transportation_routes` →
`route_versions` → `route_stop_versions`), sehingga perubahan rute menyimpan
versi baru beserta snapshot halte dan persetujuannya.

---

## 4. Layanan (22)

`FleetService`, `DriverService`, `RouteService`, `SchedulingService`,
`TransportationRequestService`, `TransportationAssignmentService`,
`TripService`, `ManifestService`, `BoardingService`, `InspectionService`,
`IncidentService`, `FuelCostService`, `CapacityPlanningService`,
`DailyClosingService`, `LostFoundService`, `TransportationAnalyticsService`,
`TransportationNotificationService`, `TransportationScopeService`, plus
gateway integrasi.

Yang membedakan dari CRUD biasa:

* **Dispatch bergerbang** (`TripService::validateDispatch`): rute aktif, pengemudi
  & kendaraan ada, SIM sesuai, kualifikasi wajib lengkap, dokumen valid,
  kendaraan tidak dalam pemeliharaan, checklist pra-trip tanpa kegagalan
  kritis, manifes tidak melebihi kapasitas — semua dilaporkan lengkap dengan
  kode blokir; override hanya dengan alasan dan tercatat.
* **Boarding idempoten + notifikasi**: `boarding/dropoff/no-show` memakai
  kunci idempotensi ber-scope, menitipkan bukti ke modul presensi, dan
  mengirim notifikasi ke wali (`PassengerBoarded`, `PassengerNoShow`,
  `PassengerDroppedOff`).
* **Serah terima aman** (§24/§87 Rule 11): penjemput diverifikasi; penumpang
  tidak dapat diturunkan sebelum tercatat naik kecuali koreksi berwenang yang
  menjadi `transportation_adjustments`.
* **Mogok kendaraan** (§49): satu langkah menghasilkan insiden + kendaraan
  pengganti + pengemudi pengganti + pemindahan penumpang + notifikasi wali,
  dan **trip asli dipertahankan** (bukan trip baru).
* **Penutupan harian & penyesuaian**: setelah ditutup, perubahan wajib
  beralasan dan diaudit (Rule 13).

---

## 5. API

**149 rute** `GET/POST/PUT /api/v1/transportation/*` (Sanctum + izin
`transportation.*` + batas laju per peran), antara lain:

```
GET  /dashboard                     GET  /dispatch-board      GET /alerts
GET  /vehicles  POST /vehicles/{id}/status  POST /fuel-logs  GET /vehicles/expiring-documents
GET  /drivers   POST /drivers/{id}/licenses POST /drivers/{id}/availability
GET  /routes    POST /routes/{id}/stops     GET /routes/{id}/capacity  GET /routes/{id}/optimization-data
GET  /requests  POST /requests/{id}/approve POST /assignments  GET /waitlist  POST /waitlist/evaluate
GET  /trips     POST /trips   GET /trips/{id}/manifest   GET /trips/{id}/dispatch-validation
POST /trips/{id}/dispatch|start|arrive-stop|depart-stop|board|dropoff|no-show|delay|complete|cancel
POST /trips/{id}/replace-vehicle|assign-driver|replace-driver|breakdown
POST /incidents/{id}/acknowledge|escalate|resolve|review|close   POST /pre-trip-inspections
POST /sync/offline-events   POST /access/scan   POST /access/verify-token
GET  /driver/today|current-trip|trips/{id}/stops|trips/{id}/offline-manifest
GET  /portal/children|children/{id}|children/{id}/today|children/{id}/history
GET  /analytics/metrics|routes|vehicles|drivers   GET /reports/daily|monthly|management
GET  /costs  POST /costs  GET /daily-closings  POST /daily-closings  POST /adjustments
```

---

## 6. Aturan bisnis (§87) — statusnya di kode

| Rule | Implementasi |
| --- | --- |
| 1. Kendaraan `MAINTENANCE` tidak boleh ditugaskan | `FleetService::eligibility` + `MaintenanceGateway::isBlocked` |
| 2. SIM wajib valid & sesuai | `DriverService::licenseCheck` + `TransportStatus::licenseSuitableForVehicle` |
| 3. Pengemudi tidak boleh double-book | `DriverService::eligibility` (bentrok rentang jam) |
| 4. Kendaraan tidak boleh double-book | `TripService::vehicleConflict` |
| 5. Satu siswa satu penugasan per periode | `TransportationAssignmentService::create` (kecuali multi-layanan dibuka) |
| 6. Penumpang ≤ kapasitas | `FleetService::validateCapacity` (+ override berwenang tercatat) |
| 7. Halte harus milik rute | `RouteService::stopBelongs` |
| 8. Trip harus punya rute | `TripService::validateDispatch` |
| 9. Trip harus punya pengemudi & kendaraan | idem |
| 10. Kegagalan checklist kritis memblokir dispatch | `InspectionService::preTrip` → `blocks_dispatch` |
| 11. Tidak boleh turun sebelum naik | `BoardingService::dropoff` + koreksi berwenang |
| 12. Trip selesai tidak dapat diubah destruktif | `TripService::updateTrip` menolak status non-rencana |
| 13. Penyesuaian pasca-closing wajib alasan | `DailyClosingService::adjust` |
| 14. Wali hanya data anaknya | `TransportationScopeService::canAccessStudent` |
| 15. Pengemudi hanya tripnya | `TransportationScopeService::canOperateTrip` |

---

## 7. Idempotensi, offline, perangkat, QR (§37/§38/§88/§89/§90)

* **Idempotensi ber-scope**: kunci disimpan sebagai `scope:kunci`
  (`BOARDING:…`, `NOTIFICATION:…`) sehingga operasi dan notifikasi tidak
  saling menelan; balapan dua permintaan bersamaan ditangani lewat
  `UNIQUE` + transaksi (status `CONCURRENT`).
* **QR boarding**: token bertanda tangan HMAC, berisi hanya
  `student_id`, `trip_id`, `exp`, `nonce` — tanpa data pribadi; uji menolak
  token yang diubah.
* **Perangkat**: `transportation_devices` (RFID/NFC/QR/GPS) dengan
  `last_sync_at`/`last_seen_at`; event dari perangkat tak terdaftar ditolak.
* **Offline-first**: `POST /sync/offline-events` menerima batch
  (BOARD/DROPOFF/NO_SHOW/PRETRIP/POSTTRIP/INCIDENT), menerapkan tepat sekali,
  menandai duplikat, dan mencatat konflik untuk ditinjau
  (`SERVER_WINS_WITH_AUDIT`).
* Entri BBM, insiden, penugasan, dan boarding semuanya menerima
  `idempotency_key`.

---

## 8. Analitik & laporan (§75, §78-§82)

`TransportationAnalyticsService` menghasilkan dashboard operasional, metrik
trip (tepat waktu, keterlambatan, no-show, pembatalan, okupansi, insiden,
biaya per trip/penumpang/km, efisiensi BBM), analitik rute/kendaraan, laporan
operasional pengemudi **dengan disclaimer** (bukan penilaian kinerja otomatis),
laporan harian/bulanan/manajemen (biaya per siswa & per rute), serta daftar
peringatan terpadu (dokumen, trip tanpa penugasan, rute penuh, biaya belum
diposting).

---

## 9. Antarmuka pengguna (§95-§99)

`frontend/src/features/transportation` + `frontend/src/routes/domains/transportation.tsx`
(sidebar grup **Transportasi**, 18 entri sesuai §95):

| Halaman | Isi |
| --- | --- |
| `/transportation` | Dashboard: trip, kendaraan, pengemudi, penumpang belum naik, insiden, biaya, kapasitas rute, peringatan |
| `/transportation/dispatch` | Papan dispatch: status per trip, atur ulang kendaraan/pengemudi, paksa dispatch beralasan, lapor keterlambatan, batalkan |
| `/transportation/trips` | Trip hari ini, kalender (termasuk pengecualian), manifes + aksi naik/turun/tidak hadir/tutup trip, riwayat |
| `/transportation/fleet` | Kendaraan & kelayakan, dokumen (kedaluwarsa/verifikasi), BBM + efisiensi, inspeksi |
| `/transportation/drivers` | Pengemudi, SIM, kualifikasi, ketersediaan, kelayakan |
| `/transportation/routes` | Rute, halte (koordinat & jam rencana), versi & kapasitas, batasan rute |
| `/transportation/students` | Penugasan, permintaan (validasi + kapasitas), daftar tunggu (skor prioritas, tawaran kursi) |
| `/transportation/incidents` | Alur insiden + rencana darurat + alur mogok kendaraan |
| `/transportation/lost-found` | Temuan → identifikasi → dikabari → dikembalikan |
| `/transportation/reports` | Metrik, utilisasi rute/kendaraan, laporan pengemudi, biaya, penutupan harian |
| `/transportation/driver-app` | Ponsel: trip hari ini, trip aktif, halte langkah-demi-langkah, checklist, boarding, QR, manifes offline |
| `/transportation/portal` | Portal wali: rute/halte anak, status naik/turun hari ini, pengajuan perubahan |

Konfigurasi: `vite.config.ts` menyediakan proxy `/api` → backend (dev &
preview, target via `API_PROXY_TARGET`, bawaan `http://localhost:8001`) agar
dapat dibuka lewat tautan tunnel.

---

## 10. Bukti pengujian

### 10.1 Uji backend end-to-end (§101)

`backend/tests/Manual/transportation_smoke.php` — dijalankan pada **basis data
kosong** (migrasi → seeder peran → uji):

```
=== RINGKASAN UJI TRANSPORTATION MANAGEMENT (PHASE 2) ===
lulus: 213 | gagal: 0        (EXIT=0, 1052 tabel hasil migrasi)
```

Cakupan: master & batas modul, armada (kapasitas, perlengkapan kritis,
dokumen + peringatan kedaluwarsa, blokir pemeliharaan, BBM + efisiensi,
override berwenang), pengemudi (SIM sesuai kendaraan, kualifikasi wajib,
ketersediaan, penugasan, pengganti, double-book), rute (halte, versi
non-destruktif, batasan, kapasitas, fondasi optimasi), permintaan → kapasitas
→ persetujuan → penugasan, daftar tunggu (skor prioritas → tawaran → terima),
penangguhan/pengaktifan/perubahan rute, jadwal + kalender akademik + pembentukan
trip harian idempoten, **siklus trip penuh** (manifes, pra-trip gagal/berhasil,
dispatch, start, tiba/berangkat halte dengan rencana vs aktual, boarding
idempoten + notifikasi wali, tidak hadir, turun dengan verifikasi penjemput,
Rule 11 + koreksi berwenang, override kapasitas, keterlambatan + notifikasi,
penutupan dengan validasi odometer, pasca-trip → permintaan pemeliharaan +
barang temuan, pembatalan), insiden (pelaporan, eskalasi otomatis kritis,
tindakan, resolusi, tinjauan, penutupan, mogok → penggantian + pemindahan
penumpang), biaya (BBM otomatis, tol/parkir, ringkasan, biaya per penumpang,
posting ke keuangan), penutupan harian + pembukaan ulang + penyesuaian
beralasan, barang hilang, analitik & laporan, perencanaan kapasitas
(120 penumpang ÷ 40 kursi = 3 kendaraan), keamanan (isolasi wali/pengemudi),
QR bertanda tangan, RFID dengan perangkat terdaftar, sinkronisasi offline
(diterapkan/duplikat/konflik), **API-level** (200/401/403 sesuai izin),
kontrak penyedia untuk modul lain, event bus, dan audit.

### 10.2 Uji UI dengan browser sungguhan

Login nyata + navigasi ke modul transportasi pada dua ukuran layar:

* Desktop 1280×900: dashboard menampilkan KPI, papan dispatch menampilkan 5
  baris trip; sidebar grup **Transportasi** tampil lengkap.
* Ponsel 390×844: dashboard, aplikasi pengemudi, manifes trip, dan portal
  wali — **tanpa keluhan tata letak** (`no layout issues`) setelah menu
  diringkas, dan tanpa galat konsol.

Catatan jujur: audit gaya menemukan kontras teks sidebar yang rendah
(rasio ±1,1–1,5) pada tema terang — ini karakteristik sidebar aplikasi yang
sudah ada (berlaku juga untuk menu modul lain), bukan berasal dari modul ini;
perbaikannya sebaiknya dilakukan sekali di tema bersama.

---

## 11. Bug nyata yang ditemukan pengujian (dan diperbaiki)

1. **Kunci idempotensi bertabrakan antar-scope**: kunci operasi dan kunci
   notifikasi yang sama saling menelan sehingga notifikasi tidak terkirim dan
   aksi tampak berhasil. Kini kunci disimpan ber-prefix cakupan.
2. **Filter tanggal gagal** karena kolom bertipe `date` disimpan sebagai
   `Y-m-d H:i:s`: `where('trip_date', '2026-10-02')` tidak pernah cocok →
   kapasitas rute terbaca 0, daftar tunggu tidak aktif, metrik kosong. Diubah
   ke `whereDate()`/rentang tanggal di 14 berkas.
3. **Hitungan penumpang per halte tidak pernah terisi**: penugasan merujuk
   halte **rute**, aksi pengemudi merujuk halte **trip**; kini keduanya
   dihitung sebagai halte yang sama dan disegarkan saat tiba/berangkat.
4. **Laporan insiden mengembalikan pembungkus idempotensi** sehingga alur mogok
   gagal membaca `incident_id` → dibuka sebelum dipakai.
5. **Permintaan pemeliharaan selalu gagal** karena kolom wajib
   (`request_number`, `requested_by`, `reported_at`) dan kolom `reference`
   tidak ada pada skema instalasi → kini diisi adaptif terhadap kolom nyata.
6. **Tabel audit hanya punya `created_at`** sementara model menulis
   `updated_at` sehingga seluruh baris audit gagal senyap → model disetel
   tanpa timestamps.
7. **Gateway wali mengembalikan kosong** karena modul lain punya implementasi
   yang belum mengenal data lokal → kini jatuh ke pembacaan langsung.
8. **Gateway pegawai kehilangan nama** ketika modul HRD mengembalikan bentuk
   data berbeda → pembacaan lokal menjadi sumber utama, kontrak sebagai
   pelengkap.
9. **Enam berkas layanan memakai namespace placeholder** (`namespace NS;`)
   sehingga tidak dapat dimuat autoloader — ditemukan karena seeder gagal.
10. **Menu sidebar terlalu panjang** menutupi tautan terakhir pada layar
    ponsel → menu diringkas dan sub-tampilan dipindah ke tab halaman.

---

## 12. Batas fase (§103) — yang sengaja tidak dikerjakan

Tidak dibangun pada fase ini: pelacakan GPS real-time & peta hidup, prediksi
ETA lanjutan, optimasi rute otomatis/AI, pemeliharaan prediktif, penjadwalan
otomatis, dan peramalan permintaan. Yang disiapkan sebagai fondasi:
`RouteOptimizationProviderInterface` + data optimasi (koordinat, jarak,
penumpang, batasan), `LocationGatewayInterface` (jarak haversine sudah
berfungsi, geocoding menunggu penyedia), `transportation_devices` untuk
perangkat lapangan, serta `estimated_arrival_at` pada halte trip.

Belum ada juga:

1. **Protokol perangkat keras RFID/NFC sendiri** — modul menerima *event* hasil
   pembacaan; pemetaan kartu↔siswa saat ini memakai riwayat pemindaian.
2. **Kanal notifikasi eksternal** (push/WhatsApp/SMS) — tercatat sebagai
   `SKIPPED` bila penyedia belum dipasang; in-app via tabel `notifications`.
3. **Suite PHPUnit**: verifikasi masih berupa uji manual 213 pemeriksaan
   (siap dipecah menjadi Unit/Feature/Integration/Security).
4. **Impor CSV/Excel** rute/halte/kendaraan/pengemudi/penugasan (§93).
5. **Retensi & pengarsipan otomatis** (§92) — kebijakan retensi sudah ada di
   konfigurasi (`transportation.retention`), belum ada perintah terjadwal.

---

## 13. Cara menjalankan

```bash
# Backend
cd backend
rm -f /tmp/tms.sqlite && touch /tmp/tms.sqlite
export DB_CONNECTION=sqlite DB_DATABASE=/tmp/tms.sqlite CACHE_STORE=array
php artisan migrate --force
php artisan db:seed --force                     # termasuk TransportationMasterSeeder
php artisan serve --port=8001

# Uji end-to-end (skenario §101 + 15 aturan bisnis)
php tests/Manual/transportation_smoke.php

# Frontend
cd ../frontend
npm install
npm run dev          # atau: npm run build && npx vite preview --port 3000
```

Seeder modul: `App\Modules\SchoolOperations\Transportation\Database\Seeders\TransportationMasterSeeder`
(10 jenis layanan, 7 jenis kendaraan, 45 izin, peran-peran transportasi).

---

## 14. Prinsip akhir

> **Transportasi bukan sekadar daftar kendaraan.** Ia harus membuktikan setiap
> perjalanan: siapa mengemudi, siapa naik di halte mana, kapan tiba, apa yang
> terjadi bila terlambat, siapa yang menerima anak di titik turun, berapa biaya
> perjalanan, dan apa yang harus diperbaiki esok hari — semuanya tanpa
> menduplikasi master yang sudah dimiliki sekolah.
