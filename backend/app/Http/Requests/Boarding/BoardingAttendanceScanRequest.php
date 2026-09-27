<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Scan kartu RFID untuk absensi (§23, §25). Kartu memakai infrastruktur
 * Wallet yang sudah ada — tidak ada tabel kartu baru.
 */
class BoardingAttendanceScanRequest extends FormRequest
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
            'uid' => ['required', 'string', 'max:64'],
            'activity_id' => ['nullable', 'integer', Rule::exists(BoardingActivity::class, 'id')],
            'date' => ['nullable', 'date'],
            'session' => ['nullable', 'string', 'max:20'],
            'device' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'uid' => 'nomor kartu',
            'activity_id' => 'kegiatan',
            'device' => 'perangkat pemindai',
        ];
    }
}
