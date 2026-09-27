<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceReRegistrationResource;
use App\Models\FinanceReRegistration;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Services\Finance\ReRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Daftar Ulang (§2–§7, §38 Phase 2): pratinjau, generasi (tunggal/massal),
 * penyelesaian + aktivasi akademik, pembatalan, dan buka kembali. Logika bisnis
 * ada di `ReRegistrationService`; controller tipis — validasi, panggil, bungkus.
 *
 * Otorisasi lewat `permission:` per route (§3): lihat `routes/api/finance.php`.
 */
class FinanceReRegistrationController extends BaseApiController
{
    public function __construct(private readonly ReRegistrationService $reRegistration) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceReRegistration::query()
            ->with(['student', 'nextAcademicYear', 'currentAcademicYear', 'feePackage', 'billing', 'invoice'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('next_academic_year_id'), fn (Builder $q) => $q->where('next_academic_year_id', (int) $request->input('next_academic_year_id')))
            ->when($request->filled('current_academic_year_id'), fn (Builder $q) => $q->where('current_academic_year_id', (int) $request->input('current_academic_year_id')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('activated'), fn (Builder $q) => $q->where('activated', $request->boolean('activated')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('registration_number', 'like', "%$term%")
                        ->orWhereHas('student', fn (Builder $s) => $s->where('full_name', 'like', "%$term%")->orWhere('nis', 'like', "%$term%"));
                });
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceReRegistrationResource::collection($query->get()), 'Daftar pendaftaran ulang berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceReRegistrationResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => [
                    'statuses' => FinanceReRegistration::STATUSES,
                    'status_labels' => FinanceReRegistration::STATUS_LABELS,
                ]]),
            'Daftar pendaftaran ulang berhasil diambil',
        );
    }

    public function show(FinanceReRegistration $reRegistration): JsonResponse
    {
        $reRegistration->load(['student', 'nextAcademicYear', 'currentAcademicYear', 'feePackage', 'billing.items.feeType', 'invoice.items.account', 'costCenter', 'revenueAccount', 'receivableAccount']);

        return $this->successResponse(new FinanceReRegistrationResource($reRegistration), 'Detail pendaftaran ulang berhasil diambil');
    }

    /**
     * Pratinjau generasi massal (§5): eligible / already_generated / will_generate /
     * skipped — tanpa menulis apa pun.
     */
    public function preview(Request $request): JsonResponse
    {
        $config = $this->validatedConfig($request);

        return $this->successResponse($this->reRegistration->preview($config, $request->user()), 'Pratinjau daftar ulang berhasil dihitung');
    }

    /**
     * Generate pendaftaran ulang (§5). Bila `student_id` diberikan → tunggal;
     * selain itu → massal untuk seluruh santri yang lolos filter segmen.
     * Idempoten per (santri, tahun tujuan) (§24).
     */
    public function generate(Request $request): JsonResponse
    {
        $config = $this->validatedConfig($request, forGenerate: true);

        if (! empty($config['student_id'])) {
            $student = Student::query()->findOrFail((int) $config['student_id']);
            $reg = $this->reRegistration->generateForStudent($student, $config, $request->user());

            return $this->createdResponse(
                new FinanceReRegistrationResource($reg->load(['student', 'nextAcademicYear', 'billing', 'invoice'])),
                'Pendaftaran ulang berhasil dibuat',
            );
        }

        $result = $this->reRegistration->generateBulk($config, $request->user());

        return $this->successResponse($result, 'Pendaftaran ulang massal selesai (idempoten per santri/tahun)');
    }

    /**
     * Selesaikan pendaftaran (§7): invoice harus lunas, lalu tahun ajaran
     * berikutnya diaktifkan (riwayat akademik + opsional penempatan rombel).
     */
    public function complete(Request $request, FinanceReRegistration $reRegistration): JsonResponse
    {
        $options = $request->validate([
            'history_status' => ['nullable', 'string', 'in:'.implode(',', StudentHistory::STATUSES)],
            'next_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
        ]);

        $reg = $this->reRegistration->complete($reRegistration, $request->user(), $options);

        return $this->successResponse(
            new FinanceReRegistrationResource($reg->load(['student', 'nextAcademicYear', 'invoice'])),
            'Pendaftaran ulang selesai dan tahun ajaran berikutnya diaktifkan',
        );
    }

    /** Batalkan pendaftaran (§6) — hanya bila belum ada pembayaran. */
    public function cancel(Request $request, FinanceReRegistration $reRegistration): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $reg = $this->reRegistration->cancel($reRegistration, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinanceReRegistrationResource($reg->load('student')), 'Pendaftaran ulang dibatalkan');
    }

    /** Buka kembali pendaftaran kedaluwarsa/batal (§5). */
    public function reopen(Request $request, FinanceReRegistration $reRegistration): JsonResponse
    {
        $reg = $this->reRegistration->reopen($reRegistration, $request->user());

        return $this->successResponse(new FinanceReRegistrationResource($reg->load(['student', 'invoice'])), 'Pendaftaran ulang dibuka kembali');
    }

    /**
     * Validasi config daftar ulang (§3). Tahun ajaran opsional di request —
     * service menurunkannya dari settings/auto bila kosong.
     *
     * @return array<string, mixed>
     */
    private function validatedConfig(Request $request, bool $forGenerate = false): array
    {
        $rules = [
            'current_academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'next_academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'level' => ['nullable', 'string', 'max:30'],
            'fee_package_id' => ['nullable', 'integer', 'exists:finance_fee_packages,id'],
            'fee_type_id' => ['nullable', 'integer', 'exists:finance_fee_types,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'cost_center_id' => ['nullable', 'integer', 'exists:finance_cost_centers,id'],
            'revenue_account_id' => ['nullable', 'integer', 'exists:finance_accounts,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ];

        if ($forGenerate) {
            // Saat generate, sumber nominal wajib ada: paket ATAU nominal manual.
            $rules['fee_package_id'][] = 'required_without:amount';
            $rules['amount'][] = 'required_without:fee_package_id';
        }

        $data = $request->validate($rules);

        if ($forGenerate && empty($data['fee_package_id']) && round((float) ($data['amount'] ?? 0), 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Tentukan paket tagihan (fee_package_id) atau nominal daftar ulang (amount) lebih dari nol.',
            ]);
        }

        return $data;
    }
}
