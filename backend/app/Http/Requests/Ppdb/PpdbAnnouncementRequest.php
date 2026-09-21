<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbAnnouncement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengumuman PPDB.
 *
 * `channel` menyatakan niat saluran; pengiriman nyata tetap lewat kanal
 * database yang ada (lihat `PpdbAnnouncementService::publish()`).
 */
class PpdbAnnouncementRequest extends FormRequest
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
        $required = $isPost ? 'required' : 'sometimes';

        return [
            'period_id' => [$required, 'integer', 'exists:ppdb_periods,id'],
            'title' => [$required, 'string', 'max:200'],
            'body' => [$required, 'string', 'max:20000'],
            'channel' => ['nullable', 'string', Rule::in(PpdbAnnouncement::CHANNELS)],
            'status' => ['nullable', 'string', Rule::in([
                PpdbAnnouncement::STATUS_DRAFT,
                PpdbAnnouncement::STATUS_PUBLISHED,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_id.required' => 'Periode pengumuman wajib dipilih.',
            'period_id.exists' => 'Periode tidak ditemukan.',
            'title.required' => 'Judul pengumuman wajib diisi.',
            'body.required' => 'Isi pengumuman wajib diisi.',
            'channel.in' => 'Saluran pengumuman tidak dikenali.',
            'status.in' => 'Pengumuman hanya boleh disimpan sebagai draft atau diterbitkan langsung.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'title' => 'judul',
            'body' => 'isi pengumuman',
            'channel' => 'saluran',
        ];
    }
}
