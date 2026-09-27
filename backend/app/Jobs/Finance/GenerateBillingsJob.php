<?php

namespace App\Jobs\Finance;

use App\Models\FinanceFeePackage;
use App\Models\FinanceOperation;
use App\Models\Student;
use App\Services\Finance\FinanceBillingService;
use App\Services\Finance\FinanceOperationService;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Billing massal lewat antrian (§28): satu paket, banyak santri, satu periode.
 *
 * Perhitungannya tetap milik `FinanceBillingService::generateBulk()` — job ini
 * tidak menambah aturan apa pun. Yang ia tambahkan hanya dua hal yang tidak bisa
 * diberikan sebuah request sinkron: kemajuan selama ratusan santri ditagih
 * (baris selesai / baris total) dan fakta bahwa HTTP tidak menunggunya.
 *
 * Daftar santri diambil persis seperti endpoint generate sinkron (id yang dikirim,
 * tanpa filter tambahan) supaya jalur antrian dan jalur layar tidak pernah
 * menghasilkan isi tagihan yang berbeda.
 *
 * Idempotensi per (santri, paket, periode) adalah alasan `retryable()` pada
 * dasarnya true (§29): menjalankan ulang tagihan yang sama tidak melahirkan
 * tagihan kedua, hanya menuntaskan yang belum terbit.
 */
class GenerateBillingsJob extends FinanceQueueJob
{
    public static function operationName(): string
    {
        return FinanceOperation::JOB_BILLING_GENERATE;
    }

    protected function process(FinanceOperation $operation, FinanceOperationService $operations): array
    {
        $packageId = (int) ($this->payload['fee_package_id'] ?? 0);
        $periodKey = trim((string) ($this->payload['period_key'] ?? ''));
        $studentIds = array_values(array_unique(array_map(
            'intval',
            (array) ($this->payload['student_ids'] ?? []),
        )));

        if ($periodKey === '' || $studentIds === []) {
            throw ValidationException::withMessages([
                'payload' => 'Periode dan daftar santri wajib diisi.',
            ]);
        }

        $package = FinanceFeePackage::query()->find($packageId);

        if ($package === null) {
            throw ValidationException::withMessages(['fee_package_id' => 'Paket tagihan tidak ditemukan.']);
        }

        $students = Student::query()->whereIn('id', $studentIds)->orderBy('id')->get();

        if ($students->isEmpty()) {
            throw ValidationException::withMessages(['student_ids' => 'Tidak ada santri terpilih untuk ditagih.']);
        }

        $operations->progress($operation, 0, $students->count());

        $options = array_filter([
            'period_start' => $this->dateOrNull('period_start'),
            'period_end' => $this->dateOrNull('period_end'),
            'due_date' => $this->dateOrNull('due_date'),
            'notes' => filled($this->payload['notes'] ?? null) ? (string) $this->payload['notes'] : null,
            'cost_center_id' => filled($this->payload['cost_center_id'] ?? null)
                ? (int) $this->payload['cost_center_id']
                : null,
            'created_by' => $this->actor()?->id,
        ], fn (mixed $v): bool => $v !== null);

        $result = app(FinanceBillingService::class)
            ->generateBulk($package, $this->track($students, $operation, $operations), $periodKey, $options);

        return [
            'generated' => $result['generated'],
            'skipped' => $result['skipped'],
            'billings' => $result['billings'],
        ];
    }

    /**
     * Servisan santri yang melaporkan kemajuan tiap baris. Bentuknya generator
     * supaya `generateBulk()` tetap satu-satunya pemilik loop-nya.
     */
    private function track(iterable $students, FinanceOperation $operation, FinanceOperationService $operations): Generator
    {
        $total = $students instanceof \Countable ? $students->count() : (int) $operation->total_rows;
        $done = 0;

        foreach ($students as $student) {
            yield $student;

            $operations->progress($operation, ++$done, $total);
        }
    }

    private function dateOrNull(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return filled($value) ? Carbon::parse((string) $value)->toDateString() : null;
    }
}
