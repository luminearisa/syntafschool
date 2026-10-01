<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAuditService;
use App\Modules\Asset\Application\Services\AssetQrService;
use App\Modules\Asset\Application\Services\AssetTagService;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * QR & label aset.
 *
 * `lookup` sengaja TIDAK memerlukan otentikasi (yang memindai label harus
 * mendapat jawaban), dan justru karena itu isinya dibatasi keras: hanya kode,
 * nama, lokasi, kondisi, dan status. Tidak ada angka keuangan, tidak ada nomor
 * seri — dan pencariannya tetap dibatasi laju.
 */
class AssetQrController extends AssetControllerBase
{
    public function __construct(
        private readonly AssetQrService $qr,
        private readonly AssetTagService $tags,
        private readonly AssetAuditService $audit,
    ) {}

    public function lookup(Request $request, string $code): JsonResponse
    {
        $payload = $this->qr->lookup($code, withDetail: true);

        if ($payload === null) {
            return response()->json(['message' => 'Kode tidak dikenali.', 'data' => null], 404);
        }

        return $this->ok($payload, 'Data aset (tanpa informasi keuangan).');
    }

    public function label(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $this->audit->logAccess(AuditAction::ASSET_PRINTED, $asset, true, 'Label dicetak/dilihat.', $this->actor($request));

        return $this->ok($this->qr->labelPayload($asset), 'Isi label aset.');
    }

    public function markPrinted(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        return $this->ok($this->tags->markPrinted($this->actor($request), [$asset->id]), 'Label ditandai tercetak.');
    }

    public function attach(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate(['tag_code' => ['required', 'string', 'max:80']]);

        $updated = $this->tags->attach($this->actor($request), $asset, $data['tag_code']);

        return $this->ok($updated, 'Label terpasang dan terverifikasi.');
    }

    public function replace(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:200']]);

        return $this->ok($this->tags->replace($this->actor($request), $asset, $data['notes'] ?? null), 'Label diganti; label lama tersimpan sebagai riwayat.');
    }
}
