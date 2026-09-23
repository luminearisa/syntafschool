<?php

namespace App\Http\Requests\Wallet;

use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pembuatan wallet oleh petugas (§4B).
 *
 * Payload memakai kolom pemilik apa adanya (`student_id` / `employee_id` /
 * `guardian_id` / `user_id`) karena skema wallet memang menyimpan empat FK
 * yang saling lepas. Aturan "tepat satu pemilik" ditegakkan di dua tempat:
 * di sini untuk pesan yang ramah, dan di `WalletService` untuk penelepon non-HTTP.
 */
class WalletStoreRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:wallet.create`. */
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
            'owner_type' => ['sometimes', 'nullable', 'string', Rule::in(Wallet::OWNER_TYPES)],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Satu wallet satu pemilik: nol pemilik ditolak, dua pemilik juga ditolak.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $filled = array_values(array_filter(
                    Wallet::OWNER_COLUMNS,
                    fn (string $column): bool => filled($this->input($column)),
                ));

                if (count($filled) > 1) {
                    $validator->errors()->add('owner_type', 'Satu wallet hanya boleh memiliki satu pemilik.');

                    return;
                }

                if ($filled === []) {
                    $validator->errors()->add(
                        'student_id',
                        'Pilih pemilik wallet: santri, karyawan, orang tua/wali, atau pengguna.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'owner_type' => 'tipe pemilik',
            'student_id' => 'santri',
            'employee_id' => 'karyawan',
            'guardian_id' => 'orang tua/wali',
            'user_id' => 'pengguna',
            'notes' => 'catatan internal',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_type.in' => 'Tipe pemilik harus salah satu dari: santri, karyawan, orang tua/wali, atau pengguna.',
            'student_id.exists' => 'Data santri tidak ditemukan. Periksa kembali pilihannya.',
            'employee_id.exists' => 'Data karyawan tidak ditemukan. Periksa kembali pilihannya.',
            'guardian_id.exists' => 'Data orang tua/wali tidak ditemukan. Periksa kembali pilihannya.',
            'user_id.exists' => 'Akun pengguna tidak ditemukan. Periksa kembali pilihannya.',
            'notes.max' => 'Catatan internal maksimal :max karakter.',
        ];
    }
}
