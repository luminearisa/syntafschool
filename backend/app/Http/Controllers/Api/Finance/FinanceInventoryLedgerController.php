<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\InventoryItem;
use App\Services\Finance\Accounting\InventoryLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mutasi persediaan yang sekaligus menjadi jurnal.
 *
 * Berbeda dari `/inventory/items/{item}/adjust` (yang mengubah stok saja), endpoint
 * di sini menuntut akun persediaan & akun lawannya, lalu memposting jurnalnya
 * sekaligus menggerakkan stok dalam satu transaksi. Dipakai untuk pembelian dan
 * pemakaian barang yang harus terlihat di buku besar.
 */
class FinanceInventoryLedgerController extends BaseApiController
{
    public function __construct(private readonly InventoryLedgerService $persediaan) {}

    public function masuk(Request $request, InventoryItem $item): JsonResponse
    {
        $data = $request->validate($this->rules(inbound: true));

        return $this->successResponse($this->persediaan->masuk($item, $data, $request->user()), 'Barang masuk dicatat beserta jurnalnya.');
    }

    public function keluar(Request $request, InventoryItem $item): JsonResponse
    {
        $data = $request->validate($this->rules(inbound: false));

        return $this->successResponse($this->persediaan->keluar($item, $data, $request->user()), 'Pemakaian barang dicatat beserta jurnalnya.');
    }

    /** @return array<string, mixed> */
    protected function rules(bool $inbound): array
    {
        return [
            'kuantiti' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'harga_satuan' => [$inbound ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'akun_persediaan_id' => ['required', 'integer', 'exists:finance_accounts,id'],
            'akun_sumber_id' => [$inbound ? 'required' : 'nullable', 'integer', 'exists:finance_accounts,id'],
            'akun_beban_id' => [$inbound ? 'nullable' : 'required', 'integer', 'exists:finance_accounts,id'],
            'tanggal' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'kode_unit' => ['nullable', 'string', 'exists:finance_business_units,code'],
            'kode_dana' => ['nullable', 'string', 'exists:finance_funds,kode_dana'],
        ];
    }
}
