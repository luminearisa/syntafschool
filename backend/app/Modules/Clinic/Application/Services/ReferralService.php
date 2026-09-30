<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\ReferralStatus;
use App\Modules\Clinic\Domain\Enums\VisitStatus;
use App\Modules\Clinic\Models\ClinicReferral;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rujukan ke fasilitas kesehatan eksternal.
 *
 * Rujukan wajib punya **tujuan dan alasan** (Rule 10) — rujukan tanpa alasan
 * tidak dapat ditindaklanjuti rumah sakit dan menyulitkan pertanggungjawaban.
 * Status rujukan bergerak maju dan setiap perubahan dicatat, termasuk saat
 * pasien tiba di fasilitas tujuan dan saat hasilnya diketahui.
 *
 * Catatan kejujuran integrasi: modul ini **tidak** mengklaim terhubung ke
 * sistem rumah sakit. Tidak ada API eksternal yang dipanggil; semua status
 * diisi oleh petugas.
 */
class ReferralService
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly ClinicalRecordService $clinical,
        private readonly ClinicAuditService $audit,
        private readonly ClinicNotificationService $notifications,
    ) {
    }

    /**
     * Buat rujukan.
     *
     * @param array<string, mixed> $data
     */
    public function create(ClinicVisit $visit, array $data, User $actor): ClinicReferral
    {
        $facility = trim((string) ($data['facility'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($facility === '') {
            throw ValidationException::withMessages(['facility' => 'Fasilitas tujuan rujukan wajib diisi.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan rujukan wajib diisi.']);
        }

        return DB::transaction(function () use ($visit, $data, $actor, $facility, $reason): ClinicReferral {
            $referral = ClinicReferral::query()->create([
                'referral_number' => ClinicNumber::referral($this->nextSequence()),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'emergency_id' => $data['emergency_id'] ?? null,
                'provider_id' => $data['provider_id'] ?? $actor->id,
                'facility' => $facility,
                'facility_type' => $data['facility_type'] ?? null,
                'reason' => $reason,
                'diagnosis_summary' => $data['diagnosis_summary'] ?? null,
                'urgency' => $data['urgency'] ?? 'ROUTINE',
                'referral_date' => $data['referral_date'] ?? now(),
                'transport' => $data['transport'] ?? null,
                'status' => ReferralStatus::REFERRED->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            // Kunjungan berpindah ke status dirujuk (bukan ditutup: masih menunggu hasil).
            if ($visit->isOpen() && $visit->status !== VisitStatus::REFERRED->value) {
                $this->visits->advanceStatus($visit, VisitStatus::REFERRED, $actor);
            }

            $this->audit->record(AuditAction::CREATE, $referral, (int) $visit->patient_id, $actor, [
                'referral_number' => $referral->referral_number,
                'facility' => $referral->facility,
                'urgency' => $referral->urgency,
            ], [], 'Rujukan dibuat');

            $this->notifications->notifyReferral($visit->fresh(), $referral, $actor);

            return $referral;
        });
    }

    /**
     * Perbarui status perjalanan rujukan.
     *
     * @param array<string, mixed> $data
     */
    public function updateStatus(ClinicReferral $referral, array $data, User $actor): ClinicReferral
    {
        $target = ReferralStatus::from($data['status'] ?? $referral->status);

        if ($target === ReferralStatus::COMPLETED && blank($data['outcome'] ?? $referral->outcome)) {
            throw ValidationException::withMessages([
                'outcome' => 'Hasil rujukan wajib diisi sebelum menandai selesai.',
            ]);
        }

        $before = $referral->status;

        $referral->status = $target->value;
        $referral->transport = $data['transport'] ?? $referral->transport;
        $referral->notes = $data['notes'] ?? $referral->notes;
        $referral->outcome = $data['outcome'] ?? $referral->outcome;

        if ($target === ReferralStatus::ARRIVED) {
            $referral->arrived_at = $data['arrived_at'] ?? now();
        }

        if ($target === ReferralStatus::COMPLETED) {
            $referral->completed_at = now();
        }

        $referral->updated_by = $actor->id;
        $referral->save();

        // Rujukan selesai → buat tindak lanjut agar hasil rumah sakit tidak hilang.
        if ($target === ReferralStatus::COMPLETED && $referral->visit_id !== null) {
            $this->clinical->createFollowUp([
                'patient_id' => $referral->patient_id,
                'visit_id' => $referral->visit_id,
                'referral_id' => $referral->id,
                'due_date' => $data['follow_up_date'] ?? now()->addDays(7)->toDateString(),
                'type' => 'RESULT_REVIEW',
                'instruction' => 'Tindak lanjut hasil rujukan '.$referral->referral_number,
            ], $actor);
        }

        $this->audit->record(AuditAction::UPDATE, $referral, (int) $referral->patient_id, $actor, [
            'status_before' => $before,
            'status_after' => $target->value,
        ], [], 'Status rujukan diperbarui');

        return $referral->fresh();
    }

    private function nextSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_referrals', 'referral_date', now()->toDateString());

        while (ClinicReferral::withTrashed()->where('referral_number', ClinicNumber::referral($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
