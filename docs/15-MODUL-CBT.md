# Modul CBT — Ujian Berbasis Komputer

Modul mandiri di `backend/app/Modules/CBT` dan `frontend/src/features/cbt`.
Tidak memakai tabel fondasi A8 (`questions`, `exam_schedules`) karena skema itu
belum punya mesin percobaan; CBT memakai tabel `cbt_*`.

## Yang tercakup

- Bank soal berversi: pilihan ganda, pilih banyak (penilaian parsial), benar/salah,
  isian singkat, numerik bertoleransi, menjodohkan, mengurutkan, esai.
- Kisi: butir wajib + penarikan acak menurut topik/kesukaran/tipe.
- Sesi: jendela waktu, token pengawas, pendaftaran rombel, akomodasi waktu tambahan.
- Percobaan: timer server, urutan soal/opsi per siswa, simpan jawaban, heartbeat,
  pengumpulan otomatis saat waktu habis (`cbt:expire-attempts` tiap menit).
- Integritas: token salah, pindah tab, jeda heartbeat, skor integritas, pembatalan pengawas.
- Nilai: otomatis plus antrean esai; rilis hasil; analisis butir (tingkat kesukaran,
  daya beda 27% atas/bawah); ekspor CSV; dorong nilai ke `assessments`/`scores`.

## Izin

`cbt.view`, `cbt.question.*`, `cbt.blueprint.*`, `cbt.session.*`, `cbt.proctor`,
`cbt.grade`, `cbt.report.view`, `cbt.attempt`. Dibagikan ke guru, kepala sekolah,
dan siswa oleh `CbtPermissionSeeder`.

## Batas yang disengaja

Bukan penguncian OS/browser (itu butuh klien lab terpisah) dan bukan proktor video.
Kunci jawaban tidak pernah dikirim ke pemain ujian.
