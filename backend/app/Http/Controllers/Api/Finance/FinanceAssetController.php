<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\FinanceAsset;
use App\Services\Finance\Accounting\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aset tetap: pendaftaran, daftar, dan pelepasan.
 *
 * Penyusutannya tidak dijalankan dari sini, melainkan lewat
 * `FinanceDepreciationController` — supaya penyusutan selalu diproses per periode
 * untuk seluruh aset, dan tidak pernah menjadi tindakan per aset yang mudah
 * terlewat.
 */
class FinanceAssetController extends BaseApiController
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->assets->all($request->query()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return $this->createdResponse($this->assets->save($data, null, $request->user()), 'Aset tetap didaftarkan.');
    }

    public function show(int $id): JsonResponse
    {
        $asset = FinanceAsset::query()->with('depreciations')->findOrFail($id);

        return $this->successResponse([
            'asset' => $asset,
            'penyusutan_bulanan' => $asset->penyusutanBulanan(),
            'sisa_dapat_disusutkan' => $asset->sisaDapatDisusut(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $asset = FinanceAsset::query()->findOrFail($id);

        return $this->successResponse($this->assets->save($request->validate($this->rules(false)), $asset->id, $request->user()), 'Aset tetap diperbarui.');
    }

    public function dispose(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tanggal_pelepasan' => ['nullable', 'date'],
            'hasil_pelepasan' => ['required', 'numeric', 'min:0'],
            'akun_penerimaan_id' => ['nullable', 'integer', 'exists:finance_accounts,id'],
            'akun_laba_id' => ['nullable', 'integer', 'exists:finance_accounts,id'],
            'akun_rugi_id' => ['nullable', 'integer', 'exists:finance_accounts,id'],
        ]);

        $asset = FinanceAsset::query()->findOrFail($id);

        return $this->successResponse([
            'transaction' => $this->assets->dispose($asset, $data, $request->user()),
            'asset' => $asset->refresh(),
        ], 'Aset dilepas dan jurnalnya diposting.');
    }

    /** @return array<string, list<string|\Illuminate\Validation\Rules\Unique>> */
    protected function rules(bool $wajib = true): array
    {
        return [
            'kode_aset' => $wajib ? ['required', 'string', 'max:30'] : ['nullable', 'string', 'max:30'],
            'nama' => $wajib ? ['required', 'string', 'max:160'] : ['nullable', 'string', 'max:160'],
            'kategori' => ['nullable', 'string', 'max:60'],
            'tanggal_perolehan' => $wajib ? ['required', 'date'] : ['nullable', 'date'],
            'harga_perolehan' => $wajib ? ['required', 'numeric', 'min:0'] : ['nullable', 'numeric', 'min:0'],
            'nilai_residu' => ['nullable', 'numeric', 'min:0'],
            'umur_bulan' => $wajib ? ['required', 'integer', 'min:1', 'max:1200'] : ['nullable', 'integer', 'min:1', 'max:1200'],
            'metode' => ['nullable', 'in:garis_lurus,saldo_menurun'],
            'akun_aset_id' => $wajib ? ['required', 'integer', 'exists:finance_accounts,id'] : ['nullable', 'integer', 'exists:finance_accounts,id'],
            'akun_akumulasi_id' => $wajib ? ['required', 'integer', 'exists:finance_accounts,id'] : ['nullable', 'integer', 'exists:finance_accounts,id'],
            'akun_beban_depresiasi_id' => $wajib ? ['required', 'integer', 'exists:finance_accounts,id'] : ['nullable', 'integer', 'exists:finance_accounts,id'],
            'kode_unit' => ['nullable', 'string', 'exists:finance_business_units,code'],
            'kode_dana' => ['nullable', 'string', 'exists:finance_funds,kode_dana'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ];
    }
}
