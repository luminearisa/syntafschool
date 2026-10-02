<?php

namespace App\Modules\Hrd\Core\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Domain\Contracts\AIHRProviderInterface;
use App\Modules\Hrd\Core\Models\HrAiRequest;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use Throwable;

/**
 * Lapisan AI yang hanya boleh menyarankan (§109–§110).
 *
 * Dua pagar yang dijaga di sini: penyedia AI tidak pernah menerima kolom yang
 * tidak boleh dilihat pengguna (gaji, kinerja, medis, disiplin), dan tidak ada
 * jalur dari saran AI menuju keputusan HR — keputusan tetap milik manusia yang
 * berwenang, dengan jejak review tersimpan.
 */
class AIHRService
{
    /** Kapabilitas yang diizinkan beserta sifatnya (selalu menyarankan). */
    private const CAPABILITIES = [
        'GENERATE_JOB_DESCRIPTION',
        'SUMMARIZE_CANDIDATE',
        'EXTRACT_SKILLS_FROM_CV',
        'SUGGEST_INTERVIEW_QUESTIONS',
        'SUGGEST_TRAINING',
        'IDENTIFY_SKILL_GAP',
        'SUMMARIZE_PERFORMANCE',
        'DRAFT_DEVELOPMENT_PLAN',
        'HR_QA',
        'PAYROLL_ANOMALY_DETECTION',
        'ATTENDANCE_ANOMALY_DETECTION',
    ];

    public function __construct(private readonly AIHRProviderInterface $provider) {}

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return [
            'enabled' => (bool) config('hrd_phase2.ai.enabled', false),
            'provider' => (string) config('hrd_phase2.ai.provider', 'null'),
            'may_decide_hiring' => (bool) config('hrd_phase2.ai.may_decide_hiring', false),
            'capabilities' => self::CAPABILITIES,
            'blocked_fields' => (array) config('hrd_phase2.ai.blocked_fields', []),
            'provider_capabilities' => $this->provider->capabilities(),
        ];
    }

    /**
     * Meminta saran. Selalu menghasilkan draf yang harus ditinjau manusia.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function suggest(string $capability, array $input, User $actor, ?HrEmployee $employee = null): array
    {
        $capability = strtoupper($capability);

        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw HrdValidationException::forField('capability', "Kapabilitas AI \"{$capability}\" tidak dikenal.", 'ai.capability_unknown');
        }

        $allowed = $this->allowedFields($actor);
        $redacted = $this->provider->redact($input, $allowed);

        $request = HrAiRequest::query()->create([
            'code' => sprintf('AI-%s-%05d', now()->format('Y'), HrAiRequest::query()->whereYear('created_at', now()->year)->count() + 1),
            'capability' => $capability,
            'provider' => (string) config('hrd_phase2.ai.provider', 'null'),
            'requested_by_user_id' => $actor->getKey(),
            'subject_type' => $employee !== null ? HrEmployee::class : null,
            'subject_id' => $employee?->getKey(),
            'employee_id' => $employee?->getKey(),
            'status' => 'PENDING',
            'prompt_hash' => hash('sha256', json_encode($redacted)),
            'redacted_payload' => $redacted,
        ]);

        try {
            $response = $this->provider->suggest($capability, $redacted, [
                'actor_id' => $actor->getKey(),
                'employee_id' => $employee?->getKey(),
                'locale' => app()->getLocale(),
            ]);

            $request->forceFill([
                'status' => 'COMPLETED',
                'response' => $response,
                'tokens' => $response['tokens'] ?? null,
                'latency_ms' => $response['latency_ms'] ?? null,
            ])->save();
        } catch (Throwable $exception) {
            $request->forceFill(['status' => 'FAILED', 'response' => ['error' => $exception->getMessage()]])->save();
        }

        return [
            'request_id' => $request->getKey(),
            'code' => $request->code,
            'capability' => $capability,
            'status' => $request->status,
            'disclaimer' => 'Saran AI adalah draf. Keputusan HR tetap ditetapkan manusia yang berwenang.',
            'suggestion' => $request->response,
        ];
    }

    public function review(int $requestId, User $actor, bool $accepted, ?string $notes = null): HrAiRequest
    {
        $request = HrAiRequest::query()->findOrFail($requestId);

        $request->forceFill([
            'human_reviewed' => true,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'review_notes' => $notes,
            'status' => $accepted ? 'COMPLETED' : 'REJECTED',
        ])->save();

        return $request->refresh();
    }

    /**
     * Kolom yang boleh dikirim ke penyedia: seluruh kolom pegawai kecuali yang
     * dilarang dan yang tidak boleh dilihat pengguna (§110).
     *
     * @return list<string>
     */
    private function allowedFields(User $actor): array
    {
        $blocked = (array) config('hrd_phase2.ai.blocked_fields', []);

        if (! $actor->can('hr.employee.sensitive.view')) {
            $blocked = array_merge($blocked, ['national_id', 'nik', 'bank_account', 'bank_account_number', 'salary', 'net_pay']);
        }

        if (! $actor->can('hr.payroll.view')) {
            $blocked = array_merge($blocked, ['base_amount', 'gross_amount', 'net_amount', 'component_amount']);
        }

        if (! $actor->can('hr.performance.view')) {
            $blocked = array_merge($blocked, ['performance_rating', 'final_rating', 'potential_rating', 'nine_box_code', 'pip_status']);
        }

        return array_values(array_unique(array_merge(['salary', 'net_pay', 'performance_rating', 'medical', 'disciplinary'], $blocked)));
    }
}
