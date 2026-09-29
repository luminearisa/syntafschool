# Modul Supervisi — Implementasi Fase 2 (selesai)

> Pelaksanaan kegiatan supervisi: dari kunjungan kelas sampai hasil bertanda
> tangan. Lanjutan dari [`03-IMPLEMENTASI-FASE-1.md`](03-IMPLEMENTASI-FASE-1.md).

## 1. Alur yang sudah berjalan ujung-ke-ujung

```
Buat kegiatan (instrumen terbit & beku)
   → isi instrumen (skala rubrik / entri angka / ya-tidak / narasi)  ← server yang menghitung skor
   → wawancara pra & pasca-observasi
   → temuan: kelebihan, kelemahan, masalah, penyebab, prioritas, rekomendasi
   → kirim untuk dianalisis (ANALYZED)
   → finalisasi: checklist kelengkapan → snapshot beku → status COMPLETED
   → tanda tangan yang disupervisi (sidik jari kriptografis)
   → keberatan (opsional) → keputusan moderator → nilai dihitung ulang / hasil lama berlaku
   → pembukaan ulang (audited) bila perlu koreksi
```

## 2. Layanan backend baru

| Berkas | Tanggung jawab |
|---|---|
| `VisitLifecycleService` | Membuat kegiatan (nomor `SUP/2026/000123`), perpindahan status mengikuti graf `VisitStatus::allowedNext()`, pembatalan, pengiriman untuk dianalisis |
| `VisitAnswerService` | Menyimpan jawaban per indikator; **menurunkan skor dari definisi instrumen, bukan dari klien**; validasi `NA`, rentang & langkah angka, komentar wajib; menghitung agregat per bagian |
| `VisitFinalizationService` | Checklist kelengkapan, finalisasi + snapshot beku (checksum SHA-256), pembukaan ulang dengan riwayat revisi |
| `VisitConferenceService` | Wawancara pra/pasca-observasi, kesepakatan, refleksi, fokus berikutnya |
| `VisitFindingService` | Temuan analisis data (kelebihan→rekomendasi, prioritas) |
| `VisitEvidenceService` | Bukti foto/dokumen/tautan + visibilitas (pribadi/penilai/internal/publik) |
| `VisitAcknowledgmentService` | Tanda tangan hasil, keberatan, keputusan moderator |
| `VisitAccessService` | Hak lihat/isi/finalkan/tandai + pembatasan daftar sesuai keterlibatan |

**Prinsip keamanan nilai:** pengguna hanya mengirim pilihan/angka mentah
(`option_id`, `value_number`, `value_text`); skor, bobot, konversi 100,
predikat, dan nilai akhir seluruhnya dihitung server. Uji
`test_skor_dan_pilihan_dihitung_server_bukan_dari_klien` membuktikan klien
yang “menitipkan” skor 100 tetap tersimpan 3.

## 3. Aturan tata kelola yang ditegakkan

| Aturan | Konsekuensi |
|---|---|
| Pelaksana wajib terdaftar di registry supervisor | 422 dengan pesan jelas bila bukan supervisor |
| Rekan sejawat/mentor tidak pernah boleh finalisasi | 403 pada `POST /visits/{id}/finalize` |
| Yang disupervisi tidak boleh memfinalkan dirinya | 403 |
| Yang disupervisi hanya melihat hasilnya sendiri; guru lain tidak melihat sama sekali | daftar tersaring, detail 403 |
| Pemegang `supervisi.visit.view.all` (+ `supervisi.manage`, `supervisi.audit.view`) melihat seluruh sekolah | kemampuan `view_all` pada respons |
| Indikator wajib & wawancara pasca-observasi harus ada sebelum finalisasi | 422 berisi daftar checklist |
| Nilai akhir di bawah ambang wajib disertai temuan/rekomendasi | 422 (ambang `finding_required_below_percent`, bawaan 80) |
| Hasil yang sudah difinalkan bersifat beku | pengisian 422/403; koreksi hanya lewat pembukaan ulang |
| Keberatan hanya setelah tanda tangan, dalam `dispute_window_days` (bawaan 7 hari) | 422 |
| Pesan kegagalan izin dibedakan dari kegagalan isian | 403 = bukan haknya, 422 = datanya belum lengkap |

## 4. API baru (`/api/v1/supervisi/*`)

```
GET    visits                       daftar (filter status, open, mine, jenis, program, tanggal, pencarian)
POST   visits                       buat kegiatan dari instrumen terbit
GET    visits/{id}                  detail lengkap + abilities + checklist + riwayat
PATCH  visits/{id}                  ubah metadata (topik, waktu, ringkasan)
PUT    visits/{id}/answers          simpan jawaban (sebagian boleh) → nilai sementara
GET    visits/{id}/preview          hitung ulang tanpa menyimpan
POST   visits/{id}/submit           kirim untuk dianalisis
POST   visits/{id}/finalize         kunci hasil (checklist + snapshot)
POST   visits/{id}/reopen           buka ulang untuk koreksi
POST   visits/{id}/cancel           batalkan
DELETE visits/{id}                  hapus draf saja
GET    visits/{id}/conferences      daftar wawancara
PUT    visits/{id}/conferences/{PRE|POST}
GET|PUT visits/{id}/findings        temuan
POST   visits/{id}/evidence         unggah berkas / simpan tautan
DELETE visits/{id}/evidence/{id}
POST   visits/{id}/acknowledge      tanda tangan penerimaan
POST   visits/{id}/dispute          ajukan keberatan
POST   disputes/{id}/resolve        keputusan moderator
```

## 5. Antarmuka (React)

* `/supervisi/visits` — daftar kegiatan dengan tab **Perlu tindakan**, **Hasil
  final**, **Semua**, pencarian, tombol "Tandai hasil" untuk guru, dan modal
  pembuatan kegiatan (pegawai, pelaksana dari registry, instrumen terbit,
  jenis kegiatan, waktu, fokus).
* `/supervisi/visits/:id` — tiga tab:
  1. **Observasi & Instrumen** — kendali otomatis sesuai tipe indikator
     (tombol rubrik berwarna, entri angka dengan satuan/rentang/arah, ya—
     sebagian—tidak, pilihan ganda, teks, tanggal), komentar wajib otomatis
     di bawah ambang, catatan bukti, bukti foto/tautan, **bilah tindakan
     lengan** berisi jumlah terjawab, skor sementara, peringatan wajib belum
     diisi, tombol Simpan & Kirim untuk dianalisis.
  2. **Wawancara & Temuan** — wawancara pra/pasca, editor temuan
     (komponen, prioritas, kelebihan, kelemahan, masalah, penyebab,
     rekomendasi), panel finalisasi beserta checklist kelengkapan.
  3. **Hasil & Tanda Tangan** — ringkasan nilai (mentah/maksimum/pencapaian
     per bagian), temuan, tanda tangan penerimaan + keberatan, keputusan
     moderator, dan riwayat revisi.

## 6. Pengaturan modul baru (`SupSetting::DEFAULTS`)

| Kunci | Bawaan | Arti |
|---|---|---|
| `require_post_conference` | `true` | Wawancara pasca-observasi wajib sebelum finalisasi |
| `finding_required_below_percent` | `80` | Ambang wajib temuan/rekomendasi |
| `require_action_plan_before_finalize` | `false` | Diaktifkan pada Fase 3 begitu RTL tersedia |
| `dispute_window_days` | `7` | Tenggat pengajuan keberatan setelah hasil final |

## 7. Predikat kini memakai kode

Skala predikat diberi `code` (`A/B/C/K`, `AB/B/C/S/K`, dst.) dan penanda
`min_inclusive`, sehingga nilai yang **tepat di batas** (90, 80, 70) masuk ke
predikat yang benar sesuai pedoman. Bila sekolah memakai skala kustom tanpa
`code`, sistem otomatis memakai label sebagai pengenal.

## 8. Pengujian

`php -d memory_limit=1G vendor/bin/phpunit --filter Supervisi` → **48 tes,
297 asersi, hijau** (Fase 1: 32 · Fase 2: 16).

Fase 2 mencakup: alur penuh pembuatan→pengisian→wawancara→temuan→finalisasi,
komentar wajib di bawah ambang, penolakan nilai di luar rentang, skor dari
server (bukan klien), indikator wajib menahan pengiriman, penolakan finalisasi
tanpa wawancara pasca, wajib temuan untuk nilai rendah, **snapshot tidak
berubah walau instrumen direvisi**, pembukaan ulang, unggah/hapus bukti, guru
hanya melihat miliknya, guru lain 403, peer & diri sendiri tidak boleh
memfinalkan, supervisor tak terdaftar 422, keberatan sebelum tanda tangan
ditolak, tanda tangan ganda ditolak, keputusan moderator (ditolak→hasil lama,
diterima→dinilai ulang), pembatalan, dan penghapusan draf saja.

Uji antarmuka: pembuatan kegiatan, pengisian indikator, penyimpanan jawaban
(toast "Jawaban tersimpan"), dan tab hasil untuk kegiatan yang sudah final
(dinilai 88,33 · predikat "Sebagian Besar Lengkap" · checksum snapshot tampil).

## 9. Fase berikutnya

| Fase | Isi |
|---|---|
| 3 | RTL otomatis dari temuan, papan pemantauan, pengembangan kompetensi & coaching, kalkulator PKG/NKKS (koreksi kehadiran), kuadran SKP, tinjauan kinerja multi-sumber, PIP |
| 4 | Analitik tren indikator, laporan wajib Permendikbud 15/2018, CIPP, kalibrasi penilai, dokumen cetak |
| 5 | Audit menyeluruh, mode luring penuh (draf lokal + `client_uuid` sudah disiapkan), OpenAPI, panduan pengguna |

Tabel untuk seluruh fase tersebut sudah tersedia sejak Fase 1; Fase 3 tinggal
menambah logika, layar, dan pelaporan.
