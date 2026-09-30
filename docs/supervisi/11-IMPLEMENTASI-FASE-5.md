# Modul Supervisi — Implementasi Fase 5 (pengerasan & dokumentasi)

> Lanjutan [`06-IMPLEMENTASI-FASE-4.md`](06-IMPLEMENTASI-FASE-4.md) — analitik, laporan, kalibrasi,
> CIPP, dan pengingat — ditutup dengan pengerasan. Setiap temuan audit
> ([`07-AUDIT-FASE-5.md`](07-AUDIT-FASE-5.md)) dikerjakan di sini, dengan bukti
> uji dan angka pengukuran.

## 1. Ringkasan hasil

| Area | Sebelum | Sesudah |
|---|---|---|
| Privasi hasil supervisi | Guru/staf dapat membaca analitik & laporan seluruh sekolah | Cakupan data (`DataScope`) menyempitkan ke dirinya; identitas rekan sejawat tidak pernah terbuka; ringkasan agregat opsional lewat `peer_summary_enabled` |
| Kueri papan RTL | **324 kueri** untuk 25 baris | **< 40 kueri**, stabil di halaman ke-5 |
| Finalisasi ganda (dua tab) | Mungkin lolos | Ditolak: baris dikunci (`lockForUpdate`) + periksa ulang di dalam transaksi |
| `per_page` | `?per_page=100000` diterima | Dibatasi maksimum 100 |
| Pencarian `LIKE` | Wildcard `%`/`_` dari pengguna dipakai apa adanya | Dibuang & dibatasi (`SearchTerm`) |
| Batas laju | **0 dari 122 rute** berbatas | Seluruh 125 rute berbatas (modul 300/menit + 7 batas khusus) |
| Mode luring | Kolom `client_uuid` tanpa muara | Sinkronisasi idempoten + formulir cetak + antrean perangkat |
| Pemantauan integritas | Tidak ada | 9 pemeriksaan (`GET /supervisi/health`, `supervisi:health`, terjadwal mingguan) |
| Retensi berkas | Berkas menumpuk tanpa kebijakan | `supervisi:prune` (lapor bawaan, `--force` untuk hapus; riwayat tetap) |
| Dokumentasi | Tanpa API/deployment/panduan | OpenAPI 97 path/125 operasi + panduan API, pengguna, dan deployment |
| Uji modul | 85 tes / 764 asersi | **105 tes / 1.024 asersi** (Fase 5 menambah 20 tes) |

## 2. Berkas yang ditambah/diubah

**Ditambah**

| Berkas | Isi |
|---|---|
| `Application/Services/DataScope.php` | Nilai cakupan data (sekolah vs diri), termasuk catatan kebijakan untuk antarmuka |
| `Application/Services/DataScopeService.php` | Penentu cakupan dari izin + pengaturan sekolah; gerbang laporan agregat |
| `Application/Services/OfflineSyncService.php` | Sinkronisasi luring idempoten, tahan sebagian, pelapor konflik |
| `Application/Services/BlankFormService.php` + `resources/views/pdf/supervisi-blank-form.blade.php` | Formulir observasi kosong (pilihan berlabel huruf) siap cetak |
| `Application/Services/SupervisiHealthService.php` | Sembilan pemeriksaan integritas + saran perbaikan |
| `Application/Support/SearchTerm.php` | Pembersih kata kunci pencarian (wildcard, panjang) |
| `Console/SupervisiHealthCommand.php`, `Console/SupervisiPruneCommand.php` | Perintah pemantauan & retensi |
| `config/supervisi.php` | Batas laju & retensi berkas (dapat diubah sekolah tanpa deploy) |
| `frontend/.../offline/offlineQueue.ts` | Antrean jawaban di perangkat (`localStorage`, `client_uuid`) |
| `frontend/.../components/OfflineModePanel.tsx` | Panel Mode Luring: unduh formulir, daftar tunggu, kirim antrean, penanganan konflik |
| `docs/openapi-supervisi.yaml`, `docs/supervisi/07…10` | Kontrak & dokumen operasional |

**Diubah (inti perbaikan)**

* `AnalyticsService` — parameter `?DataScope` + `personalOverview()` untuk dasbor pribadi;
* `ReportService` — `?DataScope` pada `build()`/`history()`, penyaringan baris per pegawai, daftar laporan agregat (`AGGREGATE_REPORTS`);
* `VisitFinalizationService` — `lockForUpdate()` pada `finalize()`/`reopen()`;
* `ActionPlanService` — `deriveStatus()` murni + `refreshStatuses()` berkelompok;
* `SupVisitController` — `syncOfflineAnswers()`, `blankForm()`;
* `SupReportController` — cakupan pada analitik/laporan/riwayat + `health()`;
* `Routes/api.php`, `Routes/public.php`, `SupServiceProvider` — limiter & rute baru;
* enam lokasi pencarian (`SupInstrumentController`, `SupSupervisorController`, `SupervisorDirectoryService`, `AppraisalService`, `SupVisitController`) memakai `SearchTerm`;
* `SupOfflineModeTest`… lihat §3.

## 3. Bukti uji

`php -d memory_limit=2G vendor/bin/phpunit --filter 'Supervisi|AppraisalCalculator'`
→ **105 tes, 1.024 asersi, hijau** (58 detik).

Fase 5 menambah 20 tes:

| Berkas | Tes | Yang dibuktikan |
|---|---|---|
| `SupPrivacyScopeTest` | 4 | Guru hanya melihat barisnya sendiri pada analitik/laporan/riwayat; nama rekan sejawat tidak muncul; laporan agregat 403; katalog laporan disaring; `peer_summary_enabled` membuka **agregat tanpa identitas**; kepala sekolah tetap melihat seluruh sekolah; akun "diri" tidak dapat menghasilkan dokumen sekolah |
| `SupOfflineModeTest` | 9 | Sinkronisasi luring: `option_code` huruf → opsi benar, idempoten (kirim ulang 0 diterapkan/3 dilewati), tahan sebagian (2 baris rusak ditolak dengan alasan), konflik tidak menimpa server, kegiatan final menolak sinkronisasi, formulir kosong berupa PDF & akses ditolak untuk bukan pelaksana, batas laju publik (429 pada permintaan ke-31) dan ekspor (20/menit), wildcard pencarian tidak lagi mencocokkan, `per_page` kalibrasi dibatasi 100, kesehatan menemukan data tidak konsisten, penyapu menghapus berkas tetapi **mempertahankan** riwayat + kode + checksum |
| `SupPerformanceTest` | 3 | Jumlah kueri tetap saat data bertambah (240 kegiatan/2.880 jawaban), halaman ke-5 sama murahnya, anggaran waktu agregat, dan rencana kueri benar-benar memakai indeks blueprint (`sup_visit_supervisee_idx`, `sup_answer_unique`, `sup_item_due_idx`) |
| `SupRouteGovernanceTest` | 4 | **Setiap** rute modul wajib berizin & berbatas laju (temuan #6 tidak dapat terulang), hanya satu endpoint publik (verifikasi dokumen) yang juga tidak mengungkap kunci lain, dan operasi berat memakai batas khusus |

Angka pengukuran yang dicetak uji beban:

```text
[Beban 240 kegiatan / 2880 jawaban] analitik 62 ms · laporan 16 ms · kesehatan 5 ms
```

Sebelum perbaikan N+1, satu halaman papan RTL berisi 25 baris memakai **324
kueri**; sekarang di bawah **40** dan tidak tumbuh antar halaman.

## 4. Bukti di antarmuka

* Uji peramban nyata: dasbor analitik berisi data, pratinjau laporan, ekspor
  XLSX dengan kode verifikasi, sesi kalibrasi, evaluasi CIPP, dan halaman
  verifikasi publik menyatakan **"Dokumen sah"** (dijalankan pada Fase 4 dan
  diulang pada Fase 5).
* Panel **Mode Luring** tampil pada kegiatan yang belum final: status
  daring/luring, jumlah jawaban menunggu, unduh formulir, kirim antrean,
  pembersihan antrean, dan pesan khusus saat ada konflik.
* Audit tata letak peramban melaporkan temuan **lintas modul** (kontras sidebar
  aplikasi, satu tombol ikon tanpa label — sudah diberi `aria-label`/`title`
  pada Fase 5). Tidak ada temuan tata letak pada halaman baru modul supervisi.

## 5. Latihan pemulihan (ringkas)

Rincian ada di [`10-DEPLOYMENT-SUPERVISI.md`](10-DEPLOYMENT-SUPERVISI.md) §6.
Ringkasnya, pada basis data 8,5 MB berisi 241 kegiatan/2.887 jawaban:

* cadangan `sqlite3 .backup` **73 ms**, `integrity_check` `ok`;
* simulasi musibah pada salinan (tabel jawaban dihapus) — data hilang;
* pemulihan **28 ms**, jumlah baris identik, `integrity_check` `ok`;
* kode verifikasi & sidik digital dokumen utuh → verifikasi publik tetap
  menyatakan **sah**;
* pemasangan baru dari nol: `migrate` **3,8 s**, `db:seed` **53 s**
  (22 instrumen, 280 indikator).

## 6. Sisa keterbatasan (jujur, beserta jalur perbaikannya)

| Keterbatasan | Dampak | Jalur perbaikan |
|---|---|---|
| `lockForUpdate` hanya benar-benar mengunci pada MySQL/PostgreSQL; SQLite (bawaan pengembangan) mengabaikan klausa tersebut | Pada SQLite, perlombaan dua tab masih mungkin secara teori | Produksi memakai MySQL/PostgreSQL (didukung penuh) atau SQLite dengan `PRAGMA journal_mode=WAL` + serialisasi permintaan tulis |
| Agregat analitik dihitung saat permintaan (tanpa tabel ringkasan) | 62–147 ms pada 240 kegiatan; akan tumbuh linear | Tabel ringkasan/snapshot per bulan bila data mencapai puluhan ribu jawaban (ambang tercatat pada audit) |
| Antrean luring memakai `localStorage` | Hilang bila pengguna membersihkan data situs atau berpindah perangkat | Pindah ke IndexedDB + kunci per perangkat; atau kirim otomatis saat kembali daring |
| Bukti (foto/video) belum dapat diunggah luring | Supervisor mengunggah setelah kembali ke jaringan | Antrean berkas dengan kuota & kompresi, terpisah dari antrean jawaban |
| Satu sekolah per pemasangan (tanpa `school_id` pada tabel `sup_*`) | Yayasan dengan banyak sekolah memerlukan pemasangan terpisah | Perluas `DataScopeService` (satu titik) + migrasi kolom penyewa |
| PDF memerlukan ekstensi `gd` untuk QR | Tanpa `gd`, PDF tercetak tanpa QR (kode & tautan tetap tercetak sebagai teks) | Pasang `gd` di server produksi |
| Ringkasan agregat bagi rekan sejawat masih menampilkan indikator terlemah sekolah | Bila diaktifkan, guru mengetahui kompetensi kolektif yang lemah (tanpa nama) | Kebijakan sekolah; dapat dipersempit ke agregat per bagian bila dikehendaki |

## 7. Definisi selesai Fase 5

* [x] Seluruh temuan CRITICAL/HIGH/MEDIUM audit ditutup dengan kode **dan** uji
* [x] Setiap rute modul berizin dan berbatas laju (dijaga uji struktural)
* [x] Privasi hasil supervisi sesuai blueprint §1.2 & §11.6, dengan kebijakan
      sekolah yang dapat diatur
* [x] Mode luring blueprint §12 terpasang ujung-ke-ujung (kertas → perangkat →
      server) dengan sifat idempoten yang diuji
* [x] Pemantauan integritas & retensi berjalan sebagai perintah terjadwal
* [x] OpenAPI, panduan API, panduan pengguna, dan dokumen deployment tersedia
* [x] Latihan pencadangan/pemulihan dijalankan dengan angka nyata
* [x] 105 tes / 1.024 asersi hijau; build produksi frontend hijau; uji peramban
      nyata pada alur utama

**Modul supervisi dinyatakan tuntas (Fase 1–5).** Pekerjaan lanjutan yang bersifat
peningkatan (tabel ringkasan, antrean berkas luring, multi-sekolah) tercatat pada
§6 sebagai jalur perbaikan, bukan utang yang disembunyikan.
