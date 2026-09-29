<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\VisitAccessService;
use App\Modules\Supervisi\Application\Services\VisitFindingService;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Temuan analisis data supervisi (kelebihan, kelemahan, sebab, rekomendasi). */
class SupVisitFindingController extends SupController
{
    public function __construct(
        private readonly VisitFindingService $findings,
        private readonly VisitAccessService $access,
    ) {}

    public function index(Request $request, SupVisit $visit): JsonResponse
    {
        $this->access->assertCanView($this->actor($request), $visit);

        return $this->successResponse($visit->findings()->get()->toArray(), 'Temuan supervisi');
    }

    /** Ganti seluruh daftar temuan (payload = daftar lengkap). */
    public function replace(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanExecute($actor, $visit);

        $data = $request->validate([
            'findings' => ['present', 'array'],
            'findings.*.component' => ['nullable', 'string', 'max:40'],
            'findings.*.priority' => ['nullable', 'string', 'max:20'],
            'findings.*.indicator_id' => ['nullable', 'integer'],
            'findings.*.strength' => ['nullable', 'string', 'max:2000'],
            'findings.*.weakness' => ['nullable', 'string', 'max:2000'],
            'findings.*.problem' => ['nullable', 'string', 'max:2000'],
            'findings.*.cause' => ['nullable', 'string', 'max:2000'],
            'findings.*.recommendation' => ['nullable', 'string', 'max:2000'],
        ]);

        $rows = $this->findings->replace($visit, $data['findings'], $actor);

        return $this->successResponse($rows, 'Temuan supervisi disimpan');
    }
}
