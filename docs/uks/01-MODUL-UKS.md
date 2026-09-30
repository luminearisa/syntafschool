# Modul UKS — Usaha Kesehatan Sekolah

Modul operasional kesehatan siswa yang menghubungkan lima pihak:

| Pihak | Apa yang bisa dilakukan | Cakupan data |
|---|---|---|
| **Petugas UKS** (guru/staf/nakes terdaftar di `uks_officers`) | Menangani kunjungan, memberi obat, merujuk, menerbitkan surat, mengelola stok | Seluruh siswa |
| **Admin sekolah** | Semua operasional + registri petugas + pemeriksaan kesehatan data | Seluruh siswa |
| **Kepala sekolah** | Melihat dan mengekspor laporan | Seluruh siswa (tanpa mengelola) |
| **Guru & wali kelas** | Mencatat rujukan dari kelas, melaporkan insiden, melihat rekam | Hanya siswa di kelas yang diampu (wali kelas **atau** pengajar mapel, tahun ajaran aktif) |
| **Orang tua/wali** | Menerima kabar, memberi persetujuan, mengunduh surat | Hanya anaknya (`student_guardians`) |
| **Siswa** | Melihat riwayatnya sendiri | Hanya dirinya |

Izin di route (`uks.*`) menentukan *layar*; `UksAccessService` menentukan *baris*.
Keduanya wajib — pola yang sama dengan `DataScope` modul Supervisi.

## Alur utama

1. Guru/petugas mencatat kunjungan (`POST /uks/visits`) — nomor otomatis `UKS/{tahun}/{00001}`.
2. Kasus **berat/darurat** otomatis mengirim notifikasi ke akun orang tua.
3. Petugas menangani (`/handle`), memberi obat (`/medications` — stok berkurang
   dalam transaksi berkunci, obat kedaluwarsa ditolak, stok tidak bisa minus),
   atau merujuk (`/referral`).
4. Orang tua menyetujui/menolak lewat aplikasi (`/consent`, kanal `APLIKASI`);
   petugas juga dapat mencatat persetujuan hasil telepon.
5. Kunjungan ditutup (`/close`) dan terkunci.

Selain kunjungan: profil kesehatan siswa, pemeriksaan berkala (IMT dihitung
server), imunisasi + jadwal susulan, insiden/kecelakaan, dan surat keterangan
PDF dengan kode verifikasi 8 karakter yang dapat diperiksa publik di
`GET /api/v1/uks/letters/verify/{code}` (tanpa login, dibatasi 30/menit/IP).

## Operasional

- `php artisan uks:health` — 8 pemeriksaan integritas (kasus berat yang orang
  tuanya belum dikabari, stok negatif, pemberian obat tanpa mutasi, surat tanpa
  kode verifikasi, dst). Juga lewat `GET /uks/health` (izin `uks.manage`).
- `php artisan uks:remind` — pengingat harian idempoten ke petugas: stok
  menipis, obat menjelang kedaluwarsa, imunisasi susulan jatuh tempo, kunjungan
  terbuka lebih dari 2 hari.
- Keduanya dijadwalkan di `routes/console.php`.

## Konfigurasi (`config/uks.php`)

Batas laju per menit (`module` 300, `public_verify` 30, `search` 90,
`visit_write` 120, `report` 20) dan ambang pengingat (`expiry_warning_days`,
`immunization_due_days`).

## Tabel (semua `uks_*`, tambahan — tidak mengubah tabel inti)

`uks_officers`, `uks_health_profiles`, `uks_medicines`, `uks_stock_movements`,
`uks_visits`, `uks_visit_medications`, `uks_consents`, `uks_referrals`,
`uks_screenings`, `uks_immunizations`, `uks_incidents`, `uks_letters`.

## Menjalankan

```bash
cd backend && php artisan migrate && php artisan db:seed --class="App\\Modules\\UKS\\Database\\Seeders\\UksPermissionSeeder"
```

Izin `uks.*` dipetakan ke peran yang sudah ada (`admin-sekolah`,
`kepala-sekolah`, `petugas-kesehatan`, `guru`, `wali-kelas`, `staf`, `siswa`,
`wali-murid`). Akun petugas UKS = akun pegawai yang `userable`-nya tertaut ke
pegawai terdaftar di registri petugas.
