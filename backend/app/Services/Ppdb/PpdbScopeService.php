<?php

namespace App\Services\Ppdb;

use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbFormField;
use App\Models\PpdbProgram;
use App\Models\PpdbQuestion;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationFee;
use App\Models\PpdbWave;
use Illuminate\Support\Collection;

/**
 * Resolver isi formulir pendaftaran.
 *
 * Konfigurasi PPDB bersifat data dan punya scope (periode / program / gelombang).
 * Service ini menjawab satu hal: "field, pertanyaan, dokumen, dan biaya mana yang
 * berlaku untuk kombinasi periode + program + gelombang ini?" — supaya controller
 * portal, publik, dan verifikasi tidak menyalin logika scope masing-masing.
 *
 * Aturan scope inklusif: aturan tanpa program/gelombang berlaku global untuk
 * periode tersebut; aturan berscope hanya berlaku bagi pilihan yang cocok.
 */
class PpdbScopeService
{
    /**
     * @param  int|null  $programId  id PpdbProgram (penawaran PPDB)
     * @param  int|null  $waveId     id PpdbWave
     * @return array<string, mixed>
     */
    public function schemaFor(int $periodId, ?int $programId = null, ?int $waveId = null): array
    {
        $fields = $this->formFields($periodId);
        $questions = $this->questions($periodId, $programId, $waveId);
        $documents = $this->documentRequirements($periodId, $programId, $waveId);
        $fees = $this->fees($periodId, $programId, $waveId);

        return [
            'period_id' => $periodId,
            'program_id' => $programId,
            'wave_id' => $waveId,
            'sections' => (object) PpdbFormField::SECTION_LABELS,
            'sections_ordered' => $fields->pluck('section')->unique()->values()
                ->map(fn (string $key) => ['key' => $key, 'label' => PpdbFormField::SECTION_LABELS[$key] ?? $key])
                ->all(),
            'fields' => $fields->all(),
            'questions' => $questions->all(),
            'documents' => $documents->all(),
            'fees' => $fees->all(),
            'fee_total' => (float) $fees->sum('amount'),
        ];
    }

    public function schemaForRegistration(PpdbRegistration $registration): array
    {
        return $this->schemaFor(
            (int) $registration->period_id,
            $registration->program_id === null ? null : (int) $registration->program_id,
            $registration->wave_id === null ? null : (int) $registration->wave_id,
        );
    }

    /**
     * Field form yang aktif, terurut per section.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function formFields(int $periodId): Collection
    {
        return PpdbFormField::query()
            ->where('period_id', $periodId)
            ->active()
            ->orderBy('section')
            ->orderBy('order')
            ->get()
            ->map(fn (PpdbFormField $field) => [
                'id' => $field->id,
                'key' => $field->field_key,
                'label' => $field->label,
                'type' => $field->type,
                'section' => $field->section,
                'section_label' => PpdbFormField::SECTION_LABELS[$field->section] ?? $field->section,
                'placeholder' => $field->placeholder,
                'description' => $field->description,
                'required' => $field->required,
                'order' => $field->order,
                'options' => $this->normalizeOptions($field->options),
                'validation' => $field->validation ?? [],
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function questions(int $periodId, ?int $programId = null, ?int $waveId = null): Collection
    {
        return PpdbQuestion::query()
            ->with('options')
            ->where('period_id', $periodId)
            ->active()
            ->forScope($programId, $waveId)
            ->orderBy('order')
            ->get()
            ->map(fn (PpdbQuestion $question) => [
                'id' => $question->id,
                'code' => $question->code,
                'question' => $question->question,
                'description' => $question->description,
                'type' => $question->type,
                'required' => $question->required,
                'order' => $question->order,
                'program_id' => $question->program_id,
                'wave_id' => $question->wave_id,
                'options' => $question->options->map(fn ($option) => [
                    'label' => $option->label,
                    'value' => $option->value ?? $option->label,
                ])->values()->all(),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function documentRequirements(int $periodId, ?int $programId = null, ?int $waveId = null): Collection
    {
        return PpdbDocumentRequirement::query()
            ->where('period_id', $periodId)
            ->active()
            ->forScope($programId, $waveId)
            ->orderBy('order')
            ->get()
            ->map(fn (PpdbDocumentRequirement $requirement) => [
                'id' => $requirement->id,
                'code' => $requirement->code,
                'name' => $requirement->name,
                'description' => $requirement->description,
                'required' => $requirement->required,
                'order' => $requirement->order,
                'program_id' => $requirement->program_id,
                'wave_id' => $requirement->wave_id,
                'file_types' => $requirement->file_types,
                'allowed_extensions' => $requirement->allowedExtensions(),
                'max_size_kb' => $requirement->max_size_kb,
                'min_size_kb' => $requirement->min_size_kb,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function fees(int $periodId, ?int $programId = null, ?int $waveId = null): Collection
    {
        return PpdbRegistrationFee::query()
            ->where('period_id', $periodId)
            ->active()
            ->where(fn ($q) => $q->whereNull('program_id')->orWhere('program_id', $programId))
            ->where(fn ($q) => $q->whereNull('wave_id')->orWhere('wave_id', $waveId))
            ->orderBy('type')
            ->get()
            ->map(fn (PpdbRegistrationFee $fee) => [
                'id' => $fee->id,
                'type' => $fee->type,
                'name' => $fee->name,
                'amount' => (float) $fee->amount,
                'required' => $fee->required,
                'payment_deadline' => $fee->payment_deadline?->toDateString(),
                'program_id' => $fee->program_id,
                'wave_id' => $fee->wave_id,
            ]);
    }

    /**
     * Aturan validasi Laravel untuk jawaban dinamis.
     *
     * Bentuk input yang diterima portal:
     *   fields.{field_key}   → jawaban field form builder
     *   questions.{id}       → jawaban pertanyaan tambahan
     *
     * Field bertipe `file` sengaja dilewati: berkas unggahan calon dikelola
     * lewat modul dokumen (ppdb_applicant_documents), bukan sebagai jawaban JSON.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(int $periodId, ?int $programId = null, ?int $waveId = null): array
    {
        $rules = [];

        foreach ($this->formFields($periodId) as $field) {
            if ($field['type'] === 'file') {
                continue;
            }

            $rules['fields.'.$field['key']] = $this->rulesForField($field);
        }

        foreach ($this->questions($periodId, $programId, $waveId) as $question) {
            $rules['questions.'.$question['id']] = $this->rulesForQuestion($question);
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    public function rulesForField(array $field): array
    {
        return $this->rules($field['type'], (bool) $field['required'], $field['options'] ?? [], (array) ($field['validation'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    public function rulesForQuestion(array $question): array
    {
        return $this->rules($question['type'], (bool) $question['required'], $question['options'] ?? [], []);
    }

    /**
     * @param  list<array{label: string, value: string}>  $options
     * @return list<string>
     */
    private function rules(string $type, bool $required, array $options, array $extra): array
    {
        $presence = $required ? ['required'] : ['nullable'];
        $values = array_values(array_filter(array_map(
            static fn (array $option) => (string) ($option['value'] ?? ''),
            $options
        ), static fn (string $v) => $v !== ''));

        $rules = match ($type) {
            'textarea' => ['string', 'max:5000'],
            'number' => ['numeric'],
            'date' => ['date'],
            'email' => ['email', 'max:100'],
            'phone' => ['string', 'max:30'],
            'checkbox' => ['boolean'],
            'yes_no' => ['in:yes,no,1,0,true,false'],
            'multi_select' => ['array'],
            'select', 'radio', 'dropdown' => $values === [] ? ['string', 'max:150'] : ['string', 'in:'.implode(',', $values)],
            default => ['string', 'max:255'],
        };

        return [...$presence, ...$rules, ...$extra];
    }

    /**
     * Normalisasi opsi: menerima `["A","B"]` maupun `[{"label":"A","value":"a"}]`.
     *
     * @param  mixed  $options
     * @return list<array{label: string, value: string}>
     */
    public function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return collect($options)->map(static function ($option) {
            if (is_array($option)) {
                $label = (string) ($option['label'] ?? $option['value'] ?? '');

                return ['label' => $label, 'value' => (string) ($option['value'] ?? $label)];
            }

            return ['label' => (string) $option, 'value' => (string) $option];
        })->filter(static fn (array $o) => $o['label'] !== '')->values()->all();
    }

    /**
     * Ringkasan pilihan pendaftaran (untuk dropdown portal & halaman publik).
     *
     * @return array{programs: list<array<string, mixed>>, waves: list<array<string, mixed>>}
     */
    public function offerings(int $periodId): array
    {
        return [
            'programs' => PpdbProgram::query()
                ->where('period_id', $periodId)
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (PpdbProgram $program) => [
                    'id' => $program->id,
                    'code' => $program->code,
                    'name' => $program->name,
                    'quota' => $program->quota,
                    'description' => $program->description,
                    'education_level_id' => $program->education_level_id,
                ])->all(),
            'waves' => PpdbWave::query()
                ->where('period_id', $periodId)
                ->active()
                ->orderBy('priority')
                ->get()
                ->map(fn (PpdbWave $wave) => [
                    'id' => $wave->id,
                    'code' => $wave->code,
                    'name' => $wave->name,
                    'quota' => $wave->quota,
                    'priority' => $wave->priority,
                    'registration_start' => $wave->registration_start?->toDateString(),
                    'registration_end' => $wave->registration_end?->toDateString(),
                    'description' => $wave->description,
                ])->all(),
        ];
    }
}
