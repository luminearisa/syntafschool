<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Support\Str;

/**
 * Pertanyaan terstruktur yang sadar izin (§83, §84).
 *
 * Modul ini belum memiliki asisten AI — dan sengaja tidak berpura-pura memilikinya.
 * Yang disiapkan adalah **lapisan data yang dapat dijawab mesin dengan aman**:
 * pertanyaan seperti
 *
 * ```
 * Berapa pegawai aktif di unit X?
 * Siapa yang kontraknya berakhir 30 hari lagi?
 * Posisi apa yang masih kosong?
 * Siapa yang sertifikasinya akan berakhir?
 * Berapa pegawai yang dipromosikan tahun ini?
 * ```
 *
 * dijawab oleh kueri deterministik di sini, **selalu melewati cakupan akses
 * pemanggil**. Asisten AI pada fase berikutnya boleh menumpang di atasnya — dan
 * tidak akan pernah dapat membaca data yang tidak boleh dibaca pemanggilnya,
 * karena penyaringan terjadi sebelum jawaban dibentuk.
 */
class HrQueryService
{
    public function __construct(
        private readonly HrdAccessService $access,
        private readonly PositionService $positions,
        private readonly EmploymentService $employment,
        private readonly DocumentService $documents,
        private readonly CompetencyService $competency,
        private readonly LifecycleTransactionService $transactions,
    ) {}

    /**
     * Jawab pertanyaan terstruktur. `intent` dipakai alih-alih bahasa bebas supaya
     * hasilnya dapat diuji dan tidak menebak maksud pengguna.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function ask(string $intent, array $parameters, User $user): array
    {
        $scoped = $this->scopedQuery($user, $parameters);

        return match ($intent) {
            'active_headcount' => $this->answerHeadcount($scoped, $parameters),
            'contracts_expiring' => $this->answerContracts($parameters),
            'vacant_positions' => $this->answerVacancies($scoped, $parameters),
            'department_headcount' => $this->answerHeadcount($scoped, $parameters),
            'incomplete_documents' => $this->answerMissingDocuments($scoped),
            'certifications_expiring' => $this->answerCertifications($parameters),
            'promotions_this_year' => $this->answerPromotions($parameters),
            'probation_ending' => $this->answerProbations($parameters),
            'employees_without_assignment' => $this->answerMissingAssignment($scoped),
            default => [
                'intent' => $intent,
                'answer' => null,
                'error' => 'Pertanyaan ini belum didukung. Intent yang tersedia: '
                    .implode(', ', [
                        'active_headcount', 'contracts_expiring', 'vacant_positions', 'department_headcount',
                        'incomplete_documents', 'certifications_expiring', 'promotions_this_year',
                        'probation_ending', 'employees_without_assignment',
                    ]).'.',
            ],
        };
    }

    /** @return list<string> */
    public function supportedIntents(): array
    {
        return [
            'active_headcount', 'contracts_expiring', 'vacant_positions', 'department_headcount',
            'incomplete_documents', 'certifications_expiring', 'promotions_this_year',
            'probation_ending', 'employees_without_assignment',
        ];
    }

    /** Daftar pegawai yang boleh dibaca pemanggil — selalu tersaring cakupan. */
    private function scopedQuery(User $user, array $parameters)
    {
        return $this->access->applyEmployeeScope(HrEmployee::query(), $user);
    }

    /** @return array<string, mixed> */
    private function answerHeadcount($scoped, array $parameters): array
    {
        $query = (clone $scoped)->active();

        if (! empty($parameters['organization_id'])) {
            $query->where('organization_id', $parameters['organization_id']);
        }

        if (! empty($parameters['department_id'])) {
            $query->where('department_id', $parameters['department_id']);
        }

        $count = $query->count();

        $byUnit = (clone $scoped)->active()
            ->selectRaw('organization_id, count(*) as aggregate')
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id')
            ->all();

        return [
            'intent' => 'active_headcount',
            'answer' => $count,
            'unit' => 'pegawai',
            'breakdown' => array_map('intval', $byUnit),
            'parameters' => $parameters,
        ];
    }

    /** @return array<string, mixed> */
    private function answerContracts(array $parameters): array
    {
        $days = (int) ($parameters['days'] ?? 30);
        $rows = $this->employment->expiringContracts($days, $parameters['organization_id'] ?? null);

        return [
            'intent' => 'contracts_expiring',
            'answer' => count($rows),
            'unit' => 'kontrak',
            'items' => array_map(fn (array $row) => [
                'employee_id' => $row['employee_id'] ?? null,
                'employee_number' => $row['employee']['employee_number'] ?? null,
                'employee_name' => $row['employee']['person']['full_name'] ?? null,
                'contract_number' => $row['contract_number'] ?? null,
                'end_date' => $row['end_date'] ?? null,
            ], $rows),
            'parameters' => ['days' => $days],
        ];
    }

    /** @return array<string, mixed> */
    private function answerVacancies($scoped, array $parameters): array
    {
        $rows = $this->positions->vacancies($parameters['organization_id'] ?? null);

        return [
            'intent' => 'vacant_positions',
            'answer' => count($rows),
            'unit' => 'posisi',
            'items' => $rows,
            'parameters' => $parameters,
        ];
    }

    /** @return array<string, mixed> */
    private function answerMissingDocuments($scoped): array
    {
        $mandatory = \App\Modules\Hrd\Models\HrDocumentType::query()
            ->where('is_mandatory', true)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        if ($mandatory === []) {
            return [
                'intent' => 'incomplete_documents',
                'answer' => 0,
                'unit' => 'pegawai',
                'items' => [],
                'note' => 'Belum ada jenis dokumen yang ditandai wajib.',
            ];
        }

        $missing = [];

        (clone $scoped)->active()->select(['id', 'employee_number'])->chunkById(200, function ($employees) use (&$missing, $mandatory): void {
            foreach ($employees as $employee) {
                $owned = \App\Modules\Hrd\Models\HrEmployeeDocument::query()
                    ->where('employee_id', $employee->getKey())
                    ->whereIn('document_type_id', $mandatory)
                    ->pluck('document_type_id')
                    ->all();

                $absent = array_values(array_diff($mandatory, $owned));

                if ($absent !== []) {
                    $missing[] = [
                        'employee_id' => (int) $employee->getKey(),
                        'employee_number' => $employee->employee_number,
                        'missing_document_type_ids' => $absent,
                    ];
                }
            }
        });

        return [
            'intent' => 'incomplete_documents',
            'answer' => count($missing),
            'unit' => 'pegawai',
            'items' => $missing,
        ];
    }

    /** @return array<string, mixed> */
    private function answerCertifications(array $parameters): array
    {
        $rows = $this->competency->expiringCertifications((int) ($parameters['days'] ?? 60), $parameters['organization_id'] ?? null);

        return [
            'intent' => 'certifications_expiring',
            'answer' => count($rows),
            'unit' => 'sertifikat',
            'items' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function answerPromotions(array $parameters): array
    {
        $year = (int) ($parameters['year'] ?? now()->year);

        $rows = $this->transactions->query(['type_code' => 'PROMOTION'])
            ->whereYear('effective_date', $year)
            ->whereIn('status', ['approved', 'completed'])
            ->get();

        return [
            'intent' => 'promotions_this_year',
            'answer' => $rows->count(),
            'unit' => 'promosi',
            'items' => $rows->map(fn ($transaction) => [
                'transaction_number' => $transaction->transaction_number,
                'employee_name' => $transaction->employee?->displayName(),
                'effective_date' => $transaction->effective_date?->toDateString(),
            ])->all(),
            'parameters' => ['year' => $year],
        ];
    }

    /** @return array<string, mixed> */
    private function answerProbations(array $parameters): array
    {
        $rows = $this->employment->probationsEnding((int) ($parameters['days'] ?? 30), $parameters['organization_id'] ?? null);

        return [
            'intent' => 'probation_ending',
            'answer' => $rows->count(),
            'unit' => 'pegawai',
            'items' => $rows->map(fn ($probation) => [
                'employee_id' => (int) $probation->employee_id,
                'employee_name' => $probation->employee?->displayName(),
                'end_date' => $probation->end_date?->toDateString(),
                'status' => $probation->status,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function answerMissingAssignment($scoped): array
    {
        $rows = (clone $scoped)->active()
            ->whereDoesntHave('assignments', fn ($q) => $q->where('status', 'active'))
            ->get(['id', 'employee_number']);

        return [
            'intent' => 'employees_without_assignment',
            'answer' => $rows->count(),
            'unit' => 'pegawai',
            'items' => $rows->map(fn (HrEmployee $employee) => [
                'employee_id' => (int) $employee->getKey(),
                'employee_number' => $employee->employee_number,
            ])->all(),
        ];
    }

    /** Ringkasan teks untuk ditampilkan pada UI asisten (belum AI, sudah jujur). */
    public function humanize(array $result): string
    {
        if (($result['error'] ?? null) !== null) {
            return (string) $result['error'];
        }

        $unit = (string) ($result['unit'] ?? 'data');
        $answer = $result['answer'] ?? 0;

        return Str::of("{$answer} {$unit}")->ucfirst()->toString();
    }
}
