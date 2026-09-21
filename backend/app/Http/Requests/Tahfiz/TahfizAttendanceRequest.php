<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pencatatan kehadiran peserta Tahfiz.
 *
 * Status izin/sakit wajib disertai keterangan. Satu peserta hanya boleh
 * memiliki satu catatan kehadiran per (sesi) atau per (halaqah, tanggal).
 */
class TahfizAttendanceRequest extends FormRequest
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
            'session_id' => ['nullable', 'integer', 'exists:tahfiz_sessions,id'],
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:'.implode(',', \App\Models\TahfizAttendance::STATUSES)],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'permission_reason' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'session_id' => 'sesi',
            'halaqah_id' => 'halaqah',
            'participant_id' => 'peserta',
            'date' => 'tanggal',
            'status' => 'status kehadiran',
            'arrival_time' => 'jam datang',
            'permission_reason' => 'keterangan izin',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'status.in' => 'Status harus salah satu: hadir, izin, sakit, alpa, terlambat.',
        ];
    }

    /**
     * Izin & sakit wajib ada keterangan.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $status = $this->input('status');
                if (in_array($status, ['izin', 'sakit'], true) && blank($this->input('permission_reason'))) {
                    $validator->errors()->add(
                        'permission_reason',
                        "Keterangan wajib diisi untuk status {$status}."
                    );
                }
            },
        ];
    }
}
