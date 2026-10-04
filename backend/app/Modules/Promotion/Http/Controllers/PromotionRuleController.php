<?php

namespace App\Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Promotion\Http\Requests\StorePromotionRuleRequest;
use App\Modules\Promotion\Http\Requests\UpdatePromotionRuleRequest;
use App\Modules\Promotion\Http\Resources\PromotionRuleResource;
use App\Modules\Promotion\Models\PromotionRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionRuleController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = PromotionRule::query()
            ->with(['educationLevel', 'academicYear'])
            ->when($request->filled('academic_year_id'), fn (Builder $q) => $q->where('academic_year_id', (int) $request->input('academic_year_id')))
            ->when($request->filled('education_level_id'), fn (Builder $q) => $q->where('education_level_id', (int) $request->input('education_level_id')))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('id');

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            PromotionRuleResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar aturan kenaikan kelas berhasil diambil',
        );
    }

    public function store(StorePromotionRuleRequest $request): JsonResponse
    {
        $rule = PromotionRule::create($request->validated());

        return $this->createdResponse(
            new PromotionRuleResource($rule->load(['educationLevel', 'academicYear'])),
            'Aturan kenaikan kelas berhasil dibuat',
        );
    }

    public function show(PromotionRule $rule): JsonResponse
    {
        $rule->load(['educationLevel', 'academicYear']);

        return $this->successResponse(new PromotionRuleResource($rule), 'Detail aturan kenaikan kelas berhasil diambil');
    }

    public function update(UpdatePromotionRuleRequest $request, PromotionRule $rule): JsonResponse
    {
        $rule->update($request->validated());

        return $this->successResponse(
            new PromotionRuleResource($rule->fresh(['educationLevel', 'academicYear'])),
            'Aturan kenaikan kelas berhasil diperbarui',
        );
    }

    public function destroy(PromotionRule $rule): JsonResponse
    {
        $rule->delete();

        return $this->successResponse(null, 'Aturan kenaikan kelas berhasil dihapus');
    }
}
