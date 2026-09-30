# Modul Supervisi — Implementasi Fase 3 (selesai)

> Tindak lanjut & penilaian kinerja: RTL, pengembangan kompetensi, coaching,
> PKG/NKKS, kuadran SKP, tinjauan multi-sumber, dan PIP.
> Lanjutan dari [`04-IMPLEMENTASI-FASE-2.md`](04-IMPLEMENTASI-FASE-2.md).

## 1. Alur yang kini tersambung

```
Kegiatan difinalkan → temuan   ──► RTL (butir + tenggat + bukti + verifikasi atasan)
Kegiatan difinalkan (nilai)    ──► komponen penilaian kinerja (bila bobot supervisi > 0%)
PKG/NKKS/tinjauan pegawai/SKP  ──► nilai akhir + predikat + angka kredit → terbitkan hasil
Predikat butuh perbaikan       ──► PIP (sasaran terukur, tinjauan berkala, keputusan hasil)
Diklat/workshop/mandiri        ──► catatan jam + sertifikat (bahan angka kredit PKB)
Coaching/mentoring             ──► komitmen + tenggat tindak lanjut
```

## 2. Rumus resmi yang dipakai (`AppraisalCalculator`, murni & teruji)

| Perhitungan | Rumus | Contoh diuji |
|---|---|---|
| PKG guru mapel/kelas | Σ 14 kompetensi (1–4) = maks 56 → ×100/56 | 42/56 → **75** |
| PKG guru BK | Σ 17 kompetensi = maks 68 → ×100/68 | 51/68 → **75** |
| NKKS kepala sekolah | Σ 6 aspek = maks 24 → ×100/24 | 18/24 → **75** |
| Koreksi kehadiran | NPK = Nilai × (100% − (hari alfa ÷ hari kerja) × 100%) | 5 hari dari 46 → 89,13% |
| Sebutan & angka kredit | 91–100 Amat Baik 125% · 76–90 Baik 100% · 61–75 Cukup 75% · 51–60 Sedang 50% · ≤50 Kurang 25% | 100 → Amat Baik 125% |
| Kuadran SKP 3×3 | matriks PermenPANRB 6/2022 | (di atas, sesuai) → **Baik**; (di bawah, di bawah) → **Sangat Kurang** + PIP |
| Rating dari persen | > 100% di atas · 90–100% sesuai · < 90% di bawah | 105 → di atas |
| Multi-sumber 360° | Σ(skor × bobot) ÷ Σbobot | 60/20/10/10 dengan 80/70/90/100 → **81,00** |

Semua langkah (mentah → konversi → koreksi → kontribusi supervisi → multi-sumber → predikat)
disimpan di `snapshot` beserta instrumen dan skalanya, sehingga dokumen lama tetap sahih
walau rumus/pengaturan sekolah berubah.

## 3. Layanan backend baru

| Berkas | Tanggung jawab |
|---|---|
| `AppraisalCalculator` | Seluruh rumus PKG/NKKS/koreksi kehadiran/kuadran/multi-sumber (fungsi murni) |
| `AppraisalService` | Dokumen penilaian: buat, simpan skor (validasi ketat), hitung, sumber 360°, terbitkan, buka ulang |
| `ActionPlanService` | RTL otomatis dari temuan, butir + tenggat, laporan kemajuan, verifikasi atasan, papan pemantauan |
| `DevelopmentService` | Catatan diklat/workshop/mandiri + ringkasan jam & sertifikat |
| `CoachingService` | Sesi coaching: fokus, komitmen, tenggat tindak lanjut, penutupan sesi |
| `PipService` | PIP: sasaran terukur, tinjauan berkala, keputusan hasil, tanda tangan pegawai |

`ScoringEngine` di-*refactor* agar satu jalur perhitungan dipakai bersama oleh
kegiatan supervisi **dan** penilaian kinerja (`scoreAnswers()`), sehingga NA,
bobot bagian, dan konversi 100 tidak mungkin berbeda antar fitur.

## 4. Aturan yang ditegakkan

| Aturan | Konsekuensi |
|---|---|
| RTL hanya dari kegiatan yang sudah difinalkan, satu RTL per kegiatan | 422 |
| Butir RTL terkunci setelah diverifikasi | 422 |
| Verifikasi butuh seluruh butir selesai (atau dihentikan) | 422 berisi daftar butir |
| Skor wajib salah satu pilihan rubrik (atau dalam rentang & langkah untuk indikator angka) | 422 |
| Penilai tidak boleh menilai dirinya sendiri | 422 |
| Bobot sumber ≠ 100% ditolak bila normalisasi otomatis dimatikan sekolah | 422 |
| Hasil terbit terkunci; koreksi hanya lewat buka ulang beralasan (tercatat) | 422 + jejak audit |
| "Membaik" tanpa menyelesaikan seluruh butir PIP | 422 |
| "Diperpanjang" tanpa tanggal tinjauan akhir baru | 422 |
| Guru hanya melihat RTL/PIP/pengembangan miliknya; wali kelas/guru lain tidak | 403 / daftar tersaring |
| Catatan pengembangan hanya boleh dikelola pemiliknya atau tata kelola | 403 |

## 5. API baru (`/api/v1/supervisi/*`)

```
GET|POST            action-plans                     papan RTL & penyusunan manual
GET|PATCH           action-plans/{id}               rincian & perubahan RTL
POST                action-plans/{id}/items         tambah butir
PATCH               action-plans/items/{id}         ubah butir
POST                action-plans/items/{id}/progress laporan kemajuan
POST                action-plans/{id}/verify|drop   verifikasi / hentikan
POST                visits/{id}/action-plan         susun RTL dari temuan kegiatan
GET|POST            appraisals                      daftar & buat dokumen penilaian
GET                 appraisals/reference            acuan rumus untuk sekolah
GET|PATCH           appraisals/{id}                 rincian & ubah periode/penilai
PUT                 appraisals/{id}/scores          skor per kompetensi
POST                appraisals/{id}/calculate       hitung (alfa, rating, persen)
PUT                 appraisals/{id}/sources         bobot & skor multi-sumber
POST                appraisals/{id}/publish|reopen  terbitkan / buka ulang
GET|POST            developments                    pengembangan kompetensi
PATCH|DELETE        developments/{id}
GET|POST            coachings                       sesi coaching
PATCH               coachings/{id} · POST {id}/close
GET|POST            pips                            PIP
GET|PATCH           pips/{id} · POST {id}/items · PATCH items/{id}
POST                pips/{id}/reviews|conclude|acknowledge
```

## 6. Antarmuka baru

* **`/supervisi/action-plans`** — papan RTL: kartu *terlambat / jatuh tempo ≤ 7 hari /
  menunggu verifikasi / terverifikasi*, filter, bilah kemajuan per RTL, butir dengan
  tenggat & penanggung jawab, pelaporan kemajuan, riwayat laporan, tombol verifikasi.
* **`/supervisi/appraisals`** — daftar dokumen penilaian + ringkasan (total, draf, terbit,
  perlu PIP), kartu **Acuan Perhitungan** berisi rumus, predikat, dan % angka kredit.
* **`/supervisi/appraisals/:id`** — lembar kerja: hasil perhitungan bertahap
  (mentah/maks/nilai akhir/predikat/angka kredit + langkah koreksi kehadiran,
  kontribusi supervisi, multi-sumber, kuadran), penilaian per kompetensi dengan rubrik
  1–4, koreksi kehadiran & rating SKP, editor multi-sumber dengan penanda total bobot,
  serta aksi *Simpan skor / Hitung nilai / Terbitkan / Buka ulang*.
* **`/supervisi/growth`** — tab **Pengembangan** (jam & sertifikat dengan ringkasan),
  **Coaching** (fokus, komitmen, tindak lanjut jatuh tempo), **PIP** (sasaran, tinjauan,
  keputusan hasil, tanda tangan pemahaman).
* Tab **Hasil & Tanda Tangan** pada kegiatan kini memiliki tombol **“Susun RTL dari temuan”**.

## 7. Pengaturan baru

| Kunci | Bawaan | Arti |
|---|---|---|
| `attendance_working_days` | `46` | Pembagi koreksi kehadiran |
| `pkg_credit_scale_code` | `pkg_sebutan` | Skala sebutan & angka kredit PKG/NKKS |
| `appraisal_source_weights` | 60/20/10/10 | Bobot bawaan penilaian multi-sumber |
| `appraisal_normalize_weights` | `true` | `false` = bobot wajib tepat 100% |
| `skp_rating_thresholds` | `{above:100, below:90}` | Ambang rating SKP |
| `skp_quadrant_matrix` | `{}` | Matriks kuadran sekolah (kosong = bawaan) |
| `skp_predicate_credit` | `{}` | Persentase angka kredit per predikat |
| `action_plan_default_due_days` | `30` | Tenggat bawaan butir RTL (mendesak → ¼, tinggi → ½) |
| `action_plan_auto_from_findings` | `true` | RTL susun otomatis dari temuan |
| `coaching_follow_up_days` | `14` | Tenggat tindak lanjut coaching |

## 8. Pengujian

`php -d memory_limit=1G vendor/bin/phpunit --filter 'Supervisi|AppraisalCalculator'`
→ **75 tes, 546 asersi, hijau** (Fase 1: 32 · Fase 2: 16 · Fase 3: 27).

Fase 3 mencakup: 10 tes rumus resmi (PKG 56/68, NKKS 24, koreksi kehadiran, rating,
kuadran bawaan & kustom, multi-sumber 60/20/10/10 & penolakan bobot ≠ 100%),
7 tes alur penilaian (PKG 56 → Amat Baik, koreksi kehadiran → Baik, skor di luar batas
ditolak, penilai = diri sendiri ditolak, terbit → terkunci → buka ulang, 360° → 81,00,
SKP dari rating & persentase, acuan rumus), 5 tes RTL (otomatis dari temuan, tenggat
per prioritas, kemajuan → verifikasi → terkunci, papan menonjolkan keterlambatan, akses
terbatas), dan 5 tes PIP/pengembangan/coaching (jam & sertifikat, akses pemilik,
komitmen & tenggat coaching, PIP dari PKG rendah sampai keputusan hasil + tanda tangan,
PIP orang lain 403).

Diuji pula di browser nyata: papan RTL, daftar penilaian, dan lembar PKG (nilai 25 ·
predikat “Kurang” · angka kredit 25% · konversi 100 : 25).

## 9. Fase berikutnya

| Fase | Isi |
|---|---|
| 4 | Laporan wajib (Permendikbud 15/2018) & analitik tren indikator, evaluasi CIPP, kalibrasi penilai, ekspor CSV/XLSX/PDF + tanda tangan dokumen, pengingat terjadwal |
| 5 | Audit menyeluruh (IDOR, kebocoran antar sekolah, uji beban agregasi), mode luring penuh, OpenAPI, panduan pengguna & deployment |

Tabel `sup_reports`, `sup_program_evaluations`, `sup_calibration_sessions/scores`
sudah tersedia sejak Fase 1 untuk Fase 4.
