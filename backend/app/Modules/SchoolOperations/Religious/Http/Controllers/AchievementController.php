<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\ReligiousAchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Prestasi, sertifikat, dan portofolio keagamaan siswa (§55-§57). */
class AchievementController extends Controller
{
    public function __construct(private readonly ReligiousAchievementService $achievements)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('religious.achievement.manage') || abort(403);

        return response()->json(['data' => $this->achievements->record($request->validate([
            'title' => ['required', 'string'], 'achievement_type' => ['required', 'string'],
            'student_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'],
            'participant_id' => ['nullable', 'integer'], 'category' => ['nullable', 'string'],
            'level' => ['nullable', 'string'], 'rank_label' => ['nullable', 'string'],
            'score' => ['nullable', 'numeric'], 'activity_id' => ['nullable', 'integer'],
            'assessment_id' => ['nullable', 'integer'], 'achievement_date' => ['nullable', 'date'],
            'organizer' => ['nullable', 'string'], 'location' => ['nullable', 'string'],
            'description' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function storeCertificate(Request $request): JsonResponse
    {
        $request->user()->can('religious.certificate.issue') || abort(403);

        return response()->json(['data' => $this->achievements->issueCertificate($request->validate([
            'title' => ['required', 'string'], 'certificate_type' => ['nullable', 'string'],
            'achievement_id' => ['nullable', 'integer'], 'participant_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'],
            'issued_date' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date'],
            'recipient_name' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    /** Portofolio keagamaan siswa (§55). */
    public function portfolio(Request $request, int $studentId): JsonResponse
    {
        $user = $request->user();
        $own = (int) ($user->student_id ?? 0);

        if (! $user->can('religious.achievement.view') && $own !== $studentId) {
            abort(403, 'Portofolio hanya dapat diakses sesuai cakupan yang berwenang (§94).');
        }

        return response()->json(['data' => $this->achievements->portfolio($studentId)]);
    }

    public function report(Request $request): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->achievements->report($request->only(['from', 'to', 'level']))]);
    }
}
