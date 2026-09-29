# Modul Supervisi — Implementasi Fase 1 (selesai)

> Ringkasan apa yang sudah dibangun, cara menjalankannya, dan apa yang belum.
> Keputusan desain: [`02-KEPUTUSAN-DESAIN.md`](02-KEPUTUSAN-DESAIN.md) ·
> Riset: [`00-RESEARCH-SUPERVISI.md`](00-RESEARCH-SUPERVISI.md) ·
> Rancangan: [`01-BLUEPRINT-MODUL-SUPERVISI.md`](01-BLUEPRINT-MODUL-SUPERVISI.md)

## 1. Yang sudah jadi

### 1.1 Basis data (4 migrasi, 30 tabel berprefix `sup_`)
| Migrasi | Tabel |
|---|---|
| `..._100001_create_sup_instrument_tables` | `sup_grade_scales`, `sup_instruments`, `sup_instrument_versions`, `sup_sections`, `sup_indicators`, `sup_indicator_options`, `sup_supervisors`, `sup_settings`, `sup_audit_logs` |
| `..._100002_create_sup_program_tables` | `sup_programs`, `sup_program_targets`, `sup_assignments`, `sup_schedules` |
| `..._100003_create_sup_visit_tables` | `sup_visits`, `sup_visit_answers`, `sup_visit_scores`, `sup_visit_evidence`, `sup_visit_notes`, `sup_conferences`, `sup_findings`, `sup_acknowledgments`, `sup_disputes`, `sup_visit_revisions` |
| `..._100004_create_sup_followup_tables` | `sup_action_plans`, `sup_action_items`, `sup_action_progress`, `sup_developments`, `sup_coaching_sessions`, `sup_appraisals`, `sup_appraisal_scores`, `sup_appraisal_sources`, `sup_pip_plans`, `sup_pip_items`, `sup_pip_reviews`, `sup_calibration_sessions`, `sup_calibration_scores`, `sup_program_evaluations`, `sup_reports` |

Skema kegiatan (visits), tindak lanjut (RTL), penilaian kinerja (PKG/PIP), dan
kalibrasi sudah disiapkan sejak Fase 1 supaya fase berikutnya tidak perlu
mengubah tabel yang sudah berisi data.

### 1.2 Domain & layanan inti
| Berkas | Fungsi |
|---|---|
| `Domain/Enums/*` (21 enum) | Kategori instrumen, tipe input, mode skor, jenis supervisor, pendekatan supervisi (Glickman), jenis kegiatan, status kunjungan/RTL/penilaian, dsb. |
| `Application/Services/InstrumentBuilderService` | Membuat instrumen & versi, menyusun struktur (bagian → indikator → opsi), checklist kelayakan terbit, publikasi (+checksum & skor maksimum), kloning versi, ekspor/impor |
| `Application/Services/ScoringEngine` | Mesin skor: skala rubrik, angka (dua arah), ya/tidak, pilihan ganda, `NA` dikeluarkan dari pembilang & penyebut, bobot, konversi 100, predikat |
| `Application/Services/SupervisorDirectoryService` | Registry supervisor: pendaftaran, calon supervisor, peta cakupan + peringatan, daftar supervisor yang memenuhi syarat, penegakan aturan (diri sendiri, kapasitas, masa berlaku, cakupan) |
| `Application/Services/AssignmentService` | Penugasan supervisor (hanya supervisor terdaftar), peran, pendekatan, jumlah kunjungan minimum |
| `Application/Services/SupervisiSettings` | Pengaturan modul (frekuensi minimum, visibilitas hasil, bobot supervisi dalam penilaian kinerja) |
| `Application/Services/AuditLogger` | Jejak audit setiap tindakan penting |

### 1.3 API (`/api/v1/supervisi/*`)
* `meta/instruments` — metadata pembuat instrumen (kategori, tipe input, preset skala, mode skor, skala predikat).
* `supervisors`, `supervisors/{id}`, `supervisors/candidates`, `supervisors/coverage`, `supervisors/eligible`, `supervisors/kinds`.
* `instruments`, `instruments/{id}`, `instruments/import/{id}`, `instruments/{id}/versions`.
* `instrument-versions/{id}`, `.../structure`, `.../checklist`, `.../publish`, `.../clone`, `.../export`.
* `programs` (+ `publish`, `close`), `programs/{id}/assignments`, `assignments`, `assignments/workload`.
* `schedules` (+ `invite`, `reschedule`, `cancel`).
* `grade-scales` (+ `default`), `settings`.

Izin granular `supervisi.*` (44 permission) dibuat `SupPermissionSeeder` dan
dibagikan ke peran ERP: super-admin & admin sekolah (penuh), kepala sekolah
(penuh kecuali hapus instrumen), kepala unit, auditor (baca saja), guru & staf
(lihat instrumen, jadwal sendiri, RTL, hak tanggapan).

### 1.4 Instrumen bawaan (22 instrumen, langsung terbit)
| Instrumen | Butir | Skor maks | Skala |
|---|---|---|---|
| Administrasi & Perangkat Pembelajaran | 12 | 48 | 1–4 |
| Telaah RPP / Modul Ajar | 36 | 72 | 0–2 |
| Pengamatan Pelaksanaan Pembelajaran | 40 | 40 | ya/tidak |
| Supervisi Penilaian Hasil Belajar | 11 | 44 | 1–4 |
| Kunjungan Kelas Singkat (Walkthrough) | 7 | — | 1–4 + **angka** |
| Wawancara Pra / Pasca-Observasi | 9 / 10 | naratif | teks |
| Dampak / Outcome Supervisi | 5 | 20 | 1–4 |
| Telaah Kisi-kisi & Butir Soal | 24 | — | ya/tidak + **angka HOTS** |
| Tendik: kepala administrasi, persuratan, sarpras, perpustakaan, laboratorium, layanan khusus | 7–11 | — | 1–4 + **angka** |
| Supervisi Manajerial Sekolah (8 aspek) | 8 | 32 | 1–4 |
| PKG Pembelajaran | 14 | 56 | 1–4 |
| PKG BK/Konselor | 17 | 68 | 1–4 |
| NKKS Kepala Sekolah | 6 | 24 | 1–4 |
| Tinjauan Kinerja Pegawai | 12 | — | 1–5 berbobot |
| PIP (Rencana Perbaikan Kinerja) | 6 | — | angka target |
| Evaluasi Program (CIPP) | 9 | — | 1–4 + angka |

Skala predikat bawaan: **A/B/C/K (90/80/70)**, **sebutan PKG 91/76/61/51 + %
angka kredit**, **HR 1–5 → persentase berbobot**, dan **Ya/Tidak**.

### 1.5 Antarmuka (React)
* `/supervisi` — **Pengaturan Supervisor**: ringkasan tata kelola + peringatan,
  daftar supervisor, tambah dari daftar calon (jenis diusulkan otomatis dari
  jabatan), ubah, aktif/nonaktifkan, kapasitas, masa berlaku, hak finalisasi.
* `/supervisi/instruments` — **Pustaka Instrumen**: filter kategori/pencarian,
  status versi & skor maksimum, buat instrumen baru, nonaktifkan.
* `/supervisi/instruments/:instrumentId/versions/:versionId` — **Pembuat
  Instrumen**: indikator berskala (kelola opsi/preset 1–4, 0–2, 0–4, 1–5,
  ya/tidak) **atau** bertipe angka (min, maks, langkah, satuan, arah
  “makin kecil makin baik”), bobot, wajib/`NA`/kritis, komentar wajib,
  checklist kelayakan, terbitkan, kloning versi, ekspor.

Menu masuk ke grup **Akademik**: “Pengaturan Supervisor” & “Instrumen Supervisi”.

### 1.6 Pengujian
`tests/Feature/Supervisi` — **32 tes, 158 asersi, semua hijau** (≈15 detik):
* `SupInstrumentBuilderTest` — metadata, instrumen campuran skala+angka,
  checklist, publikasi, versi terbit beku, kloning, ekspor/impor, izin guru.
* `SupScoringEngineTest` — skala, angka dua arah, ya/tidak, `NA`,
  pemotongan nilai di luar rentang, bobot bagian, predikat.
* `SupSupervisorDirectoryTest` — pendaftaran, larangan ganda, sejawat tidak
  boleh finalisasi, calon & usulan jenis, penolakan menilai diri sendiri,
  penugasan wajib supervisor terdaftar, kapasitas, peta cakupan, pengaturan.
* `SupScheduleGovernanceTest` — penolakan supervisor tak terdaftar & diri
  sendiri, alur undang/ubah/batal, guru hanya melihat jadwal miliknya.
* `SupBuiltInInstrumentTest` — jumlah butir & skor maksimum instrumen bawaan
  (12/48, 36/72, 40/40, 14/56, 17/68, 24), sebutan PKG, instrumen naratif.

## 2. Cara menjalankan

```bash
# backend
cd backend
php artisan migrate                 # membuat tabel sup_*
php artisan db:seed --class="App\Modules\Supervisi\Database\Seeders\SupPermissionSeeder"
php artisan db:seed --class="App\Modules\Supervisi\Database\Seeders\SupInstrumentSeeder"

# pengujian modul
php -d memory_limit=1G vendor/bin/phpunit --filter Supervisi

# frontend
cd frontend && npm run build        # atau: npx vite
```

`DatabaseSeeder` juga sudah memanggil kedua seeder supervisi, sehingga
`php artisan db:seed` sekali jalan menyiapkan seluruh modul.

## 3. Belum dikerjakan (fase berikutnya)

| Fase | Isi |
|---|---|
| 2 | Melaksanakan kegiatan: pra-observasi, pengisian instrumen (mode HP/luring), bukti, analisis temuan, pasca-observasi, **finalisasi + snapshot + acknowledge/keberatan** |
| 3 | RTL & papan pemantauan, pengembangan kompetensi, coaching; kalkulator PKG/NKKS (koreksi kehadiran), kuadran SKP, tinjauan pegawai multi-sumber, PIP |
| 4 | Analitik (tren indikator, distribusi predikat, kebutuhan pelatihan), laporan wajib Permendikbud 15/2018, CIPP, kalibrasi penilai, dokumen cetak |
| 5 | Audit menyeluruh, uji beban, mode luring, dokumentasi API/OpenAPI, panduan pengguna |

Skema tabel untuk seluruh fase di atas **sudah ada**, jadi fase berikutnya
berfokus pada logika, layar, dan pelaporan.
