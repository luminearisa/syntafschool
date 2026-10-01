<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetImportService;
use App\Modules\Asset\Models\AssetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetImportController extends AssetControllerBase
{
    public function __construct(private readonly AssetImportService $imports) {}

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:assets,locations,facilities,asset_categories,custodians'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx'],
        ]);

        $file = $data['file'];
        $path = $file->storeAs('asset-imports', uniqid('import-', true).'.'.$file->getClientOriginalExtension(), 'local');

        $import = $this->imports->upload($this->actor($request), $data['type'], $path, $file->getClientOriginalName());

        return $this->created([
            'import' => $import,
            'summary' => $import->summary,
            'preview' => $import->preview,
        ], 'Berkas diperiksa. Periksa pratinjau sebelum mengimpor.');
    }

    public function confirm(Request $request, AssetImport $import): JsonResponse
    {
        $result = $this->imports->confirm($this->actor($request), $import);

        return $this->ok([
            'created' => $result['created'],
            'skipped' => count($result['skipped']),
            'skipped_details' => $result['skipped'],
            'import' => $result['import'],
        ], 'Impor selesai.');
    }
}
