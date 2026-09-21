<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\QuranSurah;
use App\Models\TahfizMemorizationRecord;
use App\Models\TahfizMistakeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Setoran hafalan — pencatatan inti modul Tahfiz.
 *
 * Kesalahan dicatat sebagai larik bersarang `mistakes` (tipe, jumlah, ayat,
 * catatan) agar pola kesalahan peserta bisa dianalisis, bukan hanya skornya.
 */
class TahfizMemorizationRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enrollment_id' => ['nullable', 'integer', 'exists:tahfiz_enrollments,id'],
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'session_id' => ['nullable', 'integer', 'exists:tahfiz_sessions,id'],
            'date' => ['required', 'date'],
            'type' => ['required', 'string', 'in:'.implode(',', TahfizMemorizationRecord::TYPES)],
            'surah_id' => ['required', 'integer', 'exists:quran_surahs,id'],
            'ayah_start' => ['required', 'integer', 'min:1', 'max:6236'],
            'ayah_end' => ['required', 'integer', 'min:1', 'max:6236', 'gte:ayah_start'],
            'juz' => ['nullable', 'integer', 'min:1', 'max:30'],
            'page_start' => ['nullable', 'integer', 'min:1', 'max:604'],
            'page_end' => ['nullable', 'integer', 'min:1', 'max:604', 'gte:page_start'],
            'line_count' => ['nullable', 'integer', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fluency' => ['nullable', 'integer', 'min:1', 'max:5'],
            'tajwid' => ['nullable', 'integer', 'min:1', 'max:5'],
            'makhraj' => ['nullable', 'integer', 'min:1', 'max:5'],
            'teacher_note' => ['nullable', 'string', 'max:2000'],
            'participant_note' => ['nullable', 'string', 'max:2000'],
            'mistakes' => ['nullable', 'array'],
            'mistakes.*.mistake_type_id' => ['nullable', 'integer', 'exists:tahfiz_mistake_types,id'],
            'mistakes.*.type_label' => ['nullable', 'string', 'max:100'],
            'mistakes.*.ayah' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'mistakes.*.count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'mistakes.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $surahId = (int) $this->input('surah_id');
                $surah = QuranSurah::query()->whereKey($surahId)->first();

                if ($surah !== null) {
                    $end = (int) $this->input('ayah_end');
                    if ($end > (int) $surah->ayah_count) {
                        $validator->errors()->add(
                            'ayah_end',
                            "Surah {$surah->name} hanya memiliki {$surah->ayah_count} ayat."
                        );
                    }
                }

                // Salah satu identifikasi tipe kesalahan harus ada.
                $mistakes = (array) $this->input('mistakes', []);
                foreach ($mistakes as $i => $mistake) {
                    if (empty($mistake['mistake_type_id']) && empty($mistake['type_label'])) {
                        $validator->errors()->add(
                            "mistakes.{$i}.mistake_type_id",
                            'Tipe kesalahan atau labelnya harus diisi.'
                        );
                    }
                }
            },
        ];
    }

    /**
     * Mistakes yang siap disimpan, lengkap dengan label cadangan.
     *
     * @return list<array<string, mixed>>
     */
    public function mistakesPayload(): array
    {
        $typeLabels = TahfizMistakeType::query()
            ->whereIn('id', collect((array) $this->input('mistakes', []))
                ->pluck('mistake_type_id')
                ->filter()
                ->all())
            ->pluck('label', 'id');

        return collect((array) $this->input('mistakes', []))
            ->map(function (array $m) use ($typeLabels): array {
                $typeId = $m['mistake_type_id'] ?? null;

                return [
                    'mistake_type_id' => $typeId ?: null,
                    // Label disalin agar rekam jejak tetap terbaca walau tipe diubah nanti.
                    'type_label' => $m['type_label'] ?? ($typeId ? ($typeLabels[$typeId] ?? null) : null),
                    'ayah' => $m['ayah'] ?? null,
                    'count' => (int) ($m['count'] ?? 1),
                    'note' => $m['note'] ?? null,
                ];
            })
            ->all();
    }

    public function attributes(): array
    {
        return [
            'enrollment_id' => 'enrollment',
            'participant_id' => 'peserta',
            'halaqah_id' => 'halaqah',
            'session_id' => 'sesi',
            'date' => 'tanggal',
            'type' => 'jenis setoran',
            'surah_id' => 'surah',
            'ayah_start' => 'ayat awal',
            'ayah_end' => 'ayat akhir',
            'juz' => 'juz',
            'page_start' => 'halaman awal',
            'page_end' => 'halaman akhir',
            'line_count' => 'jumlah baris',
            'duration_minutes' => 'durasi (menit)',
            'score' => 'skor',
            'fluency' => 'kelancaran',
            'tajwid' => 'tajwid',
            'makhraj' => 'makhraj',
            'teacher_note' => 'catatan guru',
            'participant_note' => 'catatan peserta',
            'mistakes' => 'kesalahan',
            'mistakes.*.mistake_type_id' => 'tipe kesalahan',
            'mistakes.*.type_label' => 'label kesalahan',
            'mistakes.*.ayah' => 'ayat kesalahan',
            'mistakes.*.count' => 'jumlah kesalahan',
            'mistakes.*.note' => 'catatan kesalahan',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'date.required' => 'Tanggal setoran wajib diisi.',
            'type.required' => 'Jenis setoran wajib dipilih.',
            'type.in' => 'Jenis setoran tidak valid.',
            'surah_id.required' => 'Surah wajib dipilih.',
            'surah_id.exists' => 'Surah tidak ditemukan.',
            'ayah_start.required' => 'Ayat awal wajib diisi.',
            'ayah_end.required' => 'Ayat akhir wajib diisi.',
            'ayah_end.gte' => 'Ayat akhir tidak boleh sebelum ayat awal.',
            'page_end.gte' => 'Halaman akhir tidak boleh sebelum halaman awal.',
        ];
    }
}
