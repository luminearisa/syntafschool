<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Jam operasional mingguan (bulk replace).
 *
 * Beberapa baris untuk hari yang sama diperbolehkan — itulah cara menyatakan
 * beberapa interval dalam sehari. Interval pada hari yang sama tidak boleh
 * saling tumpang tindih.
 */
class LibraryOperatingHoursRequest extends FormRequest
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
            'hours' => ['required', 'array', 'min:1'],
            'hours.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.is_open' => ['required', 'boolean'],
            'hours.*.open_time' => ['nullable', 'date_format:H:i'],
            'hours.*.close_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $rows = (array) $this->input('hours', []);

                foreach ($rows as $index => $row) {
                    $isOpen = (bool) ($row['is_open'] ?? false);

                    if (! $isOpen) {
                        continue;
                    }

                    if (empty($row['open_time'])) {
                        $validator->errors()->add("hours.{$index}.open_time", 'Jam buka wajib diisi untuk hari yang dibuka.');
                    }

                    if (empty($row['close_time'])) {
                        $validator->errors()->add("hours.{$index}.close_time", 'Jam tutup wajib diisi untuk hari yang dibuka.');
                    }

                    if (! empty($row['open_time']) && ! empty($row['close_time']) && $row['close_time'] <= $row['open_time']) {
                        $validator->errors()->add("hours.{$index}.close_time", 'Jam tutup harus setelah jam buka.');
                    }
                }

                $this->validateNoOverlap($validator, $rows);
            },
        ];
    }

    /**
     * Interval pada hari yang sama tidak boleh tumpang tindih.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function validateNoOverlap(Validator $validator, array $rows): void
    {
        $byDay = [];

        foreach ($rows as $index => $row) {
            if (! (bool) ($row['is_open'] ?? false) || empty($row['open_time']) || empty($row['close_time'])) {
                continue;
            }

            $byDay[(int) $row['day_of_week']][] = [
                'index' => $index,
                'open' => $row['open_time'],
                'close' => $row['close_time'],
            ];
        }

        foreach ($byDay as $intervals) {
            usort($intervals, fn (array $a, array $b) => strcmp($a['open'], $b['open']));

            for ($i = 1; $i < count($intervals); $i++) {
                if ($intervals[$i]['open'] < $intervals[$i - 1]['close']) {
                    $validator->errors()->add(
                        "hours.{$intervals[$i]['index']}.open_time",
                        'Interval jam operasional pada hari yang sama tidak boleh tumpang tindih.'
                    );
                }
            }
        }
    }
}