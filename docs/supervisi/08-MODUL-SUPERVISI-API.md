# Modul Supervisi — Panduan API

> Ringkasan kontrak HTTP modul supervisi. Spesifikasi lengkap mesin-terbaca:
> [`docs/openapi-supervisi.yaml`](../openapi-supervisi.yaml) (97 path, 125 operasi).
> Dokumen ini menjelaskan **pola** yang berlaku untuk seluruh endpoint, lalu
> per kelompok fitur.

## 1. Dasar

| Hal | Ketentuan |
|---|---|
| Basis | `/api/v1/supervisi/*` |
| Autentikasi | Bearer token Sanctum: `POST /api/v1/auth/login` |
| Envelope sukses | `{ "success": true, "data": …, "message": … }` (daftar menambah `meta`: `current_page`, `per_page`, `total`, `last_page`) |
| Envelope galat | `{ "success": false, "message": …, "errors": { field: [pesan] } }` |
| Kode status | `200` baca/ubah · `201` buat · `403` izin kurang · `404` tidak ada/tidak boleh dilihat · `422` validasi · `429` batas laju |
| Izin | setiap operasi mencantumkan `x-permission` (Spatie) pada OpenAPI |
| Batas laju | seluruh modul 300 permintaan/menit per pengguna (per IP bila tanpa token); endpoint berat lebih ketat dan tercantum sebagai `x-rate-limit` |

Dua hal yang sering disalahpahami:

1. **Izin ≠ cakupan data.** Izin menentukan layar; cakupan (`DataScope`)
   menentukan baris. Guru memegang `supervisi.report.view` untuk melihat **hasil
   sendiri** — analitik dan laporan otomatis menyempit ke dirinya
   (`data.scope.level = "DIRI"`). Sekolah dapat membuka ringkasan agregat bagi
   rekan sejawat lewat pengaturan `peer_summary_enabled` (bawaan: mati), tanpa
   pernah mengungkap identitas pegawai lain.
2. **Idempotensi disengaja.** Jawaban instrumen disimpan per
   `(visit_id, indicator_id)`, pengiriman ulang tidak menggandakan; sinkronisasi
   luring juga mengenali `client_uuid`.

## 2. Cakupan data di dalam respons

Endpoint analitik menyertakan blok berikut agar antarmuka dapat menjelaskan apa
yang sedang dilihat pengguna:

```json
"scope": {
  "level": "SEKOLAH",
  "employee_id": null,
  "user_id": 12,
  "peer_summary": true,
  "label": "Seluruh sekolah",
  "note": null
}
```

Untuk guru: `level = "DIRI"`, `peer_summary = false`, dan `unsupervised` /
`needs_pip_rows` kosong.

## 3. Kelompok endpoint

### 3.1 Instrumen & pengaturan
`GET meta/instruments` · `GET|POST instruments` · `PUT|PATCH|DELETE instruments/{instrument}` ·
`POST instruments/{instrument}/versions` · `POST instruments/import/{instrument}` ·
`GET instrument-versions/{version}` · `PUT instrument-versions/{version}/structure` ·
`GET instrument-versions/{version}/checklist` · `POST instrument-versions/{version}/publish` ·
`POST instrument-versions/{version}/clone` · `GET instrument-versions/{version}/export` ·
`GET|POST grade-scales` · `PUT|PATCH grade-scales/{scale}` · `POST grade-scales/{scale}/default` ·
`GET|PUT settings`

Versi yang sudah **terbit** tidak dapat diubah strukturnya (422) — koreksi
melalui klon versi baru, sehingga nilai yang sudah dihitung tetap dapat
dipertanggungjawabkan.

### 3.2 Program, penugasan, jadwal
`GET|POST programs` · `GET|PUT|PATCH programs/{program}` · `POST programs/{program}/publish|close|assignments` ·
`GET assignments` · `GET assignments/workload` · `PUT|PATCH|DELETE assignments/{assignment}` ·
`GET|POST schedules` · `PUT|PATCH schedules/{schedule}` · `POST schedules/{schedule}/invite|reschedule|cancel`

### 3.3 Kegiatan (pelaksanaan)
`GET|POST visits` · `GET|PUT|PATCH|DELETE visits/{visit}` · `GET visits/{visit}/preview` ·
`PUT visits/{visit}/answers` · `POST visits/{visit}/submit|finalize|reopen|cancel` ·
`GET|PUT visits/{visit}/findings` · `GET visits/{visit}/conferences` · `PUT visits/{visit}/conferences/{kind}` ·
`POST visits/{visit}/evidence` · `DELETE visits/{visit}/evidence/{evidence}` ·
`POST visits/{visit}/acknowledge|dispute` · `POST visits/{visit}/action-plan` · `POST disputes/{dispute}/resolve`

Aturan yang ditegakkan server (bukan oleh UI):

* pelaksana tidak dapat memfinalkan penilaian dirinya sendiri;
* finalisasi menuntut kelengkapan checklist (jawaban wajib, komentar di bawah
  ambang, wawancara pasca-observasi, temuan bila nilai rendah, RTL bila
  instrumen mewajibkannya);
* hasil final bersifat beku; koreksi lewat `reopen` yang tercatat pada
  `sup_visit_revisions` beserta alasannya;
* `finalize` mengunci baris (`lockForUpdate`) sehingga dua tab tidak dapat
  memfinalkan kegiatan yang sama.

### 3.4 Mode luring
| Operasi | Kegunaan |
|---|---|
| `GET visits/{visit}/blank-form` | PDF formulir kosong: indikator + pilihan berlabel huruf (A/B/C/…) + kolom catatan |
| `POST visits/{visit}/answers/sync` | Kirim antrean jawaban dari perangkat; menjawab per baris: `applied`, `skipped`, `rejected`, `conflicts` |

Contoh:

```json
POST /api/v1/supervisi/visits/41/answers/sync
{
  "device": "Tablet Ruang 3",
  "answers": [
    { "indicator_id": 7, "option_code": "D", "comment": "Bukti kelas.", "client_uuid": "c-9f2…", "captured_at": "2026-09-30T08:12:00+07:00" },
    { "indicator_id": 8, "value_number": 8, "comment": "Pencatatan manual.", "client_uuid": "c-77a…" }
  ]
}
```

```json
{
  "success": true,
  "data": {
    "applied": 2,
    "skipped": [],
    "rejected": [],
    "conflicts": [],
    "visit": { "answers_count": 12, "is_locked": false },
    "server_time": "2026-09-30T08:20:11+07:00"
  }
}
```

* `option_code` menerima **huruf** formulir cetak (A, B, C…), **skor** opsinya
  (mis. `"4"`), atau label opsinya.
* `captured_at` menentukan deteksi konflik: bila server sudah diubah setelah
  waktu itu dan nilainya berbeda, server **dipertahankan** dan baris dilaporkan
  pada `conflicts` — supervisor memutuskan, bukan mesin.
* Satu baris rusak tidak membatalkan sisanya (`rejected` berisi alasannya).
* Kegiatan yang sudah difinalkan menolak sinkronisasi (422).

### 3.5 Tindak lanjut (RTL)
`GET|POST action-plans` · `GET|PATCH action-plans/{actionPlan}` · `POST action-plans/{actionPlan}/items|verify|drop` ·
`PATCH action-plans/items/{actionItem}` · `POST action-plans/items/{actionItem}/progress`

`GET action-plans` mengembalikan `buckets` (terlambat, mendekati tenggat,
berjalan, menunggu verifikasi) dan baris yang sudah dipangkas sesuai cakupan
pengguna.

### 3.6 Penilaian kinerja (PKG/NKKS/SKP/360°)
`GET|POST appraisals` · `GET appraisals/reference` · `GET|PATCH appraisals/{appraisal}` ·
`PUT appraisals/{appraisal}/scores|sources` · `POST appraisals/{appraisal}/calculate|publish|reopen`

`GET appraisals/reference` memuat rumus yang dipakai server (14 kompetensi PKG,
koreksi kehadiran, kuadran SKP, bobot multi-sumber) — supaya sekolah dapat
memeriksa angka sistem tanpa membaca kode.

### 3.7 Pengembangan, coaching, PIP
`GET|POST developments` · `PATCH|DELETE developments/{development}` ·
`GET|POST coachings` · `PATCH coachings/{coaching}` · `POST coachings/{coaching}/close` ·
`GET|POST pips` · `GET|PATCH pips/{pipPlan}` · `POST pips/{pipPlan}/items|reviews|acknowledge|conclude` ·
`PATCH pips/items/{pipItem}`

### 3.8 Analitik, laporan, kesehatan
`GET analytics` (jendela `from`/`to`) · `GET health` (perlu `supervisi.audit.view`/`manage`) ·
`GET reports/catalog|reports/history` · `POST reports/preview` · `POST reports` (CSV/XLSX/PDF/JSON) ·
`GET reports/{report}/download` · `GET reports/verify/{code}` (**publik**)

```json
GET /api/v1/supervisi/health
{
  "status": "warning",
  "summary": { "checks": 9, "ok": 8, "warning": 1, "critical": 0, "needs_attention": 1 },
  "checks": [
    {
      "key": "calibration_pending_conclusion",
      "label": "Kalibrasi melewati ambang tanpa kesepakatan",
      "status": "warning",
      "count": 1,
      "samples": ["Moderasi nilai observasi kelas semester ganjil"],
      "suggestion": "Selisih antar penilai belum disepakati; …"
    }
  ]
}
```

### 3.9 Kalibrasi & evaluasi program (CIPP)
`GET|POST calibrations` · `GET|PATCH calibrations/{session}` · `PUT calibrations/{session}/scores` ·
`POST calibrations/{session}/finalize` · `GET calibrations/divergence` ·
`GET evaluations` · `GET|PATCH evaluations/{evaluation}` · `POST evaluations/{evaluation}/finalize` ·
`POST programs/{program}/evaluations`

## 4. Kesalahan yang paling sering ditemui

| Kode | Penyebab umum | Tindakan |
|---|---|---|
| 422 `checklist` | Finalisasi sebelum kelengkapan | Lengkapi jawaban wajib/komentar/wawancara pasca-observasi sesuai pesan galat |
| 422 `visit` | Kegiatan sudah final / dibatalkan | Gunakan `reopen` (dengan alasan) bila memang perlu dikoreksi |
| 403 | Izin modul kurang, **atau** laporan agregat sekolah diminta oleh akun berizik "diri" | Periksa `x-permission`; laporan agregat memerlukan `peer_summary_enabled` bagi rekan sejawat |
| 404 `valid: false` | Kode verifikasi salah **atau** berkas berubah | Minta dokumen terbaru dari sekolah |
| 429 | Batas laju | Tunggu satu menit; untuk ekspor massal, jalankan berurutan |

## 5. Integritas dokumen

Setiap dokumen yang dihasilkan menyimpan `verification_code` (8 karakter) dan
`checksum` SHA-256 atas isi berkas. `GET reports/verify/{code}` (publik; hanya
keterangan dokumen yang diungkap, bukan isinya) menyatakan dokumen **sah** hanya
bila berkas di penyimpanan masih ber-hash sama. PDF memuat QR menuju halaman
`/verifikasi-dokumen/{kode}` di aplikasi.
