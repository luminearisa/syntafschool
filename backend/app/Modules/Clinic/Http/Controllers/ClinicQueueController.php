<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ClinicReportService;
use App\Modules\Clinic\Application\Services\VisitService;
use App\Modules\Clinic\Domain\Enums\QueueStage;
use App\Modules\Clinic\Models\ClinicQueueEntry;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Papan antrean & pemanggilan pasien.
 *
 * Antrean adalah alat kerja petugas UKS: ia menjawab "siapa berikutnya, dan
 * sudah berapa lama menunggu". Karena itu pengurutan mengikuti prioritas
 * klinis (gawat darurat lebih dahulu) baru waktu kedatangan — bukan sekadar
 * urutan pendaftaran.
 */
class ClinicQueueController extends BaseApiController
{
    public function __construct(
        private readonly ClinicReportService $reports,
        private readonly VisitService $visits,
        private readonly ClinicAccessService $access,
    ) {
    }

    /** Papan antrean hari ini. */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse([
            'date' => now()->toDateString(),
            'warning_minutes' => (int) config('clinic.queue.waiting_warning_minutes', 30),
            'rows' => $this->reports->queueBoard($request->user()),
        ]);
    }

    /** Pindahkan pasien ke tahap berikutnya. */
    public function move(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'stage' => ['required', Rule::in(QueueStage::values())],
        ]);

        $this->visits->moveQueue($visit, QueueStage::from($validated['stage']), $request->user());

        $entry = ClinicQueueEntry::query()->where('visit_id', $visit->id)->first();

        return $this->successResponse($entry, 'Tahap antrean diperbarui');
    }

    /** Panggil pasien berikutnya pada sebuah lokasi. */
    public function callNext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => ['nullable', 'integer', 'exists:clinic_clinics,id'],
        ]);

        $priorityOrder = "CASE priority WHEN 'EMERGENCY' THEN 0 WHEN 'URGENT' THEN 1 WHEN 'SEMI_URGENT' THEN 2 ELSE 3 END";

        $entry = ClinicQueueEntry::query()
            ->with(['visit.patient.student:id,nis,full_name', 'visit.triage'])
            ->whereDate('queue_date', now()->toDateString())
            ->whereIn('stage', [QueueStage::WAITING->value, QueueStage::WAITING_PROVIDER->value])
            ->when(! empty($validated['clinic_id']), fn ($q) => $q->where('clinic_id', (int) $validated['clinic_id']))
            ->join('clinic_visits', 'clinic_visits.id', '=', 'clinic_queue_entries.visit_id')
            ->orderByRaw($priorityOrder)
            ->orderBy('clinic_queue_entries.sequence')
            ->select('clinic_queue_entries.*')
            ->first();

        if ($entry === null) {
            return $this->successResponse(null, 'Tidak ada pasien dalam antrean');
        }

        $entry->called_at = now();
        $entry->assigned_to = $request->user()->id;
        $entry->save();

        return $this->successResponse([
            'queue_number' => $entry->queue_number,
            'visit_id' => $entry->visit_id,
            'visit_number' => $entry->visit?->visit_number,
            'patient_name' => $entry->visit?->patient?->student?->full_name,
            'priority' => $entry->visit?->priority,
            'called_at' => $entry->called_at?->toIso8601String(),
        ], 'Pasien dipanggil');
    }
}
