<?php

namespace App\Http\Resources;

use App\Models\PpdbRegistrationAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu jawaban pendaftaran (field form atau pertanyaan tambahan).
 *
 * Nilai disimpan pada `value` (skalar) atau `value_json` (larik) dan dikembalikan
 * sudah sesuai tipe field-nya; karena tipe ada di konfigurasi formulir (bukan di
 * baris jawaban), pemanggil menyerahkan `$type`/`$value` — lihat
 * `PpdbAnswerService::keyed()`.
 *
 * @property PpdbRegistrationAnswer $resource
 */
class PpdbRegistrationAnswerResource extends JsonResource
{
    public function __construct(
        PpdbRegistrationAnswer $resource,
        private readonly string $type = 'text',
        private readonly mixed $value = null,
        private readonly ?string $label = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::row(
            $this->resource,
            $this->type,
            $this->value ?? $this->resource->typedValue(),
            $this->label,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(
        PpdbRegistrationAnswer $answer,
        string $type,
        mixed $value,
        ?string $label = null,
    ): array {
        return [
            'id' => $answer->id,
            'registration_id' => $answer->registration_id,
            'source' => $answer->field_key !== null ? 'field' : 'question',
            'field_key' => $answer->field_key,
            'question_id' => $answer->question_id,
            'type' => $type,
            'label' => $label,
            'value' => $value,
            'updated_at' => $answer->updated_at?->toIso8601String(),
        ];
    }
}
