<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbQuestionRequest;
use App\Http\Resources\PpdbQuestionResource;
use App\Models\PpdbQuestion;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pertanyaan tambahan (scoped: global periode / program / gelombang).
 *
 * Pilihan jawaban dikelola bersama pertanyaan dalam satu transaksi: pilihan
 * baru dibuat, yang cocok di-update (berdasarkan id), yang hilang dihapus.
 * Resolver scope-nya tetap `PpdbScopeService` — controller ini hanya menulis
 * konfigurasinya.
 */
class PpdbQuestionController extends BaseApiController
{
    public function __construct(protected PpdbPeriodService $config)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = PpdbQuestion::query()->with(['options', 'program', 'wave']);

        if ($request->filled('period_id')) {
            $query->where('period_id', $request->integer('period_id'));
        }

        if ($request->filled('program_id')) {
            $query->where('program_id', $request->integer('program_id'));
        }

        if ($request->filled('wave_id')) {
            $query->where('wave_id', $request->integer('wave_id'));
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('question', 'like', "%{$search}%"));
        }

        $questions = $this->config->paginated(
            $request,
            $this->config->sorted($query, $request, ['code', 'question', 'order', 'created_at'], 'order', 'asc')
                ->orderBy('id')
        );

        return $this->successResponse(
            PpdbQuestionResource::collection($questions),
            'Daftar pertanyaan berhasil diambil'
        );
    }

    public function store(PpdbQuestionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $options = array_key_exists('options', $data) ? (array) $data['options'] : null;

        $question = DB::transaction(function () use ($data, $options): PpdbQuestion {
            $question = PpdbQuestion::create(collect($data)->except('options')->all());

            if ($options !== null) {
                $this->syncOptions($question, $options);
            }

            // Nilai bawaan kolom (active, required, order) baru terisi setelah dibaca ulang.
            return $question->refresh();
        });

        return $this->createdResponse(
            new PpdbQuestionResource($this->withRelations($question)),
            'Pertanyaan berhasil dibuat'
        );
    }

    public function show(PpdbQuestion $question): JsonResponse
    {
        return $this->successResponse(
            new PpdbQuestionResource($this->withRelations($question)),
            'Detail pertanyaan berhasil diambil'
        );
    }

    public function update(PpdbQuestionRequest $request, PpdbQuestion $question): JsonResponse
    {
        $data = $request->validated();
        $options = array_key_exists('options', $data) ? (array) $data['options'] : null;

        DB::transaction(function () use ($question, $data, $options): void {
            $question->fill(collect($data)->except('options')->all());
            $question->save();

            if ($options !== null) {
                $this->syncOptions($question, $options);
            }
        });

        return $this->successResponse(
            new PpdbQuestionResource($this->withRelations($question)),
            'Pertanyaan berhasil diperbarui'
        );
    }

    /**
     * Hapus pertanyaan. Jawaban pendaftar menautkan `question_id` dengan
     * `cascadeOnDelete`, jadi pertanyaan yang sudah dijawab tidak boleh dihapus.
     */
    public function destroy(PpdbQuestion $question): JsonResponse
    {
        $this->config->assertNotInUse(
            $this->config->questionAnswerUsage($question),
            'Pertanyaan',
            'jawaban pendaftar',
            'question'
        );

        $question->delete();

        return $this->successResponse(null, 'Pertanyaan berhasil dihapus');
    }

    /**
     * Sinkronkan pilihan jawaban: create / update (via id) / delete.
     *
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(PpdbQuestion $question, array $options): void
    {
        $existing = $question->options()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($options) as $index => $option) {
            $label = (string) ($option['label'] ?? '');

            if ($label === '') {
                continue;
            }

            $payload = [
                'label' => $label,
                'value' => filled($option['value'] ?? null) ? (string) $option['value'] : $label,
                'order' => (int) ($option['order'] ?? $index),
            ];

            $row = isset($option['id']) ? $existing->get((int) $option['id']) : null;

            if ($row === null) {
                $kept[] = $question->options()->create($payload)->id;
                continue;
            }

            $row->fill($payload);
            $row->save();
            $kept[] = $row->id;
        }

        if ($kept === []) {
            $question->options()->delete();

            return;
        }

        $question->options()->whereKeyNot($kept)->delete();
    }

    private function withRelations(PpdbQuestion $question): PpdbQuestion
    {
        $question->loadMissing(['options', 'period', 'program', 'wave']);

        return $question;
    }
}
