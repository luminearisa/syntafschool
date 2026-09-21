<?php

namespace App\Http\Requests\Ppdb;

use App\Services\Ppdb\PpdbReportService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter bersama untuk dashboard, laporan JSON, ekspor CSV, dan PDF PPDB.
 *
 * Satu-duanya filter dipakai seluruh agregat supaya angka dashboard dan isi
 * laporan tidak berbeda: periode, gelombang, program, rentang tanggal, dan
 * status. Param status boleh dikirim sebagai daftar yang dipisah koma
 * (`status=submitted,under_review`) agar tombol "pending actions" di dashboard
 * dapat langsung dipakai sebagai query deep-link.
 *
 * Daftar nilai sah, dasar tanggal, granularitas, dan batas per halaman diambil
 * dari `PpdbReportService` supaya hanya ada satu sumber kebenaran.
 *
 * Otorisasi TIDAK di sini — rute modul PPDB sudah memasang middleware
 * `permission:ppdb.report.view` / `permission:ppdb.report.export`.
 */
class PpdbReportFilterRequest extends FormRequest
{
    /**
     * Param bernilai status beserta daftar nilainya yang sah.
     *
     * @var array<string, list<string>>
     */
    public const LIST_PARAMS = PpdbReportService::LIST_PARAMS;

    /** Kolom tempat `date_from`/`date_to` diterapkan pada entitas induk. */
    public const DATE_BASES = PpdbReportService::DATE_BASES;

    public const GRANULARITIES = PpdbReportService::GRANULARITIES;

    public const DEFAULT_PER_PAGE = PpdbReportService::DEFAULT_PER_PAGE;

    public const MAX_PER_PAGE = PpdbReportService::MAX_PER_PAGE;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi awal: "a,b" → ["a","b"] dan `period_id=all` → cakupan semua
     * periode, sehingga aturan validasi cukup memakai `array`.
     */
    public function prepareForValidation(): void
    {
        $merged = [];

        foreach (array_keys(self::LIST_PARAMS) as $param) {
            $value = $this->input($param);

            if (is_string($value) && trim($value) !== '') {
                $merged[$param] = array_values(array_filter(
                    array_map(static fn (string $item) => trim($item), explode(',', $value)),
                    static fn (string $item) => $item !== ''
                ));
            }
        }

        $period = $this->input('period_id');

        if (is_string($period) && strtolower(trim($period)) === 'all') {
            $merged['period_id'] = null;
            $merged['all_periods'] = true;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'period_id' => ['nullable', 'integer', 'exists:ppdb_periods,id'],
            'all_periods' => ['nullable', 'boolean'],
            'wave_id' => ['nullable', 'integer', 'exists:ppdb_waves,id'],
            'program_id' => ['nullable', 'integer', 'exists:ppdb_programs,id'],
            'stage_id' => ['nullable', 'integer', 'exists:ppdb_selection_stages,id'],
            'requirement_id' => ['nullable', 'integer', 'exists:ppdb_document_requirements,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'date_basis' => ['nullable', Rule::in(self::DATE_BASES)],
            'keyword' => ['nullable', 'string', 'max:100'],
            'granularity' => ['nullable', Rule::in(self::GRANULARITIES)],
            'include_missing' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];

        foreach (self::LIST_PARAMS as $param => $allowed) {
            // Aturan penutup dipasang pada param itu sendiri (bukan `param.*`)
            // agar klien cukup membaca `errors.status`, bukan `errors.status.0`.
            $rules[$param] = [
                'nullable',
                'array',
                'max:'.count($allowed),
                function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
                    foreach ((array) $value as $item) {
                        if (! is_string($item) || ! in_array($item, $allowed, true)) {
                            $fail('Nilai :attribute tidak dikenali.');
                        }
                    }
                },
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'wave_id' => 'gelombang',
            'program_id' => 'program',
            'stage_id' => 'tahap seleksi',
            'requirement_id' => 'persyaratan dokumen',
            'date_from' => 'tanggal awal',
            'date_to' => 'tanggal akhir',
            'date_basis' => 'dasar tanggal',
            'keyword' => 'kata kunci',
            'granularity' => 'granularitas tren',
            'per_page' => 'jumlah baris per halaman',
            'status' => 'status pendaftaran',
            'payment_status' => 'status pembayaran pendaftaran',
            'document_status' => 'status kelengkapan dokumen',
            'selection_result' => 'hasil seleksi',
            'invoice_status' => 'status tagihan',
            'participant_status' => 'status peserta seleksi',
            'review_status' => 'status tinjau dokumen',
            'account_status' => 'status akun orang tua',
            'applicant_status' => 'status calon',
        ];
    }

    /**
     * Filter siap pakai (hanya param yang dikenal) untuk service laporan.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->only([
            'period_id', 'all_periods', 'wave_id', 'program_id', 'stage_id', 'requirement_id',
            'date_from', 'date_to', 'date_basis', 'keyword', 'granularity', 'include_missing',
            ...array_keys(self::LIST_PARAMS),
        ]);
    }

    public function page(): int
    {
        return max(1, (int) ($this->input('page') ?: 1));
    }

    public function perPage(): int
    {
        $perPage = (int) ($this->input('per_page') ?: self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
