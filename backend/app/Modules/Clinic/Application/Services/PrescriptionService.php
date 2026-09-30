<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\PrescriptionStatus;
use App\Modules\Clinic\Models\ClinicMedication;
use App\Modules\Clinic\Models\ClinicPrescription;
use App\Modules\Clinic\Models\ClinicPrescriptionItem;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerbitan resep.
 *
 * Aturan yang ditegakkan:
 *
 * - **Hanya peran berwenang** (Rule 13). Dokter/perawat dengan kewenangan,
 *   diperiksa lewat `ClinicAccessService`, bukan dari klaim klien.
 * - **Peringatan alergi wajib muncul** saat resep dibuat (Rule 3). Bila ada
 *   benturan dan dokter tetap melanjutkan, alasan tertulis wajib diisi —
 *   keputusan klinis tetap milik dokter, tetapi jejaknya tersimpan.
 * - **Resep tidak boleh diterbitkan pada kunjungan yang sudah ditutup.**
 */
class PrescriptionService
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly ClinicAccessService $access,
        private readonly ClinicAuditService $audit,
    ) {
    }

    /**
     * Buat resep beserta baris-barisnya.
     *
     * @param array<string, mixed> $data
     * @return array{prescription: ClinicPrescription, allergy_warnings: list<array<string, mixed>>}
     */
    public function create(ClinicVisit $visit, array $data, User $actor): array
    {
        if (! $this->access->canPrescribe($actor)) {
            throw ValidationException::withMessages([
                'authorization' => 'Anda tidak berwenang menerbitkan resep.',
            ]);
        }

        if (! $visit->isOpen()) {
            throw ValidationException::withMessages([
                'visit' => 'Resep tidak dapat dibuat pada kunjungan yang sudah ditutup.',
            ]);
        }

        $items = array_values((array) ($data['items'] ?? []));

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Resep harus memiliki minimal satu obat.']);
        }

        $patient = $visit->patient;

        // Peringatan alergi dihitung dari data pasien + nama obat yang diminta.
        $medicationIds = array_map(fn (array $i): ?int => isset($i['medication_id']) ? (int) $i['medication_id'] : null, $items);
        $names = array_map(fn (array $i): ?string => $i['medication_name'] ?? null, $items);

        $warnings = $this->patients->allergyWarnings($patient, $medicationIds, array_values(array_filter($names)));

        $critical = array_values(array_filter($warnings, fn (array $w): bool => (bool) ($w['is_critical'] ?? false)));

        $override = (bool) ($data['allergy_override'] ?? false);
        $overrideReason = $data['override_reason'] ?? null;

        if ($warnings !== [] && ! $override) {
            throw ValidationException::withMessages([
                'allergy_override' => 'Terdapat potensi benturan alergi. Tandai override dan tuliskan alasan klinis untuk melanjutkan.',
            ]);
        }

        if ($override && $warnings !== [] && (bool) config('clinic.dispensing.require_override_reason', true) && blank($overrideReason)) {
            throw ValidationException::withMessages([
                'override_reason' => 'Alasan klinis wajib diisi ketika resep dilanjutkan meski ada peringatan alergi.',
            ]);
        }

        return DB::transaction(function () use ($visit, $data, $actor, $items, $warnings, $override, $overrideReason, $critical): array {
            $sequence = $this->nextSequence();

            $prescription = ClinicPrescription::query()->create([
                'prescription_number' => ClinicNumber::prescription($sequence),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'provider_id' => $data['provider_id'] ?? $actor->id,
                'prescribed_at' => now(),
                'status' => PrescriptionStatus::ISSUED->value,
                'notes' => $data['notes'] ?? null,
                'allergy_alerts' => $warnings === [] ? null : $warnings,
                'allergy_override' => $override,
                'override_reason' => $overrideReason,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($items as $item) {
                $medication = isset($item['medication_id'])
                    ? ClinicMedication::query()->find((int) $item['medication_id'])
                    : null;

                if ($medication !== null && $medication->requires_prescription === false && empty($item['medication_name'])) {
                    // Obat bebas tetap dicatat namanya supaya rekam jejak lengkap.
                    $item['medication_name'] = $medication->name;
                }

                ClinicPrescriptionItem::query()->create([
                    'prescription_id' => $prescription->id,
                    'medication_id' => $medication?->id,
                    'medication_name' => $item['medication_name'] ?? $medication?->name ?? 'Obat',
                    'dose' => $item['dose'] ?? null,
                    'frequency' => $item['frequency'] ?? null,
                    'duration' => $item['duration'] ?? null,
                    'route' => $item['route'] ?? $medication?->route ?? 'ORAL',
                    'quantity' => $item['quantity'] ?? 1,
                    'unit' => $item['unit'] ?? $medication?->unit,
                    'instruction' => $item['instruction'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $this->audit->record(AuditAction::CREATE, $prescription, (int) $visit->patient_id, $actor, [
                'prescription_number' => $prescription->prescription_number,
                'items' => count($items),
                'allergy_override' => $override,
                'allergy_warnings' => count($warnings),
                'critical_warnings' => count($critical),
            ], [], $override ? 'Resep diterbitkan dengan override alergi: '.$overrideReason : 'Resep diterbitkan');

            $prescription->load('items');

            return ['prescription' => $prescription, 'allergy_warnings' => $warnings];
        });
    }

    /** Batalkan resep yang belum seluruhnya diserahkan. */
    public function cancel(ClinicPrescription $prescription, string $reason, User $actor): ClinicPrescription
    {
        if ($prescription->status === PrescriptionStatus::DISPENSED->value) {
            throw ValidationException::withMessages([
                'status' => 'Resep yang sudah seluruhnya diserahkan tidak dapat dibatalkan.',
            ]);
        }

        if ($prescription->status === PrescriptionStatus::CANCELLED->value) {
            return $prescription;
        }

        $prescription->status = PrescriptionStatus::CANCELLED->value;
        $prescription->notes = trim(((string) $prescription->notes)."\nDibatalkan: ".$reason);
        $prescription->updated_by = $actor->id;
        $prescription->save();

        $this->audit->record(AuditAction::UPDATE, $prescription, (int) $prescription->patient_id, $actor, [
            'status' => 'CANCELLED',
            'reason' => $reason,
        ], [], 'Resep dibatalkan');

        return $prescription->fresh('items');
    }

    /**
     * Perbarui status resep berdasarkan jumlah yang sudah diserahkan.
     * Dipanggil layanan dispensing setelah setiap penyerahan.
     */
    public function refreshStatus(ClinicPrescription $prescription): ClinicPrescription
    {
        $items = $prescription->items()->get();

        if ($items->isEmpty()) {
            return $prescription;
        }

        $allDone = $items->every(fn (ClinicPrescriptionItem $item): bool => $item->isFullyDispensed());
        $anyDone = $items->contains(fn (ClinicPrescriptionItem $item): bool => (float) $item->dispensed_quantity > 0);

        if ($allDone) {
            $status = PrescriptionStatus::DISPENSED->value;
        } elseif ($anyDone) {
            $status = PrescriptionStatus::PARTIALLY_DISPENSED->value;
        } else {
            $status = PrescriptionStatus::ISSUED->value;
        }

        if ($prescription->status !== PrescriptionStatus::CANCELLED->value) {
            $prescription->status = $status;
            $prescription->save();
        }

        return $prescription->fresh('items');
    }

    private function nextSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_prescriptions', 'prescribed_at', now()->toDateString());

        while (ClinicPrescription::withTrashed()->where('prescription_number', ClinicNumber::prescription($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
