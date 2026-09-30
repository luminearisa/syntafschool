<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Clinic\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\DischargeStatus;
use App\Modules\Clinic\Domain\Enums\VisitStatus;
use App\Modules\Clinic\Models\ClinicDischarge;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemulangan pasien.
 *
 * Kunjungan tidak pernah dianggap selesai tanpa pemulangan eksplisit
 * (Rule 12). Pemulangan menentukan apa yang terjadi setelahnya:
 *
 * - kembali ke kelas → presensi & wali kelas perlu tahu
 * - kembali ke asrama → saran istirahat untuk petugas asrama
 * - dipulangkan / dirujuk / rawat inap → orang tua wajib diberi kabar
 *
 * Efek samping di modul lain **tidak** dijalankan langsung: presensi mengikuti
 * kebijakan sekolah, dan status asrama tetap milik modul Boarding.
 */
class DischargeService
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly ClinicalRecordService $clinical,
        private readonly ClinicAuditService $audit,
        private readonly AttendanceProviderInterface $attendance,
        private readonly BoardingProviderInterface $boarding,
        private readonly ClinicNotificationService $notifications,
    ) {
    }

    /**
     * Pulangkan pasien.
     *
     * @param array<string, mixed> $data
     * @return array{discharge: ClinicDischarge, attendance: array<string, mixed>, boarding: array<string, mixed>|null}
     */
    public function discharge(ClinicVisit $visit, array $data, User $actor): array
    {
        $this->visits->assertWritable($visit);

        if (ClinicDischarge::query()->where('visit_id', $visit->id)->exists()) {
            throw ValidationException::withMessages([
                'visit' => 'Kunjungan ini sudah memiliki catatan pemulangan.',
            ]);
        }

        $status = DischargeStatus::from($data['status'] ?? DischargeStatus::RETURN_TO_CLASS->value);

        return DB::transaction(function () use ($visit, $data, $actor, $status): array {
            $discharge = ClinicDischarge::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'discharge_time' => $data['discharge_time'] ?? now(),
                'status' => $status->value,
                'condition' => $data['condition'] ?? 'STABLE',
                'instructions' => $data['instructions'] ?? null,
                'follow_up_required' => (bool) ($data['follow_up_required'] ?? false),
                'follow_up_date' => $data['follow_up_date'] ?? null,
                'return_to_class' => $status === DischargeStatus::RETURN_TO_CLASS,
                'return_to_dorm' => $status === DischargeStatus::RETURN_TO_DORM,
                'sent_home' => $status === DischargeStatus::HOME,
                'referred' => $status === DischargeStatus::REFERRED,
                'parent_notified' => false,
                'discharged_by' => $actor->id,
                'version' => 1,
                'is_current' => true,
            ]);

            if ($discharge->follow_up_required && $discharge->follow_up_date !== null) {
                $this->clinical->createFollowUp([
                    'patient_id' => $visit->patient_id,
                    'visit_id' => $visit->id,
                    'due_date' => $discharge->follow_up_date,
                    'type' => 'REVIEW',
                    'instruction' => $discharge->instructions,
                ], $actor);
            }

            $this->visits->close($visit, VisitStatus::DISCHARGED, $actor);

            // Presensi hanya berubah bila kebijakan sekolah mengizinkan.
            $attendance = ['status' => AttendanceProviderInterface::SKIPPED_NOT_STUDENT, 'attendance_id' => null, 'message' => 'Pasien bukan siswa.'];
            if ($visit->student_id !== null) {
                $attendance = $this->attendance->markMedicalVisit(
                    (int) $visit->student_id,
                    $discharge->discharge_time->toDateString(),
                    $status->label(),
                    $actor->id,
                );
            }

            // Saran asrama bersifat saran; status asrama tetap milik modul Boarding.
            $boarding = $visit->student_id === null
                ? null
                : $this->boarding->recommendRoomRest((int) $visit->student_id, $status->label());

            $this->audit->record(AuditAction::CREATE, $discharge, (int) $visit->patient_id, $actor, [
                'status' => $status->value,
                'condition' => $discharge->condition,
                'follow_up_required' => $discharge->follow_up_required,
            ], [], 'Pemulangan pasien dicatat');

            // Pemberitahuan mengikuti kebijakan privasi, bukan seluruh isi rekam medis.
            $notified = $this->notifications->notifyDischarge($visit->fresh(), $discharge, $actor);
            $discharge->parent_notified = $notified['sent'] > 0;
            $discharge->save();

            return [
                'discharge' => $discharge->fresh(),
                'attendance' => $attendance,
                'boarding' => $boarding,
            ];
        });
    }

    /**
     * Koreksi pemulangan: versi baru dibuat, versi lama tetap tersimpan.
     *
     * @param array<string, mixed> $data
     */
    public function amend(ClinicDischarge $discharge, array $data, string $reason, User $actor): ClinicDischarge
    {
        return DB::transaction(function () use ($discharge, $data, $reason, $actor): ClinicDischarge {
            $discharge->is_current = false;
            $discharge->save();

            $new = ClinicDischarge::query()->create([
                'visit_id' => $discharge->visit_id,
                'patient_id' => $discharge->patient_id,
                'discharge_time' => $data['discharge_time'] ?? $discharge->discharge_time,
                'status' => $data['status'] ?? $discharge->status,
                'condition' => $data['condition'] ?? $discharge->condition,
                'instructions' => $data['instructions'] ?? $discharge->instructions,
                'follow_up_required' => $data['follow_up_required'] ?? $discharge->follow_up_required,
                'follow_up_date' => $data['follow_up_date'] ?? $discharge->follow_up_date,
                'return_to_class' => ($data['status'] ?? $discharge->status) === DischargeStatus::RETURN_TO_CLASS->value,
                'return_to_dorm' => ($data['status'] ?? $discharge->status) === DischargeStatus::RETURN_TO_DORM->value,
                'sent_home' => ($data['status'] ?? $discharge->status) === DischargeStatus::HOME->value,
                'referred' => ($data['status'] ?? $discharge->status) === DischargeStatus::REFERRED->value,
                'parent_notified' => $discharge->parent_notified,
                'discharged_by' => $actor->id,
                'version' => ((int) $discharge->version) + 1,
                'is_current' => true,
                'amended_from_id' => $discharge->id,
            ]);

            $this->audit->amend(
                $new,
                (int) $new->version,
                $reason,
                $discharge->only(['status', 'condition', 'instructions', 'follow_up_date']),
                $new->only(['status', 'condition', 'instructions', 'follow_up_date']),
                (int) $discharge->patient_id,
                $actor,
            );

            return $new;
        });
    }
}
