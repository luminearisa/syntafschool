<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\VisitAccessService;
use App\Modules\Supervisi\Application\Services\VisitEvidenceService;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bukti kegiatan supervisi (foto, rekaman, dokumen, tautan). */
class SupVisitEvidenceController extends SupController
{
    public function __construct(
        private readonly VisitEvidenceService $evidence,
        private readonly VisitAccessService $access,
    ) {}

    public function store(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanExecute($actor, $visit);

        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,mp4,mov,mp3,m4a'],
            'url' => ['nullable', 'string', 'max:500'],
            'kind' => ['nullable', 'string', 'in:'.implode(',', VisitEvidenceService::KINDS)],
            'visibility' => ['nullable', 'string', 'in:'.implode(',', VisitEvidenceService::VISIBILITIES)],
            'caption' => ['nullable', 'string', 'max:255'],
            'visit_answer_id' => ['nullable', 'integer', 'exists:sup_visit_answers,id'],
        ]);

        $evidence = $this->evidence->store($visit, $data, $actor);

        return $this->createdResponse([
            'id' => (int) $evidence->id,
            'kind' => $evidence->kind->value,
            'kind_label' => $evidence->kind->label(),
            'path' => $evidence->path,
            'url' => $evidence->url,
            'caption' => $evidence->caption,
            'visibility' => $evidence->visibility->value,
        ], 'Bukti tersimpan');
    }

    public function destroy(Request $request, SupVisit $visit, SupVisitEvidence $evidence): JsonResponse
    {
        $this->evidence->destroy($visit, $evidence, $this->actor($request));

        return $this->successResponse(null, 'Bukti dihapus');
    }
}
