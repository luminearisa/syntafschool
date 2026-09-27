<?php

namespace Tests\Unit;

use App\Models\FinanceBudget;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinanceTransfer;
use App\Models\FinanceVendorInvoice;
use App\Services\Finance\FinanceStatusMachine;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

/**
 * §6: status machine Finance. Tabel transisi adalah satu-satunya daftar
 * "dokumen ini boleh pindah ke mana", jadi ia harus (a) lengkap terhadap
 * konstanta status model, dan (b) menolak perpindahan mundur yang tidak sah.
 */
class FinanceStatusMachineTest extends TestCase
{
    public function test_setiap_status_model_tercantum_pada_tabel(): void
    {
        foreach (FinanceStatusMachine::TRANSITIONS as $class => $rows) {
            foreach ($this->statusesOf($class) as $status) {
                $this->assertArrayHasKey(
                    $status,
                    $rows,
                    "$class::$status tidak punya baris transisi — status final pun harus terdaftar (kosong)."
                );
            }
        }
    }

    public function test_setiap_status_tujuan_terkenal_dan_punya_baris_sendiri(): void
    {
        foreach (FinanceStatusMachine::TRANSITIONS as $class => $rows) {
            $statuses = $this->statusesOf($class);

            foreach ($rows as $from => $targets) {
                $this->assertContains($from, $statuses, "$class: status asal '$from' bukan konstanta model.");

                foreach ($targets as $target) {
                    $this->assertContains($target, $statuses, "$class: status tujuan '$target' bukan konstanta model.");
                    $this->assertArrayHasKey($target, $rows, "$class: tujuan '$target' tidak punya baris sendiri.");
                }
            }
        }
    }

    public function test_pembayaran_lunas_tidak_bisa_kembali_ke_pending(): void
    {
        $payment = new FinancePayment;
        $payment->status = FinancePayment::STATUS_PAID;

        $this->assertFalse(FinanceStatusMachine::can($payment, FinancePayment::STATUS_PENDING));
        $this->assertSame([
            FinancePayment::STATUS_CANCELLED,
            FinancePayment::STATUS_PARTIAL,
            FinancePayment::STATUS_REFUNDED,
        ], FinanceStatusMachine::allowed(FinancePayment::class, FinancePayment::STATUS_PAID));
    }

    public function test_assert_menolak_perpindahan_yang_tidak_sah(): void
    {
        $transfer = new FinanceTransfer;
        $transfer->status = FinanceTransfer::STATUS_DRAFT;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak diizinkan');

        FinanceStatusMachine::assert($transfer, FinanceTransfer::STATUS_COMPLETED);
    }

    /**
     * Mundur dari lunas hanya sah sebagai akibat pembalikan, dan tujuannya
     * tetap bukan `pending` — tagihan yang pernah lunas tidak pernah lagi
     * berstatus "belum dibayar" tanpa jejak pembayaran.
     */
    public function test_invoice_lunas_hanya_bisa_mundur_ke_sebagian_atau_terbit(): void
    {
        $invoice = new FinanceInvoice;
        $invoice->status = FinanceInvoice::STATUS_PAID;

        $this->assertSame([
            FinanceInvoice::STATUS_PARTIAL,
            FinanceInvoice::STATUS_ISSUED,
        ], FinanceStatusMachine::allowed(FinanceInvoice::class, FinanceInvoice::STATUS_PAID));
    }

    public function test_status_final_tidak_memiliki_penerus(): void
    {
        $this->assertSame([], FinanceStatusMachine::allowed(FinancePayment::class, FinancePayment::STATUS_CANCELLED));
        $this->assertSame([], FinanceStatusMachine::allowed(FinanceBudget::class, FinanceBudget::STATUS_CLOSED));
    }

    /**
     * Perpindahan satu-kali tidak punya self-loop, jadi permintaan kedua untuk
     * melunasi payment yang sama ditolak mesin sebelum ada jurnal kedua (§3).
     */
    public function test_perpindahan_satu_kali_menolak_permintaan_kedua(): void
    {
        $payment = new FinancePayment;
        $payment->status = FinancePayment::STATUS_PAID;

        $this->expectException(ValidationException::class);

        FinanceStatusMachine::assert($payment, FinancePayment::STATUS_PAID);
    }

    public function test_cicilan_invoice_vendor_boleh_dibayar_berulang(): void
    {
        $invoice = new FinanceVendorInvoice;
        $invoice->status = FinanceVendorInvoice::STATUS_PARTIALLY_PAID;

        $this->assertTrue(FinanceStatusMachine::can($invoice, FinanceVendorInvoice::STATUS_PARTIALLY_PAID));
    }

    public function test_anggaran_yang_ditolak_boleh_diajukan_ulang(): void
    {
        $budget = new FinanceBudget;
        $budget->status = FinanceBudget::STATUS_REJECTED;

        $this->assertTrue(FinanceStatusMachine::can($budget, FinanceBudget::STATUS_SUBMITTED));
    }

    public function test_pesan_assert_memakai_field_pemanggil(): void
    {
        $payment = new FinancePayment;
        $payment->status = FinancePayment::STATUS_EXPIRED;

        try {
            FinanceStatusMachine::assert($payment, FinancePayment::STATUS_PAID, 'payment');
            $this->fail('Perpindahan kedaluwarsa → lunas seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
            $this->assertArrayNotHasKey('status', $exception->errors());
        }
    }

    /**
     * @return list<string>
     */
    private function statusesOf(string $class): array
    {
        $statuses = [];

        foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_') && is_string($value)) {
                $statuses[] = $value;
            }
        }

        return $statuses;
    }
}
