# 35 — MODUL LAUNDRY MANAGEMENT (School & Boarding Laundry)

> Status: **Fase 1 — backend + API + tes selesai**. Blueprint implementasi modul
> Laundry Management sebagai bagian dari School/Boarding ERP: layanan laundry
> santri end-to-end dengan jatah gratis berperiode, pembayaran Wallet/RFID,
 *traceability* pakaian masuk sampai kembali, portal santri & wali.

Lokasi kode: `backend/app/Modules/SchoolOperations/Laundry/`
Route: `/api/v1/laundry/*`, `/api/v1/me/laundry/*`, `/api/v1/parent/children/{studentId}/laundry/*`
Konfigurasi: `backend/config/laundry.php`

---

## 1. Prinsip Inti

Laundry adalah **operational service platform**, bukan CRUD. Lifecycle:

```
Laundry Policy → Student Laundry Entitlement → Laundry Order → Receive/Check-in
→ Weigh/Count → Entitlement Check → Free Quota / Paid → Wallet RFID Payment
→ Processing (Washing → Drying → Ironing/Folding → QC → Packaging)
→ Ready → Pickup/Delivery → Completed
```

Source of truth tetap milik modulnya masing-masing — laundry **tidak**
menduplikasi master:

| Data | Pemilik | Akses Laundry |
|---|---|---|
| Santri | Student/Academic | `StudentProviderInterface` |
| Wali & dependent | Parent | `ParentProviderInterface` |
| Asrama/penempatan | Boarding | `BoardingProviderInterface` |
| **Saldo wallet** | **Wallet module** | `WalletProviderInterface` (Rule 10: laundry tidak menyimpan saldo) |
| Identitas RFID | Wallet/RFID | `RfidProviderInterface` |
| Akuntansi | Finance | `FinanceProviderInterface` |
| Notifikasi | Notification | `NotificationProviderInterface` |
| Aset/Inventaris/Workflow/Dokumen | modul masing-masing | provider opsional (fail-safe) |

Binding gateway lewat `config/laundry.php → gateways.*` (pola modul Transportation).

## 2. Skema Data (26 tabel `laundry_*`)

```
Katalog     : laundry_services, laundry_pricing_rules, laundry_machines
Policy      : laundry_policies, laundry_policy_versions
Jatah       : laundry_entitlements, laundry_entitlement_transactions (ledger)
Order       : laundry_orders, laundry_order_items, laundry_order_allocations
Bag/Label   : laundry_bags, laundry_bag_assignments, laundry_labels
Proses      : laundry_batches, laundry_batch_orders, laundry_processing_steps,
              laundry_processing_logs, laundry_quality_checks, laundry_incidents
Pembayaran  : laundry_payment_attempts, laundry_payments, laundry_refunds
Serah terima: laundry_pickups, laundry_deliveries
Audit/Notif : laundry_notifications, laundry_audit_logs
```

Keputusan skema penting:

- `laundry_entitlements` unik `(student_id, period_key)` — **periode baru →
  entitlement baru**; historis tidak pernah diubah (Rule 22).
- `laundry_entitlement_transactions` = ledger jatah (ALLOCATE/CARRY_IN/USE/
  ADJUST/EXPIRE/REVERSE) dengan rantai `balance_after` (Rule 21).
- `laundry_orders.pricing_snapshot` (JSON) + `laundry_order_allocations`
  (baris FREE/PAID) — harga & split dikunci saat calculate; perubahan harga
  tidak menyentuh order lama (Rule 9/23).
- `laundry_payment_attempts` unik `(laundry_order_id, idempotency_key)` dan
  `laundry_payments.wallet_transaction_id` unik — dua lapis anti double-charge
  (Rule 11/25).
- Order memakai soft delete; tidak pernah dihapus keras (Rule 16/17).

## 3. Jatah Gratis (Entitlement)

- **Policy berversi** (`laundry_policy_versions`): quota type (WEIGHT/ORDER/
  PIECE/AMOUNT), jumlah, periode (DAILY/WEEKLY/MONTHLY/SEMESTER/ACADEMIC_YEAR/
  CUSTOM), carry-over (NO/FULL/LIMITED + cap), scope (school, dormitory,
  education level, kategori santri BOARDING/DAY, academic year), efektivitas,
  priority. Versi terbit **tidak dapat diubah** — hanya publish/archive.
- **Resolusi deterministik**: PUBLISHED + efektif pada tanggal order + scope
  cocok; skor spesifisitas (dormitory > level > kategori > school) lalu
  priority, lalu id.
- **Carry over** dihitung saat rollover: sisa periode sebelumnya dibawa
  (penuh/terbatas cap); bagian yang tidak terbawa dicatat EXPIRE. Periode
  lama ditutup EXPIRED tanpa mengubah angka historisnya.
- **Adjustment** (§14): reason wajib, audit actor/before/after, ledger ADJUST.
- Pemakaian jatah mengunci baris (`lockForUpdate`) → aman dari race dua order.

## 4. Harga & Split Free/Paid

- Pricing rule efektif-berjangka per layanan/model (WEIGHT per kg, PIECE per
  jenis item, SERVICE flat), `minimum_quantity` (mis. minimum 3 kg),
  `minimum_charge`, biaya tambahan (EXPRESS/STAIN/BEDDING/DELICATE/EMERGENCY —
  fixed atau persen).
- **Jatah gratis selalu dipakai pertama** (Rule 4), mendukung **partial**
  (Rule 5): `free = min(sisa, permintaan)`, sisanya paid. Contoh: sisa 2 kg,
  order 5 kg, Rp10.000/kg → free 2 kg + paid 3 kg = **Rp30.000** (bukan
  seluruh 5 kg berbayar).
- Biaya tambahan (express dll.) selalu porsi berbayar — jatah menutup jasa
  cuci dasar. Quota PIECE menutup item termahal lebih dulu (menguntungkan
  santri); urutan tie-break stabil → deterministik (Rule 7).
- `POST /laundry/orders/preview` & `GET /orders/{id}/payment-preview`
  menampilkan tagihan + saldo wallet + sisa setelah bayar **sebelum** konfirmasi (§54).

## 5. Pembayaran Wallet/RFID

```
calculate → paid_amount > 0 ? PAYMENT_REQUIRED : PAID (NOT_REQUIRED)
pay(idempotency_key, rfid_uid?) →
  attempt unik (order+key) → WalletProvider.charge →
    sukses  : laundry_payments (+wallet_transaction ref, balance before/after),
              order PAID, notifikasi wali, Finance postRevenue, cek saldo rendah
    gagal   : attempt FAILED, order TETAP PAYMENT_REQUIRED (Rule 12),
              notifikasi INSUFFICIENT_BALANCE (nominal + saldo saat ini)
```

- Idempoten **dua lapis**: attempt unik di laundry + cek
  `wallet_transactions.metadata.idempotency_key` di gateway sebelum posting —
  retry jaringan tidak pernah memotong dua kali (§22).
- RFID (§70) hanya credential: uid harus resolve ke santri pemilik order;
  saldo tetap di Wallet. Charge memakai `WalletLedgerService` (TYPE_WITHDRAW),
  refund TYPE_REFUND dengan `source_transaction_id`.
- **Refund** (§23): pembatalan order setelah bayar → refund penuh lewat
  WalletProvider + jatah gratis dikembalikan (ledger REVERSE).
- **No payment, no paid processing** (Rule 27): order belum lunas ditolak
  masuk antrean/batch; override `FORCE_PROCESS`/`WAIVE_FEE` wajib reason +
  izin `laundry.payment.override` + audit before/after (§56/§57).

## 6. Operasional

- **Receiving** (§29): verifikasi santri aktif (Rule 2), scan bag (RFID/QR/
  kode), item + kondisi, label digital otomatis (payload QR: order, NIS, bag,
  berat, layanan, due date), due date dari `processing_hours` layanan.
- **Weighing** (§30): gross/bag/net tersimpan mentah; `net = gross − bag`.
- **Kanban** (§32) + **batch** (§33): batch per tahap (WASHING/DRYING/IRONING/
  FOLDING) dengan mesin & operator; start → order masuk tahap; complete →
  order maju ke tahap berikutnya. Langkah individual juga didukung (§35–§37).
- **QC** (§38): checklist configurable; PASSED → boleh dikemas; FAILED →
  kembali ke WASHING; REWORK → IRONING. Kemasan ditolak tanpa QC lulus.
- **Insiden** (§39–§41): MISSING/DAMAGED/STAIN/COLOR_TRANSFER/OTHER; item
  hilang/rusak menandai order LOST/DAMAGED (Rule 18). Kompensasi
  (WALLET_REFUND/FREE_QUOTA/…) **hanya dieksekusi setelah approval**.
- **Serah terima** (§43/§44): pickup counter atau delivery asrama; verifikasi
  RFID (cocok santri), QR (order/label), PIN (4 digit NIS), STUDENT_ID, atau
  AUTHORIZED_PERSON; nama penerima wajib (Rule 19); READY → DELIVERED →
  COMPLETED otomatis; bag dilepas kembali.
- **Traceability**: `GET /orders/{id}/timeline` merangkai status log, langkah
  proses, pembayaran + referensi wallet, QC, insiden, dan serah terima.

## 7. Portal

- **Santri** (`/api/v1/me/laundry/*`): quota, orders, order detail, statement.
  Identitas dari akun (ContextService mobile) — bukan parameter request.
- **Wali** (`/api/v1/parent/children/{studentId}/laundry/*`): overview
  (jatah/terpakai/sisa + saldo wallet dari provider §47), quota, orders,
  detail order + referensi transaksi wallet (saldo sebelum/sesudah §26),
  statement periode (§53/§72), daftar anak (`/parent/laundry/children`).
  Hanya dependent sah (Rule 20) — selain itu 403.

## 8. Notifikasi (§45/§46/§74)

Dikirim ke akun wali (ParentProvider) dan tercatat di `laundry_notifications`
(dedupe per order+tipe): ORDER_RECEIVED, PAYMENT_REQUIRED (nominal + saldo),
PAYMENT_COMPLETED (nominal + referensi wallet), PROCESSING, READY, DELIVERED,
INSUFFICIENT_BALANCE, LOW_BALANCE (threshold `config('laundry.low_balance_threshold')`),
INCIDENT.

## 9. Laporan (§48–§53, §73)

- `GET /laundry/dashboard` — order hari ini, pipeline per tahap, insiden, telat.
- `GET /laundry/reports/daily` — order, kg, free kg, paid kg, revenue, wallet
  transactions, completed/pending, insiden.
- `GET /laundry/reports/free-vs-paid` — **free laundry bukan cash revenue**:
  dilaporkan sebagai estimated value (subsidi) terpisah dari revenue.
- `GET /laundry/reports/quota` — per santri: allocated/used/remaining/carry/expired.
- `GET /laundry/reports/wallet` — pembayaran + referensi transaksi wallet.
- `GET /laundry/reports/operational` — avg processing time, order per operator,
  kg per mesin, order telat, QC gagal, item hilang/rusak.
- `GET /laundry/entitlements/{studentId}/statement` — statement santri (§52/§72).
- `GET /laundry/audit-logs` — jejak audit modul (§75).

## 10. Izin & Peran (§2, §64)

29 izin `laundry.*` (view/create/update/receive/weigh/process/qc/deliver/
cancel, quota.view/adjust/manage, payment.view/override, refund, incident.view/
manage, report/export/audit, dashboard.view, service/pricing/policy/bag/batch/
machine.manage, identify, notification.send, portal.view) dipetakan ke peran:
LAUNDRY_ADMIN (`*`), LAUNDRY_MANAGER, LAUNDRY_OPERATOR, LAUNDRY_RECEIVER,
LAUNDRY_PROCESSOR, LAUNDRY_QC, LAUNDRY_PACKER, LAUNDRY_DISTRIBUTOR,
LAUNDRY_CASHIER, BOARDING_ADMIN, FINANCE, STUDENT, PARENT.
Seeder: `LaundryMasterSeeder` (layanan default + peran/izin).

## 11. Event Domain (§60)

22 event di `Domain/Events` dipublikasikan pada titik transisi sah:
LaundryOrderCreated, LaundryReceived, LaundryWeighed, LaundryQuotaAllocated,
LaundryPaymentRequired/Completed/Failed, LaundryQueued,
LaundryWashingStarted, LaundryDryingStarted, LaundryIroningStarted,
LaundryFoldingCompleted, LaundryQualityCheckPassed/Failed, LaundryPackaged,
LaundryReady, LaundryDelivered, LaundryCompleted, LaundryIncidentCreated,
LaundryRefundRequested/Completed, LaundryQuotaAdjusted.

## 12. Testing (§76)

`backend/tests/Feature/Laundry/` — **29 tes / 212 assertion, hijau**:

- `LaundryGoldenFlowTest` — §66 gratis penuh s/d COMPLETED + notifikasi;
  §67 partial free+paid + wallet 100k→80k; §68 jatah habis; §69 saldo kurang
  (tanpa double charge, retry aman, notifikasi nominal+saldo, sukses setelah
  top up); §70 RFID (kartu santri lain ditolak, replay idempoten); §23 refund
  + pengembalian jatah; Rule 21 ledger USE tertaut alokasi.
- `LaundryQuotaPaymentTest` — alokasi periode + ledger; carry over FULL/
  LIMITED/NO; adjustment beralasan + audit before/after; jatah tidak pernah
  negatif; Rule 22 policy baru tidak mengubah entitlement berjalan; Rule 27
  belum lunas ditolak proses; override FORCE_PROCESS/WAIVE_FEE beralasan;
  idempotensi kunci berbeda setelah lunas; rantai `balance_after` ledger.
- `LaundryOperationsPortalTest` — receiving (label QR, item count, due date,
  bag IN_USE); netto = gross − tare; batch + langkah + QC gagal→rework→lulus→
  kemasan→delivery asrama + traceability log penuh; verifikasi penerima salah
  ditolak; insiden MISSING→LOST + kompensasi ber-approval; portal wali
  (jatah/order/wallet ref/statement, anak orang lain 403); portal santri /me;
  endpoint admin 403 tanpa izin; dashboard + identify RFID; Rule 2 santri
  nonaktif ditolak; Rule 23 snapshot harga.

## 13. Batas Fase / Ditunda

- UI React (dashboard admin, kanban, meja penerimaan, portal wali) — menyusul
  sebagai fase UI; API sudah menyediakan seluruh datanya.
- Sensor mesin/IoT, protokol perangkat RFID fisik (gateway sudah menyediakan
  titik integrasi), impor CSV, pengarsipan terjadwal.
- Perintah scheduler `laundry:expire-entitlements` (rollover saat ini lazy —
  terjadi saat entitlement periode baru pertama kali dibutuhkan; aman karena
  konsumsi/pembacaan selalu memanggil `ensurePeriod`).

## 14. Peta Kode

```
app/Modules/SchoolOperations/Laundry/
├── Application/Services/   12 service (policy, entitlement, pricing, order,
│                           payment, state machine, processing, incident,
│                           delivery, bag, catalog, notification, analytics,
│                           scope, audit)
├── Domain/Contracts/       11 provider interface (§59)
├── Domain/Events/          22 event domain (§60)
├── Domain/Support/         OrderStatus (mesin transisi), LaundryPeriod, LaundryMath
├── Http/Controllers/       9 controller (order, entitlement, policy, catalog,
│                           processing, incident, bag, delivery, dashboard, portal)
├── Infrastructure/Gateways/ 11 gateway (Wallet module, RFID wallet, student,
│                           parent, boarding, finance, notification, null-*)
├── Models/                 26 model Eloquent
├── Database/Migrations/    5 migrasi (26 tabel)
├── Database/Seeders/       LaundryMasterSeeder
├── Routes/                 api.php, me.php, parent.php (80 route)
└── Providers/              LaundryServiceProvider
```
