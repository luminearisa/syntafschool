<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Enums\DataQualitySeverity;
use App\Modules\Hrd\Models\HrContract;
use App\Modules\Hrd\Models\HrDataQualityIssue;
use App\Modules\Hrd\Models\HrDataQualityScan;
use App\Modules\Hrd\Models\HrDocumentType;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrEmployeeCertification;
use App\Modules\Hrd\Models\HrEmployeeDocument;
use App\Modules\Hrd\Models\HrPerson;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mesin kualitas data & validasi (§56, §57).
 *
 * Setiap pemeriksa mencari satu pola kesalahan yang nyata di sekolah, bukan
 * sekadar "kolom kosong". Yang dicari: pegawai tanpa penugasan, dua penugasan
 * utama, jenjang atasan melingkar, formasi terlampaui, kontrak berjangka tanpa
 * tanggal berakhir, NIK ganda, guru tanpa sertifikat wajib, posisi yang menggantung
 * tanpa unit.
 *
 * Temuan disimpan dengan `fingerprint` sehingga satu masalah = satu baris yang
 * dapat ditindaklanjuti; pemindaian berikutnya tidak menumpuk duplikat, ia hanya
 * memperbarui kapan terakhir terlihat.
 */
class DataQualityService
{
    /** @var array<string, callable(array<string, mixed>): list<array<string, mixed>>> */
    private array $handlers = [];

    public function __construct(private readonly HrdAuditService $audit)
    {
        $this->registerDefaultHandlers();
    }

    /** Jenis pemeriksa bawaan; dapat ditambah modul lain lewat konfigurasi aturan. */
    private function registerDefaultHandlers(): void
    {
        $this->handlers = [
            'employee.without_assignment' => fn (array $config) => $this->employeesWithoutAssignment(),
            'employee.without_organization' => fn (array $config) => $this->employeesWithoutOrganization(),
            'employee.without_department' => fn (array $config) => $this->employeesMissingField('department_id', 'warning'),
            'employee.without_position' => fn (array $config) => $this->employeesMissingField('position_id', 'warning'),
            'employee.without_emergency_contact' => fn (array $config) => $this->employeesWithoutEmergencyContact(),
            'employee.without_education' => fn (array $config) => $this->employeesWithoutEducation(),
            'assignment.multiple_primary' => fn (array $config) => $this->multiplePrimaryAssignments(),
            'assignment.orphan' => fn (array $config) => $this->orphanAssignments(),
            'manager.circular' => fn (array $config) => $this->circularManagerChains(),
            'manager.orphan' => fn (array $config) => $this->orphanManagerReferences(),
            'position.orphan' => fn (array $config) => $this->orphanPositions(),
            'position.headcount_exceeded' => fn (array $config) => $this->headcountExceeded(),
            'position.without_job' => fn (array $config) => $this->positionsWithoutJob(),
            'contract.fixed_term_without_end_date' => fn (array $config) => $this->fixedTermContractsWithoutEndDate(),
            'contract.expired_still_active' => fn (array $config) => $this->expiredContractsStillActive(),
            'document.expiring' => fn (array $config) => $this->expiringDocuments((int) ($config['window_days'] ?? 30)),
            'certification.expiring' => fn (array $config) => $this->expiringCertifications((int) ($config['window_days'] ?? 30)),
            'certification.missing_mandatory' => fn (array $config) => $this->missingMandatoryCertifications(),
            'person.duplicate_nik' => fn (array $config) => $this->duplicateNik(),
            'person.duplicate_contact' => fn (array $config) => $this->duplicateContacts(),
            'employee.invalid_dates' => fn (array $config) => $this->invalidEmployeeDates(),
            'employee.missing_mandatory_document' => fn (array $config) => $this->missingMandatoryDocuments(),
        ];
    }

    /**
     * Jalankan pemindaian menyeluruh (§56).
     *
     * @return array<string, mixed>
     */
    public function scan(User $actor, bool $scheduled = false): array
    {
        $rules = HrValidationRule::query()->active()->autoDetect()->get();

        $scan = HrDataQualityScan::query()->create([
            'trigger' => $scheduled ? 'scheduled' : 'manual',
            'started_at' => now(),
            'actor_id' => $actor->getKey(),
        ]);

        $totals = ['critical' => 0, 'warning' => 0, 'info' => 0, 'rules' => []];

        foreach ($rules as $rule) {
            $handler = $this->handlers[$rule->handler ?? ''] ?? null;

            if ($handler === null) {
                continue;
            }

            try {
                $findings = $handler($rule->config ?? []);
            } catch (\Throwable $exception) {
                $totals['rules'][$rule->code] = ['error' => mb_substr($exception->getMessage(), 0, 200)];

                continue;
            }

            $severity = DataQualitySeverity::tryFrom($rule->severity) ?? DataQualitySeverity::WARNING;
            $count = 0;

            foreach ($findings as $finding) {
                $this->persistIssue($scan, $rule, $severity, $finding);
                $count++;
                $totals[$severity->value]++;
            }

            $totals['rules'][$rule->code] = $count;
        }

        // Temuan yang hilang pada pemindaian ini berarti sudah diperbaiki.
        HrDataQualityIssue::query()
            ->unresolved()
            ->where(fn ($q) => $q->whereNull('scan_id')->orWhere('scan_id', '<', $scan->getKey()))
            ->whereNotIn('rule_code', array_keys($totals['rules']))
            ->update(['status' => HrDataQualityIssue::STATUS_RESOLVED, 'resolved_at' => now()]);

        $score = $this->calculateScore($totals);

        $scan->forceFill([
            'finished_at' => now(),
            'score' => $score,
            'totals' => $totals,
        ])->save();

        $this->audit->logEvent($actor, 'data_quality.scanned', HrDataQualityScan::class, $scan->getKey(), [
            'score' => $score,
            'critical' => $totals['critical'],
            'warning' => $totals['warning'],
        ]);

        return [
            'scan_id' => (int) $scan->getKey(),
            'score' => $score,
            'totals' => $totals,
            'rules_evaluated' => count($totals['rules']),
        ];
    }

    /** Skor 0–100: makin berat temuan, makin besar pengurangannya (§47 Data Quality). */
    public function calculateScore(array $totals): int
    {
        $weights = (array) config('hrd.data_quality.weights', ['critical' => 5, 'warning' => 2, 'info' => 1]);
        $penalty = 0;

        foreach ($weights as $severity => $weight) {
            $penalty += ((int) ($totals[$severity] ?? 0)) * (int) $weight;
        }

        return max(0, 100 - $penalty);
    }

    /**
     * Ringkasan temuan terbuka untuk dasbor.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $bySeverity = HrDataQualityIssue::query()
            ->unresolved()
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity')
            ->all();

        $byRule = HrDataQualityIssue::query()
            ->unresolved()
            ->selectRaw('rule_code, severity, count(*) as aggregate')
            ->groupBy('rule_code', 'severity')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'rule_code' => $row->rule_code,
                'severity' => $row->severity,
                'count' => (int) $row->aggregate,
                'label' => HrValidationRule::query()->where('code', $row->rule_code)->value('name'),
            ])
            ->all();

        $lastScan = HrDataQualityScan::query()->orderByDesc('id')->first();

        return [
            'open' => (int) array_sum($bySeverity),
            'critical' => (int) ($bySeverity['critical'] ?? 0),
            'warning' => (int) ($bySeverity['warning'] ?? 0),
            'info' => (int) ($bySeverity['info'] ?? 0),
            'score' => $lastScan?->score === null ? null : (int) $lastScan->score,
            'last_scan_at' => $lastScan?->finished_at?->toDateTimeString(),
            'top_rules' => $byRule,
        ];
    }

    /** Validasi sebuah pegawai memakai aturan yang berlaku (§57). */
    public function validateEmployee(HrEmployee $employee, bool $blockingOnly = false): array
    {
        $issues = [];

        foreach ($this->handlers as $code => $handler) {
            $rule = HrValidationRule::query()->where('code', $code)->first();

            if ($rule === null || ! $rule->is_active) {
                continue;
            }

            $severity = DataQualitySeverity::tryFrom($rule->severity) ?? DataQualitySeverity::WARNING;

            if ($blockingOnly && ! $severity->isBlocking()) {
                continue;
            }

            foreach ($handler($rule->config ?? []) as $finding) {
                if ((int) ($finding['entity_id'] ?? 0) === (int) $employee->getKey()) {
                    $issues[] = [
                        'rule_code' => $code,
                        'severity' => $severity->value,
                        'message' => $rule->message,
                        'suggestion' => $rule->suggestion,
                        'details' => $finding,
                    ];
                }
            }
        }

        return $issues;
    }

    /** Tindak lanjuti temuan: diketahui, selesai, atau diabaikan dengan alasan. */
    public function resolveIssue(HrDataQualityIssue $issue, User $actor, string $status, ?string $notes = null): HrDataQualityIssue
    {
        $valid = [
            HrDataQualityIssue::STATUS_ACKNOWLEDGED,
            HrDataQualityIssue::STATUS_RESOLVED,
            HrDataQualityIssue::STATUS_IGNORED,
        ];

        abort_unless(in_array($status, $valid, true), 422, 'Status penanganan temuan tidak dikenal.');

        if ($status === HrDataQualityIssue::STATUS_IGNORED && ($notes === null || trim($notes) === '')) {
            abort(422, 'Mengabaikan temuan kualitas data wajib menyertakan alasan.');
        }

        $before = $issue->toArray();

        $issue->forceFill([
            'status' => $status,
            'resolved_at' => $status === HrDataQualityIssue::STATUS_ACKNOWLEDGED ? null : now(),
            'resolved_by' => $actor->getKey(),
            'resolution_notes' => $notes,
        ])->save();

        $this->audit->log($actor, 'data_quality.issue_'.$status, $issue, $before, $issue->toArray(), $notes);

        return $issue->refresh();
    }

    /** @return array<string, mixed> */
    private function persistIssue(HrDataQualityScan $scan, HrValidationRule $rule, DataQualitySeverity $severity, array $finding): void
    {
        $fingerprint = hash('sha256', $rule->code.'|'.($finding['entity_type'] ?? 'employee').'|'.($finding['entity_id'] ?? '0').'|'.($finding['key'] ?? ''));

        $existing = HrDataQualityIssue::query()->where('fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            $existing->forceFill([
                'scan_id' => $scan->getKey(),
                'status' => $existing->status === HrDataQualityIssue::STATUS_RESOLVED ? HrDataQualityIssue::STATUS_OPEN : $existing->status,
                'detected_at' => now(),
                'details' => $finding,
            ])->save();

            return;
        }

        HrDataQualityIssue::query()->create([
            'scan_id' => $scan->getKey(),
            'rule_code' => $rule->code,
            'entity_type' => $finding['entity_type'] ?? 'employee',
            'entity_id' => $finding['entity_id'] ?? null,
            'employee_id' => $finding['employee_id'] ?? null,
            'severity' => $severity->value,
            'status' => HrDataQualityIssue::STATUS_OPEN,
            'message' => $finding['message'] ?? $rule->message,
            'details' => $finding,
            'fingerprint' => $fingerprint,
            'detected_at' => now(),
        ]);
    }

    /* ── Pemeriksa ──────────────────────────────────────────────────────── */

    /** @return list<array<string, mixed>> */
    private function employeesWithoutAssignment(): array
    {
        return HrEmployee::query()
            ->whereNotIn('lifecycle_state', ['inactive', 'archived', 'draft'])
            ->whereDoesntHave('assignments', fn ($q) => $q->where('status', 'active'))
            ->get(['id', 'employee_number', 'person_id'])
            ->map(fn (HrEmployee $employee) => $this->finding($employee, 'Pegawai aktif tanpa penugasan aktif.', ['employee_number' => $employee->employee_number]))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function employeesWithoutOrganization(): array
    {
        return HrEmployee::query()
            ->whereNotIn('lifecycle_state', ['inactive', 'archived', 'draft'])
            ->whereNull('organization_id')
            ->get(['id', 'employee_number'])
            ->map(fn (HrEmployee $employee) => $this->finding($employee, 'Pegawai tidak terhubung ke unit organisasi mana pun.'))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function employeesMissingField(string $field, string $severity): array
    {
        return HrEmployee::query()
            ->whereNotIn('lifecycle_state', ['inactive', 'archived', 'draft'])
            ->whereNull($field)
            ->get(['id', 'employee_number', 'organization_id'])
            ->map(fn (HrEmployee $employee) => $this->finding($employee, "Kolom {$field} belum diisi.", ['severity_hint' => $severity]))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function employeesWithoutEmergencyContact(): array
    {
        return HrEmployee::query()
            ->whereNotIn('lifecycle_state', ['inactive', 'archived', 'draft'])
            ->whereDoesntHave('emergencyContacts')
            ->get(['id', 'employee_number'])
            ->map(fn (HrEmployee $employee) => $this->finding($employee, 'Kontak darurat belum diisi.', ['field' => 'emergency_contact']))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function employeesWithoutEducation(): array
    {
        return HrEmployee::query()
            ->whereNotIn('lifecycle_state', ['inactive', 'archived', 'draft'])
            ->whereDoesntHave('educations')
            ->get(['id', 'employee_number'])
            ->map(fn (HrEmployee $employee) => $this->finding($employee, 'Riwayat pendidikan belum diisi.', ['field' => 'education']))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function multiplePrimaryAssignments(): array
    {
        $rows = HrEmployeeAssignment::query()
            ->where('is_primary', true)
            ->where('status', 'active')
            ->selectRaw('employee_id, count(*) as aggregate')
            ->groupBy('employee_id')
            ->havingRaw('count(*) > 1')
            ->get();

        return $rows->map(fn ($row) => [
            'entity_type' => 'employee',
            'entity_id' => (int) $row->employee_id,
            'employee_id' => (int) $row->employee_id,
            'key' => 'primary_assignments',
            'message' => 'Pegawai memiliki lebih dari satu penugasan utama aktif ('.$row->aggregate.').',
            'count' => (int) $row->aggregate,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function orphanAssignments(): array
    {
        return HrEmployeeAssignment::query()
            ->where('status', 'active')
            ->whereNull('organization_id')
            ->get(['id', 'employee_id'])
            ->map(fn (HrEmployeeAssignment $assignment) => [
                'entity_type' => 'assignment',
                'entity_id' => (int) $assignment->getKey(),
                'employee_id' => (int) $assignment->employee_id,
                'key' => 'orphan',
                'message' => 'Penugasan aktif tanpa unit organisasi.',
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function circularManagerChains(): array
    {
        $findings = [];

        HrEmployee::query()
            ->whereNotNull('manager_employee_id')
            ->select(['id', 'employee_number', 'manager_employee_id'])
            ->chunkById(200, function (Collection $employees) use (&$findings): void {
                foreach ($employees as $employee) {
                    $visited = [(int) $employee->getKey()];
                    $currentId = $employee->manager_employee_id;
                    $depth = 0;

                    while ($currentId !== null && $depth < 12) {
                        if (in_array((int) $currentId, $visited, true)) {
                            $findings[] = $this->finding($employee, 'Rantai atasan membentuk lingkaran.', ['manager_employee_id' => (int) $currentId]);

                            break;
                        }

                        $visited[] = (int) $currentId;
                        $currentId = HrEmployee::query()->whereKey($currentId)->value('manager_employee_id');
                        $depth++;
                    }
                }
            });

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private function orphanManagerReferences(): array
    {
        return HrEmployee::query()
            ->whereNotNull('manager_employee_id')
            ->whereDoesntHave('manager')
            ->get(['id', 'employee_number', 'manager_employee_id'])
            ->map(fn (HrEmployee $employee) => $this->finding($employee, 'Atasan yang dirujuk tidak ditemukan.', ['manager_employee_id' => $employee->manager_employee_id]))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function orphanPositions(): array
    {
        return HrPosition::query()
            ->open()
            ->whereDoesntHave('organization')
            ->get(['id', 'code', 'title'])
            ->map(fn (HrPosition $position) => [
                'entity_type' => 'position',
                'entity_id' => (int) $position->getKey(),
                'key' => 'orphan',
                'message' => "Posisi {$position->code} tidak terhubung ke unit organisasi.",
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function headcountExceeded(): array
    {
        return HrPosition::query()
            ->open()
            ->whereColumn('filled_headcount', '>', 'authorized_headcount')
            ->get(['id', 'code', 'title', 'filled_headcount', 'authorized_headcount'])
            ->map(fn (HrPosition $position) => [
                'entity_type' => 'position',
                'entity_id' => (int) $position->getKey(),
                'key' => 'headcount',
                'message' => "Posisi {$position->code} terisi {$position->filled_headcount} dari formasi {$position->authorized_headcount}.",
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function positionsWithoutJob(): array
    {
        return HrPosition::query()
            ->open()
            ->whereNull('job_id')
            ->get(['id', 'code', 'title'])
            ->map(fn (HrPosition $position) => [
                'entity_type' => 'position',
                'entity_id' => (int) $position->getKey(),
                'key' => 'no_job',
                'message' => "Posisi {$position->code} belum dihubungkan ke jabatan (job).",
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function fixedTermContractsWithoutEndDate(): array
    {
        return HrContract::query()
            ->whereNull('end_date')
            ->whereHas('contractType', fn ($q) => $q->where('is_fixed_term', true))
            ->get(['id', 'employee_id', 'contract_number'])
            ->map(fn (HrContract $contract) => [
                'entity_type' => 'contract',
                'entity_id' => (int) $contract->getKey(),
                'employee_id' => (int) $contract->employee_id,
                'key' => 'no_end_date',
                'message' => "Kontrak berjangka {$contract->contract_number} tidak memiliki tanggal berakhir.",
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function expiredContractsStillActive(): array
    {
        return HrContract::query()
            ->whereIn('status', ['active', 'expiring'])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString())
            ->get(['id', 'employee_id', 'contract_number', 'end_date'])
            ->map(fn (HrContract $contract) => [
                'entity_type' => 'contract',
                'entity_id' => (int) $contract->getKey(),
                'employee_id' => (int) $contract->employee_id,
                'key' => 'expired_active',
                'message' => "Kontrak {$contract->contract_number} sudah berakhir tetapi masih berstatus aktif.",
                'end_date' => $contract->end_date?->toDateString(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function expiringDocuments(int $windowDays): array
    {
        return HrEmployeeDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($windowDays)->toDateString())
            ->whereNotIn('status', ['revoked', 'replaced', 'archived'])
            ->get(['id', 'employee_id', 'title', 'expiry_date'])
            ->map(fn (HrEmployeeDocument $document) => [
                'entity_type' => 'document',
                'entity_id' => (int) $document->getKey(),
                'employee_id' => (int) $document->employee_id,
                'key' => 'expiring',
                'message' => 'Dokumen '.($document->title ?? '').' berakhir pada '.$document->expiry_date?->toDateString().'.',
                'window_days' => $windowDays,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function expiringCertifications(int $windowDays): array
    {
        return HrEmployeeCertification::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($windowDays)->toDateString())
            ->get(['id', 'employee_id', 'certificate_name', 'expiry_date'])
            ->map(fn (HrEmployeeCertification $certification) => [
                'entity_type' => 'certification',
                'entity_id' => (int) $certification->getKey(),
                'employee_id' => (int) $certification->employee_id,
                'key' => 'expiring',
                'message' => 'Sertifikat '.$certification->certificate_name.' berakhir pada '.$certification->expiry_date?->toDateString().'.',
                'window_days' => $windowDays,
            ])
            ->all();
    }

    /** Guru wajib bersertifikat pendidik (§57 contoh aturan kepatuhan). */
    private function missingMandatoryCertifications(): array
    {
        $mandatoryIds = \App\Modules\Hrd\Models\HrCertification::query()
            ->where('is_mandatory_for_teachers', true)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        if ($mandatoryIds === []) {
            return [];
        }

        $teacherEmployeeIds = HrEmployee::query()
            ->whereHas('position', fn ($q) => $q->whereHas('job', fn ($job) => $job->where('is_teacher_role', true)))
            ->whereNotIn('lifecycle_state', ['inactive', 'archived', 'draft'])
            ->pluck('id');

        $findings = [];

        foreach ($teacherEmployeeIds as $employeeId) {
            foreach ($mandatoryIds as $certificationId) {
                $has = HrEmployeeCertification::query()
                    ->where('employee_id', $employeeId)
                    ->where('certification_id', $certificationId)
                    ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))
                    ->exists();

                if (! $has) {
                    $findings[] = [
                        'entity_type' => 'employee',
                        'entity_id' => (int) $employeeId,
                        'employee_id' => (int) $employeeId,
                        'key' => 'certification_'.$certificationId,
                        'message' => 'Guru belum memiliki sertifikasi wajib yang masih berlaku.',
                    ];
                }
            }
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private function duplicateNik(): array
    {
        return HrPerson::query()
            ->whereNotNull('nik')
            ->selectRaw('nik, count(*) as aggregate')
            ->groupBy('nik')
            ->havingRaw('count(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'entity_type' => 'person',
                'entity_id' => null,
                'key' => 'nik_'.$row->nik,
                'message' => "NIK {$row->nik} terdaftar pada {$row->aggregate} orang.",
                'nik' => $row->nik,
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function duplicateContacts(): array
    {
        $findings = [];

        foreach (['mobile', 'personal_email'] as $field) {
            $rows = HrPerson::query()
                ->whereNotNull($field)
                ->where($field, '!=', '')
                ->selectRaw("$field as value, count(*) as aggregate")
                ->groupBy($field)
                ->havingRaw('count(*) > 1')
                ->get();

            foreach ($rows as $row) {
                $findings[] = [
                    'entity_type' => 'person',
                    'entity_id' => null,
                    'key' => $field.'_'.$row->value,
                    'message' => "Nilai {$field} \"{$row->value}\" dipakai {$row->aggregate} orang.",
                    'field' => $field,
                    'count' => (int) $row->aggregate,
                ];
            }
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private function invalidEmployeeDates(): array
    {
        return HrEmployee::query()
            ->whereNotNull('termination_date')
            ->whereNotNull('hire_date')
            ->whereColumn('termination_date', '<', 'hire_date')
            ->get(['id', 'employee_number', 'hire_date', 'termination_date'])
            ->map(fn (HrEmployee $employee) => $this->finding($employee, 'Tanggal berhenti mendahului tanggal masuk.'))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function missingMandatoryDocuments(): array
    {
        $mandatoryTypes = HrDocumentType::query()->where('is_mandatory', true)->where('is_active', true)->get();

        if ($mandatoryTypes->isEmpty()) {
            return [];
        }

        $findings = [];

        HrEmployee::query()
            ->whereNotIn('lifecycle_state', ['inactive', 'archived', 'draft'])
            ->select(['id', 'employee_number'])
            ->chunkById(200, function (Collection $employees) use ($mandatoryTypes, &$findings): void {
                foreach ($employees as $employee) {
                    $owned = HrEmployeeDocument::query()
                        ->where('employee_id', $employee->getKey())
                        ->whereIn('document_type_id', $mandatoryTypes->pluck('id')->all())
                        ->pluck('document_type_id')
                        ->all();

                    foreach ($mandatoryTypes as $type) {
                        if (! in_array($type->getKey(), $owned, true)) {
                            $findings[] = [
                                'entity_type' => 'employee',
                                'entity_id' => (int) $employee->getKey(),
                                'employee_id' => (int) $employee->getKey(),
                                'key' => 'doc_'.$type->getKey(),
                                'message' => "Dokumen wajib \"{$type->name}\" belum diunggah.",
                            ];
                        }
                    }
                }
            });

        return $findings;
    }

    /** @return array<string, mixed> */
    private function finding(HrEmployee $employee, string $message, array $extra = []): array
    {
        return [
            'entity_type' => 'employee',
            'entity_id' => (int) $employee->getKey(),
            'employee_id' => (int) $employee->getKey(),
            'key' => $extra['field'] ?? 'general',
            'message' => $message,
        ] + $extra;
    }

    /** Jalankan satu aturan saja (dipakai validasi sebelum menyimpan). */
    public function runRule(string $ruleCode): array
    {
        $rule = HrValidationRule::query()->where('code', $ruleCode)->first();

        if ($rule === null) {
            return [];
        }

        $handler = $this->handlers[$rule->handler ?? ''] ?? null;

        return $handler === null ? [] : $handler($rule->config ?? []);
    }

    public function isSupportedHandler(?string $handler): bool
    {
        return $handler !== null && array_key_exists($handler, $this->handlers);
    }

    /** @return list<string> */
    public function supportedHandlers(): array
    {
        return array_keys($this->handlers);
    }

    public function countOpen(): int
    {
        return HrDataQualityIssue::query()->unresolved()->count();
    }
}
