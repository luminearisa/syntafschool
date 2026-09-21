# Syntaf School ERP — Catatan Proyek

## Stack & Env
- `backend/` Laravel 13 + PHP 8.4 (SQLite dev `backend/database/database.sqlite`, test in-memory) ·
  `frontend/` React 18 + TS + Vite 6 · `docs/`
- Dev: backend **8001**, frontend 5173, `VITE_API_BASE_URL=http://localhost:8001/api/v1`
- `admin@syntaf.sch.id` / `password` · Reset: `php artisan migrate:fresh --seed`

## Jalur Paralel (WAJIB — jangan edit file bersama)
- Backend: route per-domain di `backend/routes/api/<domain>.php` (auto `require` di grup `v1`+`auth:sanctum`).
  **Jangan edit `routes/api.php`.**
- Frontend: `frontend/src/routes/domains/<domain>.tsx` ekspor `domainRoutes` + `domainMenu` (auto glob).
  **Jangan edit `routes/index.tsx` atau `constants/menu.ts`.**
- Tipe per-domain di `src/types/<domain>.ts`.

## Konvensi Backend
- `BaseApiController` → `{success,message,data,meta}`; selalu API Resource; `BaseModel` (`$guarded=['id']`)
- FormRequest di `app/Http/Requests/<Domain>/` + `attributes()` Indonesia
- Otorisasi per-route `middleware('permission:<modul>.<aksi>')`; whitelist `protected array $filterable`
- Nama route `<resource>.<aksi>`; error bisnis `$this->errorResponse($msg, 422)`
- Permission modul `academic`: view/create/update/delete/publish/approve/promote/grade/attendance;
  `assessment`: view/create/update/delete

## Konvensi Frontend
- API `src/features/<domain>/api/`, halaman `pages/`; modal props
  `{open, editing, submitting, serverErrors, onClose, onSubmit}`
- `PermissionGuard` di root halaman, `<Can perm="...">` untuk tombol
- `getValidationErrors()` / `getErrorMessage()` dari `@/lib/axios`; `serverErrors` = `Record<string,string[]>`
- Pakai `ui/*`, `common/Pagination`, `layout/ConfirmDialog` — jangan bikin baru
- `SearchSelect` merender error sendiri (jangan ganda); nilainya `string|number|''` → konversi `toId()`

## Jebakan
- **`npx tsc --noEmit` di root frontend TIDAK memeriksa apa pun** (tsconfig hanya `references`).
  Pakai `npx tsc -p tsconfig.app.json --noEmit` atau `npm run build`.
- SQLite "no such column" → file DB lebih tua dari migrasi → `migrate:fresh --seed`
- `HTTP_PROXY` aktif: `curl --noproxy '*'`; python `env -u HTTP_PROXY -u HTTPS_PROXY`
- Migrasi = beberapa tabel per file, `down()` urutan terbalik; FK master `restrictOnDelete()`,
  anak `cascadeOnDelete()`, opsional `nullOnDelete()`
- Kolom `time` disimpan `H:i:s`, API mengembalikan `H:i` (`substr($v,0,5)`)
- Guest tanpa `Accept: application/json` → 500; override `redirectGuestsTo` di `bootstrap/app.php` jangan dihapus
- `AcademicYear::value('start_date')` = Carbon → `->format('Y')`
- BSD grep: `grep -E "a|b"`. **Jangan dua edit ke file sama dalam satu pesan.**
- Belum ada repo Git → tidak ada rollback

## Pola Modul Kompleks
- Bentrok jadwal: `detectConflicts()` (daftar) + `assertNoConflict()`; `check-conflict` selalu 200 + `has_conflict`
- "Satu primari" (wali utama): semua mutasi lewat `assertSinglePrimary()`/`clearPrimary()`/promosi otomatis
- Aksi yang menulis 2 tabel wajib punya aksi kebalikan simetris
- Rantai wilayah: `assertRegionChain()` + `useRegionCascade()`; `postal_code` diisi dari desa
- Presensi: persentase dihitung terhadap **hari efektif** (`basis` menandai bila jatuh ke hari tercatat)
- Keputusan kenaikan/kelulusan menyimpan snapshot; nilai terbit menyimpan `grading_formula_id`

## Seeder & Progres
- `migrate:fresh --seed` bersih (tanpa seeder dilewati): 40 siswa, 40 wali, 152 jadwal, 8 RPP,
  304 jurnal, 12 tugas, 400 presensi siswa, 100 presensi pegawai, 2760 hari efektif
- A0–A6 selesai; A11a/A11b backend selesai (frontend belum); berikutnya **A7 Penilaian**

## Peta Dokumen
`docs/`: `01-PLAN-UTAMA` · `02-PLAN-MASTER-DATA` · `03-RANCANGAN-DATABASE` ·
`04-SPESIFIKASI-API-FRONTEND` · `05-PLAN-FASE-2` · `06-BLUEPRINT-AKADEMIK` (A0–A12) · `PROGRESS.md`
