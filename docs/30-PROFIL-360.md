# Profil 360° (siswa, wali murid, pegawai)

Tombol **Detail** pada Data Siswa, Wali Murid, dan Data Pegawai kini membuka **halaman baru** berisi semua yang berhubungan dengan orang tersebut, dikumpulkan dari seluruh modul.

| Halaman | Alamat | Bagian (tab) |
|---|---|---|
| Siswa | `/students/:id` | Profil, Akademik, Keuangan, Dompet, Asrama, Tahfiz, Ekskul & Prestasi, Kesehatan (Klinik + UKS), Konseling (metadata), Perpustakaan, Belajar Daring & Ujian, Akun & Asal (PPDB), Riwayat Perubahan |
| Wali murid | `/students/guardians/:id` | Profil, Anak (ringkasan per anak), Keuangan seluruh anak, Persetujuan (kegiatan/UKS/konseling), Komunikasi, Keanggotaan lain, Akun & PPDB, Riwayat |
| Pegawai | `/employees/:id` | Profil (+ kontak darurat, tanggungan, identitas), Kepegawaian, Kualifikasi, Mengajar, Kehadiran, Cuti & Lembur, Gaji & Tunjangan, Kinerja & Pengembangan, Peran & Tanggung Jawab, Akun & Keanggotaan, Riwayat |

Nama dan baris terkait (wali ↔ anak ↔ saudara) saling terhubung ke profilnya.

## Cara kerja
- **Backend** `GET /api/v1/profiles/{students|guardians|employees}/{id}` (kepala, ringkasan, daftar tab yang boleh dilihat) dan `.../sections/{tab}` (dimuat malas per tab). Kode: `app/Services/Profile/*`, `ProfileController`, `routes/api/profiles.php`.
- **Blok generik** (stats / kv / table / note): frontend satu renderer untuk semua profil; menambah data cukup di backend.
- **Tahan skema**: tabel yang belum ada dilewati, kolom yang berganti nama dibuang, label & format (tanggal, uang, lencana) disimpulkan dari tipe kolom. Diverifikasi terhadap seluruh skema migrasi (1.300+ tabel, SQLite) — lihat catatan pengujian.
- **Izin berlapis**
  - Dasar: `student.view` (siswa/wali) atau `employee.view` (pegawai).
  - Per tab: izin modulnya (mis. keuangan `finance.*`, gaji `hr.payroll.view`, konseling `bk.case.view.all`, kesehatan `clinic.*`/`uks.*`). Tab tanpa izin tidak tampil dan endpoint-nya 403.
  - UKS memakai `UksAccessService` (kelas ampuan guru, anak sendiri) — izin `uks.view` saja tidak membuka siswa lain.
  - Akun wali/siswa: profil siswa dipagari `student.family` (hanya anak/diri sendiri; selain itu 404); profil wali & pegawai ditutup (403).
  - Data sensitif (identitas resmi, catatan SDM, gaji) hanya untuk izin `hr.*` yang sesuai. Konseling hanya metadata — isi sesi/catatan tidak pernah dikirim.
  - Pembukaan tab keuangan, dompet, kesehatan, konseling, kepegawaian, dan gaji dicatat di log aktivitas (`profile`).
- **Frontend** `src/features/profile/*`, rute di `src/routes/domains/profiles.tsx`. Tab disimpan di URL (`?tab=keuangan`). Tombol "Kelola wali & akun" membuka dialog pengelolaan yang sudah ada.

## Pengujian
- `tests/Feature/ProfileTest.php` (izin per tab, pagar keluarga, gaji) — belum dijalankan di sandbox (PHP 8.3; Laravel 13 butuh 8.4).
- Di sandbox, seluruh penyedia profil dijalankan terhadap skema migrasi lengkap pada SQLite dengan pengguna ber-izin penuh dan minimal: semua bagian terbentuk tanpa galat, dan izin minimal tidak membuka keuangan.
