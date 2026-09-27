<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\FinanceOperationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Memanggil satu pekerjaan berat lewat antrian (§28).
 *
 * Yang divalidasi di sini hanya *nama* pekerjaan. `payload` sengaja tidak
 * diratakan per jenis pekerjaan: isinya milik pekerjaan itu sendiri, dan setiap
 * job sudah menjaga dirinya (mis. `GenerateBillingsJob` menolak periode atau
 * daftar santri yang kosong). Yang tidak boleh terjadi adalah nama dari luar
 * dipetakan ke kelas mana pun — itu sebabnya daftarnya whitelist, bukan string
 * bebas.
 */
class FinanceOperationDispatchRequest extends FormRequest
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
            'job' => ['required', 'string', Rule::in(array_keys(FinanceOperationService::JOB_CLASSES))],
            'payload' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'job' => 'jenis pekerjaan',
            'payload' => 'argumen pekerjaan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'job.in' => 'Jenis pekerjaan harus salah satu dari: '
                .implode(', ', array_keys(FinanceOperationService::JOB_CLASSES)).'.',
        ];
    }
}
