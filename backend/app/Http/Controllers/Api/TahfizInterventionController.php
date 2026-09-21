<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizInterventionRequest;
use App\Http\Resources\TahfizInterventionResource;
use App\Models\TahfizIntervention;
use App\Models\TahfizParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Intervention untuk peserta yang tertinggal dari target.
 *
 * Alur: usulan (proposed) → berlangsung (in_progress) → selesai (done),
 * atau dibatalkan. Jenis intervention adalah data — tambahan murojaah,
 * pengurangan target, sesi khusus, bimbingan individual, remedial hafalan,
 * naik/turun level.
 */
class TahfizInterventionController extends BaseApiController
{
    protected array $filterable = [
        'participant_id', 'halaqah_id', 'enrollment_id', 'type', 'status',
    ];

    protected array $with = ['participant', 'halaqah', 'assignedBy'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizIntervention::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        if ($due = $request->query('due_before')) {
            $query->where('due_date', '<=', $due)->whereNull('completed_at');
        }

        $query->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('due_date')
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizInterventionResource::collection($query->get()),
                'Daftar intervention berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizInterventionResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar intervention berhasil diambil'
        );
    }

    public function show(TahfizIntervention $intervention): JsonResponse
    {
        return $this->successResponse(
            new TahfizInterventionResource($intervention->load($this->with)),
            'Detail intervention berhasil diambil'
        );
    }

    public function store(TahfizInterventionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['assigned_by'] = $request->user()?->id;

        $intervention = TahfizIntervention::create($payload);

        return $this->createdResponse(
            new TahfizInterventionResource($intervention->load($this->with)),
            'Intervention berhasil dibuat'
        );
    }

    public function update(TahfizInterventionRequest $request, TahfizIntervention $intervention): JsonResponse
    {
        if ($intervention->isFinished()) {
            throw ValidationException::withMessages([
                'status' => 'Intervention yang sudah selesai atau dibatalkan tidak dapat diubah.',
            ]);
        }

        $intervention->update($request->validated());

        return $this->successResponse(
            new TahfizInterventionResource($intervention->load($this->with)),
            'Intervention berhasil diperbarui'
        );
    }

    /**
     * Transisi status terpisah dari update umum agar perpindahan status
     * terekson dalam audit trail.
     */
    public function transition(Request $request, TahfizIntervention $intervention): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', TahfizIntervention::STATUSES)],
            'result' => ['nullable', 'string', 'max:2000'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $to = $validated['status'];
        if (! in_array($to, TahfizIntervention::TRANSITIONS[$intervention->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Intervention berstatus \"{$intervention->status}\" tidak dapat diubah ke \"{$to}\".",
            ]);
        }

        $intervention->forceFill([
            'status' => $to,
            'result' => $validated['result'] ?? $intervention->result,
            'completed_at' => $to === TahfizIntervention::STATUS_DONE
                ? ($validated['completed_at'] ?? now()->toDateString())
                : null,
        ])->save();

        return $this->successResponse(
            new TahfizInterventionResource($intervention->load($this->with)),
            'Status intervention berhasil diperbarui'
        );
    }

    public function destroy(TahfizIntervention $intervention): JsonResponse
    {
        $intervention->delete();

        return $this->successResponse(null, 'Intervention berhasil dihapus');
    }

    /**
     * Daftar intervention untuk satu peserta.
     */
    public function forParticipant(Request $request, TahfizParticipant $participant): JsonResponse
    {
        $query = $participant->interventions()->with($this->with);

        if ($request->boolean('open')) {
            $query->open();
        }

        $query->orderByDesc('id');

        return $this->successResponse(
            TahfizInterventionResource::collection($query->get()),
            'Daftar intervention peserta berhasil diambil'
        );
    }
}
