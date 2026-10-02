<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\ReligiousImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Impor/ekspor dengan pratinjau, validasi, komit, dan pembatalan (§87). */
class ImportController extends Controller
{
    public function __construct(private readonly ReligiousImportService $imports)
    {
    }

    /** Pratinjau & validasi baris (belum menulis ke tabel tujuan). */
    public function preview(Request $request): JsonResponse
    {
        $request->user()->can('religious.import') || abort(403);
        $validated = $request->validate([
            'import_type' => ['required', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'file_name' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $batch = $this->imports->preview($validated['import_type'], $validated['rows'], $request->only(['file_name', 'notes']));

        return response()->json(['data' => [
            'batch' => $batch->only(['id', 'code', 'import_type', 'total_rows', 'valid_rows', 'invalid_rows', 'status']),
            'error_report' => $batch->error_report,
            'preview' => $batch->preview,
        ]], 201);
    }

    public function commit(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.import') || abort(403);

        return response()->json(['data' => $this->imports->commit($id, $request->boolean('only_valid', true))]);
    }

    public function rollback(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.import') || abort(403);
        $validated = $request->validate(['reason' => ['required', 'string']]);

        return response()->json(['data' => $this->imports->rollback($id, $validated['reason'])]);
    }

    public function history(Request $request): JsonResponse
    {
        $request->user()->can('religious.import') || abort(403);

        return response()->json(['data' => $this->imports->history($request->only(['type', 'limit']))]);
    }

    public function export(Request $request, string $entity): JsonResponse
    {
        $request->user()->can('religious.report.export') || abort(403);

        return response()->json(['data' => $this->imports->exportCsv($entity, $request->only(['limit']))]);
    }
}
