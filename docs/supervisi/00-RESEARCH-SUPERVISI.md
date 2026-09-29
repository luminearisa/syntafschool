# Riset Supervisi — Dasar Ilmiah, Regulasi, Instrumen & Praktik Terbaik

> Dokumen ini adalah **hasil riset** sebelum membangun **Modul Supervisi**
> (supervisor → yang disupervisi: guru **dan** karyawan/tenaga kependidikan).
> Isinya: konsep, dasar hukum Indonesia, model & pendekatan, teknik, **daftar
> indikator instrumen yang benar-benar dipakai sekolah**, cara penskoran,
> praktik penilaian kinerja pegawai (PKG/SKP/e-Kinerja/PMM), praktik internasional
> (clinical supervision, Marzano, Danielson, Glickman), fitur perangkat lunak
> yang sudah ada di pasar, jebakan umum, dan **implikasi desain** untuk modul.
>
> Rangkaian desain teknisnya ada di
> [`01-BLUEPRINT-MODUL-SUPERVISI.md`](01-BLUEPRINT-MODUL-SUPERVISI.md).
>
> Semua klaim di bawah dapat ditelusuri ke sumber pada §12.

---

## 1. Ringkasan eksekutif (apa yang harus dipahami sebelum ngoding)

1. **Supervisi ≠ penilaian.** Di literatur Indonesia berulang ditegaskan
   “supervisi bukan menilai tapi mengumpulkan data untuk **menolong guru
   mengatasi kesulitan**”. Penilaian kinerja (PKG/SKP) adalah **produk
   turunan** yang memakai data supervisi, bukan tujuannya. Modul harus
   memisahkan dua hal ini sejak awal.
2. Ada **dua keluarga supervisi** yang wajib didukung modul:
   **supervisi akademik** (guru: perencanaan → pelaksanaan → penilaian →
   tindak lanjut pembelajaran) dan **supervisi manajerial** (tenaga
   kependidikan: tupoksi administrasi, perpustakaan, laboratorium, sarpras,
   kebersihan, keamanan).
3. Supervisi yang baik berbentuk **siklus**, bukan kejadian tunggal:
   *program → jadwal → pra-observasi → observasi → analisis data → pasca-observasi
   (refleksi) → umpan balik → Rencana Tindak Lanjut (RTL) → pelaksanaan RTL →
   evaluasi dampak → laporan*. Model klinis (Goldhammer/Cogan), *lesson study*
   (plan–do–see), dan tahapan Praktik Kinerja PMM (diskusi persiapan → observasi
   → diskusi tindak lanjut → upaya tindak lanjut → refleksi) semuanya varian
   siklus yang sama.
4. Sekolah Indonesia sudah punya **instrumen baku** dengan skor dan ambang yang
   spesifik: skala **1–4** (perangkat & pelaksanaan pembelajaran), **0–2**
   (telaah RPP 36 butir), **1–4** (penilaian hasil belajar), konversi
   `nilai = skor perolehan / skor maksimum × 100`, dengan kriteria **Amat baik
   (90 < A ≤ 100), Baik (80 < B ≤ 90), Cukup (70 < C ≤ 80), Kurang (≤ 70)**;
   sedangkan **PKG** memakai konversi berbeda (`/56 × 100`) dengan sebutan
   **Amat baik 91–100 (AK 125%), Baik 76–90 (100%), Cukup 61–75 (75%),
   Sedang 51–60 (50%), Kurang ≤ 50 (25%)**.
   → Artinya modul **tidak boleh** mengunci satu rumus: rumus, bobot, dan ambang
   harus **dapat dikonfigurasi per sekolah/per instrumen** dan disimpan sebagai
   *snapshot* saat finalisasi.
5. Sekolah juga dituntut **modalitas observasi yang berbeda-beda**: kunjungan
   kelas singkat (*walkthrough*), observasi kelas penuh (dengan instrumen),
   supervisi klinis individual, supervisi sejawat/kolaboratif, *lesson study*,
   hingga *coaching* 1-on-1. Produk luar negeri pun memisahkan **walkthrough
   (cepat, untuk umpan balik)** dari **formal evaluation (lengkap, untuk
   dokumentasi/kepegawaian)**. Modul butuh konsep **jenis kegiatan** yang
   menentukan panjang instrumen dan bobotnya.
6. **Bukti dukung kini digital.** Pengelolaan Kinerja guru/kepala sekolah
   (PMM) berjalan per semester dengan unggahan bukti dukung, dan **terintegrasi
   ke e-Kinerja BKN**; kepala sekolah berperan sebagai **asesor sekaligus
   coach**. Modul supervisi harus bisa menjadi **sumber bukti dukung** itu
   (bukan sistem terpisah yang menambah pekerjaan admin).
7. Penilaian pegawai (non-guru) memakai pola **kinerja (hasil kerja) × perilaku
   kerja** (SKP/e-Kinerja) dan pola HR umum: **bobot kriteria + skala 1–5 +
   PIP/PIP-ekuivalen** untuk pegawai yang belum memenuhi standar. Modul perlu
   mendukung **bobot**, **multi-penilai (atasan/rekan/diri sendiri)**, dan
   **rencana perbaikan kinerja**.
8. Jangan mengulang kesalahan umum: supervisi tanpa instrumen, tanpa RTL,
   hanya untuk memenuhi dokumen akreditasi, distribusi predikat yang tidak
   wajar, dan tidak ada hak tanggapan dari yang disupervisi. Ini semua harus
   dicegah **oleh desain sistem**, bukan sekadar imbauan.

---

## 2. Landasan hukum & kebijakan (Indonesia)

| Dasar | Relevansi langsung ke modul |
|---|---|
| **UU No. 20/2003 (Sisdiknas)** Ps. 1(5) & Ps. 39 | Definisi **tenaga kependidikan** (menunjang penyelenggaraan pendidikan) → objek supervisi tendik |
| **PP No. 19/2017** Ps. 54(1) | “Beban kerja kepala satuan pendidikan **sepenuhnya** untuk melaksanakan tugas manajerial, pengembangan kewirausahaan, dan **supervisi kepada Guru dan tenaga kependidikan**” |
| **Permendikbud No. 6/2018** Ps. 15 | Tugas pokok kepala sekolah mencakup supervisi; syarat dan mekanisme penugasan |
| **Permendikbud No. 15/2018** | Rincian beban kerja kepala sekolah; supervisi guru & tendik = **6–10 jam kerja/minggu** (setara 4–6 JTM); bukti fisik: *program supervisi, laporan pelaksanaan & hasil supervisi guru/tendik, laporan evaluasi* → **inilah daftar dokumen minimum yang harus bisa dicetak modul** |
| **Permendiknas No. 12/2007** | Standar **pengawas sekolah**: 6 dimensi kompetensi (kepribadian; supervisi manajerial; supervisi akademik; evaluasi pendidikan; penelitian & pengembangan; kompetensi sosial) → supervisi pengawas terhadap kepala sekolah/guru |
| **Permendiknas No. 13/2007** | Standar kepala sekolah (kepribadian, manajerial, kewirausahaan, supervisi, sosial) → instrumen penilaian kinerja kepala sekolah (NKKS) |
| **Permendiknas No. 16/2007** | 4 komponen kompetensi guru (pedagogik, kepribadian, sosial, profesional) → kerangka inti instrumen guru |
| **Permendiknas No. 24/2008** | Standar **tenaga administrasi sekolah**: kepala tenaga administrasi, pelaksana urusan, petugas layanan khusus; kompetensi **kepribadian, sosial, teknis, manajerial** → kerangka instrumen tendik |
| **Permenneg PAN & RB No. 16/2009** (+ Pedoman PKG) | **PKG**: 14 kompetensi (guru mapel/kelas, maks 56) & 17 kompetensi (BK/konselor, maks 68), tugas tambahan (kepala sekolah maks 24, wakasek 20, dst.); konversi ke skala 100; sebutan + persentase angka kredit; koreksi ketidakhadiran; penilai/moderator; **wajib diberitahukan** hasilnya kepada yang dinilai |
| **PermenPANRB No. 6/2022 + SE BKN No. 18/2024** | **SKP** & evaluasi kinerja ASN: hasil kerja × perilaku kerja → **kuadran kinerja** → predikat; periode **bulanan/triwulanan/tahunan**; penilaian bawahan & **umpan balik rekan kerja**; **pembinaan kinerja** (bimbingan/konseling) bila tidak ada kemajuan |
| **Panduan Substansi & Teknis Pengelolaan Kinerja Guru/Kepala Sekolah (PMM, 2024–2025)** | Tahapan Praktik Kinerja: **A. Diskusi Persiapan → B. Observasi Kinerja → C. Diskusi Tindak Lanjut → D. Upaya Tindak Lanjut → E. Refleksi Tindak Lanjut**; fokus **1 indikator**; bukti dukung digital; **terintegrasi e-Kinerja BKN**; kepala sekolah = **asesor & coach** |
| **Bahan ajar GTK “Supervisi Penilaian Pembelajaran”** | Tahapan operasional supervisi perencanaan–pelaksanaan–analisis–tindak lanjut penilaian; rubrik resmi penilaian perangkat penilaian (skor 1–4 + kriteria A/B/C/K) |

**Konsekuensi produk:** karena PP 19/2017 + Permendikbud 15/2018 menyebut
*“program supervisi, laporan pelaksanaan, dan laporan evaluasi”* sebagai bukti
kinerja kepala sekolah, modul harus menghasilkan **ketiga dokumen itu** dengan
sekali klik, dan **relate ke penilaian kinerja** kepala sekolah sendiri.

---

## 3. Konsep & prinsip

* **Supervisi akademik** — pembinaan & penilaian guru agar kualitas pembelajaran
  (perencanaan, pelaksanaan, penilaian, tindak lanjut) meningkat, berdampak pada
  hasil belajar peserta didik.
* **Supervisi manajerial** — pemantauan, pembinaan, pembimbingan pada aspek
  **pengelolaan & administrasi sekolah** yang mendukung pembelajaran (tendik).
* **Supervisi klinis** — supervisi individual yang mendalam & terstruktur
  (pra-observasi, observasi, analisis, pasca-observasi) untuk perbaikan mengajar
  tertentu.
* **Prinsip** yang berulang di sumber: demokratis, kolaboratif, kooperatif,
  kekeluargaan, konstruktif/kreatif, objektif (berbasis data & instrumen),
  terpadu dengan program sekolah, kontinu/berkelanjutan, menyeluruh, serta
  **berorientasi pertumbuhan, bukan penghukuman**.

---

## 4. Model & pendekatan (yang harus tersedia sebagai pilihan modul)

### 4.1 Model supervisi klinis
| Sumber | Tahapan |
|---|---|
| **Goldhammer (1969)** | 5 tahap: *pre-observation conference → classroom observation → analysis & strategy → post-observation conference → post-conference analysis* |
| **Cogan (1973)** | 8 tahap: membangun hubungan → perencanaan bersama guru → merencanakan strategi observasi → mengamati → menganalisis proses belajar-mengajar → merencanakan strategi pertemuan → pertemuan → perencanaan kembali (*renewed planning*) |
| **Acheson & Gall (1987)** | Penyederhanaan menjadi 3: *planning conference → classroom observation → feedback conference* |
| **Mosher & Purpel (1972)** | 5 tahap (dipakai banyak kajian Indonesia: pra-observasi → observasi → analisis & strategi → pertemuan pasca-observasi → analisis pasca-pertemuan) |

### 4.2 Pendekatan perkembangan (*developmental supervision*, Glickman)
Empat gaya interpersonal yang **dipilih sesuai tingkat perkembangan/kompetensi
guru**, bukan selera supervisor:
1. **Directive control** — guru baru/bermasalah berat; supervisor mengarahkan.
2. **Directive informational** — supervisor menjelaskan pilihan, guru memilih.
3. **Collaborative** — kapasitas menengah; keputusan bersama.
4. **Nondirective** — guru sudah mandiri; supervisor memfasilitasi refleksi.

→ Modul perlu menyimpan **pendekatan yang disepakati** per kegiatan supervisi
dan menampilkannya sebagai konteks saat observasi (agar supervisor tidak
seragam memperlakukan semua guru).

### 4.3 *Lesson study* (plan–do–see)
Pembinaan kolaboratif berkelanjutan: guru model merancang (plan) → mengajar
diamati sejawat (do) → refleksi bersama (see). Cocok untuk **supervisi sejawat /
kolaboratif**, berbeda dari supervisi atasan–bawahan.

### 4.4 Praktik Kinerja PMM (coaching)
Siklus A–E (§2) dengan kepala sekolah sebagai **asesor + coach**, fokus pada
**1 indikator** per periode. Modul harus bisa menandai kegiatan supervisi
sebagai *coaching* (bukan penilaian) dan menyimpan komitmen upaya tindak lanjut.

### 4.5 Kerangka penilaian internasional (untuk desain rubrik bertingkat)
* **Marzano Focused Teacher Evaluation** — 5 level: *Not Using (0), Beginning (1),
  Developing (2), Applying (3), Innovating (4)*, dengan bukti pada **dampak ke
  siswa**, bukan hanya perilaku guru.
* **Danielson** — 4 domain, 22 komponen, 4 level (Unsatisfactory → Distinguished).
* **TAP/NIET** — rubrik observasi + pertumbuhan siswa + kompensasi.
* Bedakan **walkthrough** (singkat, 1–2 fokus, umpan balik cepat) dari
  **formal evaluation** (instrumen lengkap, untuk dokumentasi kepegawaian).

---

## 5. Teknik supervisi (master data “jenis kegiatan”)

**Individual (5–9 teknik):** kunjungan kelas (*classroom visitation*),
observasi kelas (lebih lengkap tahapannya: persiapan → pelaksanaan → penutupan →
penilaian hasil → tindak lanjut), pertemuan individual (percakapan pribadi),
kunjungan antarkelas (*intervisitation*), menilai diri sendiri
(*self-evaluation checklist*), **portofolio supervisi**, **penelitian tindakan
(action research)**, **peer coaching**, **mentoring & induksi guru baru**.

**Kelompok (18 teknik):** pertemuan orientasi guru baru, rapat guru, studi
kelompok antarguru, tukar pengalaman (*sharing*), lokakarya, diskusi panel,
seminar, simposium, demonstrasi mengajar, perpustakaan jabatan, buletin
supervisi, *directed reading*, kursus, organisasi profesi, laboratorium
kurikulum, kunjungan sekolah lain, dsb.

**Pemilihan teknik** dipengaruhi tujuan, situasi/ kondisi, dan **faktor guru**
(mis. masalah khusus → teknik individual).

**Aspek yang diamati pada observasi kelas (daftar klasik):** usaha & aktivitas
guru–siswa, penggunaan media, variasi metode, ketepatan media–materi,
ketepatan metode–materi, reaksi mental siswa.

---

## 6. Instrumen baku Indonesia (isi lengkap — dasar seeding modul)

### 6.1 Rekaman instrumen yang diambil sebagai acuan
| Instrumen | Struktur | Skala | Nilai maksimum | Ambang |
|---|---|---|---|---|
| **Telaah perangkat pembelajaran** (administrasi guru) | 12 komponen | 4/3/2/1 | 48 | 90/80/70 |
| **Telaah RPP (K-13/ Kurikulum Merdeka)** | 36 butir (A identitas 1; B komponen utama 1; C1 KI/KD/IPK 6; C2 tujuan 4; C3 materi 4; C4 metode 5; C5 media 4; C6 langkah 4; C7 penilaian 7) | 0/1/2 | 72 | 90/80/70 |
| **Observasi pelaksanaan pembelajaran** | 40 butir: A pendahuluan 5; B1 penguasaan materi 4; B2 implementasi 13; B3 media & sumber belajar 6; B4 interaksi 5; B5 bahasa 2; C penutup 5 | Ya/Tidak (ada varian 1–4) | 40 (atau 4×butir) | 90/80/70 |
| **Supervisi penilaian hasil belajar** | 11 aspek: dokumen KKM; buku nilai; jenis tes (PH/PTS/PAS); penilaian sikap (observasi/jurnal, diri, antarteman); pengetahuan (tulis, lisan, penugasan); keterampilan (unjuk kerja, proyek, produk, portofolio); analisis PH; remedial; pengayaan; analisis butir soal; bank soal | 4/3/2/1 | 20 (varian 44) | 90/80/70 |
| **Rubrik penilaian perangkat penilaian (GTK)** | 5 komponen: teknik penilaian dalam RPP, penilaian sikap, pengetahuan, keterampilan (masing-masing: 3 komponen = skor 4 … tidak ada = 1), dokumen KKM (mempertimbangkan intake/daya dukung/kompleksitas: IPK=4, KD=3, mapel=2, tidak=1) | 1–4 | 20 | 90/80/70 |
| **Dampak/outcome supervisi** | motivasi belajar, nilai hasil belajar, kualitas pembelajaran, kompetensi guru, prestasi sekolah (+“dst.”) | 1–4 | 20–24 | 90/80/70 |
| **Wawancara pra-pengamatan** | 8 pertanyaan (materi, metode & alasan, alat/bahan, tahapan kegiatan, persiapan tertulis, materi sulit menurut perkiraan siswa, kompetensi yang diharapkan, hal yang perlu perhatian khusus) | naratif | — | — |
| **Wawancara pasca-pengamatan** | 9 pertanyaan (pendapat setelah mengajar, kesesuaian dengan rencana, hal memuaskan, ketercapaian tujuan, kesulitan siswa, kesulitan guru, alternatif solusi, identifikasi hal mantap vs perlu peningkatan, rencana pertemuan berikutnya) | naratif | — | — |
| **Analisis data supervisi** | per komponen: kelebihan, kelemahan, masalah yang ditemukan, faktor penyebab, **prioritas perbaikan** | naratif | — | — |
| **Rencana Tindak Lanjut (RTL)** | nama guru, mapel, hasil supervisi, bentuk tindak lanjut, waktu/tempat; legenda komponen (RPP/pelaksanaan/penilaian) | naratif+status | — | — |
| **Rekapitulasi umpan balik** | keberhasilan, hal yang harus diperbaiki, rekomendasi/alternatif pemecahan | naratif | — | — |
| **PKG** | 14 kompetensi (maks 56) / 17 kompetensi BK (maks 68) / tugas tambahan (kepsek 24, wakasek 20, …) | 1–4 | 56/68/24 | sebutan 91/76/61/51 |
| **NKKS (kinerja kepala sekolah)** | skor rata-rata instrumen kepsek | 6–24 | 24 | 91/76/61/51 |
| **Koreksi kehadiran PKG** | `NPK = Nilai PKG × (100% − (hari alpa tidak berketerangan / 46) × 100%)` | rumus | — | — |
| **Instrumen tendik** | per jabatan: kepala tenaga administrasi; pelaksana urusan persuratan & pengarsipan; pelaksana urusan sarana & prasarana; petugas layanan khusus (kebersihan/keamanan); kepala perpustakaan; kepala laboratorium — dinilai terhadap **tupoksi & kompetensi** masing-masing | umumnya 1–4 | per jabatan | 90/80/70 (varian rubrik pengembangan instrumen 86–100 / 76–85,99) |
| **Supervisi manajerial (8 aspek administrasi)** | administrasi kurikulum & pembelajaran; kepegawaian; kesiswaan; sarana & prasarana; keuangan & pembiayaan (7K/SIM); humas & kerja sama komite; perpustakaan; program & pelaksanaan rencana kerja sekolah | ada/tidak + skor | per aspek | 90/80/70 |

### 6.2 Item contoh yang layak menjadi template bawaan
* **Perangkat pembelajaran (12):** Program Tahunan, Program Semester, Silabus,
  RPP/Modul Ajar, Kalender Pendidikan, Jadwal Pelajaran, Agenda Harian,
  Daftar Nilai (sikap, pengetahuan, keterampilan), Dokumen KKM/KKTP,
  Presensi Peserta Didik, Buku Pedoman Guru, Buku Teks Pelajaran.
* **Telaah RPP — C6 langkah pembelajaran:** memuat pendahuluan/inti/penutup;
  pendahuluan memuat pengkondisian, kegiatan religius, apersepsi, penyampaian
  tujuan, urutan kegiatan, penilaian; inti mencirikan *active learning*,
  interaksi multi-arah, menyenangkan & menantang, integrasi PPK/literasi/4C;
  penutup memuat refleksi, umpan balik, tindak lanjut/tugas, informasi pertemuan
  berikutnya.
* **Observasi pelaksanaan — inti (contoh nyata):** menyesuaikan materi dengan
  tujuan; mengaitkan materi dengan pengetahuan lain/kehidupan nyata; menyajikan
  sistematis (mudah→sulit, konkret→abstrak); melaksanakan pembelajaran interaktif
  multi-arah; inspiratif & multifaset untuk HOTS; menantang & memunculkan
  berpikir kritis; memotivasi partisipasi bermakna; memberi ruang prakarsa,
  kreativitas, kemandirian; memperkuat budaya literasi; teknik bertanya tanpa
  *chorus answer*; pertanyaan pelacak (*probing*); mendorong *inquisitiveness*;
  TPACK; keterampilan media & sumber belajar; melibatkan peserta didik dalam
  pemanfaatan media/sumber; menghasilkan pesan menarik; menumbuhkan partisipasi
  aktif; respons positif; sikap terbuka; hubungan kondusif; keceriaan &
  antusiasme; bahasa Indonesia baik/kontekstual; pilihan kata mudah dipahami.
* **Penilaian hasil belajar:** KKM; buku nilai; PH/PTS/PAS; sikap
  (observasi/jurnal, penilaian diri, antarteman); pengetahuan (tulis, lisan,
  penugasan); keterampilan (unjuk kerja, proyek, produk, portofolio);
  analisis PH; remedial; pengayaan; analisis butir soal; bank soal.
* **Telaah soal (bonus yang sering diminta sekolah):** kisi-kisi (indikator,
  materi, konstruksi), soal pilihan ganda (18 aspek: materi, konstruksi,
  bahasa), soal uraian (14 aspek), dan **matriks level kognitif C1–C6 / L1–L3
  (HOTS)** untuk menghitung komposisi HOTS.

### 6.3 Ambang & konversi (harus konfigurabel)
```text
Nilai (skala 100) = skor perolehan / skor maksimum × 100
Kriteria bawaan:  A: 90 < A ≤ 100 | B: 80 < B ≤ 90 | C: 70 < C ≤ 80 | K: ≤ 70

PKG: Nilai PKG = skor / skor_maks × 100
  Amat baik 91–100 (angka kredit 125%)   Baik 76–90 (100%)
  Cukup 61–75 (75%)                      Sedang 51–60 (50%)   Kurang ≤ 50 (25%)
  NPK = Nilai PKG × (100% − (hari alfa / 46) × 100%)
NKKS = total skor rata-rata / 24 × 100   (kepala sekolah)

SKP/e-Kinerja: predikat = f(rating hasil kerja, rating perilaku kerja) → kuadran
  (diatas/sesuai/dibawah ekspektasi pada masing-masing sumbu)
```

---

## 7. Dari supervisi ke penilaian kinerja pegawai (jalur HR)

| Lapisan | Sumber | Yang perlu didukung modul |
|---|---|---|
| Penilaian kinerja guru ASN | PKG (PermennegPANRB 16/2009) + Pengelolaan Kinerja PMM 2024–2025 → **e-Kinerja BKN** | Perhitungan PKG (14/17 kompetensi, tugas tambahan), koreksi kehadiran, konversi sebutan → % angka kredit, unggah bukti dukung, rekap periode semester/tahun |
| Penilaian kinerja ASN umum | SKP + perilaku kerja (PermenPANRB 6/2022, SE BKN 18/2024) | Rating hasil kerja & perilaku kerja, kuadran, predikat, evaluasi periodik (bulanan/triwulanan/tahunan), **pembinaan kinerja** |
| Penilaian pegawai swasta/tendik non-ASN | praktik HR umum | **Kriteria berbobot**, skala **1–5** dengan anchor perilaku, penilaian **diri sendiri**, **rekan kerja (360°)**, dan **atasan**; goal/OKR section; sign-off |
| Pegawai yang belum memenuhi standar | **PIP** (Performance Improvement Plan) | Area perbaikan spesifik + bukti, target & tenggat SMART, dukungan yang disediakan, jadwal review (mingguan/dua-mingguan/bulanan), acknowledgement, hasil akhir |
| Dokumentasi & keadilan | praktik audit HR | Jejak siapa menilai kapan, versi instrumen, hak tanggapan pegawai, larangan menilai diri sendiri/konflik kepentingan, retensi & kerahasiaan |

---

## 8. Fitur perangkat lunak sejenis (benchmark)

| Produk | Fitur yang relevan untuk ditiru |
|---|---|
| **StaffTrac** (Educational Vistas) | Manajemen evaluasi penuh (rubrik apa pun, komponen bisa dikustomisasi), impor riwayat observasi, SLO tracking, PD (pengembangan profesional), pelaporan kepatuhan, integrasi & hak akses berlapis |
| **NEE Data Tool** | Dashboard tugas & progres; **Open Observation** (catat observasi saat *walkthrough*, guru melihat setelah dikirim); **survei siswa** tentang guru & **survei guru** tentang kepala sekolah; **Indicator Trend** (perkembangan indikator per guru/sekolah/distrik); **Status Report** untuk kepatuhan |
| **iObservation (Marzano)** | Digitalisasi rubrik bertingkat 0–4 dengan bukti perilaku spesifik per level; fokus pada **dampak ke siswa** |
| **Flint / Education Walkthrough** | Pemisahan **walkthrough cepat** vs **formal evaluation**; ringkasan/feedback otomatis; penekanan “fokus satu hal + tindak lanjut yang diuji ulang” |
| **Aplikasi supervisi sekolah (riset Indonesia)** | Jadwal supervisi, rekap otomatis, analitik perkembangan kompetensi guru, notifikasi/undangan supervisi, laporan tercetak siap akreditasi |

**Pelajaran desain:** (a) sediakan **mode cepat** dan **mode lengkap**;
(b) guru/pegawai **melihat hasil** dan bisa menanggapi; (c) **tren indikator**
lebih berguna daripada satu nilai akhir; (d) **laporan siap tanda tangan**;
(e) **umpan balik multi-sumber** (atasan, sejawat, siswa, diri sendiri) sebagai
opsi, bukan paksaan.

---

## 9. Praktik baik & jebakan (agar modul menyelesaikan masalah nyata)

**Praktik baik**
1. Supervisi direncanakan setahun (program semester) dengan **sasaran & fokus
   per guru**, bukan spontan.
2. **Pra-observasi** disepakati: fokus, instrumen, kapan, bagaimana data dicatat.
3. Observasi memakai **instrumen** dan mencatat **fakta**, bukan kesan.
4. **Pasca-observasi** dimulai dari refleksi guru, baru data supervisor.
5. **Umpan balik spesifik & dapat diuji ulang** (Marzano/walkthrough: satu
   perbaikan yang bisa dicoba sebelum kunjungan berikutnya).
6. **RTL tertulis** dengan tenggat + bukti, dan dipantau sampai tuntas.
7. **Evaluasi program supervisi** dengan CIPP (context, input, process, product)
   agar programnya sendiri diperbaiki.
8. Hasil supervisi dijadikan **dasar pengembangan kompetensi (TNA)** dan,
   bila sesuai kebijakan, **bahan penilaian kinerja** — dengan transparansi.

**Jebakan**
1. Supervisi = penilaian/administratif saja, tanpa pembinaan.
2. Tanpa instrumen → tidak objektif, tidak bisa dibandingkan antar waktu.
3. Tidak ada RTL/ tidak dipantau → hasil supervisi mengendap.
4. Dokumen dibuat **untuk akreditasi**, bukan untuk guru.
5. Supervisor tidak punya waktu (kepala sekolah 6–10 jam/minggu untuk supervisi
   akademik **dan** manajerial) → modul harus **hemat langkah**.
6. Distribusi predikat tidak wajar (semua “Amat baik”) → perlu analitik
   distribusi & pengingat kalibrasi.
7. Guru tidak pernah melihat hasil/tidak bisa menanggapi → perlu **acknowledge
   & hak tanggapan**.
8. Menilai diri sendiri / konflik kepentingan (mis. atasan menilai kerabat) →
   perlu aturan **pemisahan penilai**.
9. Instrumen berubah tiap tahun tanpa versi → nilai lama tidak bisa
   dibandingkan. **Versi instrumen wajib.**

---

## 10. Implikasi desain (ringkas → detail di blueprint)

1. **Template ber-versi**: instrumen = header + versi; versi punya section →
   indikator → (opsional) rubrik level. Versi **immutable** begitu dipakai.
2. **Multi-skala**: `SCALE_0_4`, `SCALE_1_4`, `SCALE_1_5`, `YES_NO`,
   `SCALE_0_2`, `NA`, `TEXT`, `FILE`, `DATE`, `NUMBER`, `SINGLE_CHOICE`,
   `MULTI_CHOICE`; setiap pilihan punya **label + skor**.
3. **Mesin skor**: bobot per indikator/section/komponen, `skor maksimum` dihitung
   dari definisi (bukan disimpan), normalisasi ke 100, ambang predikat
   konfigurabel, **dukungan rumus khusus** (PKG, NKKS, koreksi kehadiran,
   kuadran SKP) sebagai *calculator* terpisah, dan **snapshot** saat finalisasi.
4. **Siklus sebagai state machine** pada satu entitas `visits`/`supervisions`:
   `DRAFT → SCHEDULED → PRE_CONFERENCE → OBSERVED → ANALYZED → POST_CONFERENCE
   → ACTION_PLANNED → ACTION_IN_PROGRESS → COMPLETED → EVALUATED`
   (+ `CANCELLED`, `RESCHEDULED`).
5. **Pemisahan jelas**: `activity_kind` = WALKTHROUGH / CLASS_OBSERVATION /
   CLINICAL / PEER / LESSON_STUDY / COACHING / DOCUMENT_REVIEW / STAFF_REVIEW /
   PROGRAM_EVALUATION.
6. **Bukti & lampiran** dengan visibilitas; **tanda tangan/acknowledge**;
   **hak tanggapan/keberatan** + alur moderator (seperti moderasi PKG).
7. **RTL sebagai objek kelas satu** (bukan catatan teks): langkah, PIC, tenggat,
   bukti, status, progres — agar bisa dipantau & dilaporkan.
8. **Laporan wajib** (Permendikbud 15/2018): program supervisi, laporan
   pelaksanaan & hasil (guru & tendik), laporan evaluasi program; plus rekap
   per guru, distribusi predikat, tren indikator, dan peta kebutuhan pelatihan.
9. **Integrasi**: `Employee` (pegawai/guru), jadwal & absensi (koreksi kehadiran,
   bentrok jadwal observasi), LMS (kelas/course sebagai konteks & bukti
   pengembangan), notifikasi/kalender, penyimpanan berkas.
10. **Aturan anti-gaming & keadilan**: finalisasi mengunci nilai, perubahan
    setelah final wajib alasan + tercatat, kalibrasi distribusi, larangan
    menilai diri sendiri, dan audit trail setiap tindakan.

---

## 11. Pertanyaan desain yang perlu keputusan sekolah (sebelum coding lanjut)

1. Skala baku yang dipakai sekolah: **1–4** (gaya Kemendikbud) atau **1–5**
   (gaya HR) atau keduanya per jenis instrumen? *(Blueprint mendukung keduanya;
   seeding bawaan bisa disesuaikan.)*
2. Apakah hasil supervisi **langsung menjadi komponen penilaian kinerja**
   (mis. bobot 40% supervisi + 60% kinerja lain), atau hanya **bukti pendukung**?
3. Siapa yang boleh menjadi supervisor: hanya kepala sekolah & wakil, atau juga
   pengawas, koordinator mapel, dan **sejawat** (peer)? Perlukah moderator
   (seperti PKG)?
4. Perlukah **penilaian multi-sumber** (diri sendiri, sejawat, siswa, wali murid)
   sebagai bagian resmi, atau cukup sebagai data tambahan?
5. Apakah modul juga menangani **penilaian kinerja pegawai penuh** (SKP/1–5/PIP),
   atau cukup **supervisi + ekspor ke modul kepegawaian**?
6. Frekuensi minimum per guru/tendik per semester (mis. 1 observasi kelas penuh +
   2 walkthrough) sebagai **kepatuhan** yang diperingatkan sistem?
7. Kerahasiaan: siapa yang boleh melihat **rincian jawaban indikator**, dan siapa
   hanya melihat **ringkasan/predikat**?
8. Bahasa dokumen cetak: Indonesia saja, atau dwibahasa (ID/EN) untuk sekolah
   internasional?

---

## 12. Rujukan (dari riset ini)

**Regulasi & panduan resmi**
1. Permendiknas No. 12/2007 — Standar Pengawas Sekolah/Madrasah:
   <https://peraturan.bpk.go.id/Download/210396/Permendiknas%20Nomor%2012%20Tahun%202007.pdf>
2. Permendiknas No. 24/2008 — Standar Tenaga Administrasi Sekolah/Madrasah:
   <https://luk.staff.ugm.ac.id/atur/bsnp/Permendiknas24-2008StandarTenagaAdministrasiSekolah.pdf>
3. Pedoman Pelaksanaan PK Guru (Buku 2, Ditjen GTK):
   <https://disdik.slemankab.go.id/wp-content/uploads/2020/10/Buku-2-Pedoman-PK-Guru.pdf>
4. Bahan ajar GTK — Supervisi Akademik dalam Penilaian Pembelajaran (SUPAK):
   <https://gtk.kemendikdasmen.go.id/kemitraan/front/img/unduhan/SUPAK_Penilaian_Pembelajaran.pdf>
5. Modul Evaluasi Kinerja Pegawai (PermenPANRB 6/2022), BKN:
   <https://www.bkn.go.id/storage/2022/09/MODUL-EVALUASI-KINERJA-PEGAWAI-SESUAI-PERMENPAN-RB-NOMOR-6-TAHUN-2022.pdf>
6. SE Kepala BKN No. 18/2024 — Penggunaan layanan e-Kinerja BKN (JDIH BKN):
   <https://jdih.bkn.go.id/common/dokumen/2024peraturanlembagapemerintahnonkementerian18tahun2024-2607.pdf>
7. KMS BKN — Penilaian Kinerja PNS (perencanaan SKP, kuadran, pembinaan):
   <https://apps-denpasar.bkn.go.id/kms/ensiklopedia:penilaian_kinerja_pns>
8. Panduan Substansi Pengelolaan Kinerja Guru & Kepala Sekolah (PMM):
   <https://uploads.belajar.id/document/files/Panduan_Substansi_Pengelolaan_Kinerja_Guru_dan_Kepala_Sekolah_01hy1r8nayb61kvy4mws1tge2f.pdf>
9. Panduan Teknis Fitur Pengelolaan Kinerja Guru (PMM):
   <https://uploads.belajar.id/document/files/Panduan_Teknis_Fitur_Pengelolaan_Kinerja_Guru_-_Untuk_Guru_01hy1rs5gee2c41pwhz4f4e0hx.pdf>
10. Permendikbud No. 15/2018 (beban kerja guru/kepala sekolah/pengawas) — ringkasan
    pemenuhan 37,5 jam & supervisi 6–10 jam/minggu:
    <https://yunandra.com/beban-kerja-kepala-sekolah>

**Instrumen contoh sekolah (dipakai sebagai acuan butir & penskoran)**
11. Instrumen Supervisi Akademik Kepala Sekolah (SMPN 4 Kotabaru: perangkat,
    telaah RPP 36 butir, pengamatan pembelajaran 40 butir, penilaian hasil
    belajar, wawancara pra/pasca, analisis data, RTL, dampak):
    <https://gusndol.com/wp-content/uploads/2019/10/Instrumen-Supervisi-Akademik-Kepala-Sekolah.pdf>
12. Supervisi dan Penilaian Kinerja Guru (materi diklat):
    <https://ustjogja.ac.id/uploads/lpd/08_supervisi_dan_pk_guru.pdf>
13. Instrumen supervisi tenaga kependidikan (kepala TU, persuratan/arsip,
    sarpras, layanan khusus, perpustakaan, laboran):
    <https://www.slideshare.net/slideshow/instrumensupervisitenagakependidikandocx/260135305>
14. Supervisi & Penilaian Kinerja Tenaga Kependidikan (Ditjen GTK, 2019):
    <https://www.slideserve.com/wickham/supervisi-dan-penilaaaaaian-kinerja-tenaga-kependddidddikan-powerpoint-ppt-presentation>
15. Kumpulan instrumen supervisi akademik & manajerial pengawas SMA:
    <https://dieks2010.wordpress.com/download/instrumen-supervisi-dan-penilain-kinerja>
16. Laporan tindak lanjut supervisi & rekapitulasi hasil supervisi (contoh format):
    <https://id.scribd.com/document/958998010/Laporan-Tindak-Lanjut-Supervisi-PAUD>

**Model & teori**
17. Ringkasan model supervisi klinis (Goldhammer, Cogan, Acheson & Gall):
    <https://ajwatts2.tripod.com/supervision/id12.html> ·
    <https://www.worldwidejournals.com/global-journal-for-research-analysis-GJRA/recent_issues_pdf/2016/October/October_2016_1477994417__151.pdf>
18. Tahapan supervisi klinis (kajian Indonesia, Cogan–Mosher–Acheson):
    <https://journal.uin-alauddin.ac.id/index.php/idaarah/article/download/25689/14922>
19. Implementasi supervisi klinis kepala sekolah (pra-observasi → observasi →
    pasca-observasi):
    <https://ojs.unm.ac.id/EDUSTUDENT/article/download/39005/20124>
20. Glickman — *developmental supervision* (directive control, directive
    informational, collaborative, nondirective):
    <https://saskschoolboards.ca/wp-content/uploads/98-04.htm> ·
    <https://mca160.wordpress.com/2013/07/15/pajak-1993-ch9-carl-glickmans-developmental-supervision>
21. Teknik supervisi individual & kelompok (daftar lengkap):
    <https://mutudidik.wordpress.com/2018/05/11/pendekatan-dan-teknik-supervisi-akademik> ·
    <https://pdfs.semanticscholar.org/debf/cf75a1b6c6be62e09a5746743d220a26d506.pdf>
22. Supervisi akademik berbasis *lesson study* (plan–do–see):
    <http://journal.an-nur.ac.id/index.php/unisanjournal/article/download/4709/2869>
23. Evaluasi program supervisi dengan model **CIPP**:
    <https://dinastirev.org/JMPIS/article/download/3340/2054/15171> ·
    <https://mail.jer.or.id/index.php/jer/article/view/503>
24. Perencanaan supervisi pendidikan (langkah strategis, instrumen yang perlu ada):
    <https://jurnal.uny.ac.id/index.php/jamp/article/download/84737/23744>
25. Kurikulum Merdeka — supervisi akademik dengan paradigma *coaching*
    (pra-observasi, observasi, pasca-observasi):
    <https://ejournal.pgrikotasemarang.org/index.php/jips/article/download/215/163>

**Instrumen & praktik internasional**
26. Marzano Teacher Evaluation Model (5 level 0–4, bukti dampak ke siswa):
    <https://ospi.k12.wa.us/sites/default/files/2023-10/marzano_teacher_evaluation_model.pdf>
27. Perbandingan kerangka evaluasi guru (Danielson, Marzano, TAP/NIET) &
    cara rating bekerja:
    <https://educationwalkthrough.com/top-teacher-evaluation-frameworks>
28. Teacher observation software — fitur yang dicari sekolah (ringkasan pasar):
    <https://www.principalcenter.com/best-teacher-observation-software> ·
    <https://home.2gno.me/post/teacher-observation-checklist-software>
29. StaffTrac — manajemen evaluasi guru/kepala sekolah (fitur lengkap):
    <https://edvistas.com/integrated-products/stafftrac>
30. NEE Data Tool — dashboard, open observation, survei, indicator trend:
    <https://neeadvantage.com/teacher-evaluation-system>
31. Contoh kompetensi & template tinjauan kinerja pegawai (skala 1–5, bobot,
    tujuan/OKR):
    <https://www.peoplegoal.com/blog/performance-review-competencies-examples>
32. Template **PIP** (tujuan, target, dukungan, jadwal review, acknowledgement):
    <https://www.helpside.com/wp-content/uploads/PerformImprovePlan.pdf> ·
    <https://sas.arkansas.gov/wp-content/uploads/Performance-Improvement-Plan-guidance-and-template-1.pdf>
33. Panduan aplikasi eKinerja (alur penilaian bawahan, feedback rekan kerja,
    cetak form penilaian):
    <https://bkpsdm.langsakota.go.id/file/download/c0f4e1861f5a6b2443cb4280f3e0b0b8.pdf>

---

## 13. Ringkasan satu halaman (untuk ditempel di dinding ruang kepala sekolah)

> Supervisi yang baik = **siklus yang direncanakan, berbasis instrumen &
> fakta, diakhiri RTL yang dipantau, dan hasilnya dipakai untuk membina — bukan
> menghukum.** Instrumen Indonesia sudah matang (1–4 / 0–2 dengan konversi ke
> 100 dan ambang 90/80/70; PKG 14 kompetensi → sebutan & angka kredit);
> praktik internasional menambahkan **rubrik bertingkat**, **pemisahan
> walkthrough vs evaluasi formal**, **tren indikator**, dan **umpan balik
> multi-sumber**. Modul Supervisi harus membuat semuanya **mudah dilakukan**
> oleh kepala sekolah yang hanya punya 6–10 jam seminggu, sambil menghasilkan
> dokumen yang diminta Permendikbud 15/2018 secara otomatis.
