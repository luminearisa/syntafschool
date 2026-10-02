<?php

namespace App\Modules\Hrd\Application\Services;

use App\Modules\Hrd\Models\HrContract;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeCertification;
use App\Modules\Hrd\Models\HrOffboardingTask;
use App\Modules\Hrd\Models\HrOnboardingTask;
use App\Modules\Hrd\Models\HrProbation;
use Illuminate\Support\Carbon;

/**
 * Kalender HR (§71).
 *
 * Tidak ada tabel kalender sendiri: setiap butir **dihitung** dari sumbernya —
 * kontrak yang berakhir, masa percobaan yang selesai, ulang tahun, hari jadi
 * bekerja, sertifikasi yang kedaluwarsa, tenggat tugas onboarding/offboarding.
 *
 * Dengan begitu kalender tidak pernah menjadi daftar acara yang lupa diperbarui,
 * dan modul Kalender/Event dapat mengambil daftar yang sama lewat kontrak.
 */
class HrdCalendarService
{
    /**
     * Butir kalender pada satu rentang tanggal.
     *
     * @return list<array<string, mixed>>
     */
    public function between(string $fromDate, string $toDate, ?int $organizationId = null, ?int $employeeId = null, array $types = []): array
    {
        $items = [];

        $wants = fn (string $type): bool => $types === [] || in_array($type, $types, true);

        if ($wants('contract_expiry')) {
            $items = array_merge($items, $this->contracts($fromDate, $toDate, $organizationId, $employeeId));
        }

        if ($wants('probation_end')) {
            $items = array_merge($items, $this->probations($fromDate, $toDate, $organizationId, $employeeId));
        }

        if ($wants('certification_expiry')) {
            $items = array_merge($items, $this->certifications($fromDate, $toDate, $organizationId, $employeeId));
        }

        if ($wants('onboarding')) {
            $items = array_merge($items, $this->onboardingTasks($fromDate, $toDate, $employeeId));
        }

        if ($wants('offboarding')) {
            $items = array_merge($items, $this->offboardingTasks($fromDate, $toDate, $employeeId));
        }

        if ($wants('birthday') || $wants('anniversary')) {
            $items = array_merge($items, $this->personalDates($fromDate, $toDate, $organizationId, $employeeId, $wants('birthday'), $wants('anniversary')));
        }

        usort($items, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $items;
    }

    /** Butir kalender bulan berjalan — dasar widget dasbor. */
    public function currentMonth(?int $organizationId = null, ?int $employeeId = null): array
    {
        return $this->between(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), $organizationId, $employeeId);
    }

    /** @return list<array<string, mixed>> */
    private function contracts(string $from, string $to, ?int $organizationId, ?int $employeeId): array
    {
        return HrContract::query()
            ->with(['employee.person:id,full_name', 'employee:id,employee_number,person_id,organization_id'])
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$from, $to])
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->get()
            ->map(fn (HrContract $contract) => $this->item(
                'contract_expiry',
                $contract->end_date->toDateString(),
                'Kontrak berakhir — '.($contract->employee?->displayName() ?? ''),
                $contract->employee,
                ['contract_number' => $contract->contract_number, 'status' => $contract->status],
                'file-signature',
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function probations(string $from, string $to, ?int $organizationId, ?int $employeeId): array
    {
        return HrProbation::query()
            ->with(['employee.person:id,full_name', 'employee:id,employee_number,person_id,organization_id'])
            ->whereIn('status', ['pending', 'active', 'extended'])
            ->whereBetween('end_date', [$from, $to])
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->get()
            ->map(fn (HrProbation $probation) => $this->item(
                'probation_end',
                $probation->end_date->toDateString(),
                'Masa percobaan berakhir — '.($probation->employee?->displayName() ?? ''),
                $probation->employee,
                ['probation_status' => $probation->status, 'review_date' => $probation->review_date?->toDateString()],
                'hourglass',
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function certifications(string $from, string $to, ?int $organizationId, ?int $employeeId): array
    {
        return HrEmployeeCertification::query()
            ->with(['employee.person:id,full_name', 'employee:id,employee_number,person_id,organization_id'])
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$from, $to])
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->get()
            ->map(fn (HrEmployeeCertification $certification) => $this->item(
                'certification_expiry',
                $certification->expiry_date->toDateString(),
                'Sertifikat berakhir — '.$certification->certificate_name,
                $certification->employee,
                ['certificate_number' => $certification->certificate_number],
                'award',
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function onboardingTasks(string $from, string $to, ?int $employeeId): array
    {
        return HrOnboardingTask::query()
            ->with(['process.employee.person:id,full_name'])
            ->open()
            ->whereBetween('due_date', [$from, $to])
            ->when($employeeId !== null, fn ($q) => $q->whereHas('process', fn ($p) => $p->where('employee_id', $employeeId)))
            ->get()
            ->map(function (HrOnboardingTask $task): array {
                $employee = $task->process?->employee;

                return $this->item('onboarding', $task->due_date?->toDateString() ?? $from, 'Tugas onboarding — '.$task->title, $employee, [
                    'task_id' => (int) $task->getKey(),
                    'status' => $task->status,
                    'is_mandatory' => (bool) $task->is_mandatory,
                ], 'clipboard-check');
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function offboardingTasks(string $from, string $to, ?int $employeeId): array
    {
        return HrOffboardingTask::query()
            ->with(['process.employee.person:id,full_name'])
            ->open()
            ->whereBetween('due_date', [$from, $to])
            ->when($employeeId !== null, fn ($q) => $q->whereHas('process', fn ($p) => $p->where('employee_id', $employeeId)))
            ->get()
            ->map(fn (HrOffboardingTask $task) => $this->item(
                'offboarding',
                $task->due_date?->toDateString() ?? $from,
                'Tugas keluar — '.$task->title,
                $task->process?->employee,
                ['task_id' => (int) $task->getKey(), 'clearance_area' => $task->clearance_area, 'status' => $task->status],
                'clipboard-x',
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function personalDates(string $from, string $to, ?int $organizationId, ?int $employeeId, bool $birthdays, bool $anniversaries): array
    {
        $employees = HrEmployee::query()
            ->with('person:id,full_name,birth_date')
            ->active()
            ->when($employeeId !== null, fn ($q) => $q->whereKey($employeeId))
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->get();

        $items = [];
        $periodDays = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to));

        foreach ($employees as $employee) {
            if ($birthdays && $employee->person?->birth_date !== null) {
                $occurrence = $this->nextOccurrence($employee->person->birth_date, $from, $periodDays);

                if ($occurrence !== null) {
                    $items[] = $this->item('birthday', $occurrence, 'Ulang tahun — '.$employee->displayName(), $employee, [], 'cake');
                }
            }

            if ($anniversaries && $employee->hire_date !== null) {
                $occurrence = $this->nextOccurrence($employee->hire_date, $from, $periodDays, onlyAfterYear: false);

                if ($occurrence !== null) {
                    $years = (int) Carbon::parse($employee->hire_date)->diffInYears($occurrence);
                    $items[] = $this->item('anniversary', $occurrence, "Hari jadi bekerja {$years} tahun — ".$employee->displayName(), $employee, ['years' => $years], 'party-popper');
                }
            }
        }

        return $items;
    }

    private function nextOccurrence(\DateTimeInterface|string $date, string $from, int $periodDays, bool $onlyAfterYear = true): ?string
    {
        $original = Carbon::parse($date);
        $candidate = Carbon::parse($from)->setDate($original->year, $original->month, $original->day);

        if ($candidate->isBefore(Carbon::parse($from))) {
            $candidate->addYear();
        }

        return $candidate->diffInDays(Carbon::parse($from)) <= $periodDays ? $candidate->toDateString() : null;
    }

    /** @return array<string, mixed> */
    private function item(string $type, string $date, string $title, ?HrEmployee $employee, array $meta = [], string $icon = 'calendar'): array
    {
        return [
            'type' => $type,
            'date' => $date,
            'title' => $title,
            'icon' => $icon,
            'employee_id' => $employee?->getKey(),
            'employee_number' => $employee?->employee_number,
            'employee_name' => $employee?->displayName(),
            'meta' => $meta,
        ];
    }
}
