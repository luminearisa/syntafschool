<?php

namespace App\Services\Ppdb;

use App\Models\PpdbRegistration;
use App\Models\PpdbSelectionParticipant;
use App\Models\PpdbSelectionStage;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seleksi PPDB: tahap seleksi, peserta, penilaian, dan skor gabungan.
 *
 * Tahap berscope periode (opsional per program) sehingga satu jadwal bisa dipakai
 * banyak jalur. Satu pendaftaran hanya boleh ikut satu tahap sekali (unique
 * `stage_id` + `registration_id`); pelanggaran keunikan dilaporkan sebagai
 * baris yang dilewati, bukan error 500.
 *
 * Hasil otomatis diturunkan dari `passing_score` tahap bila petugas tidak
 * menuliskan hasil secara eksplisit.
 *
 * Skor gabungan antar tahap dihitung dari **bobot yang disimpan di kolom
 * `ppdb_selection_stages.weight`** (audit §26) — tidak ada satu pun angka bobot
 * yang dituliskan di dalam kode. Kebijakan pemilik atas bobot: total bobot satu
 * grup tahap **harus tepat 100**, tanpa normalisasi otomatis. Karena itu bobot
 * yang membuat grup melewati 100 ditolak saat tahap dibuat/diubah, dan skor
 * gabungan menolak dihitung dari grup yang total bobotnya belum 100.
 */
class PpdbSelectionService
{
    /** Pendaftaran yang boleh didaftarkan sebagai peserta seleksi. */
    public const SELECTABLE_STATUSES = [
        PpdbRegistration::STATUS_VERIFIED,
        PpdbRegistration::STATUS_SELECTION,
    ];

    /** Nilai maksimum skala umum; `passing_score` di atasnya menaikkan skala. */
    public const DEFAULT_SCALE_MAX = 100.0;

    /** Rumus skor gabungan — dibaca panitia lewat API, tidak dihitung UI. */
    public const COMPOSITE_FORMULA = 'Σ(nilai tahap × bobot tahap) / Σ(bobot tahap)';

    /** Total bobot yang diwajibkan untuk satu grup tahap (periode + program). */
    public const REQUIRED_WEIGHT_TOTAL = 100.0;

    /** Bobot default tahap yang tidak dikonfigurasi (kolomnya tidak boleh kosong). */
    public const DEFAULT_WEIGHT = 100.0;

    public function __construct(
        private readonly PpdbNotificationService $notifications,
        private readonly PpdbRegistrationStateMachine $state,
    ) {
    }

    public function query(Request $request): Builder
    {
        return PpdbSelectionStage::query()
            ->with(['period:id,name,code', 'program:id,name,code'])
            ->withCount([
                'participants',
                'participants as scored_participants_count' => fn ($q) => $q->where('status', PpdbSelectionParticipant::STATUS_SCORED),
            ])
            ->when($request->filled('period_id'), fn ($q) => $q->where('period_id', (int) $request->input('period_id')))
            ->when($request->filled('program_id'), fn ($q) => $q->where(fn ($p) => $p
                ->where('program_id', (int) $request->input('program_id'))
                ->orWhereNull('program_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('location', 'like', "%{$search}%"));
            })
            ->orderBy('order')
            ->orderBy('scheduled_at')
            ->orderBy('id');
    }

    public function participantsQuery(PpdbSelectionStage $stage, Request $request): Builder
    {
        return PpdbSelectionParticipant::query()
            ->where('stage_id', $stage->id)
            ->with([
                'registration:id,registration_number,status,payment_status,program_id,wave_id,selection_result,applicant_id',
                'registration.applicant:id,full_name,nickname,gender_id',
                'stage:id,name,type,weight,passing_score',
                'scoredBy:id,name',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('result'), fn ($q) => $q->where('result', $request->string('result')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(fn ($s) => $s
                    ->whereHas('registration', fn ($r) => $r->where('registration_number', 'like', "%{$search}%"))
                    ->orWhereHas('registration.applicant', fn ($a) => $a->where('full_name', 'like', "%{$search}%")));
            })
            ->orderByDesc('score')
            ->orderBy('id');
    }

    /**
     * Pendaftaran yang belum menjadi peserta tahap ini tetapi sudah lolos
     * verifikasi — daftar calon untuk `registerParticipants`.
     */
    public function candidatesQuery(PpdbSelectionStage $stage): Builder
    {
        return PpdbRegistration::query()
            ->where('period_id', $stage->period_id)
            ->when($stage->program_id !== null, fn ($q) => $q->where('program_id', $stage->program_id))
            ->whereIn('status', self::SELECTABLE_STATUSES)
            ->whereNotIn('id', $stage->participants()->select('registration_id'))
            ->with('applicant:id,full_name,nickname,gender_id')
            ->orderBy('registration_number');
    }

    /**
     * Daftarkan peserta ke satu tahap.
     *
     * Baris yang tidak bisa didaftarkan (pendaftaran tidak ditemukan, status belum
     * lolos verifikasi, atau sudah terdaftar) dilaporkan, bukan menggagalkan
     * seluruh permintaan.
     *
     * @param  list<int|string>  $registrationIds
     * @return array{created: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function registerParticipants(PpdbSelectionStage $stage, array $registrationIds): array
    {
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($stage, $registrationIds, &$created, &$skipped) {
            foreach (array_values(array_unique(array_map('intval', $registrationIds))) as $id) {
                $registration = PpdbRegistration::query()->with('applicant')->find($id);

                if ($registration === null) {
                    $skipped[] = ['registration_id' => $id, 'reason' => 'not_found'];

                    continue;
                }

                if (! in_array($registration->status, self::SELECTABLE_STATUSES, true)) {
                    $skipped[] = [
                        'registration_id' => $id,
                        'registration_number' => $registration->registration_number,
                        'reason' => 'status_not_selectable',
                        'status' => $registration->status,
                    ];

                    continue;
                }

                if ($stage->participants()->where('registration_id', $registration->id)->exists()) {
                    $skipped[] = [
                        'registration_id' => $id,
                        'registration_number' => $registration->registration_number,
                        'reason' => 'already_enrolled',
                    ];

                    continue;
                }

                $participant = PpdbSelectionParticipant::create([
                    'stage_id' => $stage->id,
                    'registration_id' => $registration->id,
                    'status' => PpdbSelectionParticipant::STATUS_INVITED,
                ]);

                $created[] = [
                    'id' => $participant->id,
                    'registration_id' => $registration->id,
                    'registration_number' => $registration->registration_number,
                ];
            }

            if ($created !== [] && $stage->status === PpdbSelectionStage::STATUS_DRAFT) {
                $stage->forceFill(['status' => PpdbSelectionStage::STATUS_SCHEDULED])->save();
            }
        });

        foreach ($created as $row) {
            $this->notifySchedule((int) $row['registration_id'], $stage);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Isi nilai peserta; hasil diturunkan dari `passing_score` bila kosong.
     *
     * Yang dinilai tercatat siapa dan kapan (kolom `scored_by` + riwayat
     * pendaftaran), dan nilai di luar skala tahap ditolak — lihat
     * `assertScoreWithinScale()`.
     *
     * @param  array<string, mixed>  $data
     */
    public function score(PpdbSelectionParticipant $participant, array $data, ?User $actor = null): PpdbSelectionParticipant
    {
        $participant->loadMissing('stage', 'registration');

        $score = array_key_exists('score', $data) && $data['score'] !== null
            ? round((float) $data['score'], 2)
            : ($participant->score === null ? null : (float) $participant->score);

        $this->assertScoreWithinScale($participant->stage, $score);

        $explicit = ! empty($data['result']);
        $result = $explicit
            ? $data['result']
            : ($this->deriveResult($participant->stage, $score) ?? $participant->result);

        $status = $data['status'] ?? ($score !== null ? PpdbSelectionParticipant::STATUS_SCORED : $participant->status);

        $attendedAt = $data['attended_at'] ?? null;

        // Kehadiran yang dinyatakan tanpa jam diberi stempel sekarang.
        if ($attendedAt === null && $status === PpdbSelectionParticipant::STATUS_ATTENDED && $participant->attended_at === null) {
            $attendedAt = Carbon::now();
        }

        $participant->forceFill([
            'score' => $score,
            'result' => $result,
            'status' => $score !== null ? PpdbSelectionParticipant::STATUS_SCORED : $status,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $participant->notes,
            'attended_at' => $attendedAt !== null ? Carbon::parse($attendedAt) : $participant->attended_at,
            'scored_by' => $actor?->id ?? $participant->scored_by,
        ])->save();

        if ($this->shouldRecord($data, $participant)) {
            $this->recordScoring($participant, $actor, $score, $result);
        }

        return $participant->refresh();
    }

    /**
     * Tandai hadir tanpa (belum) memberi nilai.
     */
    public function markAttended(
        PpdbSelectionParticipant $participant,
        ?Carbon $at = null,
        ?User $actor = null,
        ?string $note = null,
    ): PpdbSelectionParticipant {
        $participant->forceFill([
            'status' => PpdbSelectionParticipant::STATUS_ATTENDED,
            'attended_at' => $at ?? Carbon::now(),
            'notes' => $note ?? $participant->notes,
        ])->save();

        $participant->loadMissing('registration');

        if ($participant->registration !== null) {
            $this->state->record(
                $participant->registration,
                'selection.attended',
                'Hadir pada tahap '.($participant->stage?->name ?? '#'.$participant->stage_id).'.',
                $actor,
            );
        }

        return $participant->refresh();
    }

    /**
     * Skala nilai yang dideklarasikan sebuah tahap.
     *
     * Tabel tahap seleksi tidak punya kolom nilai maksimum, jadi skalanya
     * diturunkan dari data yang ada: 0–100, naik mengikuti `passing_score`
     * selama tahap itu memang dideklarasikan di atas 100.
     *
     * @return array{min: float, max: float}
     */
    public function scoreScale(?PpdbSelectionStage $stage): array
    {
        $passing = $stage === null || $stage->passing_score === null ? 0.0 : (float) $stage->passing_score;

        return ['min' => 0.0, 'max' => round(max(self::DEFAULT_SCALE_MAX, $passing), 2)];
    }

    /**
     * @return void
     *
     * @throws ValidationException nilai di luar skala tahap
     */
    public function assertScoreWithinScale(?PpdbSelectionStage $stage, ?float $score): void
    {
        if ($score === null) {
            return;
        }

        $scale = $this->scoreScale($stage);

        if ($score < $scale['min'] || $score > $scale['max']) {
            $name = $stage === null ? 'tahap' : $stage->name;

            throw ValidationException::withMessages([
                'score' => "Nilai $score di luar skala tahap \"$name\" ($scale[min]–$scale[max]). "
                    .'Ubah deklarasinya pada konfigurasi tahap bila skala memang berbeda.',
            ]);
        }
    }

    /**
     * Skor gabungan satu pendaftaran: **Σ(nilai × bobot) / Σ(bobot)** atas tahap
     * yang sudah dinilai (bobot dibaca dari kolom `weight`, audit §26).
     *
     * @return array<string, mixed>
     */
    public function compositeFor(PpdbRegistration $registration): array
    {
        return $this->compositesFor([(int) $registration->id])[(int) $registration->id] ?? $this->emptyComposite();
    }

    /**
     * Skor gabungan banyak pendaftaran sekaligus (satu kueri, tanpa N+1).
     *
     * @param  list<int>  $registrationIds
     * @return array<int, array<string, mixed>>
     */
    public function compositesFor(array $registrationIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $registrationIds)));

        /** @var array<int, array<string, mixed>> $result */
        $result = array_fill_keys($ids, null);

        if ($ids === []) {
            return $result;
        }

        $rows = PpdbSelectionParticipant::query()
            ->whereIn('registration_id', $ids)
            ->whereNotNull('score')
            ->with('stage:id,name,type,weight,passing_score')
            ->orderBy('id')
            ->get();

        /** @var \Illuminate\Support\Collection<int, PpdbRegistration> $registrations */
        $registrations = PpdbRegistration::query()
            ->select('id', 'period_id', 'program_id')
            ->whereIn('id', $ids)
            ->get();

        // Total bobot per grup diambil dari satu kueri tahap, bukan per baris.
        $groups = $this->weightGroupsFor($registrations);

        foreach ($ids as $id) {
            $registration = $registrations->first(fn (PpdbRegistration $r) => (int) $r->id === $id);

            $result[$id] = $this->buildComposite(
                $rows->filter(fn (PpdbSelectionParticipant $p) => (int) $p->registration_id === $id)->all(),
                $registration === null
                    ? null
                    : ($groups[$this->groupKey((int) $registration->period_id, $registration->program_id)] ?? null),
            );
        }

        return $result;
    }

    /**
     * Total bobot tahap yang berlaku untuk satu scope (periode + program).
     *
     * Tahap tanpa program berlaku untuk semua program pada periode itu, jadi
     * bobotnya ikut dihitung bersama tahap khusus program tersebut.
     *
     * @return array<string, mixed>
     */
    public function weightSummary(int $periodId, ?int $programId, ?int $ignoreStageId = null): array
    {
        $stages = PpdbSelectionStage::query()
            ->where('period_id', $periodId)
            ->when(
                $programId === null,
                fn ($q) => $q->whereNull('program_id'),
                fn ($q) => $q->where(fn ($s) => $s->whereNull('program_id')->orWhere('program_id', $programId)),
            )
            ->when($ignoreStageId !== null, fn ($q) => $q->where('id', '!=', $ignoreStageId))
            ->orderBy('order')
            ->get();

        return $this->summarizeWeights($stages);
    }

    /**
     * Penjaga bobot: penulisan tahap yang membuat total grup melewati 100 ditolak.
     *
     * Total *kurang* dari 100 sengaja belum ditolak di sini — panitia memang harus
     * bisa mengisi tahap satu per satu. Yang menolak adalah perhitungan skor
     * gabungan (`buildComposite()`), sehingga ranking tidak pernah memakai bobot
     * yang belum lengkap.
     *
     * @throws ValidationException total bobot > 100
     */
    public function assertWeightBudget(
        int $periodId,
        ?int $programId,
        float $weight,
        ?int $ignoreStageId = null,
        ?int $stageId = null,
    ): void {
        $summary = $this->weightSummary($periodId, $programId, $ignoreStageId ?? $stageId);

        $projected = round($summary['total'] + $weight, 2);

        if ($projected <= self::REQUIRED_WEIGHT_TOTAL) {
            return;
        }

        $detail = $summary['stages'] === []
            ? 'belum ada tahap lain pada grup ini'
            : 'tahap terpakai: '.$this->describeWeights($summary);

        throw ValidationException::withMessages([
            'weight' => sprintf(
                'Total bobot tahap pada grup ini menjadi %s%%, melebihi %s%%. %s Sisa bobot untuk tahap baru: %s%%.',
                $this->number($projected),
                $this->number(self::REQUIRED_WEIGHT_TOTAL),
                $detail,
                $this->number(max(0.0, self::REQUIRED_WEIGHT_TOTAL - $summary['total'])),
            ),
        ]);
    }

    /**
     * Susun bobot satu grup tahap sekaligus (audit §26 + keputusan pemilik).
     *
     * Kebijakan: total bobot satu grup (periode + program, termasuk tahap tanpa
     * program) **wajib tepat 100%** dan tidak pernah dinormalisasi otomatis.
     * Penulisan per-tahap (`create`/`update`) tidak boleh menuntut 100% karena
     * tahap memang disusun satu per satu — penuntutannya ada di aksi massal ini:
     * seluruh tahap grup harus ikut terkirim dan totalnya harus pas sebelum satu
     * baris pun disimpan. Grup yang belum pas tetap menolak dihitung skor
     * gabungannya (`buildComposite()`), jadi ranking tidak pernah memakai bobot
     * setengah jadi.
     *
     * @param  array<int|string, int|float|string>  $weights  stage_id => bobot baru
     * @return array<string, mixed> ringkasan bobot sesudah disimpan
     *
     * @throws ValidationException tahap kurangnya/asing, nilai bukan angka, total ≠ 100
     */
    public function applyWeights(int $periodId, ?int $programId, array $weights): array
    {
        $stages = PpdbSelectionStage::query()
            ->where('period_id', $periodId)
            ->when(
                $programId === null,
                fn ($q) => $q->whereNull('program_id'),
                fn ($q) => $q->where(fn ($s) => $s->whereNull('program_id')->orWhere('program_id', $programId)),
            )
            ->orderBy('order')
            ->get();

        if ($stages->isEmpty()) {
            throw ValidationException::withMessages([
                'weights' => 'Belum ada tahap seleksi pada grup ini, jadi bobot tidak dapat disusun.',
            ]);
        }

        $incoming = [];

        foreach ($weights as $stageId => $weight) {
            $incoming[(int) $stageId] = $weight;
        }

        $groupIds = $stages->pluck('id')->map(fn ($id) => (int) $id)->all();

        $foreign = array_values(array_diff(array_keys($incoming), $groupIds));

        if ($foreign !== []) {
            throw ValidationException::withMessages([
                'weights' => 'Tahap '.implode(', ', array_map('strval', $foreign))
                    .' bukan bagian dari grup ini. Kirimi bobot tahap pada grup terpilih saja.',
            ]);
        }

        $rows = [];
        $missing = [];

        foreach ($stages as $stage) {
            if (! array_key_exists((int) $stage->id, $incoming)) {
                $missing[] = $stage->name;

                continue;
            }

            $rows[] = ['stage' => $stage, 'weight' => $this->assertWeightValue($stage, $incoming[(int) $stage->id])];
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'weights' => 'Seluruh tahap pada grup ini harus dikirim sekaligus. Kurang: '
                    .implode(', ', $missing).'.',
            ]);
        }

        $total = round(array_sum(array_column($rows, 'weight')), 2);

        if (abs($total - self::REQUIRED_WEIGHT_TOTAL) >= 0.01) {
            throw ValidationException::withMessages([
                'weights' => sprintf(
                    'Total bobot grup ini %s%%, harus tepat %s%%. Pembagian sekarang: %s. '
                    .'Bobot tidak dinormalisasi otomatis, jadi perbaiki nilainya lalu simpan lagi.',
                    $this->number($total),
                    $this->number(self::REQUIRED_WEIGHT_TOTAL),
                    $this->describeWeights(['stages' => array_map(
                        static fn (array $row) => [
                            'name' => $row['stage']->name,
                            'weight' => $row['weight'],
                        ],
                        $rows,
                    )]),
                ),
            ]);
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                if (abs((float) $row['stage']->weight - $row['weight']) < 0.005) {
                    continue;
                }

                $row['stage']->forceFill(['weight' => $row['weight']])->save();
            }
        });

        return $this->weightSummary($periodId, $programId);
    }

    /**
     * Satu bobot yang dikirim: angka 0–100, dibulatkan 2 desimal.
     *
     * Batasannya dijaga lagi di sini (bukan hanya di FormRequest) karena grup
     * bobot bisa pula disusun dari kode non-HTTP.
     *
     * @throws ValidationException
     */
    private function assertWeightValue(PpdbSelectionStage $stage, mixed $weight): float
    {
        if (! is_numeric($weight)) {
            throw ValidationException::withMessages([
                'weights' => 'Bobot tahap "'.$stage->name.'" harus angka.',
            ]);
        }

        $value = round((float) $weight, 2);

        if ($value < 0.0 || $value > self::REQUIRED_WEIGHT_TOTAL) {
            throw ValidationException::withMessages([
                'weights' => sprintf(
                    'Bobot tahap "%s" (%s%%) di luar rentang 0–%s%%.',
                    $stage->name,
                    $this->number($value),
                    $this->number(self::REQUIRED_WEIGHT_TOTAL),
                ),
            ]);
        }

        return $value;
    }

    /**
     * Ringkasan total bobot sekelompok tahap (satu grup periode + program).
     *
     * @param  Collection<int, PpdbSelectionStage>  $stages
     * @return array<string, mixed>
     */
    private function summarizeWeights(Collection $stages): array
    {
        $rows = $stages->map(fn (PpdbSelectionStage $stage) => [
            'stage_id' => $stage->id,
            'name' => $stage->name,
            'program_id' => $stage->program_id,
            'weight' => round((float) $stage->weight, 2),
        ])->values()->all();

        $total = round(array_sum(array_column($rows, 'weight')), 2);

        return [
            'total' => $total,
            'expected' => self::REQUIRED_WEIGHT_TOTAL,
            'remaining' => round(max(0.0, self::REQUIRED_WEIGHT_TOTAL - $total), 2),
            'stages' => $rows,
            'stages_count' => count($rows),
            'valid' => abs($total - self::REQUIRED_WEIGHT_TOTAL) < 0.01,
            'error' => match (true) {
                $rows === [] => 'Belum ada tahap seleksi pada grup ini, skor gabungan tidak dapat dihitung.',
                $total > self::REQUIRED_WEIGHT_TOTAL => sprintf(
                    'Total bobot tahap %s%% melebihi %s%%. Perbaiki bobot tahap sebelum membaca skor gabungan.',
                    $this->number($total),
                    $this->number(self::REQUIRED_WEIGHT_TOTAL),
                ),
                abs($total - self::REQUIRED_WEIGHT_TOTAL) >= 0.01 => sprintf(
                    'Total bobot tahap baru %s%% dari %s%%. Lengkapi bobotnya dulu; skor gabungan tidak dinormalisasi otomatis.',
                    $this->number($total),
                    $this->number(self::REQUIRED_WEIGHT_TOTAL),
                ),
                default => null,
            },
        ];
    }

    /**
     * Total bobot untuk banyak scope sekaligus, dikunci `"periode:program"`.
     *
     * @param  Collection<int, PpdbRegistration>  $registrations
     * @return array<string, array<string, mixed>>
     */
    private function weightGroupsFor(Collection $registrations): array
    {
        $periodIds = $registrations->pluck('period_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $programIds = $registrations->pluck('program_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($periodIds === []) {
            return [];
        }

        $stages = PpdbSelectionStage::query()
            ->whereIn('period_id', $periodIds)
            ->when(
                $programIds === [],
                fn ($q) => $q->whereNull('program_id'),
                fn ($q) => $q->where(fn ($s) => $s->whereNull('program_id')->orWhereIn('program_id', $programIds)),
            )
            ->get();

        $groups = [];

        foreach ($registrations as $registration) {
            $key = $this->groupKey((int) $registration->period_id, $registration->program_id);

            if (array_key_exists($key, $groups)) {
                continue;
            }

            $groups[$key] = $this->summarizeWeights($stages->filter(
                fn (PpdbSelectionStage $stage) => (int) $stage->period_id === (int) $registration->period_id
                    && ($stage->program_id === null || (int) $stage->program_id === (int) $registration->program_id)
            ));
        }

        return $groups;
    }

    private function groupKey(int $periodId, int|null $programId): string
    {
        return $periodId.':'.($programId ?? 0);
    }

    /**
     * @return string
     */
    private function describeWeights(array $summary): string
    {
        return collect($summary['stages'])
            ->map(fn (array $row) => sprintf('%s (%s%%)', $row['name'], $this->number($row['weight'])))
            ->implode(', ').'.';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Tempelkan skor gabungan ke baris peserta agar ikut ter-serialisasi.
     *
     * @param  iterable<PpdbSelectionParticipant>  $participants
     * @return array<int, array<string, mixed>> skor gabungan per id pendaftaran
     */
    public function attachComposites(iterable $participants): array
    {
        $ids = [];

        foreach ($participants as $participant) {
            $ids[] = (int) $participant->registration_id;
        }

        $composites = $this->compositesFor($ids);

        foreach ($participants as $participant) {
            $participant->setAttribute('composite', $composites[(int) $participant->registration_id] ?? $this->emptyComposite());
        }

        return $composites;
    }

    /**
     * Tahap yang berlaku untuk satu pendaftaran + nilai peserta itu sendiri.
     *
     * Bentuk respons portal: jadwal, lokasi, status, nilai, hasil — tanpa data
     * peserta lain, ditutup skor gabungan berbobot seluruh tahap.
     *
     * @return array<string, mixed>
     */
    public function selectionSummary(PpdbRegistration $registration): array
    {
        return [
            'stages' => $this->stagesForRegistration($registration),
            'composite' => $this->compositeFor($registration),
            'weights' => $this->weightSummary((int) $registration->period_id, $registration->program_id),
            'formula' => self::COMPOSITE_FORMULA,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stagesForRegistration(PpdbRegistration $registration): array
    {
        $stages = PpdbSelectionStage::query()
            ->where('period_id', $registration->period_id)
            ->where(fn ($q) => $q->whereNull('program_id')->orWhere('program_id', $registration->program_id))
            ->orderBy('order')
            ->orderBy('scheduled_at')
            ->get();

        $own = PpdbSelectionParticipant::query()
            ->where('registration_id', $registration->id)
            ->with('scoredBy:id,name')
            ->get()
            ->keyBy('stage_id');

        return $stages->map(static function (PpdbSelectionStage $stage) use ($own): array {
            $participant = $own->get($stage->id);

            return [
                'stage' => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'type' => $stage->type,
                    'status' => $stage->status,
                    'scheduled_at' => $stage->scheduled_at?->toIso8601String(),
                    'location' => $stage->location,
                    'order' => $stage->order,
                    'notes' => $stage->notes,
                    'weight' => round((float) $stage->weight, 2),
                    'passing_score' => $stage->passing_score === null ? null : (float) $stage->passing_score,
                ],
                'participant' => $participant === null ? null : [
                    'id' => $participant->id,
                    'status' => $participant->status,
                    'score' => $participant->score === null ? null : (float) $participant->score,
                    'result' => $participant->result,
                    'attended_at' => $participant->attended_at?->toIso8601String(),
                    'notes' => $participant->notes,
                    'scored_by' => $participant->scoredBy?->name ?? $participant->scored_by,
                    'scored_at' => $participant->score === null ? null : $participant->updated_at?->toIso8601String(),
                ],
            ];
        })->all();
    }

    /**
     * Buat tahap seleksi.
     *
     * `program_id` kosong = berlaku untuk semua program pada periode itu.
     *
     * Pelaku tidak ditulis ke `created_by` karena tabel `ppdb_selection_stages`
     * memang tidak punya kolom itu — saat insert atribut non-kolom dibuang diam-diam
     * oleh Eloquent, jadi tulisannya hanya menyisakan ilusi pencatatan.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PpdbSelectionStage
    {
        $data = $this->withWeight($data);

        $this->assertWeightBudget(
            (int) $data['period_id'],
            isset($data['program_id']) ? (int) $data['program_id'] : null,
            (float) ($data['weight'] ?? self::DEFAULT_WEIGHT),
        );

        $stage = PpdbSelectionStage::create($data + [
            'weight' => self::DEFAULT_WEIGHT,
            'status' => $data['status'] ?? PpdbSelectionStage::STATUS_DRAFT,
            'order' => $data['order'] ?? $this->nextOrder((int) $data['period_id'], $data['program_id'] ?? null),
        ]);

        return $stage->load(['period:id,name,code', 'program:id,name,code']);
    }

    /**
     * Perbarui tahap. Perubahan scope (periode/program) tidak diizinkan setelah
     * peserta terdaftar agar tidak memindahkan orang ke jadwal lain.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PpdbSelectionStage $stage, array $data): PpdbSelectionStage
    {
        $hasParticipants = $stage->participants()->exists();

        if ($hasParticipants) {
            foreach (['period_id', 'program_id'] as $scope) {
                if (array_key_exists($scope, $data) && (int) ($data[$scope] ?? 0) !== (int) ($stage->{$scope} ?? 0)) {
                    throw ValidationException::withMessages([
                        $scope => 'Scope tahap tidak dapat diubah setelah peserta terdaftar.',
                    ]);
                }
            }
        }

        $data = $this->withWeight($data);

        if (array_key_exists('weight', $data)) {
            // Bobot baru diuji terhadap sisa grup, termasuk tahap ini sendiri yang
            // sedang diubah (dikecualikan dari total lama).
            $this->assertWeightBudget(
                (int) ($data['period_id'] ?? $stage->period_id),
                array_key_exists('program_id', $data)
                    ? ($data['program_id'] === null ? null : (int) $data['program_id'])
                    : ($stage->program_id === null ? null : (int) $stage->program_id),
                (float) $data['weight'],
                $stage->id,
            );
        }

        $stage->fill($data);

        // Tidak ada penulisan `created_by` di sini: kolomnya tidak ada pada tabel
        // ini, dan atribut non-kolom yang sudah masuk ke model tersimpan tidak
        // dibuang lagi oleh Eloquent — UPDATE-nya gagal (`no such column`).
        $stage->save();

        return $stage->refresh()->load(['period:id,name,code', 'program:id,name,code']);
    }

    /**
     * Hitung skor gabungan sekelompok baris peserta (lihat COMPOSITE_FORMULA).
     *
     * `$group` adalah total bobot konfigurasi tahap grup tersebut. Bila bobot
     * belum pas 100, skor **tidak dihitung** (tidak ada normalisasi diam-diam);
     * jumlah mentahnya tetap dikembalikan supaya panitia tahu progressnya.
     *
     * @param  list<PpdbSelectionParticipant>  $rows
     * @param  array<string, mixed>|null  $group
     * @return array<string, mixed>
     */
    private function buildComposite(array $rows, ?array $group = null): array
    {
        // Tanpa data konfigurasi grup (mis. pendaftaran belum terhubung ke satu
        // periode), total bobot diturunkan dari tahap yang dinilai saja.
        $group ??= $this->summarizeWeights(
            collect($rows)->pluck('stage')->filter()->unique('id')->values()
        );

        $weighted = 0.0;
        $assessedWeight = 0.0;
        $stages = [];

        foreach ($rows as $row) {
            $weight = $row->stage === null ? self::DEFAULT_WEIGHT : round((float) $row->stage->weight, 2);
            $score = round((float) $row->score, 2);

            $weighted += $score * $weight;
            $assessedWeight += $weight;

            $stages[] = [
                'stage_id' => $row->stage_id,
                'name' => $row->stage?->name,
                'type' => $row->stage?->type,
                'weight' => $weight,
                'score' => $score,
                'weighted_score' => round($score * $weight, 4),
            ];
        }

        // Bobot tahap yang sudah dinilai dibagi atas totalnya sendiri:
        // Σ(nilai × bobot) / Σ(bobot) — sama dengan pembagian 100 selama
        // konfigurasi bobot grup valid (tepat 100).
        $score = $group['valid'] && $assessedWeight > 0 ? round($weighted / $assessedWeight, 2) : null;

        return [
            'score' => $score,
            'weighted_sum' => round($weighted, 4),
            'total_weight' => $group['total'],
            'assessed_weight' => round($assessedWeight, 2),
            'expected_weight' => self::REQUIRED_WEIGHT_TOTAL,
            'weight_valid' => $group['valid'],
            'weight_error' => $group['valid'] ? null : $group['error'],
            'stages_count' => count($stages),
            'stages' => $stages,
            'formula' => self::COMPOSITE_FORMULA,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyComposite(): array
    {
        return [
            'score' => null,
            'weighted_sum' => 0.0,
            'total_weight' => 0.0,
            'assessed_weight' => 0.0,
            'expected_weight' => self::REQUIRED_WEIGHT_TOTAL,
            'weight_valid' => false,
            'weight_error' => 'Belum ada tahap seleksi dengan bobot terkonfigurasi.',
            'stages_count' => 0,
            'stages' => [],
            'formula' => self::COMPOSITE_FORMULA,
        ];
    }

    /**
     * Penilaian dicatat pada riwayat hanya bila memang ada isinya, agar daftar
     * riwayat tidak dipenuhi baris kosong dari petugas yang hanya membuka form.
     *
     * @param  array<string, mixed>  $data
     */
    private function shouldRecord(array $data, PpdbSelectionParticipant $participant): bool
    {
        return $participant->registration !== null
            && ($data !== [] || $participant->wasChanged('score'));
    }

    /**
     * Riwayat pendaftaran: siapa menilai, berapa, dan hasil tahapnya.
     */
    private function recordScoring(
        PpdbSelectionParticipant $participant,
        ?User $actor,
        ?float $score,
        ?string $result,
    ): void {
        $stage = $participant->stage;
        $composite = $this->compositeFor($participant->registration);

        $combined = $composite['score'] !== null
            ? (string) $composite['score']
            : 'belum dapat dihitung ('.($composite['weight_error'] ?? 'tanpa bobot terkonfigurasi').')';

        $this->state->record(
            $participant->registration,
            'selection.scored',
            'Nilai tahap "'.($stage?->name ?? '#'.$participant->stage_id).'"'
                .($score === null ? ' dikosongkan' : ": $score (bobot ".($stage === null ? self::DEFAULT_WEIGHT : round((float) $stage->weight, 2)).')')
                .($result === null ? '' : ', hasil tahap: '.PpdbRegistrationQueryService::statusLabel($result))
                .". Skor gabungan berbobot: $combined ".$composite['formula'],
            $actor,
        );
    }

    /**
     * Bobot tidak boleh kosong di database: null/'' dinormalkan ke bobot penuh.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withWeight(array $data): array
    {
        if (! array_key_exists('weight', $data)) {
            return $data;
        }

        $weight = $data['weight'] === null || $data['weight'] === ''
            ? self::DEFAULT_WEIGHT
            : (float) $data['weight'];

        $data['weight'] = round(max(0.0, min(100.0, $weight)), 2);

        return $data;
    }

    /**
     * Hapus tahap (soft delete) — hanya bila belum ada pesertanya.
     */
    public function delete(PpdbSelectionStage $stage): void
    {
        $this->assertDeletable($stage);

        $stage->delete();
    }

    /**
     * Urutan tampil berikutnya dalam satu periode/program.
     */
    private function nextOrder(int $periodId, ?int $programId): int
    {
        $max = PpdbSelectionStage::query()
            ->where('period_id', $periodId)
            ->when(
                $programId === null,
                fn ($q) => $q->whereNull('program_id'),
                fn ($q) => $q->where('program_id', $programId),
            )
            ->max('order');

        return (int) $max + 1;
    }

    /**
     * Hapus tahap hanya bila belum ada pesertanya.
     */
    public function assertDeletable(PpdbSelectionStage $stage): void
    {
        $count = $stage->participants()->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'stage' => "Tahap masih dipakai oleh $count peserta. Hapus pesertanya terlebih dahulu.",
            ]);
        }
    }

    public function deriveResult(?PpdbSelectionStage $stage, ?float $score): ?string
    {
        if ($stage === null || $score === null) {
            return null;
        }

        if ($stage->passing_score === null) {
            return null;
        }

        return $score >= (float) $stage->passing_score
            ? PpdbRegistration::STATUS_PASSED
            : PpdbRegistration::STATUS_FAILED;
    }

    private function notifySchedule(int $registrationId, PpdbSelectionStage $stage): void
    {
        $registration = PpdbRegistration::query()->with('parentAccount')->find($registrationId);

        if ($registration === null || $registration->parentAccount === null) {
            return;
        }

        $schedule = $stage->scheduled_at === null
            ? 'menunggu jadwal'
            : $stage->scheduled_at->toDayDateTimeString().
                ($stage->location !== null ? ' di '.$stage->location : '');

        $this->notifications->toParent(
            $registration->parentAccount,
            PpdbActivityNotification::TYPE_SELECTION_SCHEDULED,
            'Jadwal seleksi '.str_replace('_', ' ', $stage->type),
            "Anak Anda dijadwalkan mengikuti tahap \"{$stage->name}\" $schedule. Nomor pendaftaran {$registration->registration_number}.",
            [
                'registration_number' => $registration->registration_number,
                'stage_id' => $stage->id,
                'stage_name' => $stage->name,
            ],
        );
    }
}
