# Kontrak respons PPDB — fakta terverifikasi dari kode backend

Dipakai sebagai acuan saat memperbaiki frontend. Setiap baris di bawah sudah
dibaca langsung dari kode backend (bukan dari tipe frontend). Kalau frontend dan
dokumen ini berbeda, **backend yang benar** — kecuali dokumen ini menunjuk ke
fungsi yang ternyata kamu baca berbeda; kalau begitu laporkan, jangan diam-diam
mengarang kunci baru.

## Bentuk amplop

Semua respons: `{ success, message, data, meta }`. `unwrap()` di
`features/ppdb/api/ppdbApi.ts` mengembalikan `res.data.data`. Respons creation
berstatus **201** (`assertCreated()`), bukan 200.

## `GET /ppdb/portal/dashboard`
`PpdbRegistrationQueryService::dashboard()`
Kunci level atas: `parent`, `applicant_count`, `registration_count`,
`applications`, `counts`, `period`, `deadlines`, `unread_notifications`,
`empty_state` (`title`, `description`).

## `GET /ppdb/portal/profile` · `PUT /ppdb/portal/profile`
`PpdbPortalController::profile()` → `profilePayload($account)`; tulis ke `users`
+ `guardians`. Mutasi menuntut akun **aktif**: akun `pending_verification` /
`blocked` / `inactive` ditolak 403.

## `GET /ppdb/portal/registrations/{id}` (detail)
`PpdbRegistrationController::show()` → `new PpdbRegistrationDetailResource(
$registration, $this->queries->detail($registration))`.
Isinya **flat**: kolom `PpdbRegistrationResource` digabung (array_merge) dengan
isi `PpdbRegistrationQueryService::detail()`.

- Dari `PpdbRegistrationResource`: `id`, `registration_id`, `registration_number`,
  `status`, `status_label`, `payment_status`, `payment_status_label`,
  `document_status`, `document_status_label`, `is_editable`, `revision_note`,
  `notes`, `submitted_at`, `verified_at`, `selection_result`, `announced_at`,
  `completed_at`, `student_id`, `next_action`, `applicant` (objek: `id`,
  `full_name`, `nickname`, `gender_id`, `birth_date`, …), `program`
  (`id`, `code`, `name`, `slug`), `wave`, `applicant_id`, `parent_account_id`,
  `period_id`, `program_id`, `wave_id`, `created_at`, `updated_at`, `links`,
  `detail`, `documents`, `api_detail`, `payment_api`, `parent_account`
  (`parent_account_id`, `user_id`, `name`, `email`, `phone`, `account_status`,
  `account_status_label`).
- Dari `detail()`: `schema`, `answers`, `documents`, `progress`, `blockers`,
  `can_submit`, `next_action`, `allowed_transitions`, `editable`,
  `document_upload_allowed`, `validation_rules`, `applicant`, `parent`,
  `quota_override`, `histories`, `invoices`, `selection_participants`.
- `blockers.documents` = daftar `{id, name}` (bukan jumlah).
- TIDAK ADA `registration` (objek bersarang), `answer_map`, `missing`, `steps`,
  `invoice` (tunggal) di ujung ini. Baca `detail()` untuk bentuk `progress` dan
  `documents` sebelum menulis pemakainya.

## `GET /ppdb/portal/registrations/{registration}/payment`
`PpdbInvoiceController::forRegistration()` — persis:
`registration` (`id`, `registration_number`, `status`, `payment_status`,
`applicant_name`), `invoices` (koleksi `PpdbInvoiceResource`),
`outstanding_amount` (float), `currency` (`"IDR"`), `instructions`
(`manualInstructions()`), `online_available` (bool).
Tagihan **selalu jamak** (`invoices[]`), tidak ada `invoice`/`items`/`payments`/
`outstanding`/`payable`/`midtrans_enabled` di ujung ini.

## `POST /ppdb/portal/invoices/{invoice}/pay`
Prefix `portal`: `/api/v1/ppdb/portal/invoices/{id}/pay`. Payload:
`method` (`manual`|`midtrans`), `amount`, `payment_method_id`, `reference`;
jalur manual wajib `proof` (jpg/jpeg/png/pdf, maks 2 MB, multipart).
Midtrans belum dikonfigurasi → **422** yang menjelaskan, bukan 500.

## `GET /ppdb/portal/registrations/{id}/selection`
`PpdbSelectionController::forRegistration()` — `registration_id`,
`registration_number`, `status`, `selection_result`, `announced_at`,
`applicant_name`, `program`, `stages`, `composite`, `weights`, `formula`,
`re_registration` (`open`, `start`, `end`, `invoice`).
`selection_result` hanya terisi setelah panitia mengumumkan
(`PpdbAnnouncementService` satu-satunya penulis kolom itu), jadi sebelum
pengumuman nilainya `null`.

## `GET|POST /ppdb/portal/registrations/{id}/appeals`
`PpdbAppealController::forRegistration()` / `store()`. Baca
`PpdbAppealResource` untuk kunci baris sanggahan. Menyanggah sebelum ada hasil →
422.

## Notifikasi portal
`unread-count` mengembalikan `{unread, total}`; `read-all` mengembalikan
`{marked_as_read}`.

## Opsi referensi (admin + panitia)
`GET /ppdb/lookups` (permission `ppdb.view`) → `genders`, `religions`,
`education_levels`, `programs`, `academic_years`, `payment_methods`, masing-masing
daftar kolom yang di-whitelist. Peran `panitia-ppdb` **tidak** punya
`master-data.view` / `academic.view`, jadi `/master/*` dan `/programs` menghasilkan
403 senyap di halaman PPDB — selalu lewat `usePpdbReferenceOptions()`.

## Bentuk payload yang baru diverifikasi (sesi ini)

### `detail.documents` — `PpdbDocumentService::annotated()` (BUKAN array)
```
{
  requirements: Array< requirement & {
    status, status_label,
    document: PpdbApplicantDocumentResource|null,
    versions: PpdbApplicantDocumentResource[],
    version_count: number,
    uploaded: boolean,
  }>,
  unlinked_documents: PpdbApplicantDocumentResource[],
  summary: { total, required_total, uploaded, approved, rejected, under_review,
             missing: Array<{id,name,required}>, complete: boolean },
  can_upload: boolean,
}
```
Jadi `detail.documents.length` / `.map` / `.filter` itu SALAH — pakai
`detail.documents.requirements` dan `detail.documents.summary`.

### `detail.progress` — `PpdbRegistrationQueryService::progress()` (LARIK, fixed order)
Tiap langkah: `{ key, label, done, ...extra }`. Kunci yang ada (persis):
`data_calon`, `formulir`, `pertanyaan`, `dokumen`, `review`, `bayar`, `kirim`.
- `data_calon.extra`: `missing: string[]`
- `formulir` / `pertanyaan`: `answered`, `required_total`, `total`
- `dokumen`: `uploaded`, `required_total`, `total`, `missing`
- `review`: `hint`
- `bayar`: `applicable`, `payment_status`, `payment_status_label`, `amount_due`, `hint`
- `kirim`: `submitted_at`
Nama langkah `calon` / `pilihan` / `pembayaran` TIDAK ada di backend.

### `detail.answers` — `PpdbAnswerService::keyed()`
`{ fields: Record<string, unknown>, questions: Record<string, unknown>, list: [...] }`.
`answer_map` tidak ada.

### `detail.schema` — `PpdbScopeService::schemaForRegistration()`
`fields[]`: `{id, key, label, type, section, section_label, placeholder, description, required, order, options, validation}`;
`questions[]`, `document_requirements`, `fees`, `fee_total` (lihat `schemaFor()`), plus section.

### `detail.blockers`
`{ fields[], questions[], documents: Array<{id,name}>, applicant: string[], total, ready }`.
`missing` tidak ada di ujung ini — yang ada `blocker.documents` / `step.extra.missing`.

### Publik: program & gelombang (`quotaSummaries()`)
Row program: `id, code, name, description, education_level_id, quota, registered,
remaining, is_full` (+ `documents`, `fees`, `fee_total` di `publicPeriodPayload`).
Row wave: `id, code, name, priority, quota, registered, remaining,
registration_start, registration_end, description, is_open`.
TIDAK ADA `quota_summary`, `open_now`, `fee_from`, `fee_to`, `education_level`
(nama level) di ujung mana pun — sisakan dari `registered`/`remaining`/`is_full`/
`is_open` dan dari array `fees[]`.

## Aturan bisnis yang sudah diputuskan (jangan diubah frontend)
1. Ekspor = CSV (UTF-8 ber-BOM, guard injeksi formula `= + - @`), tanpa dependensi Excel.
2. Sanggahan diterima tidak pernah mengubah `failed`/`waitlisted` jadi `passed`;
   hanya membuka keputusan untuk direview ulang panitia.
3. Total bobot tahap seleksi wajib tepat 100%; tidak ada normalisasi otomatis.
4. Meluluskan di atas kuota butuh permission `ppdb.registration.override_quota`
   + `quota_override_reason` wajib.
5. Midtrans sandbox/default; kredensial production belum ada = bukan bug.

## Perintah verifikasi yang benar
`cd frontend && npx tsc -p tsconfig.app.json --noEmit`
(`npx tsc --noEmit` di root frontend **tidak memeriksa apa pun** — tsconfig root
hanya berisi `references`.) `npm run build` hanya dijalankan agen pusat.
