<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbQuestion;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pertanyaan tambahan + pilihan jawabannya (nested).
 *
 * Scope pertanyaan: global periode (`program_id` & `wave_id` kosong), per
 * program, atau per gelombang. Scope yang dikirim harus milik periode yang
 * sama, dan id pilihan yang dikirim harus milik pertanyaan yang sedang diubah.
 */
class PpdbQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $question = $this->route('question');
        $isPost = $this->isMethod('post');

        return [
            'period_id' => [$isPost ? 'required' : 'sometimes', 'integer', 'exists:ppdb_periods,id'],
            'program_id' => ['nullable', 'integer', 'exists:ppdb_programs,id'],
            'wave_id' => ['nullable', 'integer', 'exists:ppdb_waves,id'],
            'code' => [
                $isPost ? 'required' : 'sometimes',
                'string', 'max:50',
                PpdbPeriodService::uniqueInPeriod('ppdb_questions', 'code', $this->periodId(), $question?->id),
            ],
            'question' => [$isPost ? 'required' : 'sometimes', 'string', 'max:250'],
            'description' => ['nullable', 'string'],
            'type' => [$isPost ? 'required' : 'sometimes', 'string', Rule::in(PpdbQuestion::TYPES)],
            'required' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0', 'max:65535'],

            // `options` dikirim ulang seluruhnya: tanpa key = tidak disentuh,
            // array kosong = hapus semua pilihan.
            'options' => ['sometimes', 'nullable', 'array'],
            'options.*.id' => ['nullable', 'integer', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:150'],
            'options.*.value' => ['nullable', 'string', 'max:100'],
            'options.*.order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->assertSinglePeriod($validator);
                $this->assertOptionsAreOwned($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'program_id' => 'program',
            'wave_id' => 'gelombang',
            'options.*.label' => 'label pilihan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_id.required' => 'Periode wajib dipilih.',
            'period_id.exists' => 'Periode tidak ditemukan.',
            'program_id.exists' => 'Program PPDB terpilih tidak ada.',
            'wave_id.exists' => 'Gelombang terpilih tidak ada.',
            'code.required' => 'Kode pertanyaan wajib diisi.',
            'code.unique' => 'Kode pertanyaan sudah dipakai pada periode ini.',
            'question.required' => 'Teks pertanyaan wajib diisi.',
            'type.in' => 'Jenis pertanyaan tidak dikenali.',
            'options.*.label.required' => 'Label pilihan wajib diisi.',
        ];
    }

    /** Pilihan yang dikirim harus milik pertanyaan ini (bukan pertanyaan lain). */
    private function assertOptionsAreOwned(Validator $validator): void
    {
        $question = $this->route('question');
        $ids = collect($this->input('options', []))
            ->pluck('id')
            ->filter()
            ->all();

        if ($ids === [] || $validator->errors()->isNotEmpty()) {
            return;
        }

        $owned = $question === null
            ? []
            : $question->options()->whereIn('id', $ids)->pluck('id')->all();

        if (array_diff(array_map('intval', $ids), array_map('intval', $owned)) !== []) {
            $validator->errors()->add('options', 'Pilihan jawaban tidak milik pertanyaan ini.');
        }
    }

    /** Program/gelombang harus seperiode dengan pertanyaan. */
    private function assertSinglePeriod(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        foreach (app(PpdbPeriodService::class)->scopeErrors(
            $this->periodId(),
            filled($this->input('program_id')) ? (int) $this->input('program_id') : null,
            filled($this->input('wave_id')) ? (int) $this->input('wave_id') : null,
        ) as $attribute => $message) {
            $validator->errors()->add($attribute, $message);
        }
    }

    /** Periode efektif baris ini. */
    private function periodId(): ?int
    {
        if (filled($this->input('period_id'))) {
            return (int) $this->input('period_id');
        }

        $question = $this->route('question');

        return $question?->period_id === null ? null : (int) $question->period_id;
    }
}
