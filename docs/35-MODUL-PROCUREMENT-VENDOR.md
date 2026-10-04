# 35 — Modul Procurement & Vendor Management

> Blueprint & catatan implementasi modul pengadaan. Mengikuti prinsip yang sama
> dengan modul lain (`16-MODUL-CLINIC`, `22-MODUL-ASSET-SARPRAS`,
> `33-MODUL-TRANSPORTASI-FASE-2`): **modul baru tidak menduplikasi logika yang
> sudah dimiliki modul lain**, semua integrasi lewat kontrak.

---

## 1. Prinsip arsitektur

| Domain | Sumber kebenaran |
| --- | --- |
| Proses pengadaan (PR, RFQ, penawaran, PO, penerimaan, invoice, matching) | **Modul Procurement** |
| Jurnal, payable, pembayaran, GL, laporan keuangan | Modul Accounting / Finance |
| Saldo stok | Modul Inventory |
| Aset tetap | Modul Asset & Sarpras |
| Persetujuan | Workflow & Approval Engine |
| Anggaran (pagu/komitmen/realisasi) | Modul Budget / Accounting |

Procurement **tidak** membuat jurnal, payable, kartu stok, atau aset. Ia
menyerahkan pekerjaan itu lewat kontrak:

```
AccountingProviderInterface   BudgetProviderInterface
InventoryProviderInterface    AssetProviderInterface
WorkflowProviderInterface     NotificationProviderInterface
DocumentProviderInterface
```

Sebaliknya, Procurement **memenuhi** dua kontrak milik modul Asset:

- `App\Modules\Asset\Domain\Contracts\VendorProviderInterface`
- `App\Modules\Asset\Domain\Contracts\ProcurementProviderInterface`

## 2. Lokasi kode

```
backend/app/Modules/Procurement/
├── Application/Services/     # 20 layanan domain
├── Domain/Contracts/         # 7 kontrak integrasi keluar
├── Domain/Enums/             # 24 enum (status, jenis, aksi audit)
├── Domain/Events/            # ProcurementDomainEvent
├── Domain/Exceptions/        # ProcurementException (→ 422)
├── Domain/Support/           # IntegrationResult, EnumOptions
├── Infrastructure/Providers/ # implementasi Eloquent/Null/Local/Log
├── Models/                   # 47 model (prefix tabel `proc_`)
├── Providers/ProcurementServiceProvider.php
├── Routes/api.php            # /api/v1/procurement/*
├── Database/Migrations/      # 7 berkas migrasi
├── Database/Seeders/         # izin + master
└── Console/                  # 2 perintah artisan
```

Registrasi: `bootstrap/providers.php`. Konfigurasi: `config/procurement.php`.

## 3. Skema database (prefix `proc_`)

| Fase | Tabel |
| --- | --- |
| 1 Vendor | `proc_vendors`, `proc_vendor_contacts`, `proc_vendor_addresses`, `proc_vendor_bank_accounts`, `proc_vendor_documents`, `proc_vendor_categories`, `proc_vendor_performance_records` |
| 2 Katalog & PR | `proc_categories`, `proc_items`, `proc_item_vendors`, `proc_purchase_requests`, `proc_purchase_request_items`, `proc_purchase_request_approvals`, `proc_events`, `proc_event_requests` |
| 3 RFQ & Penawaran | `proc_rfqs`, `proc_rfq_vendors`, `proc_rfq_items`, `proc_quotations`, `proc_quotation_items`, `proc_quotation_documents`, `proc_evaluation_criteria`, `proc_quotation_evaluations`, `proc_quotation_evaluation_items`, `proc_vendor_selections` |
| 4 PO | `proc_purchase_orders`, `proc_purchase_order_items`, `proc_purchase_order_versions`, `proc_purchase_order_approvals`, `proc_vendor_confirmations` |
| 5 Penerimaan | `proc_goods_receipts`, `proc_goods_receipt_items`, `proc_goods_receipt_inspections`, `proc_service_receipts`, `proc_service_receipt_items` |
| 6 Invoice | `proc_vendor_invoices`, `proc_vendor_invoice_items`, `proc_invoice_match_results`, `proc_invoice_exceptions`, `proc_integrations` |
| 7 Pendukung | `proc_contracts`, `proc_contract_items`, `proc_budgets`, `proc_commitments`, `proc_audit_logs`, `proc_events_log`, `proc_settings` |

Catatan:
- Rujukan lintas modul (`department_id`, `campus_id`, `cost_center_id`,
  `warehouse_id`, `inventory_item_ref`, `asset_category`) **tanpa foreign key**
  agar modul tidak bergantung urutan migrasi modul lain.
- Nominal memakai `decimal(18,2)`; kuantitas `decimal(18,4)`.
- Penomoran dokumen memakai `document_sequences` yang sudah ada (token
  `{SEQ:n}`, `{YYYY}`, dst.) dengan `next()` atomik (`lockForUpdate`) sehingga
  tidak pernah ada nomor kembar.

## 4. Alur siklus hidup

```
Draf PR → Diajukan → Ditinjau → Disetujui/Ditolak/Dikembalikan
        → RFQ → Pengumpulan Penawaran → Perbandingan → Evaluasi → Pemilihan Vendor
        → PO (Draf → Persetujuan → Terbit → Konfirmasi Vendor)
        → Penerimaan (sebagian/penuh) → Inspeksi
        → Invoice → 3-Way Match → (Pengecualian) → Handoff Accounting
        → Pembayaran (dibaca dari Accounting) → Ditutup
```

Didukung juga: batal, tolak, kembalikan, amandemen (berversi), penerimaan
sebagian, invoice sebagian, backorder, tutup, buka kembali.

## 5. Aturan bisnis yang ditegakkan (§52)

1. PO tidak boleh dibuat tanpa sumber yang sah kecuali pembelian langsung/darurat.
2. PO tidak boleh diterbitkan sebelum disetujui.
3. PO yang sudah terbit tidak boleh disunting langsung — hanya lewat amandemen.
4. Amandemen selalu menghasilkan versi baru; histori tidak pernah ditimpa.
5. Penerimaan tidak boleh melebihi toleransi over-delivery (configurable).
6. Invoice tidak diproses menjadi payable sebelum matching/pengecualian selesai.
7. Invoice ganda dideteksi (vendor + nomor + tanggal + nominal).
8. Mismatch menjadi **pengecualian**, bukan langsung dikirim ke Accounting.
9. Handoff Accounting idempoten (`PROC-INV-{invoice_id}`).
10. Retry tidak menghasilkan payable ganda.
11. Procurement tidak menyimpan ledger pembayaran (hanya cermin status).
12. Procurement tidak membuat GL/jurnal sendiri.
13–15. Stok, aset, dan anggaran lewat kontrak (Inventory/Asset/Budget Provider).
16. Vendor nonaktif/restricted/blacklisted tidak dapat dipakai tanpa override.
17. Dokumen wajib yang kedaluwarsa memblokir proses.
18–19. Nominal memakai decimal; mata uang eksplisit.
20. Histori tidak berubah karena perubahan master data (snapshot).

Override (vendor, kuota penawaran, anggaran, harga, toleransi, mismatch) selalu
memerlukan **alasan + pengguna berwenang + jejak audit**.

## 6. API

Seluruh endpoint di bawah `/api/v1/procurement/*` (≈95 rute). Contoh kelompok:

```
GET/POST  vendors, vendors/{id}/{submit,verify,approve,reject,suspend,classify,documents,performance}
GET/POST  categories, items
GET/POST  purchase-requests  (+validate, submit, approve, reject, return, timeline, consolidate)
GET/POST  rfqs  (+invite, issue, close, comparison, criteria, ranking)
GET/POST  quotations  (+evaluate)
GET/POST  vendor-selections  (+approve)
GET/POST  purchase-orders  (+from-selection, submit, approve, reject, issue, amend, versions, confirm, close, cancel)
GET/POST  goods-receipts  (+inspect)  ·  service-receipts (+accept/reject)
GET/POST  invoices  (+match, override, approve, handoff, retry-handoff, sync-status, payment-status)
GET/POST  invoice-exceptions  (+investigate, resolve)
GET       dashboard, reports/{type}, reports/spend/analytics, reports/cycle-times, budget/availability
GET/POST  settings
```

Format respons mengikuti `BaseApiController` → `{success, message, data, meta}`.

## 7. Peran & izin

Izin: `procurement.{view,manage,create_pr,approve_pr,create_rfq,manage_quotation,
evaluate_vendor,create_po,approve_po,issue_po,receive,inspect,manage_invoice,
match_invoice,send_to_accounting,manage_vendor,view_reports,manage_settings,audit.view}`

Peran baru: `admin-pengadaan`, `manajer-pengadaan`, `petugas-pengadaan`,
`pemohon-pengadaan`, `verifikator-anggaran`, `petugas-penerimaan`,
`inspektur-mutu`, `verifikator-invoice`. Izin juga diberikan ke peran ERP yang
sudah ada (`kepala-sekolah`, `kepala-unit`, `auditor`, `guru`, `pegawai`).

## 9. Antarmuka React

Modul punya permukaan UI sendiri di `frontend/src/features/procurement`:

```
types.ts                      Bentuk data (dicerminkan dari Model/Controller)
lib/labels.ts                 Terjemahan enum → label + warna
api/procurementApi.ts         Satu modul API per domain (vendor, katalog, PR, RFQ,
                              penawaran, pemilihan, PO, penerimaan, invoice,
                              laporan, pengaturan)
components/ProcurementUi.tsx  StatusBadge, DetailRow, FormField, SectionCard,
                              Money, useEnumMetadata, useVendorOptions, dll.
pages/                        16 halaman
```

Halaman: dasbor, vendor (daftar/detail), katalog, PR (daftar/detail), RFQ
(daftar/detail), PO (daftar/detail), penerimaan, invoice (daftar/detail),
pengecualian invoice, laporan, pengaturan.

Route & menu didaftarkan lewat `routes/domains/procurement.tsx`
(mengekspor `domainRoutes` + `procurementMenu`), lalu ditempel di
`constants/menu.ts` sebagai grup **Pengadaan**. Sesuai konvensi modul, angka
keuangan tidak dirender untuk pengguna tanpa izin — dan backend juga tidak
mengirimkannya.

## 10. Menjalankan

```bash
cd backend
php artisan migrate                      # membuat tabel proc_*
php artisan db:seed --class="App\Modules\Procurement\Database\Seeders\ProcurementPermissionSeeder"
php artisan db:seed --class="App\Modules\Procurement\Database\Seeders\ProcurementMasterSeeder"
php artisan procurement:vendor-performance 2026-09
php artisan procurement:retry-handoff

php tests/Manual/procurement_smoke.php    # uji asap alur emas (harus 0 gagal)
```

## 11. Status implementasi

**Selesai (backend):** skema 47 tabel, 47 model, 24 enum, 7 kontrak integrasi,
20 layanan domain (vendor & onboarding, kinerja vendor, katalog, PR & approval,
RFQ, penawaran & perbandingan, evaluasi, pemilihan vendor, PO & amandemen
berversi, penerimaan & inspeksi, penerimaan jasa, invoice & deteksi duplikat,
3-way matching & pengecualian, handoff Accounting idempoten, anggaran, analitik),
10 controller, ±95 rute, seeder izin & master, 2 perintah artisan, serta adapter
untuk kontrak modul Asset.

**Selesai (verifikasi):** 7 migrasi `proc_*` berjalan bersih; skrip uji asap
`tests/Manual/procurement_smoke.php` menempuh alur emas end-to-end dan **lulus
41/0** (3 vendor → PR → approval → konsolidasi → RFQ → 3 penawaran → perbandingan
→ evaluasi → pemilihan → PO → amandemen ber-versi → penerimaan sebagian+penuh →
invoice → 3-way match → handoff idempoten).

**Selesai (frontend):** 16 halaman React + API + komponen bersama + entri
route/menu.

**Belum:** portal vendor (self-service), unggah/impor-ekspor berkas, rendering PDF
dokumen pengadaan, endpoint & layar kontrak pengadaan (model sudah ada, rute
belum), dan suite PHPUnit otomatis (uji dilakukan lewat skrip smoke).

