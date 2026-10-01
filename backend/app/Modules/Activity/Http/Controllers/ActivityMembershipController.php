<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityMembershipService;
use App\Modules\Activity\Domain\Enums\MembershipRole;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityTransfer;
use App\Modules\Activity\Models\ActivityWithdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Keanggotaan, penangguhan, pengunduran diri, dan pindah aktivitas (\§26, \§72\–74).
 */
class ActivityMembershipController extends BaseApiController
{
    public function __construct(private readonly ActivityMembershipService $memberships) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->memberships->paginate($request->all(), $request->user()));
    }

    public function store(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'role' => ['nullable', Rule::in(MembershipRole::values())],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'allow_over_capacity' => ['nullable', 'boolean'],
        ]);

        $membership = $this->memberships->createDirect(
            $program,
            (int) $request->input('student_id'),
            $request->all(),
            $request->user(),
        );

        return $this->createdResponse($membership, 'Keanggotaan dibuat.');
    }

    public function show(ActivityMembership $membership): JsonResponse
    {
        return $this->successResponse([
            'membership' => $membership->load(['program:id,name,code', 'period:id,name', 'student:id,full_name,nis', 'progress']),
            'history' => $this->memberships->histories($membership),
        ]);
    }

    public function suspend(ActivityMembership $membership, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->successResponse(
            $this->memberships->suspend($membership, $request->user(), (string) $request->input('reason')),
            'Keanggotaan ditangguhkan.',
        );
    }

    public function reactivate(ActivityMembership $membership, Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->memberships->reactivate($membership, $request->user(), $request->input('reason')),
            'Keanggotaan diaktifkan kembali.',
        );
    }

    public function changeRole(ActivityMembership $membership, Request $request): JsonResponse
    {
        $request->validate([
            'role' => ['required', Rule::in(MembershipRole::values())],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse(
            $this->memberships->changeRole($membership, $request->user(), (string) $request->input('role'), $request->input('reason')),
            'Peran anggota diperbarui.',
        );
    }

    public function withdraw(ActivityMembership $membership, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'reason_code' => ['nullable', 'string', 'max:48'],
            'effective_immediately' => ['nullable', 'boolean'],
            'auto_approve' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse(
            $this->memberships->requestWithdrawal($membership, $request->user(), $request->all()),
            'Permohonan pengunduran diri dicatat.',
        );
    }

    public function decideWithdrawal(ActivityWithdrawal $withdrawal, Request $request): JsonResponse
    {
        $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT,APPROVED,REJECTED'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = str_starts_with(strtoupper((string) $request->input('decision')), 'APPROVE') ? 'APPROVE' : 'REJECT';

        return $this->successResponse(
            $this->memberships->decideWithdrawal($withdrawal, $request->user(), $decision, $request->input('notes')),
            'Pengunduran diri diputuskan.',
        );
    }

    public function transfer(ActivityMembership $membership, Request $request): JsonResponse
    {
        $request->validate([
            'to_program_id' => ['required', 'integer', 'exists:activity_programs,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $program = ActivityProgram::query()->findOrFail($request->integer('to_program_id'));

        return $this->createdResponse(
            $this->memberships->requestTransfer($request->user(), $membership, $program, $request->all()),
            'Permohonan pindah aktivitas dicatat.',
        );
    }

    public function decideTransfer(ActivityTransfer $transfer, Request $request): JsonResponse
    {
        $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT,APPROVED,REJECTED'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = str_starts_with(strtoupper((string) $request->input('decision')), 'APPROVE') ? 'APPROVE' : 'REJECT';

        return $this->successResponse(
            $this->memberships->decideTransfer($transfer, $request->user(), $decision, $request->input('notes')),
            'Pindah aktivitas diputuskan.',
        );
    }

    /** Keanggotaan aktif seorang siswa (portal siswa/orang tua). */
    public function forStudent(int $studentId): JsonResponse
    {
        return $this->successResponse($this->memberships->activeFor($studentId));
    }

    public function promoteWaitlist(ActivityProgram $program): JsonResponse
    {
        $membership = $this->memberships->promoteWaitlistIfSeatAvailable($program);

        return $this->successResponse(
            $membership,
            $membership === null ? 'Tidak ada kursi atau daftar tunggu yang dapat dipromosikan.' : 'Peserta daftar tunggu dipromosikan.',
        );
    }
}
