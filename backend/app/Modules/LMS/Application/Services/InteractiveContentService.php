<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\InteractiveContentStatus;
use App\Modules\LMS\Domain\Enums\InteractiveContentType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsInteractiveContent;
use App\Modules\LMS\Models\LmsInteractiveContentVersion;
use App\Modules\LMS\Models\LmsInteractiveResponse;
use Illuminate\Support\Facades\DB;

/**
 * Interactive content engine (§7–§12).
 *
 * Konten interaktif menempel pada `lms_learning_items` melalui `item_id`,
 * sehingga completion/progress/availability Phase 1 tetap satu-satunya sumber
 * kebenaran. Tipe baru cukup ditambahkan pada `InteractiveContentType` +
 * normalisasi data di sini — core course engine tidak berubah.
 *
 * Progres siswa disimpan pada SATU baris `lms_interactive_responses` per
 * (konten, siswa): jawaban per elemen ada di kolom `responses` (JSON) dan
 * agregatnya (skor, slide terlihat, kartu dikuasai) ada di kolom tersendiri.
 */
class InteractiveContentService
{
    use LogsLmsAudit;

    public function __construct(private readonly LearningEventService $events) {}

    /** @return list<array<string, mixed>> */
    public function types(): array
    {
        return array_map(fn (InteractiveContentType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ], InteractiveContentType::cases());
    }

    public function find(int $id): LmsInteractiveContent
    {
        $content = LmsInteractiveContent::query()->find($id);

        if ($content === null) {
            LmsFault::notFound('Konten interaktif tidak ditemukan.');
        }

        return $content;
    }

    /**
     * Buat konten interaktif baru (opsional menempel pada learning item).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, LmsActor $actor): LmsInteractiveContent
    {
        $type = InteractiveContentType::from($data['type'] ?? InteractiveContentType::SLIDES->value);

        $content = LmsInteractiveContent::query()->create([
            'course_id' => $data['course_id'] ?? null,
            'item_id' => $data['item_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $type,
            'content_data' => $this->normalizeContent($type, $data['content_data'] ?? []),
            'configuration' => $data['configuration'] ?? null,
            'is_reusable' => (bool) ($data['is_reusable'] ?? false),
            'completion_rule' => $data['completion_rule'] ?? 'NONE',
            'required_interactions' => $data['required_interactions'] ?? null,
            'completion_min_slides' => $data['completion_min_slides'] ?? null,
            'completion_min_score' => $data['completion_min_score'] ?? null,
            'status' => InteractiveContentStatus::DRAFT,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->snapshot($content, $actor->id);
        $this->audit('LMS_INTERACTIVE_CREATED', $content, ['type' => $type->value]);

        return $content;
    }

    /**
     * Perbarui konten & catat versi baru — percobaan siswa lama tetap valid
     * karena jawaban menunjuk versi yang mereka lihat (§7).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LmsInteractiveContent $content, array $data, LmsActor $actor): LmsInteractiveContent
    {
        $content->fill([
            'title' => $data['title'] ?? $content->title,
            'description' => $data['description'] ?? $content->description,
            'configuration' => $data['configuration'] ?? $content->configuration,
            'is_reusable' => $data['is_reusable'] ?? $content->is_reusable,
            'completion_rule' => $data['completion_rule'] ?? $content->completion_rule,
            'required_interactions' => $data['required_interactions'] ?? $content->required_interactions,
            'completion_min_slides' => $data['completion_min_slides'] ?? $content->completion_min_slides,
            'completion_min_score' => $data['completion_min_score'] ?? $content->completion_min_score,
            'updated_by' => $actor->id,
        ]);

        if (array_key_exists('content_data', $data)) {
            $content->content_data = $this->normalizeContent($content->type, $data['content_data']);
        }

        $content->save();

        if ($content->wasChanged('content_data')) {
            $this->snapshot($content, $actor->id);
        }

        $this->audit('LMS_INTERACTIVE_UPDATED', $content, ['version' => (int) $content->version]);

        return $content;
    }

    public function publish(LmsInteractiveContent $content, LmsActor $actor): LmsInteractiveContent
    {
        $this->assertPublishable($content);

        $content->status = InteractiveContentStatus::PUBLISHED;
        $content->published_at = now();
        $content->updated_by = $actor->id;
        $content->save();

        $this->audit('LMS_INTERACTIVE_PUBLISHED', $content, []);

        return $content;
    }

    public function delete(LmsInteractiveContent $content, LmsActor $actor): void
    {
        $this->audit('LMS_INTERACTIVE_DELETED', $content, ['title' => $content->title]);

        $content->delete();
    }

    /**
     * Payload pemutar untuk siswa: konten tanpa kunci jawaban (§28, §72)
     * ditambah ringkasan progres siswa bila ada.
     *
     * @return array<string, mixed>
     */
    public function playerPayload(LmsInteractiveContent $content, ?int $userId = null): array
    {
        $response = $userId === null ? null : $this->responseFor($content, $userId);

        return [
            'id' => (int) $content->id,
            'title' => $content->title,
            'description' => $content->description,
            'type' => $content->type->value,
            'type_label' => $content->type->label(),
            'status' => $content->status->value,
            'content' => $this->playerContent($content),
            'completion_rule' => $content->completion_rule,
            'required_interactions' => $content->required_interactions === null
                ? null
                : (int) $content->required_interactions,
            'completion_min_slides' => $content->completion_min_slides === null
                ? null
                : (int) $content->completion_min_slides,
            'completion_min_score' => $content->completion_min_score === null
                ? null
                : (float) $content->completion_min_score,
            'progress' => $response === null ? null : $this->responsePayload($response, $content),
        ];
    }

    /**
     * Catat jawaban/aksi siswa pada satu elemen (§8–§12) dan evaluasi
     * completion bila dikonfigurasi.
     *
     * @param  array<string, mixed>  $data  element_key, response_data,
     *                                       is_correct/score (server-verified),
     *                                       delta: slides_seen / cards_known.
     * @return array<string, mixed>
     */
    public function respond(LmsInteractiveContent $content, int $userId, array $data, ?LmsActor $actor): array
    {
        if ($content->status !== InteractiveContentStatus::PUBLISHED) {
            LmsFault::conflict('Konten interaktif ini belum diterbitkan.');
        }

        $elementKey = (string) ($data['element_key'] ?? 'content');
        $response = $this->responseFor($content, $userId);

        $responses = collect($response?->responses ?? []);
        $responses[$elementKey] = [
            'data' => $data['response_data'] ?? [],
            'is_correct' => $data['is_correct'] ?? null,
            'score' => $data['score'] ?? null,
            'answered_at' => now()->toIso8601String(),
        ];

        $payload = [
            'interactive_content_version_id' => $this->currentVersionId($content),
            'course_id' => $content->course_id,
            'item_id' => $content->item_id,
            'enrollment_id' => $content->course_id === null
                ? null
                : LmsEnrollment::query()
                    ->where('course_id', $content->course_id)
                    ->where('user_id', $userId)
                    ->value('id'),
            'responses' => $responses->all(),
            'interactions_seen' => max(
                (int) ($response?->interactions_seen ?? 0),
                $this->interactiveElementKeys($content, 'interactions')->intersect($responses->keys())->count(),
            ),
            'slides_seen' => max(
                (int) ($response?->slides_seen ?? 0),
                min($this->elementCount($content, 'slides'), (int) ($data['slides_seen'] ?? 0)),
            ),
            'cards_known' => max((int) ($response?->cards_known ?? 0), (int) ($data['cards_known'] ?? 0)),
            'status' => $response?->status ?? 'IN_PROGRESS',
        ];

        $state = $this->stateFor($content, $payload['responses'], $payload['slides_seen'], $payload['cards_known']);

        if ($state['is_complete'] && $response?->status !== 'COMPLETED') {
            $payload['status'] = 'COMPLETED';
            $payload['completed_at'] = now();
            $payload['score'] = $state['average_score'];
            $payload['percentage'] = $state['average_score'];
            $payload['passed'] = $content->completion_min_score === null
                ? null
                : $state['average_score'] !== null && $state['average_score'] >= (float) $content->completion_min_score;
        }

        $response = LmsInteractiveResponse::query()->updateOrCreate(
            ['interactive_content_id' => $content->id, 'user_id' => $userId],
            $payload + ['started_at' => $response?->started_at ?? now()],
        );

        $this->events->record(
            \App\Modules\LMS\Domain\Enums\LearningEventType::ACTIVITY_VIEW,
            $actor,
            $content->course_id === null ? null : $content->course()->first(),
            ['interactive_content_id' => (int) $content->id, 'element_key' => $elementKey],
            $content->item_id === null ? null : $content->item()->first(),
            null,
            $content,
        );

        return [
            'status' => $response->status,
            'progress' => $this->responsePayload($response, $content),
        ];
    }

    /**
     * Status penyelesaian konten interaktif untuk seorang siswa (§8, §9).
     *
     * @param  array<string, mixed>|null  $responses  null → baca dari basis data.
     * @return array<string, mixed>
     */
    public function stateFor(LmsInteractiveContent $content, ?array $responses = null, ?int $slidesSeen = null, ?int $cardsKnown = null): array
    {
        $responses ??= $this->responseFor($content, auth()->id() ?? 0)?->responses ?? [];

        $answered = collect($responses)->keys();
        $elements = $this->interactiveElementKeys($content);
        $scores = collect($responses)
            ->filter(fn ($row) => is_array($row) && ($row['score'] ?? null) !== null)
            ->map(fn ($row) => (float) $row['score']);

        $averageScore = $scores->isEmpty() ? null : round($scores->avg(), 2);
        $requiredInteractions = (int) ($content->required_interactions ?? 0);

        $met = match ($content->completion_rule) {
            'ALL_INTERACTIONS' => $elements->isNotEmpty() && $elements->diff($answered)->isEmpty(),
            'REQUIRED_INTERACTIONS' => $requiredInteractions > 0 && $answered->count() >= $requiredInteractions,
            'ALL_SLIDES' => $content->type === InteractiveContentType::SLIDES
                && $answered->count() >= $this->elementCount($content, 'slides'),
            'MIN_SLIDES' => $content->completion_min_slides !== null
                && max($answered->count(), $slidesSeen ?? 0) >= (int) $content->completion_min_slides,
            'MIN_SCORE' => $content->completion_min_score !== null
                && $averageScore !== null
                && $averageScore >= (float) $content->completion_min_score,
            default => false,
        };

        return [
            'is_complete' => $met,
            'responded_elements' => $answered->count(),
            'total_elements' => $elements->count(),
            'slides_seen' => $slidesSeen,
            'cards_known' => $cardsKnown,
            'required_interactions' => $requiredInteractions > 0 ? $requiredInteractions : null,
            'average_score' => $averageScore,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forCourse(int $courseId, ?InteractiveContentType $type = null): array
    {
        return LmsInteractiveContent::query()
            ->where('course_id', $courseId)
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->orderBy('title')
            ->get()
            ->map(fn (LmsInteractiveContent $content) => [
                'id' => (int) $content->id,
                'title' => $content->title,
                'type' => $content->type->value,
                'type_label' => $content->type->label(),
                'status' => $content->status->value,
                'version' => (int) $content->version,
                'item_id' => $content->item_id === null ? null : (int) $content->item_id,
                'responses_count' => $content->responses()->count(),
            ])
            ->all();
    }

    /**
     * Statistik guru (§58): berapa siswa yang menyelesaikan konten interaktif.
     *
     * @return array<string, mixed>
     */
    public function statistics(LmsInteractiveContent $content): array
    {
        $responses = $content->responses()->get();

        return [
            'interactive_content_id' => (int) $content->id,
            'title' => $content->title,
            'type' => $content->type->value,
            'participants' => $responses->count(),
            'completed' => $responses->where('status', 'COMPLETED')->count(),
            'completion_rate' => $responses->isEmpty()
                ? null
                : round($responses->where('status', 'COMPLETED')->count() / $responses->count() * 100, 2),
            'average_score' => $responses->filter(fn ($row) => $row->score !== null)->avg('score'),
        ];
    }

    // ------------------------------------------------------------------ internal

    private function responseFor(LmsInteractiveContent $content, int $userId): ?LmsInteractiveResponse
    {
        return LmsInteractiveResponse::query()
            ->where('interactive_content_id', $content->id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $responses
     * @return array<string, mixed>
     */
    private function responsePayload(LmsInteractiveResponse $response, LmsInteractiveContent $content): array
    {
        $state = $this->stateFor($content, $response->responses ?? [], (int) $response->slides_seen, (int) $response->cards_known);

        return [
            'status' => $response->status,
            'is_complete' => $state['is_complete'],
            'responded_elements' => $state['responded_elements'],
            'total_elements' => $state['total_elements'],
            'slides_seen' => (int) $response->slides_seen,
            'cards_known' => (int) $response->cards_known,
            'score' => $response->score === null ? null : (float) $response->score,
            'percentage' => $response->percentage === null ? null : (float) $response->percentage,
            'passed' => $response->passed,
        ];
    }

    private function snapshot(LmsInteractiveContent $content, ?int $actorId): void
    {
        DB::transaction(function () use ($content, $actorId): void {
            LmsInteractiveContentVersion::query()->create([
                'interactive_content_id' => $content->id,
                'version' => (int) $content->version,
                'content_data' => $content->content_data,
                'configuration' => $content->configuration,
                'created_by' => $actorId,
            ]);
        });
    }

    private function currentVersionId(LmsInteractiveContent $content): ?int
    {
        return LmsInteractiveContentVersion::query()
            ->where('interactive_content_id', $content->id)
            ->where('version', (int) $content->version)
            ->value('id');
    }

    private function assertPublishable(LmsInteractiveContent $content): void
    {
        $data = collect($content->content_data ?? []);

        $key = match ($content->type) {
            InteractiveContentType::INTERACTIVE_VIDEO => 'interactions',
            InteractiveContentType::SLIDES => 'slides',
            InteractiveContentType::FLASHCARD => 'cards',
            InteractiveContentType::MATCHING => 'pairs',
            InteractiveContentType::TIMELINE => 'entries',
            InteractiveContentType::INTERACTIVE_QUIZ => 'questions',
        };

        if (collect($data->get($key, []))->count() < 1) {
            LmsFault::invalid(
                'Konten interaktif minimal memiliki satu elemen ('.$content->type->label().').',
                'content_data',
            );
        }
    }

    private function elementCount(LmsInteractiveContent $content, string $key): int
    {
        return count(collect($content->content_data ?? [])->get($key, []));
    }

    /**
     * Kunci elemen sebuah daftar (mis. `interactions`). Untuk daftar lain
     * (slides/cards/pairs/questions/entries) kuncinya sama dengan daftar itu.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function interactiveElementKeys(LmsInteractiveContent $content, ?string $listKey = null): \Illuminate\Support\Collection
    {
        $key = $listKey ?? match ($content->type) {
            InteractiveContentType::INTERACTIVE_VIDEO => 'interactions',
            InteractiveContentType::SLIDES => 'slides',
            InteractiveContentType::FLASHCARD => 'cards',
            InteractiveContentType::MATCHING => 'pairs',
            InteractiveContentType::TIMELINE => 'entries',
            InteractiveContentType::INTERACTIVE_QUIZ => 'questions',
        };

        $rows = collect($content->content_data ?? [])->get($key, []);

        return collect(is_array($rows) ? $rows : [])
            ->map(fn ($row) => is_array($row) ? ($row['key'] ?? null) : null)
            ->filter()
            ->values();
    }
    /** Tampilkan elemen tanpa membocorkan kunci jawaban (§28, §72). */
    private function playerContent(LmsInteractiveContent $content): array
    {
        $data = collect($content->content_data ?? []);

        return match ($content->type) {
            InteractiveContentType::INTERACTIVE_VIDEO => [
                'video_url' => $data->get('video_url'),
                'duration_seconds' => (int) ($data->get('duration_seconds') ?? 0),
                'caption_url' => $data->get('caption_url'),
                'transcript' => $data->get('transcript'),
                'interactions' => collect($data->get('interactions', []))->map(fn (array $row) => [
                    'key' => $row['key'],
                    'at_seconds' => (int) $row['at_seconds'],
                    'kind' => $row['kind'],
                    'title' => $row['title'] ?? '',
                    'body' => $row['body'] ?? null,
                    'options' => array_map(
                        fn ($option) => is_array($option)
                            ? ['key' => $option['key'] ?? null, 'text' => $option['text'] ?? '']
                            : ['key' => null, 'text' => (string) $option],
                        $row['options'] ?? [],
                    ),
                ])->all(),
            ],
            InteractiveContentType::MATCHING => [
                // Pasangan dikocok dan sisi kanan tidak menyertai kuncinya (§11).
                'left' => collect($data->get('pairs', []))->map(fn (array $row) => [
                    'key' => $row['key'],
                    'text' => $row['left'],
                ])->shuffle()->values()->all(),
                'right' => collect($data->get('pairs', []))->map(fn (array $row) => [
                    'key' => $row['key'],
                    'text' => $row['right'],
                ])->shuffle()->values()->all(),
            ],
            default => $data->all(),
        };
    }
}
