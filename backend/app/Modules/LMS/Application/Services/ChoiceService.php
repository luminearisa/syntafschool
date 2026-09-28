<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Enums\ChoiceResultsVisibility;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsChoice;
use App\Modules\LMS\Models\LmsChoiceOption;
use App\Modules\LMS\Models\LmsChoiceResponse;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Polling sederhana (§38). */
class ChoiceService implements ActivityConfiguratorInterface
{
    use LogsLmsAudit;

    public function __construct(private readonly ActivityCompletionService $completion) {}

    public function createForActivity(LmsActivity $activity, array $data): void
    {
        $choice = LmsChoice::create([
            'activity_id' => $activity->id,
            'allow_multiple' => $data['allow_multiple'] ?? false,
            'limit_responses' => $data['limit_responses'] ?? 1,
            'results_visibility' => $data['results_visibility'] ?? ChoiceResultsVisibility::AFTER_RESPONSE->value,
            'anonymous' => $data['anonymous'] ?? false,
            'show_available_choices' => $data['show_available_choices'] ?? false,
            'settings' => $data['choice_settings'] ?? null,
        ]);

        foreach ((array) ($data['options'] ?? []) as $index => $option) {
            $choice->options()->create([
                'text' => is_array($option) ? (string) $option['text'] : (string) $option,
                'limit' => is_array($option) ? ($option['limit'] ?? null) : null,
                'sort_order' => is_array($option) ? ($option['sort_order'] ?? $index + 1) : $index + 1,
            ]);
        }
    }

    public function updateForActivity(LmsActivity $activity, array $data): void
    {
        $choice = $this->config($activity);

        if ($choice === null) {
            return;
        }

        $choice->fill(collect($data)->only([
            'allow_multiple', 'limit_responses', 'results_visibility', 'anonymous', 'show_available_choices',
        ])->all());

        if (array_key_exists('choice_settings', $data)) {
            $choice->settings = $data['choice_settings'];
        }

        $choice->save();

        if (array_key_exists('options', $data)) {
            $keptIds = [];

            foreach (array_values((array) $data['options']) as $index => $option) {
                $attributes = [
                    'text' => is_array($option) ? (string) $option['text'] : (string) $option,
                    'limit' => is_array($option) ? ($option['limit'] ?? null) : null,
                    'sort_order' => $index + 1,
                ];

                $id = is_array($option) ? ($option['id'] ?? null) : null;

                if ($id !== null) {
                    $choice->options()->whereKey($id)->update($attributes);
                    $keptIds[] = (int) $id;

                    continue;
                }

                $keptIds[] = (int) $choice->options()->create($attributes)->id;
            }

            $choice->options()->whereNotIn('id', $keptIds)->delete();
        }
    }

    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void
    {
        $choice = $this->config($source);

        if ($choice === null) {
            return;
        }

        // Jawaban tidak disalin (§44).
        $this->createForActivity($copy, array_merge($choice->toArray(), [
            'choice_settings' => $choice->settings,
            'options' => $choice->options->map(fn (LmsChoiceOption $option) => [
                'text' => $option->text,
                'limit' => $option->limit,
                'sort_order' => $option->sort_order,
            ])->all(),
        ]));
    }

    public function config(LmsActivity $activity): ?LmsChoice
    {
        return $activity->choice ?? $activity->load('choice')->choice;
    }

    public function ensureConfig(LmsActivity $activity): LmsChoice
    {
        $choice = $this->config($activity);

        if ($choice === null) {
            $this->createForActivity($activity, []);
            $choice = $this->config($activity->fresh('choice'));
        }

        return $choice;
    }

    /**
     * Tampilan polling untuk siswa (§38) — hasil mengikuti konfigurasi.
     *
     * @return array<string, mixed>
     */
    public function view(LmsActivity $activity, ?int $userId): array
    {
        $choice = $this->ensureConfig($activity);

        $myResponses = $userId === null
            ? collect()
            : LmsChoiceResponse::query()->where('choice_id', $choice->id)->where('user_id', $userId)->get();

        $hasResponded = $myResponses->isNotEmpty();
        $resultsVisible = $choice->resultsVisible($hasResponded);

        return [
            'choice' => $this->configPayload($choice),
            'options' => $choice->options->map(fn (LmsChoiceOption $option) => [
                'id' => (int) $option->id,
                'text' => (string) $option->text,
                'limit' => $option->limit === null ? null : (int) $option->limit,
                'sort_order' => (int) $option->sort_order,
            ])->all(),
            'my_response' => $myResponses->pluck('option_id')->map(fn ($id) => (int) $id)->all(),
            'has_responded' => $hasResponded,
            'responses_used' => $myResponses->count(),
            'results' => $resultsVisible ? $this->results($choice) : null,
            'results_visible' => $resultsVisible,
        ];
    }

    /**
     * Simpan jawaban polling (§38).
     *
     * @param  list<int>  $optionIds
     */
    public function respond(LmsActivity $activity, LmsEnrollment $enrollment, array $optionIds, ?int $studentId = null): array
    {
        $choice = $this->ensureConfig($activity);
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));

        if ($optionIds === []) {
            throw ValidationException::withMessages(['options' => 'Pilih minimal satu jawaban.']);
        }

        if (! $choice->allow_multiple && count($optionIds) > 1) {
            throw ValidationException::withMessages(['options' => 'Polling ini hanya menerima satu pilihan.']);
        }

        $valid = $choice->options()->whereIn('id', $optionIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (count($valid) !== count($optionIds)) {
            throw ValidationException::withMessages(['options' => 'Ada pilihan yang tidak dikenal pada polling ini.']);
        }

        $used = LmsChoiceResponse::query()
            ->where('choice_id', $choice->id)
            ->where('user_id', $enrollment->user_id)
            ->count();

        $limit = $choice->responseLimit();

        if ($limit > 0 && $used + count($optionIds) > $limit) {
            throw ValidationException::withMessages([
                'options' => 'Batas pengisian polling ini adalah '.$limit.' pilihan.',
            ]);
        }

        return DB::transaction(function () use ($choice, $enrollment, $valid, $studentId, $activity): array {
            foreach ($valid as $optionId) {
                LmsChoiceResponse::create([
                    'choice_id' => $choice->id,
                    'option_id' => $optionId,
                    'user_id' => $enrollment->user_id,
                    'student_id' => $studentId ?? $enrollment->student_id,
                ]);
            }

            $this->completion->complete($activity, $enrollment, null, 'choice_responded', $enrollment->user_id, [
                'options' => $valid,
            ]);

            $this->audit('LMS_CHOICE_RESPONDED', $activity, [
                'options' => $valid,
                'user_id' => $enrollment->user_id,
            ]);

            return $this->view($activity, $enrollment->user_id);
        });
    }

    /**
     * Hasil polling: jumlah & persentase per pilihan (§38).
     *
     * @return array<string, mixed>
     */
    public function results(LmsChoice $choice): array
    {
        $counts = LmsChoiceResponse::query()
            ->where('choice_id', $choice->id)
            ->selectRaw('option_id, count(*) as total')
            ->groupBy('option_id')
            ->pluck('total', 'option_id');

        $total = (int) $counts->sum();

        return [
            'total_responses' => $total,
            'participants' => LmsChoiceResponse::query()->where('choice_id', $choice->id)->distinct()->count('user_id'),
            'options' => $choice->options->map(fn (LmsChoiceOption $option) => [
                'id' => (int) $option->id,
                'text' => (string) $option->text,
                'count' => (int) ($counts[$option->id] ?? 0),
                'percent' => $total > 0 ? round(((int) ($counts[$option->id] ?? 0)) / $total * 100, 2) : 0.0,
            ])->all(),
            'anonymous' => (bool) $choice->anonymous,
        ];
    }

    /** @return array<string, mixed> */
    public function configPayload(LmsChoice $choice): array
    {
        return [
            'id' => (int) $choice->id,
            'allow_multiple' => (bool) $choice->allow_multiple,
            'limit_responses' => (int) $choice->limit_responses,
            'results_visibility' => $choice->results_visibility?->value,
            'results_visibility_label' => $choice->results_visibility?->label(),
            'anonymous' => (bool) $choice->anonymous,
            'show_available_choices' => (bool) $choice->show_available_choices,
            'settings' => $choice->settings,
        ];
    }

    /** Statistik polling untuk guru. */
    public function statistics(LmsActivity $activity): array
    {
        $choice = $this->ensureConfig($activity);

        $participants = LmsEnrollment::query()
            ->where('course_id', $activity->course_id)
            ->where('role', 'STUDENT')
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->count();

        $results = $this->results($choice);

        return array_merge($results, [
            'participants' => $participants,
            'response_rate' => $participants > 0 ? round($results['participants'] / $participants * 100, 2) : null,
        ]);
    }
}
