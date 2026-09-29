<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\FinanceBilling;
use App\Modules\LMS\Domain\Contracts\FinancialEligibilityProviderInterface;
use App\Modules\LMS\Domain\Enums\FinancialEligibilityStatus;
use Illuminate\Support\Facades\Schema;

/**
 * Integrated Mode: kelayakan dari tagihan yang lewat jatuh tempo.
 *
 * LMS tidak menanamkan aturan keuangan apa pun (§8): provider hanya melaporkan
 * status, dan kebijakan pembatasan ada di `lms.finance.*`. Restriction **tidak
 * pernah** menghapus progress, submission, nilai, sertifikat, atau riwayat.
 */
class ErpFinancialEligibilityProvider implements FinancialEligibilityProviderInterface
{
    public function key(): string
    {
        return 'erp';
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('finance_billings');
    }

    public function eligibilityFor(int $studentId): array
    {
        if (! $this->isAvailable()) {
            return $this->unknown('Modul keuangan tidak tersedia.');
        }

        $overdue = FinanceBilling::query()
            ->where('student_id', $studentId)
            ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
            ->where('remaining_amount', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->get();

        $outstanding = (float) $overdue->sum('remaining_amount');
        $count = $overdue->count();

        if ($count === 0) {
            return [
                'status' => FinancialEligibilityStatus::ELIGIBLE->value,
                'outstanding_amount' => 0.0,
                'overdue_count' => 0,
                'reason' => null,
                'checked_at' => now()->toIso8601String(),
                'source' => 'erp',
            ];
        }

        $grace = (int) config('lms.finance.grace_days', 0);
        $recent = $overdue->filter(fn (FinanceBilling $billing) => $billing->due_date !== null
            && $billing->due_date->diffInDays(now()) <= $grace)->count();

        $blocking = $count - $recent;

        return [
            'status' => ($blocking > 0
                ? FinancialEligibilityStatus::RESTRICTED
                : FinancialEligibilityStatus::ELIGIBLE)->value,
            'outstanding_amount' => round($outstanding, 2),
            'overdue_count' => $count,
            'reason' => $blocking > 0
                ? 'Ada '.$blocking.' tagihan lewat jatuh tempo.'
                : null,
            'checked_at' => now()->toIso8601String(),
            'source' => 'erp',
        ];
    }

    public function restrictedStudentIds(int $limit = 100): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return FinanceBilling::query()
            ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
            ->where('remaining_amount', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->subDays((int) config('lms.finance.grace_days', 0))->toDateString())
            ->whereNotNull('student_id')
            ->groupBy('student_id')
            ->limit($limit)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<string, mixed> */
    private function unknown(string $reason): array
    {
        return [
            'status' => FinancialEligibilityStatus::UNKNOWN->value,
            'outstanding_amount' => 0.0,
            'overdue_count' => 0,
            'reason' => $reason,
            'checked_at' => now()->toIso8601String(),
            'source' => 'erp',
        ];
    }
}
