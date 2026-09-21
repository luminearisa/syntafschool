<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbParentAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah status akun orang tua (admin).
 *
 * Akun nonaktif / diblokir / menunggu verifikasi otomatis menjadi read-only di
 * portal karena `PpdbParentAccount::canTransact()` bernilai false.
 */
class PpdbParentAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Terima `status` sebagai alias `account_status`.
        if (! $this->has('account_status') && $this->has('status')) {
            $this->merge(['account_status' => $this->input('status')]);
        }

        if (! $this->has('notes') && $this->has('note')) {
            $this->merge(['notes' => $this->input('note')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_status' => ['required', 'string', Rule::in(PpdbParentAccount::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_status' => 'status akun',
            'notes' => 'catatan admin',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_status.required' => 'Status akun wajib dipilih.',
            'account_status.in' => 'Status akun harus salah satu: '
                .implode(', ', PpdbParentAccount::STATUSES).'.',
        ];
    }
}
