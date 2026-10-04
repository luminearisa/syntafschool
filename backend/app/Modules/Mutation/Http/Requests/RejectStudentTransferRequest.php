<?php

namespace App\Modules\Mutation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penolakan permohonan mutasi: wajib menyertakan alasan.
 */
class RejectStudentTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'note' => 'alasan penolakan',
        ];
    }
}
