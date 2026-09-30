<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Contracts\ParentProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\Priority;
use App\Modules\Clinic\Domain\Enums\QueueStage;
use App\Modules\Clinic\Domain\Enums\VisitStatus;
use App\Modules\Clinic\Models\ClinicEmergency;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur kegawatdaruratan.
 *
 * Gawat darurat **melewati** antrean biasa (Rule 11): kunjungan langsung
 * berstatus dalam penanganan, prioritas DARURAT, dan tahap antreannya
 * pemeriksaan. Yang dijaga kelas ini:
 *
 * - penanganan segera tercatat sebelum administrasi menyusul;
 * - kontak orang tua tidak boleh terlupa — statusnya terlihat di laporan;
 * - rujukan & transportasi dicatat sebagai bagian dari alur, bukan catatan bebas.
 */
class EmergencyService
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly ClinicAuditService $audit,
        private readonly ClinicNotificationService $notifications,
        private readonly ParentProviderInterface $parents,
    ) {
    }

    /**
     * Buka peristiwa gawat darurat pada sebuah kunjungan.
     *
     * @param array<string, mixed> $data
     */
    public function open(ClinicVisit $visit, array $data, User $actor): ClinicEmergency
    {
        return DB::transaction(function () use ($visit, $data, $actor): ClinicEmergency {
            $emergency = ClinicEmergency::query()->create([
                'incident_number' => ClinicNumber::incident($this->nextSequence()),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'clinic_id' => $visit->clinic_id,
                'severity' => $data['severity'] ?? Priority::EMERGENCY->value,
                'incident_at' => $data['incident_at'] ?? $visit->visit_at ?? now(),
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? $visit->chief_complaint ?? 'Kegawatdaruratan',
                'action_taken' => $data['action_taken'] ?? null,
                'stabilization' => $data['stabilization'] ?? null,
                'parent_contacted' => (bool) ($data['parent_contacted'] ?? false),
                'parent_contacted_at' => ($data['parent_contacted'] ?? false) ? now() : null,
                'contacted_guardian_id' => $data['contacted_guardian_id'] ?? null,
                'referral_required' => (bool) ($data['referral_required'] ?? false),
                'ambulance_required' => (bool) ($data['ambulance_required'] ?? false),
                'hospital' => $data['hospital'] ?? null,
                'transport_method' => $data['transport_method'] ?? null,
                'status' => 'OPEN',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            // Gawat darurat tidak menunggu antrean: prioritas & tahap langsung naik.
            $visit->is_emergency = true;
            $visit->priority = Priority::EMERGENCY->value;
            $visit->visit_type = 'EMERGENCY';
            $visit->updated_by = $actor->id;
            $visit->save();

            if ($visit->status === VisitStatus::REGISTERED->value) {
                $this->visits->advanceStatus($visit, VisitStatus::IN_PROGRESS, $actor);
            }

            $this->visits->moveQueue($visit, QueueStage::IN_EXAMINATION, $actor);

            $this->audit->record(AuditAction::CREATE, $emergency, (int) $visit->patient_id, $actor, [
                'incident_number' => $emergency->incident_number,
                'severity' => $emergency->severity,
                'ambulance_required' => $emergency->ambulance_required,
            ], [], 'Peristiwa gawat darurat dibuka');

            // Orang tua diberi kabar segera — isinya mengikuti kebijakan privasi.
            $this->notifications->notifyEmergency($visit->fresh(), $emergency, $actor);

            return $emergency;
        });
    }

    /**
     * Perbarui penanganan / kontak orang tua / rujukan.
     *
     * @param array<string, mixed> $data
     */
    public function update(ClinicEmergency $emergency, array $data, User $actor): ClinicEmergency
    {
        $before = $emergency->only([
            'severity', 'action_taken', 'stabilization', 'parent_contacted',
            'referral_required', 'ambulance_required', 'hospital', 'transport_method', 'status',
        ]);

        $emergency->fill(array_intersect_key($data, array_flip([
            'severity', 'action_taken', 'stabilization', 'referral_required', 'ambulance_required',
            'hospital', 'transport_method', 'outcome', 'contacted_guardian_id',
        ])));

        if (array_key_exists('parent_contacted', $data) && (bool) $data['parent_contacted'] && ! $emergency->parent_contacted) {
            $emergency->parent_contacted = true;
            $emergency->parent_contacted_at = now();
        }

        if (array_key_exists('status', $data)) {
            $emergency->status = $data['status'];

            if (in_array($data['status'], ['CLOSED'], true)) {
                $emergency->closed_at = now();
            }
        }

        $emergency->updated_by = $actor->id;
        $emergency->save();

        $this->audit->record(AuditAction::UPDATE, $emergency, (int) $emergency->patient_id, $actor, [
            'before' => $before,
            'after' => $emergency->only(array_keys($before)),
        ], [], 'Pembaruan penanganan gawat darurat');

        return $emergency->fresh();
    }

    /** Tutup peristiwa dengan hasil akhir. */
    public function close(ClinicEmergency $emergency, string $outcome, User $actor): ClinicEmergency
    {
        if (trim($outcome) === '') {
            throw ValidationException::withMessages(['outcome' => 'Hasil akhir wajib diisi sebelum menutup peristiwa.']);
        }

        $emergency->status = 'CLOSED';
        $emergency->closed_at = now();
        $emergency->outcome = $outcome;
        $emergency->updated_by = $actor->id;
        $emergency->save();

        $this->audit->record(AuditAction::UPDATE, $emergency, (int) $emergency->patient_id, $actor, [
            'status' => 'CLOSED',
            'outcome' => $outcome,
        ], [], 'Peristiwa gawat darurat ditutup');

        return $emergency->fresh();
    }

    /** Kontak wali yang tersedia untuk dipilih petugas saat darurat. */
    public function contactOptions(ClinicEmergency $emergency): array
    {
        return $this->parents->guardiansForStudent((int) $emergency->patient->student_id);
    }

    private function nextSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_emergencies', 'incident_at', now()->toDateString());

        while (ClinicEmergency::withTrashed()->where('incident_number', ClinicNumber::incident($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
