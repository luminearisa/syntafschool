# Syntaf School ERP — Rencana Master Data

## 1. Pengertian Master Data

**Master Data** adalah data referensi dasar yang menjadi acuan (lookup/reference) bagi seluruh modul transaksional. Master data harus dikelola terpusat, konsisten, dan memiliki validasi kuat. Perubahan pada master data berdampak luas ke modul lain sehingga perlu kontrol akses ketat (umumnya hanya Super Admin / Admin Sekolah).

---

## 2. Kategori Master Data

Master data dikelompokkan menjadi **9 kategori** berikut:

| # | Kategori | Contoh Data |
|---|----------|-------------|
| A | **Profil Institusi** | Sekolah, Yayasan, Unit/Jenjang |
| B | **Wilayah & Alamat** | Provinsi, Kabupaten, Kecamatan, Kelurahan, Kode Pos |
| C | **Referensi Akademik** | Tingkat, Tahun Ajaran, Semester, Kurikulum |
| D | **Referensi Kesiswaan** | Agama, Jenis Kelamin, Status Siswa, Pekerjaan |
| E | **Referensi Keuangan** | Komponen Biaya, Metode Pembayaran, Bank |
| F | **Referensi Perpustakaan** | Kategori Buku, Penerbit, Rak |
| G | **Referensi Inventaris** | Kategori Barang, Satuan, Lokasi |
| H | **Referensi Absensi** | Status Kehadiran, Jenis Izin |
| I | **Referensi Sistem** | Pengaturan Umum, Kode Otomatis, Tahun Anggaran |

---

## 3. Detail Master Data per Kategori

### A. Profil Institusi

#### A1. Sekolah (`schools`)
Data identitas sekolah (mendukung multi-sekolah/yayasan).

| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| npsn | varchar(20) unique | Nomor Pokok Sekolah Nasional |
| name | varchar(150) | Nama sekolah |
| jenjang | enum | SD/SMP/SMA/SMK |
| status | enum | Negeri/Swasta |
| accreditation | varchar(5) | Akreditasi (A/B/C) |
| foundation_id | bigint (FK) | Yayasan (nullable) |
| address | text | Alamat lengkap |
| province_id | bigint (FK) | |
| city_id | bigint (FK) | |
| district_id | bigint (FK) | |
| village_id | bigint (FK) | |
| postal_code | varchar(10) | |
| phone | varchar(20) | |
| email | varchar(100) | |
| website | varchar(150) | |
| logo_path | varchar(255) | |
| headmaster_name | varchar(150) | Nama kepala sekolah |
| is_active | boolean | |
| timestamps | | |

#### A2. Yayasan (`foundations`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| name | varchar(150) | Nama yayasan |
| address | text | |
| phone | varchar(20) | |
| email | varchar(100) | |
| is_active | boolean | |

---

### B. Wilayah & Alamat

Data wilayah Indonesia (hierarki 4 level). Dapat menggunakan data BPS resmi lalu disimpan lokal untuk performa.

#### B1. Provinsi (`provinces`)
| Field | Tipe |
|-------|------|
| id | varchar(2) (PK) |
| name | varchar(100) |

#### B2. Kabupaten/Kota (`cities`)
| Field | Tipe |
|-------|------|
| id | varchar(4) (PK) |
| province_id | varchar(2) (FK) |
| name | varchar(100) |
| type | enum(kabupaten/kota) |

#### B3. Kecamatan (`districts`)
| Field | Tipe |
|-------|------|
| id | varchar(7) (PK) |
| city_id | varchar(4) (FK) |
| name | varchar(100) |

#### B4. Kelurahan/Desa (`villages`)
| Field | Tipe |
|-------|------|
| id | varchar(10) (PK) |
| district_id | varchar(7) (FK) |
| name | varchar(100) |
| postal_code | varchar(10) |

> **Catatan:** Wilayah bersifat *read-only* (impor dari sumber resmi), bukan CRUD manual.

---

### C. Referensi Akademik

#### C1. Tingkat/Jenjang (`education_levels`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| name | varchar(50) | Contoh: "Kelas 1", "Tingkat 10" |
| level_number | int | Urutan tingkat |
| jenjang | enum | SD/SMP/SMA/SMK |
| is_active | boolean | |

#### C2. Tahun Ajaran (`academic_years`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| name | varchar(20) | Contoh: "2025/2026" |
| start_date | date | |
| end_date | date | |
| is_active | boolean | Hanya 1 yang aktif |

#### C3. Semester (`semesters`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| academic_year_id | bigint (FK) | |
| name | enum | Ganjil/Genap |
| start_date | date | |
| end_date | date | |
| is_active | boolean | |

#### C4. Kurikulum (`curricula`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| name | varchar(100) | Contoh: "Kurikulum Merdeka" |
| code | varchar(20) | K13, MERDEKA |
| description | text | |
| is_active | boolean | |

#### C5. Mata Pelajaran (`subjects`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| code | varchar(20) unique | Kode mapel |
| name | varchar(100) | Nama mapel |
| curriculum_id | bigint (FK) | |
| education_level_id | bigint (FK) | |
| category | enum | Wajib/Muatan Lokal/Pilihan |
| credit | decimal(4,2) | Bobot/jam |
| is_active | boolean | |

#### C6. Ruangan (`rooms`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| code | varchar(20) unique | |
| name | varchar(100) | |
| type | enum | Kelas/Lab/Perpustakaan/Kantor/Lainnya |
| capacity | int | |
| building | varchar(50) | |
| is_active | boolean | |

---

### D. Referensi Kesiswaan

#### D1. Agama (`religions`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) |

> Seed: Islam, Kristen, Katolik, Hindu, Buddha, Konghucu, Lainnya

#### D2. Jenis Kelamin (`genders`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| code | char(1) | L/P |
| name | varchar(20) | Laki-laki/Perempuan |

#### D3. Status Siswa (`student_statuses`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) | Aktif/Lulus/Mutasi/Keluar/Tinggal Kelas |
| is_active | boolean |

#### D4. Jenis Tinggal (`residence_types`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) | Bersama Orang Tua/Wali/Pesantren/Kos |

#### D5. Moda Transportasi (`transportations`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) | Jalan Kaki/Sepeda/Motor/Mobil/Antar Jemput |

#### D6. Pekerjaan (`occupations`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(100) | PNS/TNI/POLRI/Pegawai Swasta/Wiraswasta/dll |

#### D7. Pendidikan Terakhir (`education_backgrounds`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) | SD/SMP/SMA/D3/S1/S2/S3 |
| level_number | int | Urutan |

#### D8. Penghasilan (`income_ranges`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| label | varchar(100) | Contoh: "< Rp 1.000.000" |
| min_value | decimal | |
| max_value | decimal | |

#### D9. Hubungan Wali (`guardian_relations`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) | Ayah/Ibu/Wali/Kakak/dll |

#### D10. Jenis Pelanggaran (`violation_types`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| name | varchar(150) | |
| category | enum | Ringan/Sedang/Berat |
| point | int | Poin pelanggaran |
| is_active | boolean | |

#### D11. Jenis Prestasi (`achievement_types`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(100) | Akademik/Non-Akademik/Olahraga/Seni |
| level | enum | Sekolah/Kecamatan/Kabupaten/Provinsi/Nasional/Internasional |

#### D12. Ekstrakurikuler (`extracurriculars`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(100) | Pramuka/PMR/Basket/dll |
| category | varchar(50) | |
| instructor | varchar(150) | |
| is_active | boolean |

---

### E. Referensi Keuangan

#### E1. Komponen Biaya (`fee_components`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| code | varchar(20) unique | |
| name | varchar(100) | SPP/Uang Gedung/Seragam/Kegiatan |
| type | enum | Bulanan/Tahunan/Sekali/etc |
| default_amount | decimal(15,2) | Nominal default |
| is_active | boolean | |

#### E2. Metode Pembayaran (`payment_methods`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) | Tunai/Transfer/VA/QRIS |
| code | varchar(20) | CASH/TRANSFER/VA/QRIS |
| is_active | boolean |

#### E3. Bank (`banks`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(100) | |
| code | varchar(10) | Kode bank |
| is_active | boolean |

#### E4. Diskon/Beasiswa (`discount_types`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) | |
| name | varchar(100) | Beasiswa Prestasi/KIP/Yatim |
| type | enum | Persen/Nominal |
| value | decimal(15,2) | |
| is_active | boolean |

#### E5. Tahun Anggaran (`fiscal_years`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(20) | 2025/2026 |
| start_date | date | |
| end_date | date | |
| is_active | boolean |

---

### F. Referensi Perpustakaan

#### F1. Kategori Buku (`book_categories`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| code | varchar(20) | |
| name | varchar(100) | |
| is_active | boolean |

#### F2. Penerbit (`publishers`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(100) | |
| address | text | |

#### F3. Pengarang (`authors`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(100) | |

#### F4. Rak/Shelf (`shelves`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| code | varchar(20) | |
| name | varchar(50) | |

---

### G. Referensi Inventaris

#### G1. Kategori Barang (`item_categories`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(100) | |
| type | enum | Aset/Habis Pakai |

#### G2. Satuan (`units`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| name | varchar(50) | Unit/Pcs/Box/Rim/Lusin |
| symbol | varchar(10) | |

#### G3. Lokasi Penyimpanan (`storage_locations`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) | |
| name | varchar(100) | Gudang/Ruang Guru/dll |
| room_id | bigint (FK, nullable) | |

---

### H. Referensi Absensi

#### H1. Status Kehadiran (`attendance_statuses`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| code | char(2) | H/A/I/S/T (Hadir, Alpa, Izin, Sakit, Terlambat) |
| name | varchar(50) | Hadir/Alpa/Izin/Sakit/Terlambat |
| color | varchar(10) | Kode warna UI |

#### H2. Jenis Izin (`leave_types`)
| Field | Tipe |
|-------|------|
| id | bigint (PK) | |
| name | varchar(100) | Sakit/Izin/Keperluan Keluarga/Dispensasi |
| requires_attachment | boolean | Butuh surat/berkas |

---

### I. Referensi Sistem

#### I1. Pengaturan Umum (`settings`)
Key-value configuration.
| Field | Tipe |
|-------|------|
| id | bigint (PK) |
| key | varchar(100) unique |
| value | text |
| group | varchar(50) | school/general/finance |
| type | varchar(20) | string/text/number/boolean/json |

#### I2. Kode Otomatis / Penomoran (`document_sequences`)
| Field | Tipe | Keterangan |
|-------|------|------------|
| id | bigint (PK) | |
| code | varchar(50) | NIS/NISN/INVOICE/PAYMENT |
| prefix | varchar(20) | |
| format | varchar(100) | `{prefix}/{year}/{sequence}` |
| last_number | int | |
| reset_period | enum | never/year/month |

#### I3. Hari (`days`)
| Field | Tipe |
|-------|------|
| id | tinyint (PK) | 1=Senin..7=Minggu |
| name | varchar(20) | |

---

## 4. Prinsip Pengelolaan Master Data

1. **Kode Unik** — setiap master data dengan `code` harus unik & immutable setelah dipakai.
2. **Soft Delete** — gunakan soft delete agar data historis transaksi tetap valid.
3. **Is Active Flag** — nonaktifkan data alih-alih menghapus untuk data yang sudah dipakai.
4. **Audit Trail** — catat siapa membuat/mengubah data (spatie/activitylog).
5. **Validasi Ketat** — gunakan Form Request di Laravel.
6. **Caching** — master data yang jarang berubah di-cache (Redis) untuk performa.
7. **Seeder** — sediakan seeder default agar sistem langsung siap pakai.
8. **Import/Export** — sediakan fitur impor massal (Excel) untuk data besar.
9. **Dependensi Terkunci** — tidak bisa hapus master data jika masih direferensikan.

---

## 5. Urutan Implementasi Master Data

```
Fase 1A (Fondasi)
  └── Wilayah (B) → Agama (D1) → Gender (D2) → Pekerjaan (D6)
      → Pendidikan (D7) → Hubungan Wali (D9)

Fase 1B (Akademik)
  └── Kurikulum (C4) → Tingkat (C1) → Tahun Ajaran (C2)
      → Semester (C3) → Mata Pelajaran (C5) → Ruangan (C6)

Fase 1C (Operasional)
  └── Komponen Biaya (E1) → Metode Bayar (E2) → Bank (E3)
      → Kategori Buku (F1) → Kategori Barang (G1) → Satuan (G2)
      → Status Absensi (H1)

Fase 1D (Sistem)
  └── Pengaturan (I1) → Kode Otomatis (I2) → Hari (I3)
```

---

## 6. Ringkasan Tabel Master Data

| Kategori | Jumlah Tabel | Tabel |
|----------|:------------:|-------|
| A. Institusi | 2 | schools, foundations |
| B. Wilayah | 4 | provinces, cities, districts, villages |
| C. Akademik | 6 | education_levels, academic_years, semesters, curricula, subjects, rooms |
| D. Kesiswaan | 12 | religions, genders, student_statuses, residence_types, transportations, occupations, education_backgrounds, income_ranges, guardian_relations, violation_types, achievement_types, extracurriculars |
| E. Keuangan | 5 | fee_components, payment_methods, banks, discount_types, fiscal_years |
| F. Perpustakaan | 4 | book_categories, publishers, authors, shelves |
| G. Inventaris | 3 | item_categories, units, storage_locations |
| H. Absensi | 2 | attendance_statuses, leave_types |
| I. Sistem | 3 | settings, document_sequences, days |
| **TOTAL** | **41** | |

---

## 7. Dokumen Terkait

- [`01-PLAN-UTAMA.md`](./01-PLAN-UTAMA.md) — Rencana utama proyek
- [`03-RANCANGAN-DATABASE.md`](./03-RANCANGAN-DATABASE.md) — Skema & relasi database
- [`04-SPESIFIKASI-API-FRONTEND.md`](./04-SPESIFIKASI-API-FRONTEND.md) — API & struktur frontend
