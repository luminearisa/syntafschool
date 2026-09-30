<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicScreening;
use App\Modules\Clinic\Models\ClinicScreeningProgram;
use App\Modules\Clinic\Models\ClinicVaccination;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Skrining kesehatan berkala & pencatatan imunisasi.
 *
 * Skrining bersifat massal: satu program, banyak siswa. Karena itu pencatatan
 * hasil harus tahan terhadap pengiriman berulang (idempotent per siswa +
 * tanggal) supaya laporan tidak menghitung siswa yang sama dua kali.
 *
 * Catatan batasan: modul ini **bukan** registry imunisasi resmi. Ia mencatat
 * apa yang dilakukan sekolah, dan tidak menggantikan pencatatan puskesmas.
 */
class ScreeningService
{
    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly PatientService $patients,
        private readonly ClinicAuditService $audit,
        private readonly VisitService $visits,
    ) {
    }

    /**
     * Buat program skrining.
     *
     * @param array<string, mixed> $data
     */
    public function createProgram(array $data, User $actor): ClinicScreeningProgram
    {
        $program = ClinicScreeningProgram::query()->create([
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => $data['name'],
            'type' => $data['type'] ?? 'GENERAL',
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'academic_year_id' => $data['academic_year_id'] ?? DB::table('academic_years')->where('is_active', true)->value('id'),
            'status' => $data['status'] ?? 'ACTIVE',
            'description' => $data['description'] ?? null,
            'notify_parent' => (bool) ($data['notify_parent'] ?? true),
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::CREATE, $program, null, $actor, [
            'code' => $program->code,
            'type' => $program->type,
        ], [], 'Program skrining dibuat');

        return $program;
    }

    /**
     * Catat hasil skrining seorang siswa (idempotent per siswa + tanggal + jenis).
     *
     * @param array<string, mixed> $data
     */
    public function record(ClinicScreeningProgram $program, int $studentId, array $data, User $actor): ClinicScreening
    {
        $patient = $this->patients->forStudent($studentId, $actor);
        $screenedAt = $data['screened_at'] ?? now()->toDateString();
        $type = $data['type'] ?? $program->type;

        $bmi = $this->visits->bmi($data['weight_kg'] ?? null, $data['height_cm'] ?? null);

        return DB::transaction(function () use ($program, $patient, $studentId, $data, $actor, $screenedAt, $type, $bmi): ClinicScreening {
            $existing = ClinicScreening::query()
                ->where('patient_id', $patient->id)
                ->whereDate('screened_at', $screenedAt)
                ->where('type', $type)
                ->first();

            $payload = [
                'program_id' => $program->id,
                'patient_id' => $patient->id,
                'student_id' => $studentId,
                'clinic_id' => $data['clinic_id'] ?? null,
                'type' => $type,
                'screened_at' => $screenedAt,
                'height_cm' => $data['height_cm'] ?? null,
                'weight_kg' => $data['weight_kg'] ?? null,
                'bmi' => $bmi,
                'bmi_category' => $bmi === null ? null : \App\Modules\Clinic\Models\ClinicVitalSign::classifyBmi($bmi),
                'vision_left' => $data['vision_left'] ?? null,
                'vision_right' => $data['vision_right'] ?? null,
                'hearing_left' => $data['hearing_left'] ?? null,
                'hearing_right' => $data['hearing_right'] ?? null,
                'dental_status' => $data['dental_status'] ?? null,
                'result' => $data['result'] ?? 'NORMAL',
                'findings' => $data['findings'] ?? null,
                'recommendation' => $data['recommendation'] ?? null,
                'follow_up_required' => (bool) ($data['follow_up_required'] ?? false),
                'screened_by' => $actor->id,
            ];

            if ($existing !== null) {
                $existing->fill($payload)->save();
                $screening = $existing;
            } else {
                $screening = ClinicScreening::query()->create($payload);
            }

            $this->audit->record(
                $existing !== null ? AuditAction::UPDATE : AuditAction::CREATE,
                $screening,
                (int) $patient->id,
                $actor,
                ['result' => $screening->result, 'type' => $screening->type, 'bmi' => $screening->bmi],
                [],
                'Hasil skrining dicatat',
            );

            return $screening->fresh();
        });
    }

    /**
     * Catat imunisasi.
     *
     * @param array<string, mixed> $data
     */
    public function recordVaccination(ClinicPatient $patient, array $data, User $actor): ClinicVaccination
    {
        $vaccination = ClinicVaccination::query()->create([
            'patient_id' => $patient->id,
            'student_id' => $patient->student_id,
            'clinic_id' => $data['clinic_id'] ?? null,
            'vaccine' => $data['vaccine'],
            'dose' => $data['dose'] ?? 1,
            'given_at' => $data['given_at'] ?? now()->toDateString(),
            'batch_number' => $data['batch_number'] ?? null,
            'provider' => $data['provider'] ?? null,
            'location' => $data['location'] ?? null,
            'next_due_at' => $data['next_due_at'] ?? null,
            'reaction' => $data['reaction'] ?? null,
            'notes' => $data['notes'] ?? null,
            'consent_obtained' => (bool) ($data['consent_obtained'] ?? false),
            'recorded_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::CREATE, $vaccination, (int) $patient->id, $actor, [
            'vaccine' => $vaccination->vaccine,
            'dose' => $vaccination->dose,
        ], [], 'Imunisasi dicatat');

        return $vaccination;
    }

    /**
     * Daftar siswa yang menjadi sasaran program (per kelas atau seluruh sekolah).
     *
     * @return list<int>
     */
    public function targetStudents(ClinicScreeningProgram $program, ?int $schoolClassId = null, ?int $educationLevelId = null): array
    {
        if ($schoolClassId !== null) {
            return $this->students->studentIdsInClass($schoolClassId);
        }

        return $this->students->activeStudentIds($educationLevelId);
    }

    /** Progres program: berapa siswa sasaran yang sudah diskrining. */
    public function progress(ClinicScreeningProgram $program): array
    {
        $done = ClinicScreening::query()->where('program_id', $program->id)->count();
        $flagged = ClinicScreening::query()
            ->where('program_id', $program->id)
            ->whereIn('result', ['NEEDS_ATTENTION', 'REFERRAL'])
            ->count();

        return [
            'screened' => $done,
            'needs_follow_up' => $flagged,
            'referrals' => ClinicScreening::query()->where('program_id', $program->id)->where('result', 'REFERRAL')->count(),
        ];
    }
}
