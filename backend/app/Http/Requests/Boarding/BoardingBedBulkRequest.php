<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat banyak bed sekaligus untuk satu kamar — mempercepat pengisian kamar
 * baru (§8) tanpa harus menekan "tambah" delapan kali.
 */
class BoardingBedBulkRequest extends FormRequest
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
            'room_id' => ['required', 'integer', Rule::exists(BoardingRoom::class, 'id')],
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'prefix' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_id' => 'kamar',
            'count' => 'jumlah bed',
            'prefix' => 'prefiks nomor',
        ];
    }
}
