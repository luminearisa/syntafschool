<?php

namespace App\Http\Requests\Boarding;

use App\Services\Boarding\BoardingPlacementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penutupan penempatan non-mutasi (§9): lulus, keluar, dibatalkan.
 */
class BoardingPlacementEndRequest extends FormRequest
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
        return [
            'reason' => ['required', Rule::in(BoardingPlacementService::CLOSABLE_END_REASONS)],
            'end_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan penutupan',
            'end_date' => 'tanggal akhir',
        ];
    }
}
