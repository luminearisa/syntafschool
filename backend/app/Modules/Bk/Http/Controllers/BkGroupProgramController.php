<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkGroupProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program kelompok BK: program, sesi, peserta, kehadiran, dan evaluasi.
 *
 * Kehadiran ditandai per sesi dan menyimpan sumbernya (manual atau Modul
 * Attendance), sehingga perbedaan angka dapat ditelusuri, bukan diperdebatkan.
 */
class BkGroupProgramController extends Controller
{
    use ApiResponse;


    public function __construct(private readonly BkGroupProgramService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('bk.program.view') || abort(403);

        return $this->successResponse($this->service->paginate($request->user(), $request->only([
            'type', 'status', 'search',
        ]), (int) $request->input('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('bk.program.manage') || abort(403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'program_type' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:48'],
            'description' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'facilitator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'string'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'target_participants' => ['nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:160'],
            'requires_consent' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->service->create($request->user(), $data), 'Program dibuat.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.program.view') || abort(403);

        $program = \App\Modules\Bk\Models\BkGroupProgram::query()
            ->with(['sessions', 'members.student:id,full_name,nis', 'facilitator:id,name'])
            ->findOrFail($id);

        return $this->successResponse([
            'program' => $program,
            'evaluation' => $this->service->evaluation($program),
            'member_attendance' => $program->members->map(fn ($member): array => $this->service->memberAttendance($member))->all(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.program.manage') || abort(403);

        $program = \App\Modules\Bk\Models\BkGroupProgram::query()->findOrFail($id);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'facilitator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:160'],
            'requires_consent' => ['nullable', 'boolean'],
            'evaluation' => ['nullable', 'array'],
            'evaluation_note' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->service->update($request->user(), $program, $data), 'Program diperbarui.');
    }

    public function addSession(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.program.manage') || abort(403);

        $program = \App\Modules\Bk\Models\BkGroupProgram::query()->findOrFail($id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:160'],
            'materials' => ['nullable', 'array'],
            'material_document_id' => ['nullable', 'integer', 'exists:bk_case_documents,id'],
            'status' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->service->addSession($request->user(), $program, $data), 'Sesi program ditambahkan.');
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.program.manage') || abort(403);

        $program = \App\Modules\Bk\Models\BkGroupProgram::query()->findOrFail($id);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'status' => ['nullable', 'string'],
            'consent_id' => ['nullable', 'integer', 'exists:bk_consents,id'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->createdResponse(
            $this->service->addMember($request->user(), $program, $data),
            'Peserta ditambahkan. Bila program mensyaratkan persetujuan, permintaan persetujuan dibuat lebih dulu.',
        );
    }

    public function attendance(Request $request, int $sessionId): JsonResponse
    {
        $request->user()->can('bk.program.manage') || abort(403);

        $session = \App\Modules\Bk\Models\BkGroupProgramSession::query()->findOrFail($sessionId);

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.member_id' => ['required', 'integer'],
            'entries.*.attendance_status' => ['required', 'string'],
            'entries.*.note' => ['nullable', 'string'],
            'entries.*.external_reference' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'in:MANUAL,ATTENDANCE_MODULE,IMPORT'],
        ]);

        $result = $this->service->recordAttendance($request->user(), $session, $data['entries'], $data['source'] ?? 'MANUAL');

        return $this->successResponse([
            ...$result,
            'summary' => $this->service->attendanceSummary($session->refresh()),
        ], 'Kehadiran sesi dicatat.');
    }

    public function evaluation(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.program.view') || abort(403);

        $program = \App\Modules\Bk\Models\BkGroupProgram::query()->findOrFail($id);

        return $this->successResponse($this->service->evaluation($program));
    }
}
