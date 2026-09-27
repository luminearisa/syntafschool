<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Keputusan atas keberesan finansial (§12): APPROVED atau WAIVED.
 *
 * Alasan wajib dan minLength-nya lebih panjang dari sekadar "5 karakter" karena
 * dua status inilah yang dipakai orang untuk menjelaskan pengecualian kepada
 * auditor dan kepada orang tua. Service tetap memotong spasi dan memeriksa
 * isinya; validasi di sini yang membuat jawabannya cepat dan jelas.
 */
class FinanceClearanceDecisionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan keputusan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Alasan keputusan minimal :min karakter: tulis tanggungan apa yang dikesampingkan dan mengapa.',
        ];
    }
}
