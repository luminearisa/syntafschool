<?php

namespace App\Services\Ppdb;

use App\Http\Resources\PpdbRegistrationAnswerResource;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationAnswer;

/**
 * Penyimpanan & pembacaan jawaban pendaftaran (field form builder + pertanyaan
 * tambahan).
 *
 * Kontrak input portal (sama dengan `PpdbScopeService::validationRules()`):
 *   fields.{field_key}   → jawaban field form builder
 *   questions.{id}       → jawaban pertanyaan tambahan
 *
 * Nilai tunggal disimpan pada `value` (teks), nilai jamak pada `value_json`.
 * Saat dibaca, nilai dikembalikan sesuai tipe field/pertanyaan aslinya supaya
 * frontend tidak perlu menebak (checkbox → bool, number → angka,
 * multi_select → larik).
 */
class PpdbAnswerService
{
    public function __construct(private readonly PpdbScopeService $scope)
    {
    }

    /**
     * Simpan jawaban (autosave). Key di luar scope pendaftaran diabaikan.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string|int, mixed>  $questions
     * @return array{saved: int, ignored: list<string>}
     */
    public function replace(PpdbRegistration $registration, array $fields = [], array $questions = []): array
    {
        $schema = $this->scope->schemaForRegistration($registration);

        $fieldTypes = $this->typeMap($schema['fields'], 'key', excludeFile: true);
        $questionTypes = $this->typeMap($schema['questions'], 'id');

        $saved = 0;
        $ignored = [];

        foreach ($fields as $key => $value) {
            if (! array_key_exists((string) $key, $fieldTypes)) {
                $ignored[] = 'fields.'.$key;

                continue;
            }

            if ($this->store($registration, ['field_key' => (string) $key], $value, $fieldTypes[(string) $key])) {
                $saved++;
            }
        }

        foreach ($questions as $id => $value) {
            if (! array_key_exists((int) $id, $questionTypes)) {
                $ignored[] = 'questions.'.$id;

                continue;
            }

            if ($this->store($registration, ['question_id' => (int) $id], $value, $questionTypes[(int) $id])) {
                $saved++;
            }
        }

        return ['saved' => $saved, 'ignored' => $ignored];
    }

    /**
     * Jawaban tersimpan dalam bentuk ter-key untuk UI.
     *
     * @param  array<string, mixed>|null  $schema
     * @return array{fields: array<string, mixed>, questions: array<string, mixed>, list: list<array<string, mixed>>}
     */
    public function keyed(PpdbRegistration $registration, ?array $schema = null, bool $withLabels = false): array
    {
        $schema ??= $this->scope->schemaForRegistration($registration);

        $fieldTypes = $this->typeMap($schema['fields'], 'key');
        $questionTypes = $this->typeMap($schema['questions'], 'id');
        $fieldLabels = $this->labelMap($schema['fields'], 'key');
        $questionLabels = $this->labelMap($schema['questions'], 'id');

        $fields = [];
        $questions = [];
        $list = [];

        foreach (PpdbRegistrationAnswer::query()->where('registration_id', $registration->id)->orderBy('id')->get() as $answer) {
            $isField = $answer->field_key !== null;

            $type = (string) ($isField
                ? ($fieldTypes[$answer->field_key] ?? 'text')
                : ($questionTypes[(int) $answer->question_id] ?? 'text'));

            $value = $this->castValue($type, $answer);

            if ($isField) {
                $fields[$answer->field_key] = $value;
            } else {
                $questions[(string) $answer->question_id] = $value;
            }

            $label = null;

            if ($withLabels) {
                $label = $isField
                    ? (string) ($fieldLabels[$answer->field_key] ?? $answer->field_key)
                    : (string) ($questionLabels[(int) $answer->question_id] ?? '');
            }

            $list[] = PpdbRegistrationAnswerResource::row($answer, $type, $value, $label);
        }

        return ['fields' => $fields, 'questions' => $questions, 'list' => $list];
    }

    /**
     * Data jawaban tersimpan dalam bentuk yang bisa divalidasi langsung dengan
     * aturan dari `PpdbScopeService::validationRules()`.
     *
     * @param  array<string, mixed>  $schema
     * @return array{fields: array<string, mixed>, questions: array<int, mixed>}
     */
    public function forValidation(PpdbRegistration $registration, array $schema): array
    {
        $keyed = $this->keyed($registration, $schema);

        $questions = [];
        foreach ($keyed['questions'] as $id => $value) {
            $questions[(int) $id] = $value;
        }

        return ['fields' => $keyed['fields'], 'questions' => $questions];
    }

    /**
     * Field wajib yang masih kosong.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $values
     * @return list<array{key: string, label: string, type: string, source: string}>
     */
    public function missingRequiredFields(array $schema, array $values): array
    {
        return $this->missing($schema['fields'], $values, 'key', 'field', skipFile: true);
    }

    /**
     * Pertanyaan wajib yang masih kosong.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string|int, mixed>  $values
     * @return list<array{key: string, label: string, type: string, source: string}>
     */
    public function missingRequiredQuestions(array $schema, array $values): array
    {
        $normalized = [];
        foreach ($values as $id => $value) {
            $normalized[(string) $id] = $value;
        }

        return $this->missing($schema['questions'], $normalized, 'id', 'question');
    }

    /**
     * Aturan autosave: "wajib" dilepas, pemeriksaan tipe tetap berjalan.
     *
     * @param  array<string, list<string>>  $rules
     * @return array<string, list<string>>
     */
    public static function typeOnly(array $rules): array
    {
        return collect($rules)->map(static function (array $rules) {
            $rules = array_values(array_filter(
                $rules,
                static fn (string $rule) => ! str_starts_with($rule, 'required'),
            ));

            return array_merge(['nullable'], $rules);
        })->all();
    }

    /**
     * @param  array{field_key?: string, question_id?: int}  $attributes
     */
    private function store(PpdbRegistration $registration, array $attributes, mixed $value, string $type): bool
    {
        $key = ['registration_id' => $registration->id] + $attributes;

        if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
            PpdbRegistrationAnswer::query()->where($key)->delete();

            return false;
        }

        if (is_array($value) || $type === 'multi_select') {
            PpdbRegistrationAnswer::query()->updateOrCreate($key, [
                'value' => null,
                'value_json' => array_values(array_map(
                    static fn (mixed $item): mixed => is_scalar($item) ? (string) $item : $item,
                    (array) $value,
                )),
            ]);

            return true;
        }

        PpdbRegistrationAnswer::query()->updateOrCreate($key, [
            'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            'value_json' => null,
        ]);

        return true;
    }

    private function castValue(string $type, PpdbRegistrationAnswer $answer): mixed
    {
        if ($answer->value_json !== null) {
            return $answer->value_json;
        }

        $raw = $answer->value;

        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'checkbox' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'multi_select' => array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                static fn (string $item): bool => $item !== '',
            )),
            'number' => is_numeric($raw) ? $raw + 0 : $raw,
            default => $raw,
        };
    }

    /**
     * Peta key/id → tipe.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string|int, string>
     */
    private function typeMap(array $items, string $keyField, bool $excludeFile = false): array
    {
        $map = [];

        foreach ($items as $item) {
            $key = $item[$keyField] ?? null;

            if ($key === null || ($excludeFile && ($item['type'] ?? '') === 'file')) {
                continue;
            }

            $map[is_int($key) ? $key : (string) $key] = (string) ($item['type'] ?? 'text');
        }

        return $map;
    }

    /**
     * Peta key/id → label.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string|int, string>
     */
    private function labelMap(array $items, string $keyField): array
    {
        $map = [];

        foreach ($items as $item) {
            $key = $item[$keyField] ?? null;

            if ($key === null) {
                continue;
            }

            $map[is_int($key) ? $key : (string) $key] = (string) ($item['label'] ?? $item['question'] ?? $key);
        }

        return $map;
    }

    /**
     * Item wajib yang nilainya kosong.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $values
     * @return list<array{key: string, label: string, type: string, source: string}>
     */
    private function missing(array $items, array $values, string $keyField, string $source, bool $skipFile = false): array
    {
        $missing = [];

        foreach ($items as $item) {
            if (! ($item['required'] ?? false) || ($skipFile && ($item['type'] ?? '') === 'file')) {
                continue;
            }

            $key = (string) ($item[$keyField] ?? '');
            $value = $values[$key] ?? null;

            if ($value === null || $value === '' || $value === [] || $value === false) {
                $missing[] = [
                    'key' => $key,
                    'label' => (string) ($item['label'] ?? $item['question'] ?? $key),
                    'type' => (string) ($item['type'] ?? 'text'),
                    'source' => $source,
                ];
            }
        }

        return $missing;
    }
}
