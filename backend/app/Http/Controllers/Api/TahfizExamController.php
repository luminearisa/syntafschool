<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizExamRequest;
use App\Http\Resources\TahfizExamItemResource;
use App\Http\Resources\TahfizExamParticipantResource;
use App\Http\Resources\TahfizExamResource;
use App\Models\TahfizExam;
use App\Models\TahfizExamItem;
use App\Models\TahfizExamParticipant;
use App\Services\Tahfiz\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ujian Tahfiz dan pendaftarannya.
 *
 * Alur: buat ujian (draft) → daftarkan peserta → jadwalkan → butir butir
 * dibangkitkan dari hafalan peserta → penguji menilai tiap butir →
 * hasil dihitung dari butir (bukan dari input).
 */
class TahfizExamController extends BaseApiController
{
    protected array $filterable = [
        'program_id', 'halaqah_id', 'type', 'status', 'examiner_id', 'level_id',
    ];

    protected array $with = ['program', 'halaqah', 'rubric', 'level', 'examiner'];

    protected array $counts = ['participants'];

    public function __construct(protected ExamService $exams)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = TahfizExam::query()
            ->with($this->with)
            ->withCount($this->counts);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($from = $request->query('from')) {
            $query->whereDate('exam_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('exam_date', '<=', $to);
        }

        $query->orderBy('exam_date');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizExamResource::collection($query->get()),
                'Daftar ujian berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizExamResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar ujian berhasil diambil'
        );
    }

    public function show(TahfizExam $exam): JsonResponse
    {
        return $this->successResponse(
            new TahfizExamResource($exam->load($this->with)->loadCount($this->counts)),
            'Detail ujian berhasil diambil'
        );
    }

    public function store(TahfizExamRequest $request): JsonResponse
    {
        $exam = TahfizExam::create($request->validated());

        // Muat ulang: kolom `status` diisi default DB ("draft") saat insert,
        // sehingga model di memori masih null sampai dibaca dari basis data.
        $exam->refresh();

        return $this->createdResponse(
            new TahfizExamResource($exam->load($this->with)->loadCount($this->counts)),
            'Ujian berhasil dibuat.'
        );
    }

    public function update(TahfizExamRequest $request, TahfizExam $exam): JsonResponse
    {
        $exam->update($request->validated());

        return $this->successResponse(
            new TahfizExamResource($exam->load($this->with)->loadCount($this->counts)),
            'Ujian berhasil diperbarui.'
        );
    }

    public function destroy(TahfizExam $exam): JsonResponse
    {
        $exam->delete();

        return $this->successResponse(null, 'Ujian berhasil dihapus.');
    }

    /**
     * Transisi status ujian (draft → scheduled → ongoing → done/cancelled).
     */
    public function transition(Request $request, TahfizExam $exam): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', TahfizExam::STATUSES)],
        ]);

        $allowed = [
            'draft' => ['scheduled', 'cancelled'],
            'scheduled' => ['ongoing', 'cancelled'],
            'ongoing' => ['done', 'cancelled'],
            'done' => [],
            'cancelled' => ['draft'],
        ];

        $to = $validated['status'];
        if ($exam->status === $to) {
            throw ValidationException::withMessages(['status' => 'Ujian sudah berada di status ini.']);
        }

        if (! in_array($to, $allowed[$exam->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Perubahan status dari {$exam->status} ke {$to} tidak diizinkan.",
            ]);
        }

        $exam->update(['status' => $to]);

        return $this->successResponse(
            new TahfizExamResource($exam->load($this->with)->loadCount($this->counts)),
            'Status ujian berhasil diubah.'
        );
    }

    /**
     * Daftarkan peserta ke ujian.
     */
    public function registerParticipants(Request $request, TahfizExam $exam): JsonResponse
    {
        $validated = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['required', 'integer', 'exists:tahfiz_participants,id'],
        ]);

        $registered = $this->exams->registerParticipants($exam, $validated['participant_ids']);

        return $this->createdResponse(
            TahfizExamParticipantResource::collection($registered),
            count($registered).' peserta berhasil didaftarkan.'
        );
    }

    /**
     * Daftar peserta ujian beserta hasilnya.
     */
    public function participants(Request $request, TahfizExam $exam): JsonResponse
    {
        $rows = $exam->participants()
            ->with(['participant', 'items.surah'])
            ->orderBy('id')
            ->get();

        return $this->successResponse(
            TahfizExamParticipantResource::collection($rows),
            'Daftar peserta ujian berhasil diambil'
        );
    }

    /**
     * Bangkitkan butir ujian (ayat awal acak dari hafalan peserta).
     */
    public function generateItems(Request $request, TahfizExam $exam): JsonResponse
    {
        $validated = $request->validate([
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'item_types' => ['required', 'array', 'min:1'],
            'item_types.*' => ['required', 'string'],
            'count' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $participant = $exam->participants()
            ->where('participant_id', $validated['participant_id'])
            ->first();

        if ($participant === null) {
            throw ValidationException::withMessages([
                'participant_id' => 'Peserta belum terdaftar pada ujian ini.',
            ]);
        }

        $items = $this->exams->generateItems(
            $participant,
            $validated['item_types'],
            (int) ($validated['count'] ?? 10)
        );

        return $this->createdResponse(
            TahfizExamItemResource::collection(TahfizExamItem::query()->whereIn('id', collect($items)->pluck('id'))->with('surah')->get()),
            count($items).' butir ujian berhasil dibangkitkan.'
        );
    }

    /**
     * Menilai satu butir ujian.
     *
     * `$exam` wajib ada di tanda tangan meski tidak dipakai: dispatcher
     * controller meneruskan parameter rute secara posisional, sehingga
     * kalau hanya `$item` yang ditulis, `Request` yang disisipkan akan
     * menggesernya dan `$item` menerima id ujian dalam bentuk string.
     */
    public function scoreItem(Request $request, TahfizExam $exam, TahfizExamItem $item): JsonResponse
    {
        $validated = $request->validate([
            'actual_ayah_end' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'score' => ['required', 'numeric', 'min:0', 'max:1000'],
            'errors' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Jawaban tidak boleh melebihi jumlah ayat surah item ini.
        if (($validated['actual_ayah_end'] ?? null) !== null
            && $item->surah !== null
            && (int) $validated['actual_ayah_end'] > (int) $item->surah->ayah_count) {
            throw ValidationException::withMessages([
                'actual_ayah_end' => "Ayat terakhir \"{$item->surah->name}\" hanya sampai {$item->surah->ayah_count}.",
            ]);
        }

        $item->update([
            'actual_ayah_end' => $validated['actual_ayah_end'] ?? null,
            'score' => $validated['score'],
            'errors' => $validated['errors'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        return $this->successResponse(
            new TahfizExamItemResource($item->load('surah')),
            'Butir ujian berhasil dinilai.'
        );
    }

    /**
     * Hitung & simpan hasil akhir seorang peserta.
     */
    public function recordResult(Request $request, TahfizExam $exam, TahfizExamParticipant $examParticipant): JsonResponse
    {
        $validated = $request->validate([
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $participant = $this->exams->recordResult($examParticipant, $validated['feedback'] ?? null);

        return $this->successResponse(
            new TahfizExamParticipantResource($participant->load(['participant', 'items.surah', 'exam'])),
            'Hasil peserta berhasil dihitung dan disimpan.'
        );
    }
}
