# Modul Workflow & Approval Engine — Blueprint & Implementasi

> **Infrastruktur bersama**, bukan modul fitur. Seluruh modul ERP (Finance,
> Procurement, HRD, Inventory, Asset, PPDB, Student, Boarding, Document/
> e-Office, Maintenance, Clinic) mengirim permintaan persetujuannya ke sini.
> Tidak ada satu pun modul yang membuat sistem approval sendiri.

---

## 1. Prinsip yang mengikat seluruh keputusan desain

| Prinsip | Artinya dalam kode |
|---|---|
| **Generic** | Engine hanya mengenal `subject`, node, kondisi, approver, keputusan, aksi. Tidak ada kata "PO", "cuti", atau "tagihan" di dalamnya. |
| **Configurable** | Tidak ada ambang nominal, SLA, atau daftar approver di dalam kode. Semua dari definisi + `config/workflow.php`. |
| **Reusable** | Satu tabel, satu API, satu kotak masuk untuk semua modul. |
| **Modular** | `App\Modules\Workflow` dengan Domain/Application/Infrastructure, mandiri lewat service provider. |
| **Versioned** | Setiap perubahan alur melahirkan **versi baru**; request lama tetap di versinya. |
| **Auditable** | Jejak audit berantai hash, append-only, dapat diverifikasi ulang. |
| **Secure** | Izin granular per endpoint + cakupan baris + larangan self-approval + SoD. |
| **Idempotent** | Keputusan & notifikasi memakai kunci idempoten yang unik di basis data. |
| **Extensible** | Resolver, aksi, provider konteks, trigger, kalender, dan direktori organisasi semuanya kontrak yang dapat ditukar. |
| **Production-ready** | SLA, eskalasi, delegasi, retensi, kalender kerja, perintah terjadwal, dan 51 tes (253 asersi) hijau. |

**Aturan tegas:** engine tidak pernah "melewati approval diam-diam". Bila approver
tidak ditemukan, request ditahan atau digagalkan **dengan alasan yang terlihat**
(§58, §59).

---

## 2. Peta konsep

```
Business Transaction        (modul: Purchase Request, Cuti, Pengeluaran, Dokumen)
        ↓  context provider (kontrak milik modul)
Workflow Definition         (template: PURCHASE_REQUEST_APPROVAL)
        ↓  versi terbit (beku, ber-hash)
Workflow Instance           (WF-2026-000123, satu dokumen bisnis)
        ↓  graf: node + edge + kondisi
Approval Step               (step instance, misal "Bendahara")
        ↓  resolver approver
Approver                    (user / role / atasan / unit / cost center / jabatan / dinamis)
        ↓  keputusan (idempoten)
Decision                    (APPROVE | REJECT | RETURN | REQUEST_CHANGES)
        ↓  kuorum (ALL | ANY | MINIMUM_N | PERCENTAGE)
Next Step → … → Approved / Rejected / Cancelled
        ↓  aksi otomatis (kontrak milik modul)
Completed                   (PO dibuat, status diubah, peristiwa dipublikasikan)
```

---

## 3. Struktur modul

```
backend/app/Modules/Workflow/
├── Domain/
│   ├── Enums/          DefinitionStatus, VersionStatus, NodeType, ApprovalMode,
│   │                   QuorumPolicy, InstanceStatus, StepStatus, StepResult,
│   │                   ApprovalStatus, DecisionType, SlaStatus, EscalationAction,
│   │                   EscalationStatus, EmptyApproverPolicy, ReturnStrategy,
│   │                   TriggerType, DelegationStatus, ApproverSource, Priority,
│   │                   ConditionOperator, NotificationEvent/Channel/Status, AuditAction
│   ├── Contracts/      ApproverResolverInterface, ConditionEvaluatorInterface,
│   │                   WorkflowActionInterface, WorkflowTriggerInterface,
│   │                   WorkflowContextProviderInterface, WorkflowSubjectStatusInterface,
│   │                   NotificationProviderInterface, BusinessCalendarInterface,
│   │                   OrganizationalDirectoryInterface
│   ├── Exceptions/     WorkflowException (422), WorkflowConflict (409), WorkflowAccessDenied (403)
│   └── Support/        WorkflowContext, WorkflowGraph, ResolvedApprover,
│                       WorkflowActionContext, NotificationMessage
├── Application/
│   ├── Services/       18 layanan (lihat §6)
│   └── Support/        WorkflowPolicy (kebijakan efektif), ApproverResolution
├── Infrastructure/
│   ├── Registry/       WorkflowRegistrar (titik ekstensi tunggal)
│   ├── Approvers/      7 resolver bawaan
│   ├── Conditions/     DefaultConditionEvaluator
│   ├── Actions/        notification.send, event.publish, subject.update_status, business.create
│   ├── Calendar/       WorkingCalendar (jam kerja + hari libur)
│   ├── Directory/      DatabaseOrganizationalDirectory
│   └── Notifications/  EngineNotificationProvider
├── Http/Controllers/   4 controller (definition, instance, approval, admin)
├── Models/             24 model (prefix tabel `workflow_`)
├── Database/Migrations/  3 migrasi (24 tabel)
├── Database/Seeders/   WorkflowPermissionSeeder, WorkflowReferenceSeeder
├── Console/            workflow:check-sla, workflow:expire, workflow:health, workflow:trigger
├── Notifications/      WorkflowAlertNotification
├── Providers/          WorkflowServiceProvider
└── Routes/api.php      70+ endpoint /api/v1/workflows/*
```

---

## 4. Basis data (24 tabel, semuanya ber-prefix `workflow_`)

**Sisi desain** (`2026_10_01_090001`): `workflow_definitions`, `workflow_versions`,
`workflow_nodes`, `workflow_edges`, `workflow_conditions`, `workflow_actions`,
`workflow_calendars`, `workflow_calendar_holidays`.

**Sisi jalan** (`2026_10_01_090002`): `workflow_instances`, `workflow_step_instances`,
`workflow_approvals`, `workflow_decisions`, `workflow_submissions`, `workflow_contexts`,
`workflow_events`, `workflow_comments`, `workflow_attachments`, `workflow_sla_records`,
`workflow_escalations`, `workflow_notifications`, `workflow_audit_logs`.

**Delegasi & direktori** (`2026_10_01_090003`): `workflow_delegations`,
`workflow_org_reports`, `workflow_org_assignments`.

Pilihan desain yang disengaja:

- **Status sebagai `string`**, nilainya diikat enum PHP → menambah status baru
  tidak perlu `ALTER TABLE`.
- **Tabel riwayat tanpa `updated_at`** (`decisions`, `events`, `submissions`,
  `contexts`, `comments`, `attachments`, `audit_logs`, `escalations`): model
  mematikan `$timestamps`, dan tidak ada endpoint update/delete untuknya.
- **`workflow_instances` tidak menyimpan salinan entitas bisnis.** Yang tersimpan:
  rujukan subjek, nominal, unit, cost center, dan snapshot konteks ber-hash.
- **`workflow_approvals` unik per (step_instance, approver)** dan
  `workflow_notifications.idempotency_key` unik — dua jaminan idempotensi di
  tingkat basis data, bukan sekadar niat di kode.
- **Tidak ada perubahan pada tabel inti ERP** → migrasi aman dijalankan pada
  basis data produksi yang sudah berisi data.

---

## 5. Graf workflow

Delapan jenis node (§18, §54):

| Node | Perilaku |
|---|---|
| `START` | Titik masuk tunggal. |
| `APPROVAL` | Menunggu keputusan. Mode `SEQUENTIAL` / `PARALLEL` / `HYBRID`; kuorum `ALL` / `ANY` / `MINIMUM_N` / `PERCENTAGE`. |
| `REVIEW` | Sama seperti APPROVAL, tetapi hasilnya `COMPLETED` (bukan `APPROVED`) — dipakai pemeriksa yang tidak berwenang menyetujui. |
| `CONDITION` | Memilih cabang: edge pertama (urut `priority`) yang kondisinya benar; cabang lain dinonaktifkan. |
| `PARALLEL` | Fan-out struktural: semua cabang aktif bersamaan; node penggabung menunggu **seluruh** cabang berakhir. |
| `NOTIFICATION` | Menjalankan aksi `enter` (mis. kirim notifikasi) lalu melanjutkan. |
| `AUTOMATIC` | Aksi otomatis tanpa interaksi (mis. memanggil handler modul). |
| `END` | Menutup instance: aksi `approved` + `completed`, status `APPROVED`, notifikasi penutup. |

**Kesiapan node** (`WorkflowExecutionService::isReady`): sebuah node aktif bila
seluruh cabang masuk yang masih hidup sudah **berakhir** (selesai atau dilewati).
Cabang yang dimatikan kondisi ditangani `pruneUnreachable()` — node yang semua
cabangnya mati ditandai `skipped`, sehingga penggabungan cabang paralel tetap
deterministik dan tidak pernah menggantung.

**Keadaan eksekusi** disimpan di `workflow_instances.execution_state`
(`completed`, `skipped`, `active`, `inactive_edges`, `hops`) sehingga request
dapat dilanjutkan oleh proses apa pun, tahan terhadap restart, dan tidak
bergantung pada tumpukan panggilan. Batas `max_execution_hops` mencegah loop tak
terbatas pada definisi yang keliru.

---

## 6. Layanan (18)

| Layanan | Tanggung jawab |
|---|---|
| `WorkflowDefinitionService` | CRUD definisi, pencarian definisi yang cocok untuk subjek/event. |
| `WorkflowVersionService` | Daftar/buat versi, bandingkan dua versi (node, SLA, approver yang berubah). |
| `WorkflowBuilderService` | Simpan graf draf, **validasi lengkap (§55)**, terbitkan, arsipkan, klon, meta builder. |
| `WorkflowInstanceService` | Daur hidup: create → submit → revise → resubmit → cancel → expire, timeline, progres. |
| `WorkflowExecutionService` | Mesin graf: aktivasi node, percabangan, penggabungan, penutupan/gagal instance. |
| `WorkflowStepService` | Aktivasi step, pembuatan penugasan, antrean bertahap, kebijakan approver kosong, penetapan manual. |
| `ApprovalService` | Keputusan: otorisasi, delegasi, self-approval, SoD, komentar wajib, idempotensi, kuorum. |
| `ApproverResolverService` | Menerjemahkan spesifikasi approver menjadi pengguna nyata, menyaring akun nonaktif & requester. |
| `ConditionEvaluatorService` | Menjembatani kondisi bernama/inline, memilih cabang, melaporkan field yang dirujuk. |
| `WorkflowActionService` | Menjalankan aksi otomatis per hook, mencatat keberhasilan/kegagalan. |
| `DelegationService` | Delegasi bercakupan & berbatas waktu, pencabutan, penyapuan aktivasi/kedaluwarsa. |
| `EscalationService` | Sapuan SLA: pengingat → segera jatuh tempo → terlambat → eskalasi berjenjang. |
| `SlaService` | Pencatatan ambang SLA per step, penyelesaian, ringkasan kepatuhan. |
| `WorkflowNotificationService` | Pengiriman notifikasi ber-idempotensi, resolusi penerima default. |
| `WorkflowSimulationService` | Simulasi tanpa instance nyata (§56) & dry run request berjalan (§57). |
| `WorkflowContextService` | Perakitan snapshot konteks + hash, validasi konteks wajib. |
| `WorkflowAuditService` | Audit berantai hash per instance, verifikasi rantai, ringkasan per aksi. |
| `WorkflowAnalyticsService` | Dasbor, volume, durasi, kepatuhan SLA, beban kerja tertunda, titik tersangkut. |
| `WorkflowAccessService` / `WorkflowInboxService` | Cakupan baris (siapa melihat apa) & kotak masuk terpusat dengan penyaring. |

---

## 7. Titik ekstensi (kontrak)

```php
// Modul mendaftarkan kemampuannya pada satu registry.
$registrar = app(WorkflowRegistrar::class);

$registrar->registerContextProvider($financePurchaseRequestProvider); // data → konteks
$registrar->registerTrigger($purchaseRequestTrigger);                 // event → workflow
$registrar->registerAction($createPurchaseOrderAction);               // approval → aksi bisnis
$registrar->registerResolver($waliKelasResolver);                     // approver khusus modul
```

`WorkflowContextProviderInterface` adalah **satu-satunya jembatan** ke data
modul. Engine tidak pernah menyentuh tabel bisnis; kondisi, resolver, dan aksi
selalu membaca snapshot konteks yang sudah dibekukan dan di-hash. Konsekuensinya:

- riwayat approval tetap dapat direkonstruksi walau data bisnis sudah berubah;
- engine tidak dapat "diam-diam" membaca data yang tidak disumbangkan modul;
- nominal yang dipakai untuk percabangan tidak dapat dipalsukan dari payload
  klien — provider modul adalah sumber kebenaran.

---

## 8. API (`/api/v1/workflows/*`, 70+ endpoint)

Semua endpoint memerlukan `auth:sanctum` + izin granular + batas laju per pengguna.

**Definisi & versi**
```
GET    definitions                      workflow.definition.view
POST   definitions                      workflow.definition.manage
GET    definitions/{id}                 workflow.definition.view
PUT    definitions/{id}                 workflow.definition.manage
POST   definitions/{id}/deactivate       workflow.definition.manage
GET    definitions/{id}/versions         workflow.definition.view
POST   definitions/{id}/versions         workflow.definition.manage
POST   definitions/{id}/simulate         workflow.definition.view
GET    versions/{id}                     workflow.definition.view
PUT    versions/{id}                     workflow.definition.manage
POST   versions/{id}/validate            workflow.definition.view
POST   versions/{id}/publish             workflow.version.publish
POST   versions/{id}/archive             workflow.version.publish
POST   versions/{id}/simulate            workflow.definition.view
```

**Request (instance)**
```
GET    instances                         workflow.request.view
POST   instances                         workflow.request.create
GET    instances/{id}                    workflow.request.view   (+ cakupan baris)
POST   instances/{id}/submit             workflow.request.submit
POST   instances/{id}/revise             workflow.request.submit
POST   instances/{id}/resubmit           workflow.request.submit
POST   instances/{id}/cancel             workflow.request.cancel
GET    instances/{id}/timeline           workflow.request.view
GET    instances/{id}/context            workflow.request.view
POST   instances/{id}/comments           workflow.request.view
POST   instances/{id}/attachments        workflow.request.view
GET    instances/{id}/audit              workflow.audit.view
POST   instances/{id}/dry-run            workflow.request.view
POST   instances/{id}/steps/{step}/assign  workflow.approval.reassign
POST   instances/{id}/steps/{step}/skip    workflow.approval.skip
```

**Keputusan & kotak masuk**
```
GET    approvals                         workflow.approval.view
GET    approvals/{id}                    workflow.approval.view
POST   approvals/{id}/approve            workflow.approval.decide
POST   approvals/{id}/reject             workflow.approval.decide
POST   approvals/{id}/return             workflow.approval.decide
POST   approvals/{id}/request-changes    workflow.approval.decide
POST   approvals/{id}/reassign           workflow.approval.reassign
GET    inbox/approvals                   workflow.approval.view
GET    inbox/requests                    workflow.request.view
GET    inbox/counter                     workflow.request.view
GET    inbox/filters                     workflow.request.view
```

**Operasional**
```
GET    delegations / POST delegations / POST delegations/{id}/revoke
GET    sla / POST sla/sweep / GET escalations
GET    dashboard / analytics / audit / POST audit/verify
GET    calendars / POST calendars / POST calendars/{id}/holidays / DELETE holidays/{id}
GET    directory / POST directory/assignments / DELETE directory/assignments/{id} / POST directory/reports
GET    meta / health
```

Idempotensi keputusan: kirim header `Idempotency-Key`. Permintaan ulang dengan
kunci sama mengembalikan keputusan yang sama (HTTP 200); penugasan yang sudah
diputuskan dan diminta ulang tanpa kunci idempoten dijawab **409** beserta
keputusan aslinya.

---

## 9. Peran & izin

**Peran sistem** (`WorkflowPermissionSeeder`):

| Peran | Kewenangan | Yang **tidak** boleh |
|---|---|---|
| `ERP_ADMIN` | seluruh izin workflow + modul lain | — |
| `WORKFLOW_ADMIN` | definisi, versi, publikasi, kalender, direktori, pemantauan | **tidak** boleh menyetujui request bisnis (§10) |
| `WORKFLOW_MANAGER` | instance, alihtugas, lewati step, override, delegasi, SLA, analitik | — |
| `AUDITOR` | baca seluruh instance + audit + analitik | tidak boleh menulis apa pun |

**Peran workflow dalam satu instance** — REQUESTER, APPROVER, REVIEWER,
OBSERVER, DELEGATE, ESCALATION_APPROVER, WORKFLOW_OWNER — bukan peran RBAC,
melainkan posisi yang ditetapkan resolver saat alur berjalan.

Izin granular: `workflow.view`, `workflow.definition.view|manage`,
`workflow.version.publish`, `workflow.request.create|view|submit|cancel|manage`,
`workflow.approval.view|decide|reassign|skip|override`,
`workflow.delegation.view|manage`, `workflow.sla.view|manage`,
`workflow.instance.view`, `workflow.analytics.view`, `workflow.audit.view`,
`workflow.settings.view|manage`, `workflow.directory.manage`.

---

## 10. Jaminan produksi

**Self-approval (§36)** — default `false`. Requester dikeluarkan dari daftar
approver saat resolusi; aturan node hanya dapat mengizinkan secara eksplisit
(`ALLOW`) atau definisi lewat `allow_self_approval = true`. Override hanya
mungkin lewat izin `workflow.approval.override` **dengan alasan tertulis**.

**Segregation of Duties (§37)** — dikonfigurasi per definisi:

```json
{ "sod": {
    "requester_cannot_be_final_approver": true,
    "forbid_same_user_multiple_steps": true,
    "min_distinct_approvers": 2 } }
```

**Kunci ganda keputusan (§64)** — transaksi + `lockForUpdate` + kunci idempoten
unik + indeks unik (step, approver). Klik ganda, retry jaringan, atau dua worker
paralel tetap menghasilkan satu keputusan.

**Delegasi tidak menghapus identitas (§8)** — penugasan dialihkan ke penerima
delegasi, tetapi `original_approver_id` dan `delegation_id` tercatat pada
penugasan *dan* keputusan; laporan dapat menjawab "siapa bertindak atas nama siapa".

**Eskalasi berjenjang (§40)** — `NOTIFY` / `REASSIGN` / `ESCALATE` /
`MARK_OVERDUE`, sampai batas `max_levels`; penugasan lama ditandai dibatalkan
(bukan dihapus) dan rujukan eskalasinya tersimpan.

**Notifikasi tidak berulang (§68)** — kunci idempoten `(event, instance, step,
penerima, kanal, penanda)` unik di basis data; pengiriman ulang tercatat
`SKIPPED` — bukti pencegahan bekerja.

**Audit berantai (§48)** — setiap baris memuat hash dirinya dan hash sebelumnya
per rantai instance. `POST /workflows/audit/verify` memverifikasi ulang seluruh
rantai; menyunting satu baris lewat SQL langsung pun akan terbaca.

**Retensi & pembersihan (§17)** — `workflow:expire` menutup request `RETURNED`
yang tidak direvisi dan draf terlantar tanpa menghapus riwayatnya.

---

## 11. Operasional

```bash
# Sapuan SLA tiap 5 menit (sudah terjadwal di routes/console.php)
php artisan workflow:check-sla

# Tutup request kedaluwarsa (harian 23:40)
php artisan workflow:expire

# Pemeriksaan cepat sebelum/sesudah deploy
php artisan workflow:health

# Jalur integrasi/ops: mulai workflow untuk subjek bisnis
php artisan workflow:trigger PURCHASE_REQUEST_APPROVAL PR-2026-000123 \
    --requester=1 --context='{"amount":15000000,"department":"SARANA"}'
```

`workflow:health` melaporkan: jumlah resolver & aksi terdaftar, provider
konteks, kalender aktif, step yang menunggu penetapan approver, instance gagal,
SLA terlambat, dan keutuhan rantai audit.

---

## 12. Antarmuka pengguna

| Layar | Isi |
|---|---|
| **Dasbor Workflow** | Berapa yang berjalan, menunggu, terlambat; kepatuhan SLA; volume harian; titik tersangkut; beban tertunda per approver. |
| **Persetujuan Saya** | Kotak masuk lintas modul dengan penyaring (kelompok, modul, prioritas, nominal, urutan) dan aksi setujui/kembalikan/tolak/alihtugas. |
| **Permintaan Saya** | Draf, berjalan, perlu revisi; buat permintaan manual; kirim, revisi, batalkan. |
| **Detail Request** | Visualizer jalur berstatus, langkah + penugasan, timeline immutable, snapshot konteks, dokumen rujukan, diskusi, jejak audit instance, dry run. |
| **Definisi & Builder** | Daftar definisi & versi, simulasi, klon versi, builder langkah + alur + kondisi + resolver, periksa & terbitkan. |
| **Delegasi** | Daftar delegasi, pembuatan bercakupan, pencabutan. |
| **Analitik** | Durasi per definisi, kepatuhan SLA, volume per modul/unit, eskalasi. |
| **Audit** | Seluruh peristiwa lintas instance + verifikasi rantai. |
| **Pengaturan** | Kalender kerja (hari kerja, jam, hari libur) & direktori organisasi (atasan, unit, jabatan, cost center). |

---

## 13. Pengujian

```
tests/Feature/Workflow/                      51 tes · 253 asersi · hijau
├── WorkflowTestCase.php                     peran, izin, helper definisi, provider & resolver uji
├── WorkflowDefinitionTest.php               validasi graf, publikasi, imutabilitas versi, definisi bawaan
├── WorkflowConditionTest.php                operator, tipe data, AND/OR/NOT, validasi ekspresi
├── WorkflowApproverResolutionTest.php       user/role/manager/departemen/jabatan/cost center/dinamis, delegasi
├── WorkflowExecutionTest.php                serial, kondisi nominal, kuorum ALL/ANY/MINIMUM_N, idempotensi,
│                                            self-approval, return & resubmit, aksi otomatis, paralel + merge,
│                                            kebijakan approver kosong + penetapan manual
├── WorkflowDelegationSlaTest.php            keputusan lewat delegasi, pencabutan, sapuan SLA, eskalasi REASSIGN,
│                                            status DUE_SOON, skip step, resolusi departemen
├── WorkflowSimulationAuditTest.php          simulasi tanpa efek samping, dry run, rantai audit & deteksi
│                                            penyuntingan, snapshot konteks ber-hash, trigger modul, perintah artisan
└── WorkflowApiTest.php                      otorisasi endpoint, inbox approver, komentar wajib, pembatalan,
                                             cakupan baris, larangan Workflow Admin menyetujui request bisnis
```

Modul juga menguji **titik ekstensi**, bukan hanya perilaku internalnya:
`registerTestProvider`, `registerTestResolver`, `registerTestAction`, dan
`registerTrigger` dipakai di dalam tes — membuktikan modul lain dapat
menyumbang kemampuan tanpa mengubah kode engine.

---

## 14. Contoh alur nyata

**Pengadaan (Propagasi nominal)**
```
Purchase Request (modul Procurement)
   ↓ provider konteks: amount, department, cost_center_id
Atasan langsung (resolver manager, SLA 24 jam kerja)
   ↓
Cek Nominal
   ├── amount ≥ 10 juta → Kepala Sekolah (SLA 48 jam)
   ├── amount ≥ 1 juta  → Bendahara    (SLA 24 jam)
   └── lainnya          → selesai langsung
   ↓ disetujui
Aksi: event.publish PurchaseRequestApproved  →  modul membuat PO
```

**Pengeluaran (pemisahan tugas)**
```
Pengeluaran → Pemeriksaan Anggaran (REVIEW, peran bendahara)
            → jika > 5 juta: Kepala Sekolah (APPROVAL)
            → selesai
SoD: requester tidak boleh menjadi approver terakhir.
```

Definisi di atas dikirim sebagai **data** oleh `WorkflowReferenceSeeder`
(4 definisi bawaan: pengadaan, cuti, pengeluaran, dokumen) — bukan kode program.
Sekolah dapat mengubahnya lewat builder tanpa deploy.
