# Mobile — Fase 4

## Isi
| Fitur | Peran | Catatan |
|---|---|---|
| Ujian CBT (`/ujian`) | siswa | daftar sesi, token, pemutar soal (pilihan ganda, multi-pilih, benar/salah, isian singkat, angka, esai, menjodohkan, mengurutkan), tandai ragu, hitung mundur dari jam server, simpan otomatis |
| Unduh rapor PDF | wali & siswa | tombol di Akademik → Rapor |
| Dasbor manajemen (`/dasbor`) | karyawan | memakai `GET dashboard/overview`; tiap bagian (siswa, absensi, keuangan) muncul sesuai izin |

## Pemantauan CBT (sisi aplikasi)
- Heartbeat tiap 25 detik: menyinkronkan batas waktu dengan server; bila server menutup percobaan (dibatalkan proktor / habis), ujian diakhiri di aplikasi.
- Meninggalkan aplikasi/tab → insiden `tab_blur` dilaporkan ke proktor (mempengaruhi skor integritas).
- Waktu habis → dikumpulkan otomatis; jawaban yang gagal terkirim diantre dan dicoba ulang.
- Aturan inti (token, jendela waktu, kuota percobaan, kunci jawaban tak pernah dikirim) tetap di `CbtExamService`; aplikasi tidak menentukan nilai.
- Batasan: pemantauan di aplikasi hanya mendeteksi keluar-aplikasi; tidak ada kunci layar/kiosk (butuh MDM) dan tidak ada tangkapan layar/kamera.

## Backend
- `config/mobile.php`: siswa boleh `cbt/my/sessions`, `cbt/sessions/*/start`, `cbt/attempts/*`. Pola terakhir juga mencakup `…/void`, tetapi rute itu butuh izin `cbt.proctor` yang tidak dimiliki siswa (dites).
- `GET mobile/children/{student}/report-cards/{card}/pdf` — kepemilikan anak (`assertChild`), hanya rapor `published` milik anak itu (404 selain itu), view PDF yang sama dengan web.
- Unduhan: web lewat blob, native lewat `expo-file-system` + `expo-sharing` (`src/lib/download.ts`).

## Belum dikerjakan (diusulkan Fase 5)
PPDB (pendaftaran/status calon siswa), Supervisi, pengajuan cuti & slip gaji, pemindai kamera (QR presensi/aset), UKS untuk wali, Tahfiz/Ekskul versi staf, ulasan jawaban CBT setelah rilis (`attempts/{id}/review` sudah diizinkan).
Tes `MobilePhase4Test` belum dijalankan (PHP 8.3 di sandbox).
