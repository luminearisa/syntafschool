<?php

namespace App\Modules\Hrd\Infrastructure\Lifecycle\Effects;

use App\Models\User;
use App\Modules\Hrd\Application\Services\AssignmentService;
use App\Modules\Hrd\Application\Services\ReferenceService;
use App\Modules\Hrd\Domain\Contracts\TransactionEffectInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Efek perpindahan penugasan (§33–§35).
 *
 * Menangani seluruh keluarga transaksi yang bermuara pada "pegawai ini sekarang
 * mengerjakan hal lain di tempat lain":
 *
 * ```
 * TRANSFER          pindah unit/departemen, posisi boleh berubah
 * MUTATION          perpindahan administratif tanpa perubahan jenjang
 * PROMOTION         naik jenjang (grade/level berbeda ke atas)
 * DEMOTION          turun jenjang
 * POSITION_CHANGE   ganti kursi, unit tetap
 * DEPARTMENT_CHANGE ganti departemen
 * LOCATION_CHANGE   ganti lokasi kerja
 * ASSIGNMENT        penugasan tambahan/sementara
 * ```
 *
 * Promosi dan demosi **tidak** menebak dari nama: jenjang dibandingkan memakai
 * `rank` pada `hr_job_levels`/`hr_job_grades`. Bila data jenjang tidak lengkap,
 * transaksi tetap diterapkan dan selisih jenjang dicatat sebagai keterangan —
 * bukan ditebak dan bukan digagalkan.
 */
class AssignmentChangeEffect implements TransactionEffectInterface
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly ReferenceService $references,
    ) {}

    public function alias(): string
    {
        return 'assignment_change';
    }

    public function supports(HrTransaction $transaction): bool
    {
        return in_array($transaction->type_code, [
            'TRANSFER', 'MUTATION', 'PROMOTION', 'DEMOTION', 'POSITION_CHANGE',
            'DEPARTMENT_CHANGE', 'LOCATION_CHANGE', 'ASSIGNMENT', 'ASSIGNMENT_CHANGE',
        ], true);
    }

    public function apply(HrTransaction $transaction, HrEmployee $employee, User $actor): array
    {
        $payload = $transaction->payload ?? [];
        $effectiveDate = $transaction->effective_date?->toDateString() ?? now()->toDateString();

        return DB::transaction(function () use ($transaction, $employee, $actor, $payload, $effectiveDate): array {
            $isRankChange = in_array($transaction->type_code, ['PROMOTION', 'DEMOTION'], true);
            $isAdditional = in_array($transaction->type_code, ['ASSIGNMENT', 'ASSIGNMENT_CHANGE'], true)
                || ($payload['is_primary'] ?? true) === false;

            $current = $this->assignments->currentPrimary($employee);
            $rankNote = $isRankChange ? $this->describeRankChange($current, $payload) : null;

            if ($current !== null && ! $isAdditional && $current->end_date === null) {
                $this->assignments->end(
                    $current,
                    date('Y-m-d', strtotime($effectiveDate.' -1 day')),
                    $actor,
                    $this->endReason($transaction, $rankNote),
                    $transaction,
                );
            }

            $assignment = $this->assignments->assign($employee, [
                'position_id' => $payload['position_id'] ?? null,
                'organization_id' => $payload['organization_id'] ?? $employee->organization_id,
                'department_id' => $payload['department_id'] ?? $employee->department_id,
                'location_id' => $payload['location_id'] ?? $employee->location_id,
                'manager_employee_id' => $payload['manager_employee_id'] ?? $employee->currentManagerId(),
                'assignment_type' => $isAdditional ? (string) ($payload['assignment_type'] ?? 'secondary') : 'primary',
                'is_primary' => ! $isAdditional,
                'start_date' => $effectiveDate,
                'end_date' => $payload['end_date'] ?? null,
                'percentage' => $payload['percentage'] ?? null,
                'reference_number' => $payload['reference_number'] ?? $transaction->transaction_number,
                'notes' => $rankNote,
            ], $actor, $transaction);

            $gradeChange = $this->applyGradeChange($payload, $employee);

            return [
                'assignment_id' => (int) $assignment->getKey(),
                'previous_assignment_id' => $current?->getKey(),
                'position_id' => $assignment->position_id,
                'organization_id' => $assignment->organization_id,
                'department_id' => $assignment->department_id,
                'location_id' => $assignment->location_id,
                'is_primary' => (bool) $assignment->is_primary,
                'rank_note' => $rankNote,
                'grade_change' => $gradeChange,
                'effective_date' => $effectiveDate,
            ];
        });
    }

    /**
     * Selisih jenjang dihitung dari posisi lama vs baru.
     *
     * @param  array<string, mixed>  $payload
     */
    private function describeRankChange(?HrEmployeeAssignment $current, array $payload): ?string
    {
        $oldGrade = $current?->position_id === null
            ? null
            : HrPosition::query()->whereKey($current->position_id)->value('grade_code');

        $newGrade = ($payload['position_id'] ?? null) === null
            ? ($payload['grade_code'] ?? null)
            : HrPosition::query()->whereKey($payload['position_id'])->value('grade_code');

        if ($oldGrade === null && $newGrade === null) {
            return null;
        }

        return 'Jenjang: '.($oldGrade ?? '—').' → '.($newGrade ?? '—');
    }

    /**
     * Perubahan golongan disimpan pada posisi baru bila diminta; bila tidak, tidak
     * ada yang ditulis (Phase 1 tidak menyimpan kompensasi — §11).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyGradeChange(array $payload, HrEmployee $employee): array
    {
        if (empty($payload['grade_code']) || empty($payload['position_id'])) {
            return [];
        }

        $this->references->assertValid('employee_status', null, 'employment_status');

        $position = HrPosition::query()->find($payload['position_id']);

        if ($position === null) {
            throw HrdValidationException::forField('position_id', 'Posisi tidak ditemukan saat menerapkan perubahan golongan.', 'hr.position.missing');
        }

        $before = $position->grade_code;
        $position->forceFill(['grade_code' => $payload['grade_code']])->save();

        return [
            'grade_before' => $before,
            'grade_after' => $payload['grade_code'],
            'note' => 'Golongan posisi diperbarui; nominal kompensasi tetap milik modul Payroll (fase berikutnya).',
        ];
    }

    private function endReason(HrTransaction $transaction, ?string $rankNote): string
    {
        $label = $transaction->type?->name ?? $transaction->type_code;

        return trim($label.($rankNote === null ? '' : ' ('.$rankNote.')'));
    }
}
