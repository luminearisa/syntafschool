# Modul Supervisi — Audit Fase 1–4 & Rencana Fase 5

> Audit internal sebelum pengerasan. Setiap temuan punya **bukti** (berkas,
> kueri, atau angka) dan **status perbaikan**; kolom *Fix* diisi setelah
> perbaikan benar-benar ada di kode **dan** diuji. Tidak ada temuan yang ditutup
> hanya dengan narasi.

Cakupan pemindaian: seluruh `backend/app/Modules/Supervisi` (130 berkas: 27
service, 34 model, 22 enum, 19 controller, 6 migrasi, 125 rute
`api/v1/supervisi/*`, 2 perintah artisan), `frontend/src/features/supervisi`
(16 berkas), `config/supervisi.php`, serta tabel ERP yang dipakai
(`employees`, `users`, `academic_years`, `semesters`).

Legenda status: `SELESAI`, `SEBAGIAN`, `RENCANA`. Legenda tingkat:
`CRITICAL`, `HIGH`, `MEDIUM`, `LOW`.

---

## 1. Ringkasan temuan

| # | Area | Status temuan | Temuan | Tingkat | Dampak | Perbaikan | Fix |
|---|---|---|---|---|---|---|---|
| 1 | Privasi & cakupan data | Menyimpang dari blueprint | `/analytics`, `/reports/catalog`, `/reports/preview`, `/reports/history` hanya dijaga izin `supervisi.report.view` — izin yang **dimiliki guru dan staf**. Akibatnya guru dapat membaca analitik sekolah (daftar pegawai belum disupervisi, daftar nilai di bawah ambang **beserta nama**) dan pratinjau laporan berisi nilai seluruh rekan sejawat. Blueprint §1.2: "guru/staf — `report.view` terbatas pada dirinya"; §11.6: "rekan sejawat hanya melihat ringkasan bila sekolah mengaktifkan" | **CRITICAL** (privasi) | Data kinerja pribadi bocor ke rekan sejawat; risiko konflik & pelanggaran kerahasiaan penilaian | `DataScope` + `DataScopeService` (cakupan sekolah vs diri) diterapkan pada analitik, laporan, dan riwayat dokumen; daftar identitas (`unsupervised`, `needs_pip_rows`, peringkat penilai) hanya untuk pemegang tata kelola; pengaturan baru `peer_summary_enabled` (bawaan **mati**) sebagai gerbang ringkasan agregat | SELESAI |
| 2 | Kinerja (N+1) | **324 kueri untuk 25 baris** | `ActionPlanService::board()` memanggil `refreshStatus()` yang menyimpan status **per rencana** (1 UPDATE/baris) dan memuat `items.owner` per rencana | **HIGH** | Papan RTL melambat seiring jumlah RTL; pada 200 rencana menjadi ratusan kueri per halaman | Eager load `items.owner` + `items.progress`; `deriveStatus()` murni; `refreshStatuses()` menulis perubahan **berkelompok** (≤ 4 UPDATE) | SELESAI |
| 3 | Konkurensi | Tanpa kunci baris | `VisitFinalizationService::finalize()/reopen()` memakai transaksi tanpa `lockForUpdate`, sehingga dua tab yang menekan "Finalkan" hampir bersamaan dapat lolos pemeriksaan `finalized_at` yang dibaca **sebelum** transaksi | **MEDIUM** | Dua baris revisi/finalisasi untuk satu kegiatan; nilai tersegel dua kali | Baca ulang baris dengan `lockForUpdate()` **di dalam** transaksi lalu periksa status lagi; uji menambah jalur "sudah difinalkan" → 422 | SELESAI |
| 4 | Batas sumber daya | Tak terbatas | `GET /calibrations?per_page=100000` diteruskan apa adanya ke `paginate()` (rute lain memakai `perPage()` dengan batas 100) | **MEDIUM** | Satu permintaan dapat meminta ratusan ribu baris → memori habis | Memakai `$this->perPage($request, 25)`; uji memastikan `per_page` dibatasi 100 | SELESAI |
| 5 | Keamanan pencarian | Wildcard diteruskan | Enam tempat menyusun pola `LIKE` langsung dari masukan pengguna (`%{$search}%` di instrumen, supervisor, direktori supervisor, penilaian, kegiatan). Pencarian `%` memindai seluruh tabel; `_` mencocokkan karakter apa pun | **MEDIUM** | Hasil menyesatkan + pemindaian tabel penuh dari satu kotak pencarian | `SearchTerm::like()` membuang wildcard dan membatasi panjang; pencarian hanya jalan bila kata kunci bermakna | SELESAI |
| 6 | Batas laju | Tidak ada | **122 rute tanpa `throttle`**, termasuk verifikasi dokumen publik (kode 8 karakter dapat ditebak berulang), pembuatan laporan (agregat + berkas), dan dasbor analitik (belasan kueri agregat per permintaan) | **MEDIUM** | Satu skrip dapat menjatuhkan layanan bagi seluruh sekolah; kode dokumen dapat dienumerasi | Limiter bernama (`config/supervisi.php`) + `throttle:supervisi-module` pada seluruh modul + batas khusus (verifikasi publik 30/menit, ekspor 20, analitik 60, jawaban 240, unggah bukti 30, finalisasi 20, pencarian 90) | SELESAI |
| 7 | Mode luring (§12) | Belum ada muara | Kolom `client_uuid` sudah ada di `sup_visits`/`sup_visit_answers` dan penyimpanan jawaban idempoten per `(visit_id, indicator_id)`, tetapi **tidak ada** endpoint sinkronisasi, formulir cetak, maupun antrean di perangkat — observasi di ruang tanpa sinyal kehilangan pekerjaan | **MEDIUM** | Guru mengetik ulang seluruh jawaban setelah kembali ke jaringan | `OfflineSyncService` + `POST /visits/{visit}/answers/sync` (idempoten, tahan sebagian, melaporkan konflik), `BlankFormService` + `GET /visits/{visit}/blank-form` (formulir huruf A/B/C), panel **Mode Luring** di UI + antrean `localStorage` | SELESAI |
| 8 | Operasional | Tidak ada | Tidak ada pemeriksaan kesehatan/integritas, tidak ada kebijakan retensi berkas ekspor, tidak ada dokumentasi API & deployment, tidak ada latihan pemulihan | **MEDIUM** | Kerusakan data (kegiatan final tanpa jawaban, berkas dokumen hilang) tidak terdeteksi; berkas menumpuk; integrator & operator menebak | `SupervisiHealthService` + `GET /supervisi/health` + `supervisi:health` (terjadwal mingguan), `supervisi:prune` (retensi, hanya melapor tanpa `--force`), `docs/openapi-supervisi.yaml`, `08`–`10` dokumen ini | SELESAI |
| 9 | Integritas data | Sehat (terverifikasi) | `sup_visits`/`sup_visit_answers` memiliki seluruh indeks wajib blueprint §12 (`sup_visit_supervisee_idx`, `sup_visit_supervisor_idx`, `sup_visit_program_idx`, `sup_visit_version_idx`, `sup_answer_unique`, `sup_item_due_idx`, …); rencana kueri memakai indeks tersebut | — | — | Dijaga oleh uji `SupPerformanceTest::test_indeks_penting_dipakai_basis_data` | SELESAI |
| 10 | Otorisasi tingkat baris | Sehat (terverifikasi) | `VisitAccessService` menegakkan: yang disupervisi melihat miliknya, pelaksana mengubah miliknya, tata kelola melihat seluruh sekolah, **tidak seorang pun** memfinalkan penilaian dirinya, hasil final beku bagi pelaksana. 124 dari 125 rute memakai middleware izin | — | — | Dijaga oleh uji `SupRouteGovernanceTest` (setiap rute wajib berizin & berbatas laju) | SELESAI |
| 11 | Jejak audit | Sehat (terverifikasi) | `sup_audit_logs` mencatat aktor, aksi, before/after, alasan; `sup_visit_revisions` menyimpan jejak finalisasi/pembukaan ulang; dokumen menyimpan checksum SHA-256 + kode verifikasi | — | — | Diuji termasuk pemulihan: kode & checksum tetap utuh setelah restore | SELESAI |
| 12 | Duplikasi logika | Bersih | Tidak ada mesin penilaian kedua: laporan dan analitik memanggil `AnalyticsService` yang sama; AppraisalCalculator murni dipakai analitik, PIP, dan laporan | — | — | Pemindaian rujukan service → pemakaian | SELESAI |
| 13 | Placeholder/TODO | Bersih | Pemindaian `TODO\|FIXME\|HACK\|XXX` pada modul = **0** | — | — | — | SELESAI |

---

## 2. Detil temuan CRITICAL

**#1 — Izin membuka layar ≠ cakupan baris.**

Penyebabnya halus dan karena itu berbahaya: guru memang **harus** memegang
`supervisi.report.view` supaya dapat membuka hasil supervisinya sendiri
(blueprint §1.2). Tetapi selama izin itu juga menjadi satu-satunya penjaga
endpoint analitik dan laporan, "boleh membuka" berubah menjadi "boleh melihat
seluruh sekolah". Bukti sebelum perbaikan:

```text
GET /api/v1/supervisi/analytics            → 200 (guru)
   data.coverage.unsupervised[0].full_name = "Siti Aminah"      ← identitas rekan
   data.appraisals.needs_pip_rows[0]       = { supervisee: "Ahmad Fauzi", final_score: 62 }
GET /api/v1/supervisi/reports/preview  hasil_supervisi
   rows = seluruh pegawai beserta nilai akhir & predikatnya
```

Perbaikan memisahkan dua pertanyaan yang sebelumnya bercampur:

| Pertanyaan | Penjawab |
|---|---|
| Boleh membuka layar ini? | middleware izin Spatie |
| Baris siapa yang boleh dilihat? | `DataScopeService::resolve($actor)` → `DataScope::school()` / `DataScope::self()` |

`DataScope` diteruskan eksplisit ke `AnalyticsService::overview()` dan
`ReportService::build()` — bukan state tersembunyi pada service, sehingga
pemanggil tidak dapat "lupa" memakainya tanpa terlihat di tanda tangan fungsi.

---

## 3. Detil temuan HIGH

**#2 — Papan RTL dan N+1.** Uji beban (240 kegiatan, 2.880 jawaban, 244 butir
RTL) mengukur **324 kueri** untuk satu halaman papan berisi 25 baris. Setelah
perbaikan, jumlah kueri di bawah 40 dan tidak tumbuh pada halaman ke-5
(uji: `test_jumlah_kueri_tetap_saat_data_bertambah`).

---

## 4. Temuan yang sengaja **tidak** diperbaiki

| Temuan | Alasan |
|---|---|
| Skema multi-sekolah (kolom `school_id` pada seluruh tabel `sup_*`) | Aplikasi ini satu sekolah (`sup_settings.school_id` bernilai tunggal). Menambahkan kolom tanpa kebutuhan nyata menambah risiko tanpa manfaat; `DataScopeService` adalah satu-satunya tempat yang perlu diperluas bila sekolah bernaung di bawah yayasan. |
| Tabel ringkasan agregat (summary tables) | Pada 240 kegiatan/2.880 jawaban, analitik selesai 62–147 ms. Ambang nyata adalah puluhan ribu jawaban; keputusan ditunda sampai ada data sebesar itu (dokumen ini mencatat angkanya agar tidak menebak). |
| Pindah dari `localStorage` ke IndexedDB | Satu kegiatan berisi puluhan indikator (< 100 KB). IndexedDB menambah kompleksitas tanpa perbedaan yang terasa; batas penyimpanan dicatat pada panduan. |
| Mode luring untuk unggahan bukti (foto/video) | Bukti visual berukuran besar; antrean berkas menuntut strategi penyimpanan & kuota yang terpisah. Fitur saat ini menerima teks/angka/pilihan dari kelas, lalu bukti diunggah saat daring. |
| Memperbaiki kontras sidebar aplikasi | Temuan audit tata letak bersifat lintas modul (bukan supervisi) dan menjadi lingkup perbaikan tema aplikasi. |

---

## 5. Status akhir

Seluruh temuan **CRITICAL**, **HIGH**, dan **MEDIUM** dikerjakan pada fase ini.
Hasil akhir, bukti uji, angka pengukuran, dan sisa keterbatasan dilaporkan pada
[`11-IMPLEMENTASI-FASE-5.md`](11-IMPLEMENTASI-FASE-5.md).
