# Keputusan Desain Modul Supervisi (ADR)

> Status: **ditetapkan** (rekomendasi teknis), berlaku sebagai acuan implementasi.
> Menggantikan §16 blueprint yang sebelumnya “menunggu persetujuan”.
> Yang sudah ditetapkan pengguna: **(a) ada pengaturan pemilihan supervisor**,
> **(b) skala instrumen fleksibel — bisa pilihan skala, bisa entri angka**.

---

## A. Daftar keputusan

| # | Pertanyaan | **Keputusan** | Alasan |
|---|---|---|---|
| A1 | Skala nilai | **Fleksibel per indikator**: `SCALE` (rubrik pilihan berlabel + skor) **atau** `NUMBER` (angka bebas: min/max/step), ditambah `BOOLEAN`, `TEXT`, `DATE`, `FILE`. Skala bawaan 1–4; tersedia 0–2, 1–5, Ya/Tidak | Permintaan pengguna; sekolah memakai bermacam instrumen (RPP 0–2, perangkat 1–4, HR 1–5, ada/tidak) |
| A2 | Hasil supervisi ke penilaian kinerja | **Konfigurabel, default “bukti pendukung”**; sekolah dapat mengaktifkan “komponen resmi” dengan bobot (default supervisi 40%) | Menjaga prinsip “supervisi untuk membina, bukan menghukum”; butuh persetujuan sadar sebelum jadi penentu gaji |
| A3 | Siapa supervisor | **Registry supervisor** — daftar orang yang boleh menilai, dengan jenis: kepala sekolah, wakasek, koordinator mapel, pengawas, mentor, peer. Setiap penugasan divalidasi (tidak boleh menilai diri sendiri, kapasitas, cakupan, mapel) | Permintaan pengguna + praktik supervisi sejawat/lesson study |
| A4 | Hak finalisasi | `mentor`/`peer` **tidak boleh finalisasi** (hanya coaching & catatan); finalisasi hanya penilai resmi (kepala sekolah/wakasek/pengawas) | Menghindari penilaian sesama rekan yang berkonflik kepentingan |
| A5 | Multi-sumber (diri/sejawat/siswa) | **Opsional per instrumen**, default mati untuk instrumen resmi | Cukup fleksibel tanpa mengacaukan nilai formal |
| A6 | Cakupan penilaian kinerja | PKG (14/17 + tugas tambahan), NKKS, PIP, tinjauan pegawai berbobot 1–5 **dibangun**; SKP/e-Kinerja penuh (kuadran) **disiapkan skemanya**, layar di Fase 3 | Fokus dulu ke yang paling sering dipakai sekolah |
| A7 | Frekuensi minimum (kepatuhan) | Guru: **1 observasi penuh + 2 walkthrough/semester**; Tendik: **1 kunjungan/semester**; Kepala sekolah: **1 supervisi/tahun** — semua dapat diubah di Pengaturan | Turunan Permendikbud 15/2018 (6–10 jam/minggu) |
| A8 | Visibilitas hasil | Guru melihat **rincian indikator miliknya**; sejawat hanya ringkasan/tren agregat; siswa/wali tidak diberi akses (hanya laporan agregat bila sekolah membuka) | Transparansi untuk coaching, privasi untuk keamanan psikologis |
| A9 | Bahasa dokumen | Indonesia saja; struktur label dipisah agar dwibahasa bisa ditambah nanti | Menjaga ruang lingkup |
| A10 | Penilai tidak konsisten | Wajib isi **panduan penilaian** per indikator + fitur **kalibrasi penilai** (Fase 4) | Keadilan antar guru |
| A11 | Publikasi hasil | Hasil berstatus draf hanya terlihat supervisor; setelah *acknowledge* guru & finalisasi → tampil ke guru; publikasi rekap ke kepala sekolah/auditor | Pemisahan pastoral vs administratif |
| A12 | Lampiran bukti | Video/foto/dokumen dengan visibilitas per berkas; retensi mengikuti kebijakan sekolah | Melindungi privasi kelas & guru |

---

## B. Pengaturan supervisor (permintaan pengguna) — rancangan

**Entitas baru `sup_supervisors`** (registry, bukan hard-code peran):

| Kolom | Isi |
|---|---|
| `employee_id` (unik) | Orangnya (dari `employees`) |
| `kind` | `KEPALA_SEKOLAH`, `WAKASEK`, `KOORDINATOR`, `PENGAWAS`, `MENTOR`, `PEER`, `EKSTERNAL` |
| `scope_type` / `scope_id` | Lingkup kewenangan: sekolah, unit, mapel, program keahlian, asrama |
| `subject_ids` (JSON) | Mapel yang ia layak nilai (opsional) |
| `can_finalize`, `can_appraise` | Boleh memfinalkan nilai / menghitung penilaian kinerja |
| `max_active_cases` | Batas beban bersamaan (mis. 12 guru/lihat periode) |
| `is_active`, `active_from`, `active_to` | Masa berlaku; sistem menolak penugasan di luar masa ini |
| `certification_no`, `notes` | Kualifikasi/pelatihan penilai |

**Layar “Pengaturan Supervisor”** (Fase 1 UI):
1. Daftar supervisor + filter jenis/aktif/cakupan; tombol **Tambah dari pegawai** (hanya pegawai dengan peran tertentu yang muncul).
2. Tombol aktif/nonaktif, ubah jenis & cakupan, atur boleh finalisasi, kapasitas.
3. Tab **“Calon supervisor”**: usulan otomatis dari peran pegawai (kepala sekolah, wakasek, koordinator) yang belum didaftarkan — sekali centang untuk menambahkan.
4. Pratinjau **“siapa menilai siapa”**: matriks guru/tendik × supervisor, deteksi: belum ada supervisor, satu orang dinilai 3+ supervisor, supervisor kapasitas penuh, konflik (menilai diri sendiri).
5. Sakelar kebijakan: izinkan peer/mentor, izinkan penilaian multi-sumber, izinkan publikasi nilai ke guru.

**API**: `GET/POST /supervisi/supervisors`, `PATCH/DELETE /supervisi/supervisors/{id}`,
`GET /supervisi/supervisors/candidates`, `GET /supervisi/supervisors/coverage`,
`GET /supervisi/supervisors/{id}/eligibility?supervisee_id=…`.

**Aturan server** (tidak bisa dilanggar lewat API):
* `employee_id` unik & pegawai aktif; tidak boleh menilai diri sendiri.
* `PEER`/`MENTOR` → `can_finalize` dipaksa `false`.
* Penugasan ditolak bila di luar `scope`, di luar masa aktif, atau kapasitas penuh.
* Setiap perubahan registry masuk audit log.

---

## C. Instrumen fleksibel: skala **atau** angka (permintaan pengguna) — rancangan

### C1. Model input per indikator
| `input_type` | Bukti yang diminta | Cara skor |
|---|---|---|
| `SCALE` | Memilih salah satu opsi rubrik (mis. *Belum (1) / Mulai (2) / Berkembang (3) / Cakap (4)*) | `score` opsi (dapat diberi bobot) |
| `NUMBER` | Mengetik angka langsung (mis. 78, 3,5) | Nilai apa adanya; `score_min`, `score_max`, `step` mengatur validasi |
| `BOOLEAN` | Ya / Tidak / (opsional “Sebagian”) | `score_yes`, `score_no`, `score_partial` |
| `TEXT` / `LONG_TEXT` | Narasi (wawancara, catatan fakta) | Tidak diskor (`is_scored = false`) |
| `DATE` / `FILE` | Tanggal / lampiran bukti | Tidak diskor |
| `MULTI_CHOICE` | Beberapa pilihan (mis. komponen yang tersedia) | Jumlah skor opsi terpilih (opsional) |

Satu instrumen boleh **campuran**: mis. Perangkat Pembelajaran = 12 indikator
`SCALE`; Observasi = 40 `BOOLEAN`; “Nilai rata-rata kelas” = `NUMBER`;
Wawancara = 8 `LONG_TEXT`.

### C2. Mode penghitungan (`scoring_mode`) per versi
* `SUM` — jumlah skor (cocok untuk 40 butir ya/tidak).
* `AVERAGE` — rata-rata skor indikator.
* `WEIGHTED` — jumlah (skor × bobot indikator/section).
* `PERCENT` — konversi ke skala 100 memakai skor maksimum terhitung.
* `CUSTOM` — rumus khusus (PKG, NKKS, koreksi kehadiran) lewat kalkulator.
Setiap versi menyimpan `max_score` **hasil hitung** — bukan angka manual — lalu
dibandingkan dengan `sup_grade_scales` (mis. A/B/C/K 90/80/70) untuk predikat.

### C3. Selalu tersedia di pembuat instrumen
1. Tambah section (bisa bersarang 1 level), atur bobot.
2. Tambah indikator: pernyataan, **panduan penilaian** (wajib untuk `SCALE`),
   tipe input, skala/rentang, bobot, wajib/tidak, boleh `NA`, ambang komentar wajib.
3. Untuk `SCALE`: kelola opsi (label, skor, warna) — bisa “pakai skala tersimpan”
   (1–4 Kemendikbud, 0–2, 1–5 HR, Ya/Tidak + “Sebagian”).
4. Untuk `NUMBER`: min, max, step, desimal, satuan, arah nilai (makin kecil makin
   baik untuk “jumlah pelanggaran”).
5. Pratinjau instrumen (seperti yang dilihat supervisor saat observasi).
6. Publikasi versi: validasi lengkap → `PUBLISHED` + checksum + `max_score`;
   versi yang sudah dipakai **tidak dapat diubah** (harus kloning versi baru).

---

## D. Dampak ke blueprint

* Bagian §4.1 tabel: tambah `sup_supervisors`, tambah kolom indikator
  (`input_type`, `score_min/max/step`, `score_yes/no/partial`, `is_scored`,
  `unit_label`, `direction`).
* Bagian §16 blueprint ditutup oleh dokumen ini; keputusan A1–A12 mengikat.
* Fase 1 (dieksekusi sekarang) mencakup: skema `sup_*`, model, registry
  supervisor, pembuat instrumen fleksibel, skala nilai, program & penugasan,
  permission `supervisi.*`, seeder instrumen bawaan, mesin skor, dan layar
  pengaturan (supervisor + instrumen).
