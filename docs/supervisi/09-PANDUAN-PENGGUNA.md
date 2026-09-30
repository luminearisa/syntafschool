# Modul Supervisi — Panduan Pengguna

> Ditulis untuk pemakai di sekolah, bukan untuk programmer. Setiap bagian dimulai
> dengan pekerjaan nyata yang ingin diselesaikan, lalu langkah-langkahnya.

## 1. Siapa melihat apa (penting dibaca lebih dulu)

| Peran | Melihat | Catatan |
|---|---|---|
| **Guru / staf** | Hasil supervisi **dirinya**, RTL yang menjadi tanggung jawabnya, jam pengembangan & coaching-nya, PIP-nya | Analitik dan laporan otomatis menyempit ke dirinya. Nama rekan sejawat tidak muncul. |
| **Kepala sekolah / wakil / koordinator** | Seluruh kegiatan & nilai sekolah, RTL, penilaian kinerja, PIP, kalibrasi, evaluasi program | Dapat memfinalkan, membuka ulang, dan mengunduh laporan |
| **Pengawas / auditor** | Sama seperti kepala sekolah, **hanya membaca** + ekspor | Tidak dapat mengubah nilai |
| **Admin sekolah** | Seluruhnya + pengaturan modul (pilih supervisor, skala nilai) | Tidak dapat menghapus instrumen (jejak audit) |

Sekolah dapat mengizinkan guru melihat **ringkasan agregat** sekolah (rata-rata
per kompetensi, indikator terlemah) tanpa nama siapa pun — mati secara bawaan
(`Pengaturan → peer_summary_enabled`). Hasil supervisi pegawai lain **tidak
pernah** terbuka bagi rekan sejawat.

## 2. Menyiapkan tahun supervisi (admin & kepala sekolah)

1. **Instrumen** — `Akademik → Supervisi → Instrumen`. Pilih dari 22 instrumen
   bawaan (observasi kelas, PKG, NKKS, supervisi tendik, dll.) atau susun
   sendiri: bagian → indikator → opsi rubrik (mis. 1–4) beserta bobotnya.
   Terbitkan versi bila sudah lengkap; versi terbit tidak dapat diubah lagi —
   koreksi dilakukan dengan **Klon versi**.
2. **Penilai** — `Supervisor`. Daftarkan pegawai yang berwenang menilai
   (kepala sekolah, wakasek, guru senior sebagai mentor), jenisnya, dan apakah
   ia boleh memfinalkan.
3. **Program & penugasan** — `Program` menentukan target tahunan; `Penugasan`
   menghubungkan penilai dengan yang dinilai (termasuk berapa kali harus
   disupervisi). Penugasan mencegah penilaian diri sendiri.
4. **Jadwal** — `Jadwal` menyusun kunjungan; supervisor menerima pengingat H-3
   otomatis (email/notifikasi aplikasi).

## 3. Melaksanakan satu kunjungan kelas (supervisor)

1. Buka `Supervisi → Kegiatan → Tambah`. Pilih guru, instrumen, teknik
   (walkthrough/observasi penuh), tanggal.
2. **Wawancara pra-observasi** (opsional sesuai pengaturan) untuk menyepakati
   fokus.
3. **Observasi** — isi instrumen per indikator:
   * pilihan rubrik → tekan salah satu opsi;
   * angka (mis. "jumlah peserta didik yang aktif") → tulis angkanya;
   * ya/tidak → pilih;
   * **Tidak relevan** → indikator dikeluarkan dari perhitungan (bukan bernilai 0).
   Nilai di bawah ambang **wajib** disertai catatan bukti (aturan sekolah).
4. **Wawancara pasca-observasi** — catat kesepakatan dan refleksi guru. Ini
   prasyarat finalisasi.
5. **Temuan** — tulis kelemahan, penyebab, dan rekomendasi. Nilai akhir di bawah
   ambang mewajibkan minimal satu temuan berrekomendasi.
6. **Finalkan** — nilai tersegel: tersimpan sebagai snapshot beserta checksum,
   sehingga angka tidak pernah berubah diam-diam. Setelah final, hasil tidak
   dapat diubah kecuali dibuka ulang oleh pemegang izin (tercatat di audit).

### 3.1 Observasi di ruang tanpa sinyal (mode luring)

1. Sebelum masuk kelas: buka kegiatan → panel **Mode Luring** →
   **Formulir kosong (PDF)** → cetak.
2. Di kelas: tulis **huruf pilihan** (A/B/C/…) pada kolom Nilai, atau angkanya
   bila instrumen meminta angka. Tulis `NA` untuk indikator yang tidak relevan.
3. Kembali ke jaringan: masukkan nilai pada aplikasi. Bila sinyal tetap tidak
   ada, tekan **Simpan** — jawaban ditahan di perangkat (penanda *"n jawaban
   menunggu dikirim"*).
4. Buka panel **Mode Luring → Kirim antrean**. Aplikasi melaporkan berapa yang
   tersimpan, sudah ada, ditolak, dan **perlu diperiksa**.
   * Kirim dua kali aman: tidak ada nilai yang terhitung ganda.
   * Bila ada *konflik* (nilai sudah diisi lewat aplikasi setelah perangkat
     merekam), nilai di server **dipertahankan** dan baris itu diminta
     diperiksa — mesin tidak menimpa keputusan supervisor.

## 4. Menindaklanjuti temuan (RTL)

* Papan `Tindak Lanjut (RTL)` menampilkan kartu terlewat tenggat, mendekati
  tenggat, berjalan, menunggu verifikasi.
* RTL dapat lahir otomatis dari temuan (`+ RTL dari temuan`) atau disusun manual.
* Guru melaporkan kemajuan per butir (persen + catatan/bukti); supervisor
  memverifikasi setelah pekerjaan selesai. Butir terlambat memicu pengingat
  harian otomatis.

## 5. Menilai kinerja (kepala sekolah)

1. `Penilaian Kinerja → Tambah`. Pilih jenis: **PKG** (guru mapel/BK),
   **NKKS** (kepala sekolah), **SKP** (e-kinerja bulanan/semester), **360°**
   (multi-sumber berbobot: atasan, rekan, diri, peserta didik).
2. Isi skor per indikator. Untuk PKG, isi juga **koreksi kehadiran** (hari tanpa
   keterangan) — rumusnya ditampilkan pada halaman *Acuan rumus*.
3. Tekan **Hitung** untuk melihat nilai akhir, predikat, dan angka kredit.
   Angka dihitung **di server**, tidak dapat dimanipulasi dari peramban.
4. **Terbitkan** bila sudah benar. Nilai yang sudah terbit hanya dapat dibuka
   ulang oleh pemegang izin, dengan catatan audit.

Nilai di bawah ambang tanpa PIP akan muncul pada dasbor analitik sebagai
*perlu ditindaklanjuti* — inilah yang memicu PIP.

## 6. PIP, coaching, dan pengembangan

* **PIP** (Personal Improvement Plan) — untuk pegawai yang belum memenuhi
  standar: alasan, standar yang diharapkan, dukungan yang disediakan, konsekuensi
  yang disampaikan **secara tertulis**, butir sasaran + tenggat, dan tinjauan
  berkala. Hasil akhir PIP: membaik / tetap / perlu tindakan kepegawaian.
  Pegawai menandai **mengetahui** PIP-nya — jejak persetujuan tersimpan.
* **Coaching** — sesi pembinaan terstruktur: fokus, komitmen, dan jadwal tindak
  lanjut yang diingatkan otomatis.
* **Pengembangan** — diklat/sertifikasi: jam, biaya, nomor sertifikat. Jam
  pengembangan dihitung otomatis pada analitik (mis. untuk kenaikan pangkat).

## 7. Membaca analitik & menyiapkan laporan wajib

`Analitik & Laporan` menjawab pertanyaan yang biasa muncul di rapat:

| Kartu | Artinya |
|---|---|
| **Cakupan supervisi** | Berapa persen guru/tendik sudah disupervisi, dan **siapa** yang belum |
| **Rata-rata nilai & sebaran predikat** | Mutu pelaksanaan pembelajaran; lonjakan/penurunan per bulan |
| **Indikator terlemah** | Kompetensi yang paling sering bernilai rendah → dasar peta kebutuhan pelatihan |
| **RTL tepat waktu** | Disiplin tindak lanjut; berapa butir terlambat |
| **Butuh PIP** | Nilai di bawah ambang yang belum punya rencana perbaikan |
| **Kelonggaran penilai** | Supervisor yang rata-ratanya menyimpang jauh → bahan kalibrasi |

**Laporan wajib Permendikbud 15/2018** tersedia dalam enam jenis
(program, hasil per pegawai, tindak lanjut, analitik indikator, rekap penilaian,
kalibrasi) dan dapat dipratinjau sebelum diunduh sebagai **CSV** (rapi dibuka di
Excel), **XLSX**, atau **PDF**. Setiap dokumen mendapat kode verifikasi dan
**sidik digital**; PDF memuat QR yang dapat dipindai pihak luar
(`/verifikasi-dokumen/{kode}`) untuk membuktikan salinan cetak itu asli dan tidak
diubah.

## 8. Kalibrasi penilai (agar kelas tidak dinilai dengan dua ukuran)

1. `Kalibrasi Penilai → Sesi Baru`. Tetapkan **ambang selisih** yang disepakati
   sekolah (mis. 1,0 poin pada skala 4).
2. Masukkan skor tiap penilai untuk indikator yang sama atau kasus bersama.
3. Tabel menampilkan rata-rata dan **selisih maks−min**; indikator yang melewati
   ambang disorot. Bahan tambahan `Kesenjangan pada Kegiatan Nyata` memperlihatkan
   indikator yang sama dinilai berbeda antar supervisor pada kunjungan nyata.
4. Finalisasi menuntut **kesepakatan tertulis** bila ada selisih melewati ambang.
   Sesudah final, sesi terkunci.

## 9. Mengevaluasi program supervisi (CIPP)

`Evaluasi Program (CIPP)` menilai program dari empat sisi — **Context** (apakah
sasarannya relevan), **Input** (kesiapan penilai/instrumen/jadwal), **Process**
(cakupan & mutu pelaksanaan), **Product** (perubahan dan hasil). Isi skor 0–100,
narasi bukti, dan pemangku kepentingan; aplikasi menyertakan **bukti otomatis**
dari data supervisi (jumlah kegiatan final, cakupan, rata-rata nilai, RTL tepat
waktu, jam pengembangan). Finalisasi menuntut rekomendasi.

## 10. Pemantauan kesehatan data (admin)

* `GET /api/v1/supervisi/health` atau perintah `php artisan supervisi:health`
  memeriksa sembilan hal yang tidak menimbulkan galat di layar tetapi membuat
  angka salah: kegiatan final tanpa jawaban/nilai, jawaban dari versi instrumen
  lain, versi terbit tanpa indikator, kalibrasi melewati ambang tanpa
  kesepakatan, PIP tanpa butir, berkas dokumen hilang, supervisor nonaktif yang
  masih ditugaskan, dan skala nilai yang tidak lengkap.
* Pemeriksaan berjalan otomatis tiap Senin pagi dan hanya **melapor** — tidak
  mengubah data.
* `php artisan supervisi:prune` melaporkan berkas ekspor lama; penghapusan
  menuntut `--force` dan **tidak pernah** menghapus baris riwayat, kode
  verifikasi, atau sidik digitalnya.

## 11. Pertanyaan yang sering diajukan

**Nilai saya sudah benar, kok tidak bisa diubah?**
Hasil yang sudah difinalkan bersifat beku supaya dapat dipertanggungjawabkan.
Minta supervisor/kepala sekolah membuka ulang dengan alasan; pembukaan itu
tercatat di jejak audit.

**Kenapa saya tidak melihat nilai rekan sejawat?**
Itu memang kebijakan privasi modul (dan standar praktik supervisi). Sekolah
dapat mengaktifkan ringkasan agregat tanpa nama bila memang diperlukan.

**Jawaban saya hilang saat sinyal putus?**
Tekan **Simpan** — jawaban ditahan di perangkat. Jika peramban dibersihkan
(data situs dihapus) atau berpindah perangkat, antrean perangkat ikut hilang;
karena itu sebaiknya kirim segera setelah kembali ke jaringan.

**Di mana bukti foto/video?**
Lampirkan sebagai **Bukti** pada kegiatan (daring). Untuk menjaga penyimpanan,
unggahan besar sebaiknya dilakukan setelah kembali ke jaringan sekolah.
