<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{CateringGatewayInterface, TransportationGatewayInterface};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    IftarEvent, ItikafParticipant, ItikafProgram, RamadanActivity, RamadanDay, RamadanProgram,
    SuhoorEvent, TahajjudSession, TarawihSession
};
use Illuminate\Validation\ValidationException;

/**
 * Ramadan (§44-§50, §80, §93).
 *
 * Aturan 10: setiap aktivitas Ramadan wajib terikat program Ramadan.
 * Aturan 11: kebutuhan makan (iftar/suhoor) diteruskan ke Catering — modul ini
 * TIDAK membuat transaksi makanan sendiri.
 * Tanggal Ramadan berasal dari kalender yang dikonfigurasi, bukan asumsi keras.
 */
class RamadanService
{
    public function __construct(
        protected ReligiousContext $context,
        protected CateringGatewayInterface $catering,
        protected TransportationGatewayInterface $transportation,
    ) {
    }

    public function createProgram(array $payload): RamadanProgram
    {
        $program = RamadanProgram::query()->create([
            'code' => $payload['code'] ?? $this->context->nextCode('RMD', 'ramadan_programs'),
            'name' => $payload['name'] ?? ('Program Ramadan '.$payload['hijri_year']),
            'hijri_year' => (string) ($payload['hijri_year'] ?? config('religious.calendar.current_hijri_year', '1448')),
            'gregorian_start' => $payload['gregorian_start'] ?? null,
            'gregorian_end' => $payload['gregorian_end'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? null,
            'organization_id' => $payload['organization_id'] ?? null,
            'program_types' => $payload['program_types'] ?? array_keys((array) config('religious.ramadan.activity_types', [])),
            'target_population' => strtoupper((string) ($payload['target_population'] ?? 'ALL')),
            'expected_participants' => $payload['expected_participants'] ?? null,
            'budget' => $payload['budget'] ?? null,
            'currency' => $payload['currency'] ?? 'IDR',
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.ramadan.program_created', $program, [
            'mosque_id' => $program->mosque_id, 'campus_id' => $program->campus_id,
        ]);
        $this->context->publish('RamadanProgramCreated', $program, ['hijri_year' => $program->hijri_year]);

        return $program;
    }

    /** Bangun kalender harian 1..29/30 Ramadan (§45). */
    public function generateDays(int $programId, int $totalDays = 30): array
    {
        $program = RamadanProgram::query()->findOrFail($programId);

        if (! $program->gregorian_start) {
            throw ValidationException::withMessages([
                'gregorian_start' => 'Tanggal mulai Ramadan belum ditetapkan pada program.',
            ]);
        }

        $start = \Carbon\Carbon::parse($program->gregorian_start);
        $created = [];

        for ($day = 1; $day <= $totalDays; $day++) {
            $date = $start->copy()->addDays($day - 1);
            $created[] = RamadanDay::query()->updateOrCreate(
                ['program_id' => $program->getKey(), 'hijri_day' => $day],
                [
                    'gregorian_date' => $date->toDateString(),
                    'fasting_start' => config('religious.ramadan.default_suhoor_time', '04:20'),
                    'fasting_end' => config('religious.ramadan.default_iftar_time', '18:05'),
                    'is_holiday' => false,
                    'meta' => null,
                ]
            )->getKey();
        }

        $program->forceFill([
            'gregorian_end' => $start->copy()->addDays($totalDays - 1)->toDateString(),
        ])->save();

        $this->context->audit('religious.ramadan.days_generated', $program, ['days' => count($created)]);

        return ['program_id' => $programId, 'days' => count($created), 'day_ids' => $created];
    }

    /** Apakah tanggal tertentu berada dalam program Ramadan (§93). */
    public function coversDate(string $date): bool
    {
        return (bool) $this->context->safe(fn () => RamadanDay::query()
            ->whereDate('gregorian_date', $date)
            ->whereHas('program', fn ($q) => $q->whereIn('status', ['PLANNED', 'ACTIVE', 'RUNNING']))
            ->exists(), false);
    }

    // ── Aktivitas harian (§44, §48, §49) ───────────────────────

    /** Aturan 10: aktivitas Ramadan wajib terikat program. */
    public function addActivity(int $programId, array $payload): RamadanActivity
    {
        $program = RamadanProgram::query()->find($programId);
        if (! $program) {
            throw ValidationException::withMessages([
                'program_id' => 'Aktivitas Ramadan wajib terikat program Ramadan (Aturan 10).',
            ]);
        }

        $dayId = $payload['ramadan_day_id'] ?? null;
        if ($dayId && ! RamadanDay::query()->whereKey($dayId)->where('program_id', $programId)->exists()) {
            throw ValidationException::withMessages([
                'ramadan_day_id' => 'Hari Ramadan tidak termasuk dalam program yang dipilih.',
            ]);
        }

        $activity = RamadanActivity::query()->create([
            'program_id' => $program->getKey(),
            'ramadan_day_id' => $dayId,
            'activity_type' => strtoupper((string) ($payload['activity_type'] ?? 'TARAWIH')),
            'title' => $payload['title'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? $program->mosque_id,
            'area_id' => $payload['area_id'] ?? null,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
            'imam_id' => $payload['imam_id'] ?? null,
            'muadzin_id' => $payload['muadzin_id'] ?? null,
            'rakaat_count' => $payload['rakaat_count'] ?? null,
            'rakaat_config' => $payload['rakaat_config'] ?? null,
            'target_population' => strtoupper((string) ($payload['target_population'] ?? 'ALL')),
            'expected_participants' => $payload['expected_participants'] ?? null,
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.ramadan.activity_added', $activity, ['program_id' => $programId]);

        return $activity;
    }

    /** Sesi tarawih (§48). Konfigurasi rakaat configurable, bukan paksaan fiqh. */
    public function scheduleTarawih(int $programId, array $payload): TarawihSession
    {
        $mosqueId = $payload['mosque_id'] ?? RamadanProgram::query()->whereKey($programId)->value('mosque_id');
        if (! $mosqueId) {
            throw ValidationException::withMessages(['mosque_id' => 'Tarawih wajib terikat masjid.']);
        }

        $session = TarawihSession::query()->create([
            'program_id' => $programId,
            'ramadan_day_id' => $payload['ramadan_day_id'] ?? null,
            'mosque_id' => (int) $mosqueId,
            'session_date' => $payload['session_date'] ?? now()->toDateString(),
            'imam_id' => $payload['imam_id'] ?? null,
            'muadzin_id' => $payload['muadzin_id'] ?? null,
            'rakaat_count' => $payload['rakaat_count'] ?? config('religious.ramadan.tarawih.default_rakaat', 20),
            'rakaat_config' => $payload['rakaat_config'] ?? config('religious.ramadan.tarawih.config', null),
            'witr_count' => $payload['witr_count'] ?? config('religious.ramadan.tarawih.witr', 3),
            'start_time' => $payload['start_time'] ?? config('religious.ramadan.tarawih.default_start', '19:30'),
            'end_time' => $payload['end_time'] ?? null,
            'expected_participants' => $payload['expected_participants'] ?? null,
            'status' => 'SCHEDULED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.ramadan.tarawih_scheduled', $session, ['mosque_id' => (int) $mosqueId]);
        $this->context->publish('TarawihStarted', $session, ['date' => (string) $session->session_date]);

        return $session;
    }

    public function scheduleTahajjud(array $payload): TahajjudSession
    {
        $session = TahajjudSession::query()->create([
            'program_id' => $payload['program_id'] ?? null,
            'ramadan_day_id' => $payload['ramadan_day_id'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'dormitory_id' => $payload['dormitory_id'] ?? null,
            'session_date' => $payload['session_date'] ?? now()->toDateString(),
            'imam_id' => $payload['imam_id'] ?? null,
            'start_time' => $payload['start_time'] ?? config('religious.ramadan.tahajjud.default_start', '03:30'),
            'end_time' => $payload['end_time'] ?? null,
            'program_name' => $payload['program_name'] ?? 'Tahajjud',
            'is_special' => (bool) ($payload['is_special'] ?? false),
            'target_population' => strtoupper((string) ($payload['target_population'] ?? 'BOARDING')),
            'expected_participants' => $payload['expected_participants'] ?? null,
            'status' => 'SCHEDULED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.ramadan.tahajjud_scheduled', $session);

        return $session;
    }

    // ── Iftar & Suhoor (§46, §47, Aturan 11) ─────────────────────────

    public function scheduleIftar(int $programId, array $payload): array
    {
        $event = IftarEvent::query()->create([
            'program_id' => $programId,
            'ramadan_day_id' => $payload['ramadan_day_id'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'event_date' => $payload['event_date'] ?? now()->toDateString(),
            'start_time' => $payload['start_time'] ?? config('religious.ramadan.default_iftar_time', '18:05'),
            'expected_participants' => $payload['expected_participants'] ?? null,
            'catering_required' => (bool) ($payload['catering_required'] ?? true),
            'catering_request_status' => 'NOT_REQUESTED',
            'transportation_required' => (bool) ($payload['transportation_required'] ?? false),
            'donor_name' => $payload['donor_name'] ?? null,
            'sponsored_by' => $payload['sponsored_by'] ?? null,
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        return $this->forwardMealRequirement('IFTAR', $event, $payload);
    }

    public function scheduleSuhoor(int $programId, array $payload): array
    {
        $event = SuhoorEvent::query()->create([
            'program_id' => $programId,
            'ramadan_day_id' => $payload['ramadan_day_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'dormitory_id' => $payload['dormitory_id'] ?? null,
            'event_date' => $payload['event_date'] ?? now()->toDateString(),
            'meal_time' => $payload['meal_time'] ?? config('religious.ramadan.default_suhoor_time', '04:20'),
            'expected_participants' => $payload['expected_participants'] ?? null,
            'catering_required' => (bool) ($payload['catering_required'] ?? true),
            'catering_request_status' => 'NOT_REQUESTED',
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        return $this->forwardMealRequirement('SUHOOR', $event, $payload);
    }

    /**
     * Aturan 11: kebutuhan makan diteruskan ke Catering, bukan transaksi baru.
     * Bila modul Catering belum terpasang, statusnya tetap tercatat eksplisit
     * sebagai menunggu penyedia.
     */
    protected function forwardMealRequirement(string $kind, object $event, array $payload): array
    {
        $reference = $kind.'-'.$event->program_id.'-'.($event->event_date ?? now()->toDateString());

        $result = $this->catering->requestMealPlan([
            'reference' => $reference,
            'kind' => $kind,
            'service_date' => (string) ($event->event_date ?? now()->toDateString()),
            'meal_time' => $event->meal_time ?? $event->start_time ?? null,
            'expected_participants' => $event->expected_participants,
            'location' => $event->campus_id ?? null,
            'notes' => 'Kebutuhan '.strtolower($kind).' dari modul Religious.',
        ]);

        $status = ($result['requested'] ?? false) ? 'REQUESTED' : (($result['pending_provider'] ?? false) ? 'PENDING_PROVIDER' : 'FAILED');
        $event->forceFill(['catering_request_status' => $status])->save();

        $transport = null;
        if (($payload['transportation_required'] ?? false) && property_exists($event, 'transportation_reference')) {
            $transport = $this->transportation->requestTransport([
                'reference' => $reference.'-TR',
                'purpose' => 'Kegiatan '.$kind.' Ramadan',
                'service_date' => (string) ($event->event_date ?? now()->toDateString()),
                'passengers' => $event->expected_participants,
            ]);
            $event->forceFill([
                'transportation_reference' => $transport['reference'] ?? null,
            ])->save();
        }

        $this->context->audit('religious.ramadan.'.strtolower($kind).'_scheduled', $event, [
            'catering_status' => $status,
        ]);
        $this->context->publish($kind === 'IFTAR' ? 'IftarStarted' : 'SuhoorStarted', $event, [
            'date' => (string) ($event->event_date ?? ''),
        ]);

        return [
            'event' => $event->only(['id', 'program_id', 'event_date', 'expected_participants']),
            'catering' => $result,
            'transportation' => $transport,
            'note' => 'Catering tetap sumber kebenaran penyediaan makanan (Aturan 11).',
        ];
    }

    // ── I'tikaf (§50) ───────────────────────────────────────────────────

    public function createItikaf(array $payload): ItikafProgram
    {
        $program = ItikafProgram::query()->create([
            'program_id' => $payload['program_id'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('ITK', 'itikaf_programs'),
            'name' => $payload['name'] ?? "I'tikaf",
            'starts_at' => $payload['starts_at'] ?? now()->toDateString(),
            'ends_at' => $payload['ends_at'] ?? null,
            'target_population' => strtoupper((string) ($payload['target_population'] ?? 'STUDENTS')),
            'capacity' => $payload['capacity'] ?? null,
            'registration_required' => (bool) ($payload['registration_required'] ?? true),
            'consent_required' => (bool) ($payload['consent_required'] ?? true),
            'meals_provided' => (bool) ($payload['meals_provided'] ?? true),
            'accommodation_required' => (bool) ($payload['accommodation_required'] ?? true),
            'security_required' => (bool) ($payload['security_required'] ?? true),
            'clinic_required' => (bool) ($payload['clinic_required'] ?? false),
            'expected_participants' => $payload['expected_participants'] ?? null,
            'status' => 'PLANNED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.ramadan.itikaf_created', $program);

        return $program;
    }

    /** Pendaftaran i'tikaf dengan persetujuan (§50). */
    public function registerItikaf(int $programId, array $payload): ItikafParticipant
    {
        $program = ItikafProgram::query()->findOrFail($programId);

        if ($program->capacity) {
            $taken = ItikafParticipant::query()->where('program_id', $programId)
                ->whereNotIn('status', ['CANCELLED'])->count();
            if ($taken >= (int) $program->capacity) {
                throw ValidationException::withMessages([
                    'program_id' => "Kapasitas i'tikaf sudah penuh.",
                ]);
            }
        }

        $participant = ItikafParticipant::query()->create([
            'program_id' => $program->getKey(),
            'participant_type' => strtoupper((string) ($payload['participant_type'] ?? 'STUDENT')),
            'student_id' => $payload['student_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
            'person_name' => $payload['person_name'] ?? null,
            'consent_status' => $program->consent_required
                ? strtoupper((string) ($payload['consent_status'] ?? 'PENDING'))
                : 'NOT_REQUIRED',
            'consent_document_id' => $payload['consent_document_id'] ?? null,
            'emergency_contact_name' => $payload['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $payload['emergency_contact_phone'] ?? null,
            'accommodation_area_id' => $payload['accommodation_area_id'] ?? null,
            'meal_required' => (bool) ($payload['meal_required'] ?? $program->meals_provided),
            'status' => 'REGISTERED',
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.ramadan.itikaf_registered', $participant, ['program_id' => $programId]);

        return $participant;
    }

    // ── Dashboard Ramadan (§93) ─────────────────────────────────────────

    public function dashboard(string $date): array
    {
        $day = RamadanDay::query()->whereDate('gregorian_date', $date)->with('program')->first();

        if (! $day) {
            return [
                'is_ramadan' => false,
                'date' => $date,
                'note' => 'Tanggal ini tidak termasuk kalender Ramadan yang dikonfigurasi.',
            ];
        }

        $activities = RamadanActivity::query()->where('ramadan_day_id', $day->getKey())
            ->orderBy('starts_at')->get()
            ->map(fn (RamadanActivity $a): array => [
                'type' => $a->activity_type,
                'title' => $a->title,
                'start' => $a->starts_at,
                'end' => $a->ends_at,
                'mosque_id' => $a->mosque_id,
            ])->all();

        return [
            'is_ramadan' => true,
            'hijri_day' => (int) $day->hijri_day,
            'hijri_year' => $day->program?->hijri_year,
            'date' => $date,
            'fasting' => ['start' => $day->fasting_start, 'end' => $day->fasting_end],
            'activities' => $activities,
            'tarawih' => TarawihSession::query()->whereDate('session_date', $date)->get()
                ->map(fn (TarawihSession $t): array => [
                    'mosque_id' => $t->mosque_id, 'start' => $t->start_time,
                    'rakaat' => $t->rakaat_count, 'witr' => $t->witr_count, 'status' => $t->status,
                ])->all(),
            'tahajjud' => TahajjudSession::query()->whereDate('session_date', $date)->get()
                ->map(fn (TahajjudSession $t): array => [
                    'start' => $t->start_time, 'dormitory_id' => $t->dormitory_id, 'status' => $t->status,
                ])->all(),
            'iftar' => IftarEvent::query()->whereDate('event_date', $date)->get()
                ->map(fn (IftarEvent $i): array => [
                    'start' => $i->start_time,
                    'expected' => $i->expected_participants,
                    'catering_status' => $i->catering_request_status,
                ])->all(),
            'suhoor' => SuhoorEvent::query()->whereDate('event_date', $date)->get()
                ->map(fn (SuhoorEvent $s): array => [
                    'time' => $s->meal_time,
                    'expected' => $s->expected_participants,
                    'catering_status' => $s->catering_request_status,
                ])->all(),
        ];
    }

    /** Rekap partisipasi Ramadan (§74). */
    public function report(int $programId): array
    {
        return [
            'program_id' => $programId,
            'days' => RamadanDay::query()->where('program_id', $programId)->count(),
            'activities' => RamadanActivity::query()->where('program_id', $programId)->count(),
            'tarawih_sessions' => TarawihSession::query()->where('program_id', $programId)->count(),
            'tarawih_participants' => (int) TarawihSession::query()->where('program_id', $programId)->sum('actual_participants'),
            'iftar_events' => IftarEvent::query()->where('program_id', $programId)->count(),
            'iftar_expected' => (int) IftarEvent::query()->where('program_id', $programId)->sum('expected_participants'),
            'suhoor_events' => SuhoorEvent::query()->where('program_id', $programId)->count(),
            'itikaf_participants' => ItikafParticipant::query()
                ->whereIn('program_id', ItikafProgram::query()->where('program_id', $programId)->pluck('id'))
                ->count(),
        ];
    }
}
