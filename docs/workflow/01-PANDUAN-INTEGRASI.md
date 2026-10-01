# Panduan Integrasi — Memakai Workflow Engine dari Modul ERP

> Untuk pengembang modul (Finance, Procurement, HRD, Inventory, Asset, PPDB,
> Student, Boarding, Document, Maintenance, Clinic). Empat langkah, tanpa
> menyentuh kode engine.

---

## Ringkasan: apa yang Anda sumbangkan, apa yang engine kerjakan

| Anda menyediakan | Engine mengerjakan |
|---|---|
| Nama `subject_type` + data konteks (nominal, unit, cost center, dll) | Percabangan kondisi, resolusi approver, kuorum, SLA, eskalasi |
| Peristiwa pemicu (event/API/manual) | Delegasi, pengingat, notifikasi, idempotensi |
| Aksi bisnis yang dijalankan setelah disetujui | Audit berantai, riwayat, kotak masuk, timeline, analitik |
| (opsional) resolver approver khusus modul Anda | Versi, publikasi, simulasi, builder |

Yang **tidak** Anda kerjakan: tabel approval, logika kuorum, penjadwalan
pengingat, halaman persetujuan, atau penyimpanan riwayat keputusan.

---

## Langkah 1 — Provider konteks (`WorkflowContextProviderInterface`)

```php
namespace App\Services\Procurement;

use App\Modules\Workflow\Domain\Contracts\WorkflowContextProviderInterface;
use App\Modules\Workflow\Domain\Contracts\WorkflowSubjectStatusInterface;

/**
 * Satu-satunya jembatan antara data pengadaan dan engine.
 *
 * Yang disumbangkan adalah **nilai yang dibutuhkan alur**: nominal, unit,
 * cost center, kategori. Bukan seluruh isi entitas.
 */
class PurchaseRequestContextProvider implements WorkflowContextProviderInterface, WorkflowSubjectStatusInterface
{
    public function __construct(private readonly PurchaseRequestService $requests) {}

    public function subjectType(): string
    {
        return 'procurement.purchase_request';
    }

    /** Engine menolak submit bila kunci ini tidak tersedia. */
    public function requiredKeys(): array
    {
        return ['amount', 'department'];
    }

    public function provide(string|int $subjectId): array
    {
        $request = $this->requests->find((int) $subjectId);

        return [
            'amount' => (float) $request->total_amount,
            'currency' => 'IDR',
            'department' => $request->department_code,
            'cost_center_id' => $request->cost_center_id,
            'category' => $request->category,
            'priority' => $request->priority,
            'subject_reference' => $request->code,   // PR-2026-000123
        ];
    }

    public function describe(string|int $subjectId): array
    {
        $request = $this->requests->find((int) $subjectId);

        return ['reference' => $request->code, 'title' => $request->title];
    }

    /** Dipanggil aksi bawaan `subject.update_status` setelah workflow selesai. */
    public function applyStatus(string|int $subjectId, string $status, array $meta = []): void
    {
        $this->requests->markWorkflowStatus((int) $subjectId, $status, $meta);
    }
}
```

**Catatan penting:** data dari provider **mengalahkan** payload klien. Nominal
yang dipakai percabangan approval tidak dapat dipalsukan dari layar.

---

## Langkah 2 — Trigger (`WorkflowTriggerInterface`)

```php
namespace App\Services\Procurement;

use App\Models\User;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Modules\Workflow\Domain\Contracts\WorkflowTriggerInterface;
use App\Modules\Workflow\Models\WorkflowInstance;

class PurchaseRequestSubmittedTrigger implements WorkflowTriggerInterface
{
    public function __construct(private readonly WorkflowInstanceService $instances) {}

    public function key(): string
    {
        return 'procurement.purchase_request.submitted';
    }

    public function definitionCode(): string
    {
        return 'PURCHASE_REQUEST_APPROVAL';
    }

    public function start(string|int $subjectId, array $payload = [], ?User $actor = null, bool $autoSubmit = true): WorkflowInstance
    {
        $instance = $this->instances->create([
            'definition_code' => $this->definitionCode(),
            'subject_type' => 'procurement.purchase_request',
            'subject_id' => (string) $subjectId,
            'title' => $payload['title'] ?? 'Permintaan pengadaan',
            'requester_id' => $payload['requester_id'] ?? $actor?->id,
            'priority' => $payload['priority'] ?? 'NORMAL',
        ], $actor);

        return $autoSubmit ? $this->instances->submit($instance, $actor) : $instance;
    }
}
```

Panggil dari modul Anda (langsung, atau lewat listener event):

```php
// PurchaseRequestService::submit()
event(new PurchaseRequestSubmitted($request->id));

// app/Listeners/StartPurchaseRequestWorkflow.php
public function handle(PurchaseRequestSubmitted $event): void
{
    app(WorkflowRegistrar::class)
        ->trigger('procurement.purchase_request.submitted')
        ->start($event->requestId, ['requester_id' => $event->requesterId], $event->actor());
}
```

---

## Langkah 3 — Aksi setelah persetujuan (`WorkflowActionInterface`)

```php
namespace App\Services\Procurement\Workflow;

use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Support\WorkflowActionContext;

/**
 * Dipanggil definisi workflow: { "handler": "procurement.create_purchase_order" }
 *
 * Wajib idempoten: engine dapat memanggilnya ulang setelah percobaan gagal.
 */
class CreatePurchaseOrderAction implements WorkflowActionInterface
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function alias(): string
    {
        return 'procurement.create_purchase_order';
    }

    public function description(): string
    {
        return 'Buat Purchase Order dari permintaan yang telah disetujui penuh.';
    }

    public function execute(WorkflowActionContext $context, array $params = []): array
    {
        $requestId = (int) $context->instance->subject_id;

        $order = $this->orders->createFromRequest($requestId, [
            'workflow_instance' => $context->instanceCode(),
        ]);

        return ['purchase_order' => $order->code];
    }
}
```

---

## Langkah 4 — Daftarkan di service provider modul Anda

```php
namespace App\Providers;

use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use Illuminate\Support\ServiceProvider;

class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registrar = $this->app->make(WorkflowRegistrar::class);

        $registrar->registerContextProvider($this->app->make(PurchaseRequestContextProvider::class));
        $registrar->registerTrigger($this->app->make(PurchaseRequestSubmittedTrigger::class));
        $registrar->registerAction($this->app->make(CreatePurchaseOrderAction::class));

        // Opsional: resolver approver khusus modul (mis. "wali kelas", "musyrif kamar").
        // $registrar->registerResolver($this->app->make(HomeroomTeacherResolver::class));
    }
}
```

Tidak ada berkas di modul Workflow yang perlu diubah. Setelah ini, definisi
workflow dapat merujuk `procurement.create_purchase_order` sebagai handler aksi.

---

## Resolver approver khusus modul (opsional)

```php
class DormitoryHeadResolver implements ApproverResolverInterface
{
    public function __construct(private readonly BoardingDirectory $dorms) {}

    public function alias(): string { return 'boarding.kepala_asrama'; }

    public function description(): string { return 'Kepala asrama pemilik kamar santri.'; }

    public function requiredSpec(): array { return ['dormitory_field' => 'string']; }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $dormitoryId = $context->get($spec['dormitory_field'] ?? 'dormitory_id');

        return array_map(
            fn (int $userId) => new ResolvedApprover($userId, ApproverSource::DYNAMIC, $this->alias()),
            $this->dorms->headsOf((int) $dormitoryId),
        );
    }
}
```

Dipakai di definisi:

```json
{ "resolver": "dynamic", "resolver_alias": "boarding.kepala_asrama",
  "params": { "dormitory_field": "dormitory_id" } }
```

---

## Pola definisi yang sering dipakai

**Ambang nominal berjenjang** (edge dengan `priority` + satu cabang `default`):

```json
{ "from_key": "gate", "to_key": "director", "priority": 0,
  "condition": { "field": "amount", "operator": ">=", "value": 10000000 } }
{ "from_key": "gate", "to_key": "finance", "priority": 1,
  "condition": { "field": "amount", "operator": ">=", "value": 1000000 } }
{ "from_key": "gate", "to_key": "end", "priority": 9, "is_default": true }
```

**Pemeriksa + penyetuju (pemisahan tugas)**:

```json
{ "key": "budget_review", "type": "REVIEW", "quorum_policy": "ANY",
  "approvers": [ { "resolver": "role", "role": "bendahara" } ] }
```

**Persetujuan paralel dengan kuorum minimum**:

```json
{ "key": "committee", "type": "APPROVAL", "approval_mode": "PARALLEL",
  "quorum_policy": "MINIMUM_N", "quorum_value": 2,
  "approvers": [ {"resolver":"role","role":"kepala-sekolah"},
                 {"resolver":"role","role":"bendahara"},
                 {"resolver":"role","role":"admin-sekolah"} ] }
```

**Bertahap (atasan → direktur)**:

```json
{ "key": "escalating", "type": "APPROVAL", "approval_mode": "SEQUENTIAL",
  "quorum_policy": "ALL",
  "approvers": [ {"resolver":"manager","levels":1}, {"resolver":"manager","levels":2} ] }
```

---

## Simulasi sebelum menerbitkan (§56)

```bash
# Lewat API
POST /api/v1/workflows/definitions/{id}/simulate
{ "context": { "amount": 15000000, "department": "SARANA" } }
```

Hasilnya berisi jalur yang akan dilalui, **approver nyata yang terselesaikan**
(nama + asal resolusi), cabang yang dilewati, perkiraan jumlah langkah & total
SLA, serta peringatan (mis. konteks belum lengkap, tidak ada approver). Tidak ada
instance, keputusan, atau notifikasi yang dibuat.

Untuk request yang **sedang berjalan**, gunakan dry run:

```
POST /api/v1/workflows/instances/{id}/dry-run
→ { "healthy": false, "blockers": ["Step \"Atasan\" menunggu penetapan approver (1 kandidat tersedia)."] }
```

---

## Praktik yang diharapkan

1. **Jangan mengirim keputusan langsung dari modul.** Setiap perubahan status
   approval harus lewat `ApprovalService::decide()` agar otorisasi, kuorum,
   idempotensi, dan audit tetap satu jalur.
2. **Sertakan data yang dibutuhkan kondisi, bukan seluruh entitas.** Snapshot
   konteks disimpan setiap submit; mengirim puluhan field memperbesar jejak tanpa manfaat.
3. **Aksi bisnis harus idempoten.** Simpan `instance_code` pada entitas hasil
   (mis. `purchase_orders.workflow_instance_code`) dan periksa sebelum membuat baru.
4. **Jangan menaruh ambang keputusan di kode modul.** Semua ambang ada di definisi;
   kalau berubah, itu tugas Workflow Admin, bukan deploy.
5. **Uji dengan definisi versi draf** (`publish(activate: false)`) bila alur baru
   ingin dicoba sebelum menggantikan alur produksi.
