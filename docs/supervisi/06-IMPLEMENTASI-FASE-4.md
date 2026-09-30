# Modul Supervisi — Implementasi Fase 4 (selesai)

> Analitik & laporan wajib, kalibrasi penilai, evaluasi program CIPP,
> ekspor CSV/XLSX/PDF dengan verifikasi dokumen, dan pengingat terjadwal.
> Lanjutan dari [`05-IMPLEMENTASI-FASE-3.md`](05-IMPLEMENTASI-FASE-3.md).

## 1. Yang dapat dilakukan sekarang

| Kebutuhan | Permukaan |
|---|---|
| “Sudah berapa persen guru disupervisi semester ini?” | `/supervisi/analytics` — kartu **Cakupan supervisi** + daftar pegawai yang belum disupervisi |
| “Apakah nilai membaik antar bulan?” | Grafik **Tren Nilai per Bulan** + sebaran predikat & rata-rata per bagian instrumen |
| “Indikator mana yang paling lemah?” | Tabel **Indikator Terlemah** (rata-rata, % dari skor maksimum, jumlah jawaban, ambang 75%) |
| “Apakah RTL diselesaikan tepat waktu?” | KPI **RTL tepat waktu**, butir terlambat, rata-rata hari penyelesaian, verifikasi |
| “Apakah penilai memakai ukuran yang sama?” | Tabel **Kelonggaran Penilai** (selisih dari rata-rata sekolah + penanda *perlu kalibrasi*) dan sesi **Kalibrasi Penilai** |
| “Cetak dokumen wajib Permendikbud 15/2018” | **Laporan Wajib**: 6 jenis laporan × CSV/XLSX/PDF + riwayat dokumen |
| “Buktikan salinan cetak ini asli” | Kode verifikasi + sidik digital SHA-256 + QR → halaman publik `/verifikasi-dokumen/{kode}` |
| “Apakah program supervisi berjalan efektif?” | **Evaluasi Program (CIPP)** dengan skor 4 dimensi, narasi bukti, dan bukti otomatis dari data supervisi |
| “Jangan sampai ada yang terlupa” | `php artisan supervisi:remind` (terjadwal 06.30) — kegiatan, RTL, coaching, tinjauan PIP |

## 2. Layanan backend baru

| Berkas | Tanggung jawab |
|---|---|
| `AnalyticsService` | Cakupan, statistik kegiatan, nilai & sebaran, rata-rata per bagian, indikator terlemah, tren bulanan, disiplin RTL, rekap penilaian & PIP, pengembangan/coaching, kinerja + indeks kelonggaran supervisor |
| `ReportService` | 6 laporan wajib (tabel + ringkasan), ekspor CSV/XLSX/PDF/JSON, penyimpanan berkas + checksum + kode verifikasi, riwayat, verifikasi publik |
| `XlsxWriter` (Support) | Penulis **XLSX OpenXML tanpa dependensi** (satu lembar, teks & angka) — instalasi ini tidak memuat pustaka spreadsheet |
| `CalibrationService` | Sesi moderasi, skor penilai per indikator/kasus, rata-rata & selisih maks−min, ambang sesi, kesimpulan wajib, penutupan sesi, bahan moderasi dari kegiatan nyata |
| `CippService` | Evaluasi program (context/input/process/product) + bukti otomatis, rekomendasi wajib saat finalisasi |
| `ReminderService` | Lima sapuan pengingat, idempoten per (penerima, jenis, kunci) per hari |
| `SupervisiRemindCommand` | `supervisi:remind [--dry-run] [--date=]` — laporan tabel per jenis pengingat |

Laporan & rumus tidak diduplikasi: seluruh angka laporan berasal dari
`AnalyticsService`, sehingga layar dan cetakan tidak mungkin berbeda.

## 3. Laporan wajib yang tersedia

| Kunci | Isi |
|---|---|
| `program_supervisi` | Program, lingkup, status, target vs kegiatan selesai, capaian %, rata-rata nilai |
| `hasil_supervisi` | Per kegiatan: pegawai, supervisor, teknik, skor mentah/maks/nilai akhir, predikat, RTL + status & kemajuannya, tanda tangan |
| `tindak_lanjut` | Per butir RTL: tenggat, status, kemajuan, selesai pada, **ketepatan waktu**, catatan hasil |
| `analitik_indikator` | Per indikator: jumlah jawaban, rata-rata skor & %, skor terendah, kategori perlu perbaikan |
| `rekap_penilaian` | Per dokumen penilaian: PKG/NKKS/SKP, koreksi kehadiran, predikat, angka kredit, status & hasil PIP |
| `kalibrasi_penilai` | Per indikator sesi: jumlah penilai, rata-rata, selisih, ambang, kesimpulan |

Ekspor: **CSV** (BOM + pemisah `;` agar rapi di Excel berlokal Indonesia),
**XLSX** (OpenXML sah, diuji dibuka `openpyxl`), **PDF** (kop sekolah, ringkasan,
tabel, kolom tanda tangan, QR verifikasi, ditutup kode & sidik digital), **JSON**
(untuk integrasi).

## 4. Tanda tangan & verifikasi dokumen

* Setiap dokumen yang dihasilkan mendapat **kode acak 8 karakter** (unik) dan
  **checksum SHA-256** atas isi berkasnya; riwayatnya tersimpan di `sup_reports`.
* QR pada PDF menunjuk ke `/verifikasi-dokumen/{kode}` — halaman publik yang
  hanya mengungkap keterangan dokumen (jenis, format, jumlah baris, waktu, sidik
  digital), bukan isinya.
* Verifikasi menyatakan **sah hanya bila berkas di disk masih ber-hash sama**:
  berkas yang disunting pihak lain akan ditolak (`valid: false`,
  `checksum_matches: false`). Diuji: mengubah berkas → verifikasi 404/tidak sah.
* QR memerlukan ekstensi GD; bila tidak tersedia, dokumen tetap dicetak dengan
  kode & tautan verifikasi sebagai teks (tidak pernah gagal hanya karena QR).

## 5. Kalibrasi penilai

* Sesi menyimpan judul, instrumen acuan (opsional), program, **ambang selisih**
  yang disepakati, tanggal, dan catatan.
* Skor tiap penilai disimpan per indikator instrumen **atau** per kasus bebas;
  ringkasan menampilkan rata-rata, **selisih maks−min**, dan penanda
  *melewati ambang* per indikator.
* Finalisasi wajib menyertakan **kesepakatan penilaian** (≥ 20 karakter) bila ada
  indikator melewati ambang; sesi yang sudah final terkunci.
* Bahan moderasi tambahan: `GET /calibrations/divergence` membandingkan
  rata-rata indikator yang sama antar supervisor pada kegiatan nyata — selisih
  yang tidak wajar muncul di atas, dan supervisor dengan simpangan ≥ 10 poin
  ditandai `needs_calibration` pada analitik.

## 6. Pengingat terjadwal

`supervisi:remind` (dijadwalkan 06.30, `withoutOverlapping`) menyapu:

1. kegiatan terjadwal dalam 3 hari ke depan → notifikasi ke supervisor;
2. kegiatan yang sudah lewat tetapi belum difinalkan → supervisor;
3. butir RTL jatuh tempo ≤ 3 hari / terlambat → penanggung jawab **dan** pencatat RTL;
4. coaching berstatus terbuka yang tindak lanjutnya jatuh tempo → pembina;
5. PIP aktif dengan tinjauan akhir ≤ 7 hari → pengelola PIP.

Idempoten: satu (penerima, jenis, kunci) hanya sekali per hari, sehingga
penjadwal boleh berjalan dua kali; `--dry-run` untuk memeriksa tanpa mengirim.

## 7. API baru

```
GET   analytics                              analitik lengkap (from/to)
GET   reports/catalog · reports/history      daftar & riwayat dokumen
POST  reports/preview                        pratinjau tabel + ringkasan
POST  reports                                hasilkan dokumen (CSV/XLSX/PDF/JSON)
GET   reports/{report}/download              unduh berkas
GET   reports/verify/{code}                  verifikasi publik (tanpa token)
GET   calibrations · calibrations/divergence
POST  calibrations · GET|PATCH calibrations/{session}
PUT   calibrations/{session}/scores
POST  calibrations/{session}/finalize
GET   evaluations · GET|PATCH evaluations/{evaluation}
POST  programs/{program}/evaluations · POST evaluations/{evaluation}/finalize
```

Izin baru: `supervisi.calibration.view|manage|finalize`,
`supervisi.evaluation.view|manage|finalize`, `supervisi.reminder.manage`.
Baca analitik memakai `supervisi.report.view`; menghasilkan/mengunduh dokumen
memakai `supervisi.report.export` (guru boleh melihat analitik, tidak boleh
mengunduh rekap nilai).

## 8. Pengujian

`php -d memory_limit=1G vendor/bin/phpunit --filter 'Supervisi|AppraisalCalculator'`
→ **84 tes, 728 asersi, hijau** (Fase 1: 32 · Fase 2: 16 · Fase 3: 27 · **Fase 4: 9**).

Fase 4 menguji: cakupan/nilai/indikator lemah/tren/kelonggaran penilai; enam
laporan × tiga format (mencegah cacat “rapi di JSON, gagal di CSV/XLSX”);
checksum & BOM; XLSX sebagai arsip OpenXML sah; PDF sebagai berkas PDF; unduhan
hanya untuk pemegang izin; **verifikasi publik menerima dokumen utuh dan
menolak berkas yang diubah**; kalibrasi (selisih, ambang, kesepakatan wajib,
sesi terkunci, guru ditolak); kesenjangan nyata antar supervisor; CIPP
(rata-rata 4 dimensi, bukti otomatis, rekomendasi wajib, terkunci setelah final);
dan pengingat (jenis & penerima tepat, idempoten, `--dry-run` tidak mengirim).

Diverifikasi di browser nyata: analitik berisi data, pratinjau laporan, ekspor
XLSX dengan kode verifikasi, sesi kalibrasi, evaluasi CIPP, dan halaman
verifikasi publik menyatakan **“Dokumen sah”**.

## 9. Fase berikutnya (5 — pengerasan)

Audit menyeluruh (IDOR antar peran, kebocoran antar sekolah, injeksi filter),
uji beban agregasi analitik pada data besar, mode luring, dokumentasi OpenAPI,
panduan pengguna & deployment, serta latihan pemulihan (backup/restore) modul.
