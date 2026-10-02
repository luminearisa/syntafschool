# Mobile — Portal UKS (wali murid & siswa)

Layar `/uks` ("UKS Sekolah"): kunjungan UKS, pemeriksaan berkala, imunisasi, profil kesehatan, surat keterangan (unduh PDF), insiden (data tersedia di API), dan **persetujuan rujukan** oleh wali.

## Backend (`MobileUksController`)
- `GET mobile/children/{student}/uks` — proyeksi terkurasi. Tidak dikirim: `notes` internal, nama petugas (pelapor/penangan/pemberi obat), saksi insiden, stok/obat internal.
- `POST mobile/uks/visits/{visit}/consent` — hanya konteks wali, hanya anak sendiri (404 selain itu), hanya kunjungan yang dirujuk dan belum diputuskan (422), saluran selalu `APLIKASI` (nilai dari klien diabaikan). Aturan & notifikasi tetap di `VisitService::consent`.
- `GET mobile/uks/letters/{letter}/pdf` — kepemilikan dicek sebelum `LetterService::pdf`.
- Endpoint mentah `uks/*` tetap **tidak** ada di daftar jalur mobile (dites → 403).
- Tes: `MobileUksTest` (belum dijalankan — PHP 8.3 di sandbox).

## Catatan
- Definisi "perlu persetujuan": kunjungan punya rujukan dan belum ada keputusan. Bila sekolah ingin alur lain (mis. wajib untuk kunjungan berat tanpa rujukan), ubah di `needs_consent` + validasi `consent`.
- Penolakan oleh wali memicu notifikasi ke petugas (logika bawaan modul).
