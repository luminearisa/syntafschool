<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Domain\Enums\AdministrationStatus;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Models\ClinicMedication;
use App\Modules\Clinic\Models\ClinicMedicationAdministration;
use App\Modules\Clinic\Models\ClinicPrescriptionItem;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Medication Administration Record (MAR).
 *
 * Setiap pemberian obat menyimpan **siapa** dan **kapan** (Rule 14).
 * Koreksi tidak menghapus baris lama: versi sebelumnya ditandai
 * `is_current = false` dan versi baru merujuk padanya, sehingga riwayat
 * pemberian obat tidak dapat dipalsukan diam-diam.
 */
class MedicationAdministrationService
{
    public function __construct(private readonly ClinicAuditService $audit)
    {
    }

    /**
     * Catat pemberian obat.
     *
     * @param array<string, mixed> $data
     */
    public function administer(ClinicVisit $visit, array $data, User $actor): ClinicMedicationAdministration
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages([
                'visit' => 'Pemberian obat tidak dapat dicatat pada kunjungan yang sudah ditutup.',
            ]);
        }

        return DB::transaction(function () use ($visit, $data, $actor): ClinicMedicationAdministration {
            $item = null;

            if (! empty($data['prescription_item_id'])) {
                $item = ClinicPrescriptionItem::query()->find((int) $data['prescription_item_id']);

                if ($item !== null && (int) $item->prescription?->visit_id !== (int) $visit->id) {
                    throw ValidationException::withMessages([
                        'prescription_item_id' => 'Baris resep tidak berasal dari kunjungan ini.',
                    ]);
                }
            }

            $medication = null;
            if (! empty($data['medication_id'])) {
                $medication = ClinicMedication::query()->find((int) $data['medication_id']);
            }

            $administration = ClinicMedicationAdministration::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'prescription_item_id' => $item?->id,
                'medication_id' => $medication?->id ?? $item?->medication_id,
                'medication_name' => $data['medication_name'] ?? $item?->medication_name ?? $medication?->name ?? 'Obat',
                'dose' => $data['dose'] ?? $item?->dose,
                'route' => $data['route'] ?? $item?->route ?? 'ORAL',
                'administered_at' => $data['administered_at'] ?? now(),
                'administered_by' => $actor->id,
                'status' => $data['status'] ?? AdministrationStatus::GIVEN->value,
                'notes' => $data['notes'] ?? null,
                'version' => 1,
                'is_current' => true,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::ADMINISTER, $administration, (int) $visit->patient_id, $actor, [
                'medication_name' => $administration->medication_name,
                'status' => $administration->status,
                'administered_at' => $administration->administered_at?->toIso8601String(),
            ], [], 'Pemberian obat dicatat');

            return $administration;
        });
    }

    /**
     * Koreksi catatan pemberian obat — non-destruktif.
     *
     * @param array<string, mixed> $data
     */
    public function correct(
        ClinicMedicationAdministration $administration,
        array $data,
        string $reason,
        User $actor,
    ): ClinicMedicationAdministration {
        return DB::transaction(function () use ($administration, $data, $reason, $actor): ClinicMedicationAdministration {
            $administration->is_current = false;
            $administration->save();

            $corrected = ClinicMedicationAdministration::query()->create([
                'visit_id' => $administration->visit_id,
                'patient_id' => $administration->patient_id,
                'prescription_item_id' => $administration->prescription_item_id,
                'medication_id' => $administration->medication_id,
                'medication_name' => $data['medication_name'] ?? $administration->medication_name,
                'dose' => $data['dose'] ?? $administration->dose,
                'route' => $data['route'] ?? $administration->route,
                'administered_at' => $data['administered_at'] ?? $administration->administered_at,
                'administered_by' => $data['administered_by'] ?? $administration->administered_by,
                'status' => $data['status'] ?? $administration->status,
                'notes' => $data['notes'] ?? $administration->notes,
                'version' => ((int) $administration->version) + 1,
                'is_current' => true,
                'corrected_from_id' => $administration->id,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->amend(
                $corrected,
                (int) $corrected->version,
                $reason,
                $administration->only(['medication_name', 'dose', 'status', 'administered_at', 'notes']),
                $corrected->only(['medication_name', 'dose', 'status', 'administered_at', 'notes']),
                (int) $administration->patient_id,
                $actor,
            );

            return $corrected;
        });
    }
}
