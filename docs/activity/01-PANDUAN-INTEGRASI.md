# Panduan Integrasi — Modul Student Activity

Ringkas dan konkret: apa yang perlu disiapkan modul lain, dan apa yang **bukan**
tanggung jawabnya.

## Ringkasan pembagian

| Modul lain | Yang disediakan | Yang **tidak** dilakukan modul aktivitas |
|---|---|---|
| Student | identitas, kelas, program siswa | menyimpan salinan nama/kelas |
| Parent | wali siswa + akunnya | menentukan siapa wali |
| HRD/Pegawai | profil pegawai, akun pengguna | menyimpan data kepegawaian |
| Attendance | penerimaan catatan cermin | menulis absensi akademik langsung |
| SIAKAD/Akademik | konteks periode, penerimaan usulan pengakuan | mengubah nilai rapor |
| LMS | kursus, keanggotaan LMS, progres | menggandakan materi/kuis |
| Keuangan | tagihan (bila diaktifkan) | menghitung pembayaran, diskon, penerimaan |
| Inventaris/Aset | stok, mutasi, peminjaman | menyimpan stok sendiri |
| Sarpras | ketersediaan & pemesanan ruang | menyimpan jadwal pemesanan ruang |
| Asrama | jadwal kegiatan asrama | menyimpan jadwal asrama |
| Event/Transportasi | — (belum terpasang) | berpura-pura berhasil |
| BK | — (hanya membaca sinyal) | menyentuh catatan konseling |

## Menambahkan aktivitas baru (tanpa menyentuh kode)

1. **Kategori** (bila perlu): `POST /api/v1/activities/categories` — dapat berjenjang.
2. **Program**: `POST /api/v1/activities/programs` dengan `skills`, `objectives`,
   `requirements`, kapasitas, jadwal pendaftaran, dan aturan penyelesaian:

```json
{
  "code": "FUTSAL-7",
  "name": "Futsal Kelas VII",
  "category_id": 2,
  "maximum_capacity": 20,
  "selection_method": "TRIAL",
  "requires_parent_consent": true,
  "requires_payment": true,
  "fee_amount": 120000,
  "minimum_attendance_percentage": 75,
  "completion_rules": { "minimum_attendance_percentage": 75, "final_assessment_min_score": 70, "required_sessions": 8 },
  "certificate_rules": { "minimum_attendance_percentage": 75, "final_assessment_min_score": 70 },
  "skills": [1, 4],
  "requirements": [
    { "type": "AGE", "operator": "GTE", "value": [12] },
    { "type": "GENDER", "operator": "EQ", "value": ["Putra"] },
    { "type": "RECOMMENDATION", "operator": "EXISTS", "value": [] }
  ]
}
```

3. **Jadwal**: `POST /api/v1/activities/programs/{id}/schedules` dengan `day_of_week`,
   `start_time`, `end_time`, `room_id`, `book_facility: true` — pemesanan ruang
   diajukan ke modul Sarpras lewat kontrak.
4. **Sesi**: otomatis tiap malam (`activity:generate-sessions`) atau
   `POST /api/v1/activities/programs/{id}/sessions/generate`.
5. **Terbitkan**: `POST /api/v1/activities/programs/{id}/publish` — ditolak bila
   kapasitas, jadwal, atau pembina belum lengkap.

## Kebijakan yang dapat diubah tanpa deploy

`config/activity.php` (atau `.env`):

```env
ACTIVITY_MAX_ACTIVE=3              # batas aktivitas aktif per siswa
ACTIVITY_MAX_ACTIVE_HARD=true      # melampaui = ditolak (false = hanya peringatan)
ACTIVITY_CONFLICT_BLOCK=true       # bentrok jadwal menggagalkan pendaftaran
ACTIVITY_CONFLICT_ACADEMIC=true    # bandingkan dengan jadwal pelajaran
ACTIVITY_CONFLICT_BOARDING=true    # bandingkan dengan kegiatan asrama
ACTIVITY_ATTENDANCE_MIRROR=false   # cerminkan kehadiran ke modul Attendance
ACTIVITY_FINANCE_INVOICES=false    # true = langsung buat draf tagihan
ACTIVITY_UNPAID_POLICY=WARN        # ALLOW | WARN | RESTRICT
ACTIVITY_FACILITY_BLOCK=true       # bentrok ruang menggagalkan pemesanan
ACTIVITY_CONSENT_EXPIRY_DAYS=14
ACTIVITY_SESSION_HORIZON=90
```

## Mengganti sebuah integrasi

Cukup ubah satu binding di `ActivityServiceProvider::bindContracts()`:

```php
FacilityProviderInterface::class => \App\Modules\Sarpras\ActivityFacilityBridge::class,
EventProviderInterface::class => \App\Modules\Event\EventModuleBridge::class,
```

Interface yang perlu dipenuhi tetap sama; layanan dan layar tidak berubah.

## Yang harus dihindari

1. **Jangan menulis langsung ke tabel `activity_*`** dari modul lain — pakai layanan
   modul aktivitas agar kapasitas, riwayat, dan audit tetap utuh.
2. **Jangan menjadikan tabel `activity_*` sumber data siswa** — identitas tetap milik
   modul Student.
3. **Jangan menyalin jadwal asrama** ke modul aktivitas; tanyakan lewat kontrak.
4. **Jangan memberi sanksi otomatis** dari angka kehadiran aktivitas; modul ini
   memproduksi peringatan dan pembatas kelayakan, bukan hukuman.
5. **Jangan menaruh catatan konseling** pada data aktivitas; BK hanya menerima
   sinyal perkembangan yang sudah bersifat publik di lingkungan sekolah.
