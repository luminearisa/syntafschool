<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceCollectionAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mencatat satu aksi penagihan (§6).
 *
 * Perhatikan apa yang TIDAK ada di sini: tidak ada nominal, tidak ada status
 * piutang, tidak ada sisa tagihan. Halaman collection hanya mencatat tindakan
 * (§6 melarang mengubah outstanding dari sana); angka piutang bergerak lewat
 * pembayaran, penyesuaian, dan write-off yang punya alur approve-nya sendiri.
 */
class FinanceCollectionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'action_type' => ['required', 'string', Rule::in(FinanceCollectionAction::TYPES)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
            'reference_type' => ['nullable', 'string', Rule::in(FinanceCollectionAction::REFERENCE_TYPES)],
            // Tabel tujuan tergantung jenis dokumen (penyesuaian §51 atau rencana
            // pembayaran §7), jadi keberadaannya diperiksa service — di situlah
            // diketahui tabel mana yang harus dituju dan apakah dokumennya milik
            // invoice yang sama.
            'reference_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action_type' => 'jenis aksi',
            'occurred_at' => 'waktu aksi',
            'reference_id' => 'dokumen hasil aksi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action_type.in' => 'Jenis aksi penagihan harus salah satu dari: '.implode(', ', FinanceCollectionAction::TYPES).'.',
        ];
    }
}
