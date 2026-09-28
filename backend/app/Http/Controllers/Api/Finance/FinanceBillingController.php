<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceBillingRequest;
use App\Http\Resources\FinanceBillingResource;
use App\Models\FinanceBilling;
use App\Models\FinanceFeePackage;
use App\Models\Student;
use App\Services\Finance\FinanceBillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Billing (§13–§16): generate dari paket (idempoten §14), buat manual, terbitkan
 * invoice + jurnal akrual, dan batalkan. Logika bisnis ada di `FinanceBillingService`;
 * controller ini tipis — validasi input, panggil service, bungkus respons.
 */
class FinanceBillingController extends BaseApiController
{
    public function __construct(private readonly FinanceBillingService $billing)
    {
    }

    /** Pilihan santri seperlunya untuk petugas yang berhak membuat tagihan. */
    public function studentOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($data['search'] ?? ''));

        $students = Student::query()
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('full_name', 'like', "%{$term}%")
                ->orWhere('nis', 'like', "%{$term}%")))
            ->orderBy('full_name')
            ->limit(200)
            ->get(['id', 'nis', 'full_name']);

        return $this->successResponse($students, 'Pilihan siswa berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = FinanceBilling::query()
            ->with(['student', 'costCenter', 'invoice'])
            ->withCount('items')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('source'), fn (Builder $q) => $q->where('source', $request->string('source')))
            ->when($request->filled('cost_center_id'), fn (Builder $q) => $q->where('cost_center_id', (int) $request->input('cost_center_id')))
            ->when($request->filled('due_before'), fn (Builder $q) => $q->whereDate('due_date', '<=', $request->input('due_before')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where('billing_number', 'like', "%$term%");
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceBillingResource::collection($query->get()), 'Daftar billing berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceBillingResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceBilling::STATUSES, 'status_labels' => FinanceBilling::STATUS_LABELS]]),
            'Daftar billing berhasil diambil',
        );
    }

    public function show(FinanceBilling $billing): JsonResponse
    {
        $billing->load(['student', 'costCenter', 'items.feeType', 'invoice']);

        return $this->successResponse(new FinanceBillingResource($billing), 'Detail billing berhasil diambil');
    }

    /** Billing manual/ad-hoc (§13). */
    public function store(FinanceBillingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $billing = $this->billing->createManual([
            ...$data,
            'customer_type' => $data['customer_type'] ?? 'student',
            'created_by' => $request->user()?->id,
        ]);

        return $this->createdResponse(new FinanceBillingResource($billing), 'Billing berhasil dibuat');
    }

    /**
     * Generate billing dari paket untuk satu santri atau sekelompok santri (§14).
     * Idempoten per (santri, paket, periode): yang sudah ada dilewati.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fee_package_id' => ['required', 'integer', 'exists:finance_fee_packages,id'],
            'period_key' => ['required', 'string', 'max:60'],
            'student_ids' => ['required_without:class_id', 'nullable', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'cost_center_id' => ['nullable', 'integer', 'exists:finance_cost_centers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $package = FinanceFeePackage::query()->findOrFail((int) $data['fee_package_id']);

        $students = $this->resolveStudents($data);

        if ($students->isEmpty()) {
            throw ValidationException::withMessages(['student_ids' => 'Tidak ada santri terpilih untuk ditagih.']);
        }

        $options = [
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ];

        $result = $this->billing->generateBulk($package, $students, (string) $data['period_key'], $options);

        return $this->successResponse($result, 'Billing berhasil digenerate (idempoten per santri/periode)');
    }

    /**
     * Preview "tagihan lain" massal (§10): pilih jenis tagihan → target → lihat
     * siapa yang akan ditagih vs yang sudah ada (exclude existing). Tidak menulis.
     */
    public function otherPreview(Request $request): JsonResponse
    {
        $data = $this->validateOtherBilling($request, false);

        $students = $this->resolveStudents($data);

        if ($students->isEmpty()) {
            throw ValidationException::withMessages(['student_ids' => 'Tidak ada santri terpilih untuk ditagih.']);
        }

        $preview = $this->billing->previewOtherBilling([
            'fee_type_id' => (int) $data['fee_type_id'],
            'period_key' => (string) $data['period_key'],
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'students' => $students,
        ]);

        return $this->successResponse($preview, 'Preview tagihan lain berhasil disusun');
    }

    /**
     * Generate "tagihan lain" massal (§10, §12) dari satu jenis tagihan. Idempoten
     * per (jenis, santri, periode) — yang sudah ada dilewati, tidak pernah dobel.
     */
    public function otherGenerate(Request $request): JsonResponse
    {
        $data = $this->validateOtherBilling($request, true);

        $students = $this->resolveStudents($data);

        if ($students->isEmpty()) {
            throw ValidationException::withMessages(['student_ids' => 'Tidak ada santri terpilih untuk ditagih.']);
        }

        $feeType = \App\Models\FinanceFeeType::query()->findOrFail((int) $data['fee_type_id']);
        $amount = round((float) ($data['amount'] ?? $feeType->default_amount), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal tagihan lain harus lebih dari nol.']);
        }

        $options = [
            'description' => $data['description'] ?? null,
            'quantity' => isset($data['quantity']) ? (int) $data['quantity'] : null,
            'due_date' => $data['due_date'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ];

        $result = $this->billing->generateOtherBulk(
            (int) $data['fee_type_id'],
            $students,
            $amount,
            (string) $data['period_key'],
            $options,
        );

        return $this->successResponse($result, 'Tagihan lain berhasil digenerate (idempoten per jenis/santri/periode)');
    }

    /**
     * Validasi input "tagihan lain". Saat `$forGenerate`, `period_key` wajib dan
     * target santri wajib ada; preview boleh lebih longgar.
     *
     * @return array<string, mixed>
     */
    private function validateOtherBilling(Request $request, bool $forGenerate): array
    {
        return $request->validate([
            'fee_type_id' => ['required', 'integer', 'exists:finance_fee_types,id'],
            'period_key' => [$forGenerate ? 'required' : 'nullable', 'string', 'max:60'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
            'student_ids' => ['required_without:class_id', 'nullable', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'due_date' => ['nullable', 'date'],
            'cost_center_id' => ['nullable', 'integer', 'exists:finance_cost_centers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** Terbitkan invoice + jurnal akrual pendapatan dari billing (§16, §46). */
    public function invoice(Request $request, FinanceBilling $billing): JsonResponse
    {
        $invoice = $this->billing->createInvoice($billing, $request->user());

        return $this->createdResponse(
            new \App\Http\Resources\FinanceInvoiceResource($invoice->load(['items.account', 'billing'])),
            'Invoice berhasil diterbitkan',
        );
    }

    /** Batalkan billing (§15) — hanya bila belum ada pembayaran. */
    public function cancel(Request $request, FinanceBilling $billing): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $this->billing->cancelBilling($billing, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinanceBillingResource($cancelled->load('items.feeType')), 'Billing dibatalkan');
    }

    /** Kumpulan santri dari `student_ids` eksplisit atau semua santri satu kelas. */
    private function resolveStudents(array $data): \Illuminate\Support\Collection
    {
        if (! empty($data['student_ids'])) {
            return Student::query()->whereIn('id', $data['student_ids'])->get();
        }

        return Student::query()
            ->whereHas('classStudents', fn (Builder $q) => $q->where('school_class_id', (int) $data['class_id']))
            ->get();
    }
}
