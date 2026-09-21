# Item sentral yang ditunda (jangan sampai hilang)

Dikerjakan oleh agen pusat setelah agen perbaikan frontend selesai — file-file di
bawah sedang dimiliki agen lain, jadi tidak boleh disentuh paralel.

1. SELESAI — `PpdbAppealEligibility` sudah dikoreksi ke bentuk nyata
   `PpdbAppealService::eligibility()`.
2. Dikerjakan agen `portal` bersama pembersihan `PpdbPortalSelectionPage.tsx`
   (`<AppealCard registrationId={...} />` sudah ada dan bersih).
3. SELESAI (sisi backend) — `PpdbApplicantResource` tidak lagi bergantung flag
   konstruktor: `registrations` dan `parent_name` kini ikut terbawa setiap kali
   relasinya benar-benar dimuat, sama pola resource lain (`whenLoaded`).
   `applicantQuery()` sudah memuat `parentAccount.user` + `registrations`, jadi
   daftar calon admin akhirnya mengirim kunci yang dijanjikannya. FE memakai
   `parent_name` dan `registrations[]`, bukan `parent_account` / `registrations_count`.
   Ini bug kelas yang sama dengan program/periode dan sudah tercatat sebagai temuan
   HIGH di laporan final.
4. Verifikasi akhir: `php artisan migrate` (aditif saja), seluruh
   `vendor/bin/phpunit`, `npx tsc -p tsconfig.app.json --noEmit`,
   `npm run build`, smoke HTTP live, lalu laporan FINAL + `docs/PROGRESS.md`.

## Sudah beres sesi ini
- `GET /ppdb/lookups` + `usePpdbReferenceOptions` (dropdown panitia tak lagi 403 senyap).
- `apiResource('invoices')` yang mendaftarkan `PUT/DELETE` tanpa metode controller
  (500) → rute eksplisit; smoke kini menegakkan 405.
- Siklus tagihan `expired/cancelled/refunded` kini terjangkau:
  `ppdb:expire-invoices` + jadwal harian + `POST /ppdb/invoices/{id}/cancel|refund`
  beralasan wajib, dengan `PpdbInvoiceLifecycleTest` (7 tes).
- `phpunit.xml`: `memory_limit` bawaan 128M membuat suite penuh (535 tes) mati di
  tengah jalan saat memuat route → dinaikkan 512M.
- Halaman panitia `Masa Sanggah` (`/ppdb/appeals`) + rute + entri menu.
- Audit sentral "setiap jalur yang dipanggil klien harus benar-benar terdaftar"
  (`/tmp/check_ppdb_api_routes.cjs`, `/tmp/check_ppdb_crud.cjs`; bandingkan
  literal di `features/ppdb/api/ppdbApi.ts` vs `php artisan route:list --path=ppdb --json`):
  - CRITICAL `payWithProof` mengirim ke `/ppdb/invoices/{id}/pay` — rute itu tidak
    pernah ada (yang ada `/ppdb/portal/invoices/{id}/pay`) → unggah bukti bayar
    orang tua selalu 404. Diperbaiki + 404 jalur palsu dikunci di smoke test.
  - HIGH tidak ada `GET /ppdb/applicants/{id}` untuk admin (hanya index/update/
    destroy) → "lihat calon" di admin 404. Ditambah `adminShow` di grup
    `permission:ppdb.view` + assertion smoke (termasuk `data.registrations` terisi).
  - LOW `links.detail` / `links.documents` di `PpdbRegistrationResource` dan
    `PpdbNotificationResource` menunjuk `/ppdb/portal/pendaftaran/{id}` yang tidak
    cocok dengan rute API mana pun maupun rute SPA, dan tidak dipakai klien →
    dibuang; `links` kini murni tautan API.
  - `crud()` kesembilan resource lengkap 5 verba; `/auth/login|me|logout` ternyata
    endpoint Sanctum existing (bukan sistem auth baru).
- `education_level` (objek `{id,name,jenjang}`) ikut dikirim di baris program
  `quotaSummaries()` agar halaman publik bisa menyebut jenjang tanpa membaca
  `/master/*`.
- Wizard: `collectErrors()` ikut menghitung field bertipe `file` sebagai jawaban
  kosong, padahal backend sengaja melewatkan file — tombol kirim bisa terkunci
  selamanya. Sudah diselaraskan dengan `missingRequiredFields(skipFile: true)`.
- Suite PPDB setelah semua ini: 88 tes / 2294 assertion hijau; suite penuh backend
  542 tes / 4282 assertion hijau.

## Temuan sesi verifikasi browser (2026-09-21, sore→malam)

Matriks lama dinilai dari tes HTTP. Run browser justru menemukan dua hal yang tidak
bisa dilihat dari phpunit — bukti bahwa "tes hijau" ≠ "workflow berjalan".

1. **CRITICAL (DI PERBAIKI) — semua mutasi dari browser 419.** `bootstrap/app.php`
   memasang `EnsureFrontendRequestsAreStateful` di grup `api`; dengan
   `SANCTUM_STATEFUL_DOMAINS=localhost:5173` setiap POST/PATCH dari SPA ikut memakai
   grup `web` + `VerifyCsrfToken` → registrasi mandiri, login, tambah anak, simpan
   draf, submit, unggah berkas, bayar: semuanya mati 419. Aplikasi ini Bearer token,
   bukan sesi cookie, jadi middleware tersebut memang tidak pada tempatnya.
   Regression test: `test_mutasi_dari_origin_spa_stateful_tidak_ditolak_csrf`
   (butuh `$app['env']='production'` supaya `PreventRequestForgery` tidak skip —
   tanpa itu tesnya hijau meski bugnya ada; sudah dibuktikan gagal 419 saat
   middleware dipasang lagi).
2. **CRITICAL (DI PERBAIKI) — berkas PPDB terbaca publik tanpa login.** Dokumen
   (KK/akta/KTP/rapor), bukti bayar, dan lampiran sanggah ditulis ke disk `public`
   dan URL-nya dikembalikan apa adanya: `curl
   /storage/ppdb/documents/1/1/v1/rapor-e2e-smp-v1.pdf` → **200 tanpa auth**, dan
   bentuk path-nya bisa ditebak (ID berurutan). Arah perbaikan: disk `local`
   (root `storage/app/private`) + rute unduhan yang dijaga otorisasi yang sudah ada
   + signed URL singkat, plus perintah pemindahan berkas lama tanpa kehilangan data.
3. **HIGH (DI PERBAIKI) — kredensial super-admin terisi otomatis di halaman login.**
   `LoginPage.tsx` menyimpan `admin@syntaf.sch.id` / `password` sebagai state awal
   DAN menampilkan blok "Akun demo" tanpa syarat, sehingga ikut terkirim ke build
   produksi. Sekarang keduanya dikunci di balik `import.meta.env.DEV`.
4. **MEDIUM (DI PERBAIKI) — label periode di seeder.** `PpdbConfigSeeder::period()`
   menyusun `PPDB {tahun}` dari tanggal hari ini sementara `public_title` mengambil
   nama tahun ajaran, jadi halaman publik bisa menyebut dua tahun berbeda. Keduanya
   kini berasal dari `AcademicYear` aktif yang sama. Baris dev yang sudah ada tidak
   ditimpa (seeder mengembalikan periode yang sudah ada berdasarkan kode).
5. **LOW (DI PERBAIKI) — 404 membocorkan teks internal.** `NotFoundHttpException`
   untuk slug/rute yang tidak dikenal mengembalikan pesan Symfony/Eloquent lengkap
   (`No query results for model [App\Models\…]`). Kini disederhanakan, sedangkan
   `abort(404, '…')` yang memang untuk klien tetap lolos utuh.
6. **LOW (DI PERBAIKI) — copy "minimal maksimum 100 KB".** `formatKbLimit()` sudah
   menempelkan kata "maksimum", lalu dipakai lagi untuk batas bawah. Dipisah jadi
   `formatKbLimit()` / `formatKbFloor()`. Dikonfirmasi di browser: kartu dokumen
   kini berbunyi "maksimum 2 MB per berkas · minimal 100 KB" dan tolakan ukuran
   klien berbunyi "Ukuran berkas terlalu kecil, minimal 100 KB."
