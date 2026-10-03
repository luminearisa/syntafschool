<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryPolicyService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPolicy;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPolicyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Kebijakan jatah laundry (§5, §7, §8, Rule 22).
 * Versi dipublikasikan eksplisit; versi terbit tidak dapat diubah.
 */
class PolicyController extends Controller
{
    public function __construct(private readonly LaundryPolicyService $policies)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        $policies = LaundryPolicy::query()
            ->with('versions')
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $policies]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('laundry.policy.manage') || abort(403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:laundry_policies,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->policies->createPolicy($data, $request->user())], 201);
    }

    /** Policy yang berlaku untuk satu santri (debug/verifikasi scope). */
    public function resolve(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('laundry.quota.view') || abort(403);

        $version = $this->policies->resolveForStudent($studentId, $request->input('date'));

        return response()->json(['data' => [
            'student_id' => $studentId,
            'context' => $this->policies->studentContext($studentId, $request->input('date')),
            'policy_version' => $version?->only([
                'id', 'laundry_policy_id', 'version', 'quota_type', 'quota_amount',
                'period_type', 'carry_over_mode', 'carry_over_limit', 'max_free_weight_per_order',
                'effective_from', 'effective_until',
            ]),
        ]]);
    }

    public function storeVersion(Request $request, LaundryPolicy $policy): JsonResponse
    {
        $request->user()->can('laundry.policy.manage') || abort(403);

        $data = $request->validate([
            'quota_type' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.quota_types'))],
            'quota_amount' => ['required', 'numeric', 'min:0'],
            'period_type' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.period_types'))],
            'custom_period_start' => ['nullable', 'date', 'required_if:period_type,CUSTOM'],
            'custom_period_end' => ['nullable', 'date', 'after_or_equal:custom_period_start'],
            'carry_over_mode' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.carry_over_modes'))],
            'carry_over_limit' => ['nullable', 'numeric', 'min:0', 'required_if:carry_over_mode,LIMITED_CARRY_OVER'],
            'max_free_weight_per_order' => ['nullable', 'numeric', 'min:0'],
            'school_id' => ['nullable', 'integer'],
            'dormitory_id' => ['nullable', 'integer'],
            'education_level_id' => ['nullable', 'integer'],
            'student_category' => ['nullable', 'string', 'max:30'],
            'academic_year_id' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['nullable', 'integer'],
            'publish' => ['nullable', 'boolean'],
        ]);

        $version = $this->policies->createVersion($policy, $data, $request->user());
        if (! empty($data['publish'])) {
            $version = $this->policies->publishVersion($version, $request->user());
        }

        return response()->json(['data' => $version], 201);
    }

    public function publishVersion(Request $request, LaundryPolicyVersion $version): JsonResponse
    {
        $request->user()->can('laundry.policy.manage') || abort(403);

        return response()->json(['data' => $this->policies->publishVersion($version, $request->user())]);
    }

    public function archiveVersion(Request $request, LaundryPolicyVersion $version): JsonResponse
    {
        $request->user()->can('laundry.policy.manage') || abort(403);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->policies->archiveVersion($version, $request->user(), $data['reason'] ?? null)]);
    }
}
