<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\DispensingService;
use App\Modules\Clinic\Application\Services\MedicationAdministrationService;
use App\Modules\Clinic\Application\Services\PrescriptionService;
use App\Modules\Clinic\Application\Services\PatientService;
use App\Modules\Clinic\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Clinic\Domain\Enums\AdministrationStatus;
use App\Modules\Clinic\Domain\Enums\DosageForm;
use App\Modules\Clinic\Domain\Enums\MedicationRoute;
use App\Modules\Clinic\Domain\Enums\PrescriptionStatus;
use App\Modules\Clinic\Models\ClinicMedication;
use App\Modules\Clinic\Models\ClinicMedicationAdministration;
use App\Modules\Clinic\Models\ClinicMedicationBatch;
use App\Modules\Clinic\Models\ClinicPrescription;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Farmasi klinik: master obat, batch/kedaluwarsa, resep, penyerahan, pemberian.
 *
 * Perhatikan yang **tidak** ada di sini: tidak ada endpoint untuk mengubah
 * saldo stok. Saldo adalah milik Inventory; endpoint `dispense` yang memanggil
 * `InventoryProviderInterface` di dalam transaksi, dan mengembalikan status
 * hasil (SUCCESS / INSUFFICIENT_STOCK / EXPIRED / NOT_AVAILABLE) agar petugas
 * tahu persis apa yang terjadi.
 */
class ClinicPharmacyController extends BaseApiController
{
    public function __construct(
        private readonly PrescriptionService $prescriptions,
        private readonly DispensingService $dispensing,
        private readonly MedicationAdministrationService $administrations,
        private readonly PatientService $patients,
        private readonly ClinicAccessService $access,
        private readonly InventoryProviderInterface $inventory,
    ) {
    }

    /** Master obat + informasi stok dari Inventory. */
    public function medications(Request $request): JsonResponse
    {
        $query = ClinicMedication::query();

        $query
            ->when($request->query('search'), function ($q, $term): void {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $like)
                    ->orWhere('generic_name', 'like', $like)
                    ->orWhere('code', 'like', $like));
            })
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('form'), fn ($q, $form) => $q->where('dosage_form', $form))
            ->orderBy('name');

        $rows = $query->limit(min(200, max(1, (int) $request->query('limit', 100))))->get();

        return $this->successResponse($rows->map(fn (ClinicMedication $medication): array => [
            'id' => $medication->id,
            'code' => $medication->code,
            'name' => $medication->name,
            'generic_name' => $medication->generic_name,
            'dosage_form' => $medication->dosage_form,
            'strength' => $medication->strength,
            'unit' => $medication->unit,
            'route' => $medication->route,
            'category' => $medication->category,
            'requires_prescription' => (bool) $medication->requires_prescription,
            'is_controlled' => (bool) $medication->is_controlled,
            'status' => $medication->status,
            'inventory_item_id' => $medication->inventory_item_id,
            // Saldo berikut hanya pembacaan dari Inventory, bukan milik Clinic.
            'stock_available' => $medication->isLinkedToInventory()
                ? $this->inventory->availableQuantity($medication->inventory_item_id)
                : null,
            'stock_source' => $medication->isLinkedToInventory() ? 'inventory' : 'unlinked',
        ]));
    }

    /** Buat master obat (dapat dipetakan ke item Inventory). */
    public function storeMedication(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:clinic_medications,code'],
            'name' => ['required', 'string', 'max:160'],
            'generic_name' => ['nullable', 'string', 'max:160'],
            'brand_name' => ['nullable', 'string', 'max:160'],
            'dosage_form' => ['nullable', Rule::in(DosageForm::values())],
            'strength' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:20'],
            'route' => ['nullable', Rule::in(MedicationRoute::values())],
            'category' => ['nullable', 'string', 'max:60'],
            'requires_prescription' => ['nullable', 'boolean'],
            'is_controlled' => ['nullable', 'boolean'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $medication = ClinicMedication::query()->create($validated + [
            'code' => strtoupper($validated['code']),
            'status' => 'ACTIVE',
            'created_by' => $request->user()->id,
        ]);

        return $this->createdResponse($medication, 'Master obat dibuat');
    }

    /** Perbarui master obat. */
    public function updateMedication(Request $request, ClinicMedication $medication): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'generic_name' => ['nullable', 'string', 'max:160'],
            'brand_name' => ['nullable', 'string', 'max:160'],
            'dosage_form' => ['nullable', Rule::in(DosageForm::values())],
            'strength' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:20'],
            'route' => ['nullable', Rule::in(MedicationRoute::values())],
            'category' => ['nullable', 'string', 'max:60'],
            'requires_prescription' => ['nullable', 'boolean'],
            'is_controlled' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'DISCONTINUED'])],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $medication->fill($validated)->save();

        return $this->successResponse($medication->fresh(), 'Master obat diperbarui');
    }

    /** Daftar batch obat + status kedaluwarsa. */
    public function batches(Request $request, ClinicMedication $medication): JsonResponse
    {
        return $this->successResponse(
            $medication->batches()->orderBy('expiry_date')->get()->map(fn (ClinicMedicationBatch $batch): array => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'status' => $batch->status,
                'is_expired' => $batch->isExpired(),
                'is_expiring_soon' => $batch->isExpiringSoon((int) config('clinic.expiry_warning_days', 60)),
                'quantity_received' => (float) $batch->quantity_received,
                'source' => $batch->source,
            ]),
        );
    }

    /** Catat batch obat yang diterima (dasar FEFO & peringatan kedaluwarsa). */
    public function storeBatch(Request $request, ClinicMedication $medication): JsonResponse
    {
        $validated = $request->validate([
            'batch_number' => ['required', 'string', 'max:60'],
            'manufacture_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:manufacture_date'],
            'quantity_received' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $batch = ClinicMedicationBatch::query()->updateOrCreate(
            ['medication_id' => $medication->id, 'batch_number' => $validated['batch_number']],
            $validated + [
                'inventory_item_id' => $medication->inventory_item_id,
                'status' => 'AVAILABLE',
            ],
        );

        return $this->createdResponse($batch, 'Batch obat dicatat');
    }

    /** Daftar resep. */
    public function prescriptions(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->access->scopePatients(
            ClinicPrescription::query()->with([
                'items:id,prescription_id,medication_name,quantity,dispensed_quantity,unit',
                'patient.student:id,nis,full_name',
                'provider:id,name',
            ]),
            $user,
        );

        $query
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('visit_id'), fn ($q, $visitId) => $q->where('visit_id', (int) $visitId))
            ->when($request->boolean('pending_only'), fn ($q) => $q->whereIn('status', ['ISSUED', 'PARTIALLY_DISPENSED']))
            ->orderByDesc('prescribed_at');

        return $this->successResponse($query->limit(200)->get());
    }

    /**
     * Terbitkan resep pada sebuah kunjungan.
     *
     * Bila ada potensi benturan alergi, permintaan tanpa override ditolak dengan
     * daftar peringatannya — supaya keputusan melanjutkan tetap sadar, bukan
     * karena peringatan tidak terlihat.
     */
    public function storePrescription(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.medication_id' => ['nullable', 'integer', 'exists:clinic_medications,id'],
            'items.*.medication_name' => ['nullable', 'string', 'max:160'],
            'items.*.dose' => ['nullable', 'string', 'max:60'],
            'items.*.frequency' => ['nullable', 'string', 'max:60'],
            'items.*.duration' => ['nullable', 'string', 'max:60'],
            'items.*.route' => ['nullable', Rule::in(MedicationRoute::values())],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.instruction' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allergy_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->prescriptions->create($visit, $validated, $request->user());

        return $this->createdResponse([
            'prescription' => $result['prescription'],
            'allergy_warnings' => $result['allergy_warnings'],
        ], 'Resep '.$result['prescription']->prescription_number.' diterbitkan');
    }

    public function showPrescription(Request $request, ClinicPrescription $prescription): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $prescription->patient_id);

        return $this->successResponse($prescription->load([
            'items.medication:id,name,unit,dosage_form,strength',
            'dispensings.items',
            'patient.student:id,nis,full_name',
            'provider:id,name',
        ]));
    }

    public function cancelPrescription(Request $request, ClinicPrescription $prescription): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $prescription->patient_id);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->successResponse(
            $this->prescriptions->cancel($prescription, $validated['reason'], $request->user()),
            'Resep dibatalkan',
        );
    }

    /**
     * Serahkan obat: satu-satunya jalur yang menyentuh stok, dan hanya lewat
     * Inventory. Idempotent terhadap pengiriman ulang.
     */
    public function dispense(Request $request, ClinicPrescription $prescription): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $prescription->patient_id);

        if (! $this->access->canDispense($request->user())) {
            return $this->errorResponse('Anda tidak berwenang menyerahkan obat.', 403);
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.prescription_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.1'],
            'items.*.batch_id' => ['nullable', 'integer', 'exists:clinic_medication_batches,id'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->dispensing->dispense($prescription, $validated, $request->user());

        return $this->createdResponse([
            'dispensing' => $result['dispensing'],
            'results' => $result['results'],
            'prescription_status' => $result['prescription_status'],
            'note' => 'Stok hanya berubah bila Inventory mengembalikan SUCCESS.',
        ], 'Penyerahan obat '.$result['dispensing']->dispensing_number.' diproses');
    }

    /**
     * Beri obat langsung ke pasien (obat bebas / tanpa resep).
     * Pemakaian obat tetap dicatat meski tidak ada resep.
     */
    public function administer(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'prescription_item_id' => ['nullable', 'integer', 'exists:clinic_prescription_items,id'],
            'medication_id' => ['nullable', 'integer', 'exists:clinic_medications,id'],
            'medication_name' => ['nullable', 'string', 'max:160', 'required_without_all:medication_id,prescription_item_id'],
            'dose' => ['nullable', 'string', 'max:60'],
            'route' => ['nullable', Rule::in(MedicationRoute::values())],
            'status' => ['nullable', Rule::in(AdministrationStatus::values())],
            'administered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $administration = $this->administrations->administer($visit, $validated, $request->user());

        return $this->createdResponse($administration, 'Pemberian obat dicatat');
    }

    /** Koreksi catatan pemberian obat (non-destruktif). */
    public function correctAdministration(Request $request, ClinicMedicationAdministration $administration): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $administration->patient_id);

        $validated = $request->validate([
            'medication_name' => ['nullable', 'string', 'max:160'],
            'dose' => ['nullable', 'string', 'max:60'],
            'route' => ['nullable', Rule::in(MedicationRoute::values())],
            'status' => ['nullable', Rule::in(AdministrationStatus::values())],
            'administered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $reason = $validated['reason'];
        unset($validated['reason']);

        $corrected = $this->administrations->correct($administration, $validated, $reason, $request->user());

        return $this->successResponse($corrected, 'Catatan pemberian obat dikoreksi menjadi versi '.$corrected->version);
    }

    /** Peringatan alergi untuk calon resep (pratinjau sebelum menyimpan). */
    public function allergyCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:clinic_patients,id'],
            'medication_ids' => ['required', 'array', 'max:20'],
            'medication_ids.*' => ['integer'],
        ]);

        $patient = \App\Modules\Clinic\Models\ClinicPatient::query()->findOrFail($validated['patient_id']);
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $names = ClinicMedication::query()
            ->whereIn('id', $validated['medication_ids'])
            ->pluck('name')
            ->all();

        $warnings = $this->patients->allergyWarnings($patient, array_values($validated['medication_ids']), $names);

        return $this->successResponse([
            'warnings' => $warnings,
            'has_critical' => collect($warnings)->contains(fn (array $w): bool => (bool) ($w['is_critical'] ?? false)),
            'note' => 'Peringatan ini tidak menggantikan pertimbangan klinis.',
        ]);
    }

    /** Antrean resep yang belum seluruhnya diserahkan. */
    public function pending(Request $request): JsonResponse
    {
        $rows = $this->access->scopePatients(ClinicPrescription::query(), $request->user())
            ->with(['patient.student:id,nis,full_name', 'items'])
            ->whereIn('status', [PrescriptionStatus::ISSUED->value, PrescriptionStatus::PARTIALLY_DISPENSED->value])
            ->orderBy('prescribed_at')
            ->limit(100)
            ->get();

        return $this->successResponse($rows);
    }
}
