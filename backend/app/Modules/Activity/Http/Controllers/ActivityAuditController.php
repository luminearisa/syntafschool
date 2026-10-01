<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jejak audit modul aktivitas.
 *
 * Hanya untuk dibaca: tidak ada endpoint yang menyunting atau menghapus baris
 * audit \— itulah yang membuatnya berguna sebagai bukti.
 */
class ActivityAuditController extends BaseApiController
{
    public function __construct(private readonly ActivityAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->audit->paginate($request->all()));
    }
}
