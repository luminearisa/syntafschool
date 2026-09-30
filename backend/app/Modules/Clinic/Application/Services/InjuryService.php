<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Models\ClinicInjury;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cedera & kecelakaan.
 *
 * Mencatat apa yang terjadi (mekanisme, bagian tubuh, saksi) — bukan hanya
 * hasilnya. Data ini yang dipakai sekolah untuk memperbaiki keselamatan
 * (mis. lantai licin, olahraga, tangga asrama), sehingga lokasi dan aktivitas
 * menjadi kolom wajib yang bermakna.
 */
class InjuryService
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly ClinicAuditService $audit,
        private readonly ClinicNotificationService $notifications,
    ) {
    }

    /**
     * Catat cedera. Bila belum ada kunjungan, satu kunjungan tipe INJURY
     * dibuatkan otomatis agar cedera tidak pernah tercatat tanpa layanan.
     *
     * @param array<string, mixed> $data
     */
    public function record(ClinicPatient $patient, array $data, User $actor): ClinicInjury
    {
        return DB::transaction(function () use ($patient, $data, $actor): ClinicInjury {
            $visitId = $data['visit_id'] ?? null;

            if ($visitId === null && (bool) ($data['create_visit'] ?? true)) {
                $visit = $this->visits->register([
                    'patient_id' => $patient->id,
                    'visit_type' => 'INJURY',
                    'source' => $data['source'] ?? 'TEACHER',
                    'chief_complaint' => $data['injury_type'] ?? 'Cedera',
                    'visit_at' => $data['incident_at'] ?? now(),
                ], $actor);

                $visitId = $visit->id;
            }

            $injury = ClinicInjury::query()->create([
                'incident_number' => ClinicNumber::incident($this->nextSequence()),
                'patient_id' => $patient->id,
                'visit_id' => $visitId,
                'emergency_id' => $data['emergency_id'] ?? null,
                'incident_at' => $data['incident_at'] ?? now(),
                'location' => $data['location'] ?? null,
                'activity' => $data['activity'] ?? null,
                'witnesses' => $data['witnesses'] ?? null,
                'injury_type' => $data['injury_type'] ?? null,
                'body_part' => $data['body_part'] ?? null,
                'mechanism' => $data['mechanism'] ?? null,
                'severity' => $data['severity'] ?? 'MINOR',
                'treatment' => $data['treatment'] ?? null,
                'referral_required' => (bool) ($data['referral_required'] ?? false),
                'parent_notified' => false,
                'status' => 'OPEN',
                'reported_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::CREATE, $injury, (int) $patient->id, $actor, [
                'incident_number' => $injury->incident_number,
                'injury_type' => $injury->injury_type,
                'severity' => $injury->severity,
                'location' => $injury->location,
            ], [], 'Cedera/kecelakaan dicatat');

            // Cedera sedang/berat atau yang memerlukan rujukan wajib dikabarkan.
            if (in_array($injury->severity, ['MODERATE', 'SEVERE', 'CRITICAL'], true) || $injury->referral_required) {
                $notified = $this->notifications->notifyInjury($patient, $injury, $actor);
                $injury->parent_notified = $notified['sent'] > 0;
                $injury->save();
            }

            return $injury;
        });
    }

    /**
     * Perbarui penanganan cedera.
     *
     * @param array<string, mixed> $data
     */
    public function update(ClinicInjury $injury, array $data, User $actor): ClinicInjury
    {
        $before = $injury->only(['treatment', 'severity', 'status', 'referral_required', 'follow_up']);

        $injury->fill(array_intersect_key($data, array_flip([
            'injury_type', 'body_part', 'mechanism', 'severity', 'treatment',
            'referral_required', 'status', 'follow_up', 'witnesses', 'location', 'activity',
        ])));
        $injury->save();

        $this->audit->record(AuditAction::UPDATE, $injury, (int) $injury->patient_id, $actor, [
            'before' => $before,
            'after' => $injury->only(array_keys($before)),
        ], [], 'Pembaruan penanganan cedera');

        return $injury->fresh();
    }

    public function close(ClinicInjury $injury, User $actor, ?string $followUp = null): ClinicInjury
    {
        if (trim((string) $injury->treatment) === '' && blank($followUp)) {
            throw ValidationException::withMessages([
                'treatment' => 'Tindakan atau catatan tindak lanjut wajib ada sebelum cedera ditutup.',
            ]);
        }

        $injury->status = 'CLOSED';
        if ($followUp !== null) {
            $injury->follow_up = $followUp;
        }
        $injury->save();

        $this->audit->record(AuditAction::UPDATE, $injury, (int) $injury->patient_id, $actor, [
            'status' => 'CLOSED',
        ], [], 'Cedera ditutup');

        return $injury->fresh();
    }

    private function nextSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_injuries', 'incident_at', now()->toDateString());

        while (ClinicInjury::withTrashed()->where('incident_number', ClinicNumber::incident($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
