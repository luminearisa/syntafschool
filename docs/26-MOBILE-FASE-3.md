# Mobile — Fase 3 (siswa)

## Fitur
| Fitur | Siswa | Catatan |
|---|---|---|
| Tugas (`/tugas`) | daftar Belum/Dikumpulkan/Dinilai, kumpul teks | wali hanya memantau |
| Kelas Digital (`/kelas`) | kelas, pemutar materi, tandai selesai, tugas LMS (teks), tenggat, nilai, siaran langsung, sertifikat | kuis dikerjakan di web (CBT = Fase 4) |
| Perpustakaan (`/perpustakaan`) | pinjaman, perpanjang, reservasi, denda, katalog OPAC | juga untuk karyawan |
| Tahfiz | capaian & setoran sendiri (`dashboards/peserta`) | layar sama dengan wali, per peran |
| Ekstrakurikuler | kegiatan saya, cari & daftar, batal | status awal ditentukan aturan program |

## Backend
- `MobileStudentController::submitAssignment` — API tugas lama hanya untuk petugas (menerima `student_id` & `submitted_at` dari klien). Jalur siswa: id siswa dari `ContextService`, waktu `now()` server, wajib anggota aktif kelas (404), tugas harus terbit, aturan `allow_late_submission`, pengumpulan `graded` tidak bisa ditimpa.
- `MobileLibraryController` — anggota dari peran aktif; semua aturan bisnis tetap di `ReservationService`/`CirculationService`. Pinjaman/reservasi milik orang lain → 404.
- `config/mobile.php` — allowlist siswa: aktivitas (discover, registrasi, portal anak, portofolio), `students/*/assignments`, `lms/*`, OPAC, kartu perpustakaan.

## Batasan / temuan
- Lampiran berkas belum didukung (hanya teks). Kuis LMS & CBT: Fase 4.
- `assignments/{id}/submissions` (petugas) tetap tertutup bagi siswa (allowlist + izin).
- Tes `MobilePhase3Test` ditulis tetapi belum dijalankan (sandbox PHP 8.3, Laravel 13 butuh 8.4); hanya `php -l` dan `tsc` yang lolos. Layar diverifikasi via ekspor web + Playwright dengan API tiruan.
