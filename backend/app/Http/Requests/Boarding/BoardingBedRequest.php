<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingBed;
use App\Models\BoardingRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui tempat tidur (§8). Nomor bed unik per kamar — dicek lewat
 * composite unique di database dan direplikasi di sini agar error datang
 * sebagai 422, bukan 500.
 */
class BoardingBedRequest extends FormRequest
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
        /** @var BoardingBed|null $current */
        $current = $this->route('bed');
        $roomId = (int) ($this->input('room_id') ?: $current?->room_id);

        return [
            'room_id' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer',
                Rule::exists(BoardingRoom::class, 'id'),
            ],
            'bed_number' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:20',
                Rule::unique(BoardingBed::class, 'bed_number')
                    ->where(fn ($q) => $q->where('room_id', $roomId))
                    ->ignore($current?->id),
            ],
            'label' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(BoardingBed::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_id' => 'kamar',
            'bed_number' => 'nomor bed',
            'label' => 'label bed',
            'status' => 'status bed',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bed_number.required' => 'Nomor bed wajib diisi, misalnya 01.',
            'bed_number.unique' => 'Nomor bed sudah dipakai di kamar yang sama.',
        ];
    }
}
