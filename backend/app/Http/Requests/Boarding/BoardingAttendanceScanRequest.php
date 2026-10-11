<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Scan kartu untuk absensi (§23, §25) — dua saluran dalam satu endpoint:
 *
 * - `uid` = kartu RFID Wallet (infrastruktur yang sudah ada);
 * - `code` = kode QR kamera, isinya NIS santri (sama dengan yang tercetak
 *   pada kartu QR absensi).
 *
 * Keduanya bermuara pada `BoardingAttendanceService::performScan()` dengan
 * dedupelikasi dan jadwal yang sama.
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
            'uid' => ['nullable', 'required_without:code', 'string', 'max:64'],
            'code' => ['nullable', 'required_without:uid', 'string', 'max:64'],
            'activity_id' => ['nullable', 'integer', Rule::exists(BoardingActivity::class, 'id')],
            'date' => ['nullable', 'date'],
            'session' => ['nullable', 'string', 'max:20'],
            'attendance_type_id' => ['nullable', 'integer', Rule::exists(\App\Models\BoardingAttendanceType::class, 'id')],
            'device' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'uid' => 'nomor kartu RFID',
            'code' => 'kode QR',
            'activity_id' => 'kegiatan',
            'attendance_type_id' => 'status absensi',
            'device' => 'perangkat pemindai',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uid.required_without' => 'Kirim nomor kartu RFID (uid) atau kode QR (code).',
            'code.required_without' => 'Kirim nomor kartu RFID (uid) atau kode QR (code).',
        ];
    }
}
