<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistrationFee;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Biaya PPDB.
 *
 * Biaya boleh discopkan ke program atau gelombang — inilah sebabnya gelombang
 * bisa memasang tarif berbeda. Scope yang dikirim harus seperiode.
 */
class PpdbFeeRequest extends FormRequest
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
        $isPost = $this->isMethod('post');

        return [
            'period_id' => [$isPost ? 'required' : 'sometimes', 'integer', 'exists:ppdb_periods,id'],
            'program_id' => ['nullable', 'integer', 'exists:ppdb_programs,id'],
            'wave_id' => ['nullable', 'integer', 'exists:ppdb_waves,id'],
            'type' => [$isPost ? 'required' : 'sometimes', 'string', Rule::in(PpdbRegistrationFee::TYPES)],
            'name' => [$isPost ? 'required' : 'sometimes', 'string', 'max:150'],
            'amount' => [$isPost ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:999999999999.99'],
            'required' => ['nullable', 'boolean'],
            'payment_deadline' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (app(PpdbPeriodService::class)->scopeErrors(
                    $this->periodId(),
                    filled($this->input('program_id')) ? (int) $this->input('program_id') : null,
                    filled($this->input('wave_id')) ? (int) $this->input('wave_id') : null,
                ) as $attribute => $message) {
                    $validator->errors()->add($attribute, $message);
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
            'period_id' => 'periode',
            'program_id' => 'program',
            'wave_id' => 'gelombang',
            'payment_deadline' => 'jatuh tempo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_id.required' => 'Periode wajib dipilih.',
            'period_id.exists' => 'Periode tidak ditemukan.',
            'program_id.exists' => 'Program PPDB terpilih tidak ada.',
            'wave_id.exists' => 'Gelombang terpilih tidak ada.',
            'type.required' => 'Jenis biaya wajib dipilih.',
            'type.in' => 'Jenis biaya tidak dikenali.',
            'name.required' => 'Nama biaya wajib diisi.',
            'amount.required' => 'Nominal biaya wajib diisi.',
            'amount.min' => 'Nominal biaya tidak boleh negatif.',
        ];
    }

    /** Periode efektif baris ini. */
    private function periodId(): ?int
    {
        if (filled($this->input('period_id'))) {
            return (int) $this->input('period_id');
        }

        return $this->route('fee')?->period_id === null ? null : (int) $this->route('fee')->period_id;
    }
}
