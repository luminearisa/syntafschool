<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\FinanceGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{EidEvent, QurbanAnimal, QurbanDistribution, QurbanProgram};
use Illuminate\Validation\ValidationException;

/**
 * Hari raya & qurban (§51, §52).
 *
 * Alur Idul Fitri/Adha: Event → Prayer → Imam → Khatib → Congregation → Attendance.
 * Qurban: modul ini mengelola program/hewan/penerima; ledger keuangan tetap
 * milik modul keuangan lewat FinanceGatewayInterface (§52).
 */
class EidService
{
    public function __construct(protected ReligiousContext $context, protected FinanceGatewayInterface $finance)
    {
    }

    public function createEvent(array $payload): EidEvent
    {
        $event = EidEvent::query()->create([
            'code' => $payload['code'] ?? $this->context->nextCode('EID', 'eid_events'),
            'eid_type' => strtoupper((string) ($payload['eid_type'] ?? 'EID_FITR')),
            'hijri_year' => $payload['hijri_year'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? null,
            'event_date' => $payload['event_date'] ?? now()->toDateString(),
            'prayer_time' => $payload['prayer_time'] ?? config('religious.eid.default_prayer_time', '06:30'),
            'khatib_id' => $payload['khatib_id'] ?? null,
            'imam_id' => $payload['imam_id'] ?? null,
            'muadzin_id' => $payload['muadzin_id'] ?? null,
            'venue' => $payload['venue'] ?? null,
            'expected_attendance' => $payload['expected_attendance'] ?? null,
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.eid.event_created', $event, [
            'mosque_id' => $event->mosque_id, 'type' => $event->eid_type,
        ]);

        return $event;
    }

    public function recordAttendance(int $eidEventId, int $actual): EidEvent
    {
        $event = EidEvent::query()->findOrFail($eidEventId);
        $event->forceFill(['actual_attendance' => $actual, 'status' => 'COMPLETED'])->save();

        $this->context->audit('religious.eid.attendance_recorded', $event, ['actual' => $actual]);

        return $event;
    }

    // ── Qurban (§52) ────────────────────────────────────────────────────

    public function createQurbanProgram(array $payload): QurbanProgram
    {
        $program = QurbanProgram::query()->create([
            'eid_event_id' => $payload['eid_event_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('QRB', 'qurban_programs'),
            'name' => $payload['name'] ?? 'Program Qurban',
            'program_year' => (string) ($payload['program_year'] ?? now()->year),
            'animal_types' => $payload['animal_types'] ?? array_keys((array) config('religious.eid.qurban_animals', [])),
            'target_beneficiaries' => $payload['target_beneficiaries'] ?? null,
            'currency' => $payload['currency'] ?? 'IDR',
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.eid.qurban_program_created', $program);

        return $program;
    }

    /** Daftarkan hewan qurban beserta donornya. */
    public function registerAnimal(int $programId, array $payload): array
    {
        $program = QurbanProgram::query()->find($programId);
        if (! $program) {
            throw ValidationException::withMessages(['program_id' => 'Program qurban tidak ditemukan.']);
        }

        $animal = QurbanAnimal::query()->create([
            'program_id' => $program->getKey(),
            'code' => $payload['code'] ?? $this->context->nextCode('QH', 'qurban_animals'),
            'animal_type' => strtoupper((string) ($payload['animal_type'] ?? 'GOAT')),
            'weight_kg' => $payload['weight_kg'] ?? null,
            'donor_name' => $payload['donor_name'] ?? null,
            'donor_contact' => $payload['donor_contact'] ?? null,
            'contribution_amount' => $payload['contribution_amount'] ?? null,
            'contribution_reference' => $payload['contribution_reference'] ?? null,
            'status' => 'REGISTERED',
            'notes' => $payload['notes'] ?? null,
        ]);

        // Kontribusi diteruskan ke modul keuangan — bukan ledger baru (§52).
        $posting = null;
        if ($animal->contribution_amount) {
            $posting = $this->finance->postCollection([
                'source' => 'QURBAN',
                'reference' => $animal->code,
                'program' => $program->code,
                'amount' => $animal->contribution_amount,
                'currency' => $program->currency,
                'donor' => $animal->donor_name,
                'date' => now()->toDateString(),
            ]);
        }

        $program->forceFill([
            'total_animals' => QurbanAnimal::query()->where('program_id', $programId)->count(),
            'total_contribution' => QurbanAnimal::query()->where('program_id', $programId)->sum('contribution_amount'),
        ])->save();

        $this->context->audit('religious.eid.qurban_animal_registered', $animal, ['finance' => $posting]);

        return ['animal' => $animal->only(['id', 'code', 'animal_type', 'donor_name']), 'finance_posting' => $posting];
    }

    /** Catat penyembelihan. */
    public function sacrifice(int $animalId, array $payload = []): QurbanAnimal
    {
        $animal = QurbanAnimal::query()->findOrFail($animalId);
        $animal->forceFill([
            'sacrificed_at' => $payload['sacrificed_at'] ?? now(),
            'slaughter_area' => $payload['slaughter_area'] ?? null,
            'status' => 'SACRIFICED',
        ])->save();

        $this->context->audit('religious.eid.qurban_sacrificed', $animal);

        return $animal;
    }

    /** Distribusi daging ke penerima. */
    public function distribute(int $programId, array $payload): QurbanDistribution
    {
        $distribution = QurbanDistribution::query()->create([
            'program_id' => $programId,
            'animal_id' => $payload['animal_id'] ?? null,
            'recipient_type' => strtoupper((string) ($payload['recipient_type'] ?? 'STUDENT')),
            'recipient_id' => $payload['recipient_id'] ?? null,
            'recipient_name' => $payload['recipient_name'] ?? null,
            'recipient_category' => $payload['recipient_category'] ?? null,
            'portion_count' => $payload['portion_count'] ?? 1,
            'distributed_at' => $payload['distributed_at'] ?? now(),
            'distributed_by' => $this->context->actorId(),
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.eid.qurban_distributed', $distribution, ['program_id' => $programId]);

        return $distribution;
    }

    public function qurbanReport(int $programId): array
    {
        return [
            'program_id' => $programId,
            'animals' => QurbanAnimal::query()->where('program_id', $programId)->count(),
            'sacrificed' => QurbanAnimal::query()->where('program_id', $programId)->where('status', 'SACRIFICED')->count(),
            'contribution_total' => (float) QurbanAnimal::query()->where('program_id', $programId)->sum('contribution_amount'),
            'distributions' => QurbanDistribution::query()->where('program_id', $programId)->count(),
            'portions' => (int) QurbanDistribution::query()->where('program_id', $programId)->sum('portion_count'),
            'by_recipient' => QurbanDistribution::query()->where('program_id', $programId)
                ->selectRaw('recipient_type, count(*) as total')->groupBy('recipient_type')->get()
                ->map(fn ($r): array => ['type' => $r->recipient_type, 'total' => (int) $r->total])->all(),
        ];
    }
}
