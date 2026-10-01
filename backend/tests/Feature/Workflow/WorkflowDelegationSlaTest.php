<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Modules\Workflow\Application\Services\ApprovalService;
use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Application\Services\EscalationService;
use App\Modules\Workflow\Domain\Enums\DecisionType;
use App\Modules\Workflow\Domain\Enums\OrgScopeType;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNotification;
use App\Modules\Workflow\Models\WorkflowSlaRecord;
use Illuminate\Support\Facades\Artisan;

/**
 * §8, §39, §40, §41, §42, §62, §68, §69 — delegasi, SLA, pengingat, eskalasi,
 * dan idempotensi notifikasi.
 */
class WorkflowDelegationSlaTest extends WorkflowTestCase
{
    protected function slaWorkflow(User $approver, int $slaMinutes = 60, array $escalation = []): void
    {
        $node = [
            'key' => 'step',
            'name' => 'Approver',
            'sla_minutes' => $slaMinutes,
            'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]],
        ];

        if ($escalation !== []) {
            $node['escalation'] = $escalation;
        }

        $graph = $this->linearGraph([$node]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'SLA_FLOW', publish: true);
    }

    public function test_delegasi_membuat_keputusan_atas_nama_delegator_dan_tercatat_di_audit(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $delegator = $this->makeUser('bendahara');
        $delegate = $this->makeUser('kepala-sekolah');

        $this->registerTestProvider();
        $this->slaWorkflow($delegator);

        app(DelegationService::class)->create($delegator, [
            'delegate_id' => $delegate->id,
            'start_at' => now()->subHour()->toDateTimeString(),
            'end_at' => now()->addDays(3)->toDateTimeString(),
            'reason' => 'Tugas luar kota',
            'scope' => ['definitions' => ['SLA_FLOW']],
        ], $delegator);

        $instance = $this->submitInstance('SLA_FLOW', $requester);

        // Penugasan berpindah ke penerima delegasi; identitas delegator tetap ada.
        $approval = $this->pendingApprovalFor($instance, $delegate);

        $this->assertNotNull($approval, 'Penerima delegasi mendapat penugasan.');
        $this->assertTrue((bool) $approval->is_delegate);
        $this->assertSame($delegator->id, (int) $approval->original_approver_id);
        $this->assertNotNull($approval->delegation_id);
        $this->assertNull($this->pendingApprovalFor($instance, $delegator));

        $decision = app(ApprovalService::class)->decide($approval, $delegate, DecisionType::APPROVE, 'Disetujui atas nama bendahara');

        $this->assertSame($delegator->id, (int) $decision->original_approver_id);
        $this->assertNotSame($decision->actor_id, $decision->original_approver_id);

        $this->assertDatabaseHas('workflow_audit_logs', [
            'instance_id' => $instance->id,
            'action' => 'DECISION_RECORDED',
        ]);
    }

    public function test_pencabutan_delegasi_tidak_menghapus_riwayat(): void
    {
        $delegator = $this->makeUser('bendahara');
        $delegate = $this->makeUser('kepala-sekolah');

        $service = app(DelegationService::class);

        $delegation = $service->create($delegator, [
            'delegate_id' => $delegate->id,
            'start_at' => now()->subHour()->toDateTimeString(),
            'end_at' => now()->addDays(2)->toDateTimeString(),
        ], $delegator);

        $service->revoke($delegation, $delegator, 'Kembali bertugas');

        $delegation->refresh();

        $this->assertSame('REVOKED', $delegation->status->value);
        $this->assertNotNull($delegation->revoked_at);
        $this->assertSame('Kembali bertugas', $delegation->revoke_reason);
        $this->assertDatabaseHas('workflow_audit_logs', ['action' => 'DELEGATION_REVOKED']);
    }

    public function test_sapuan_sla_mengirim_pengingat_lalu_menandai_terlambat_tanpa_menggandakan_notifikasi(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');
        $manager = $this->makeUser('kepala-sekolah');

        $this->registerTestProvider();
        $this->slaWorkflow($approver, 60);
        $this->makeManager($approver, $manager);

        $instance = $this->submitInstance('SLA_FLOW', $requester);

        // Majukan waktu melewati ambang pengingat (50%) dan tenggat.
        $this->travel(2)->hours();

        Artisan::call('workflow:check-sla');
        Artisan::call('workflow:check-sla');

        $record = WorkflowSlaRecord::query()->where('instance_id', $instance->id)->firstOrFail();

        $this->assertNotNull($record->reminder_sent_at);
        $this->assertNotNull($record->overdue_at);
        $this->assertContains($record->status->value, ['OVERDUE', 'ESCALATED']);

        // Idempotensi: pengingat dan notifikasi keterlambatan hanya sekali.
        $this->assertSame(1, WorkflowNotification::query()
            ->where('instance_id', $instance->id)
            ->where('event', 'APPROVAL_REMINDER')
            ->count());

        $this->assertSame(1, WorkflowNotification::query()
            ->where('instance_id', $instance->id)
            ->where('event', 'APPROVAL_OVERDUE')
            ->count());

        $this->assertGreaterThan(0, WorkflowNotification::query()
            ->where('instance_id', $instance->id)
            ->whereIn('status', ['SENT', 'SKIPPED'])
            ->count());
    }

    public function test_eskalasi_reassign_memindahkan_tugas_ke_atasan_dan_mencatat_level(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');
        $manager = $this->makeUser('kepala-sekolah');

        $this->registerTestProvider();
        $this->slaWorkflow($approver, 30, [
            'enabled' => true,
            'action' => 'REASSIGN',
            'max_levels' => 2,
            'after_percent' => 100,
            'repeat_percent' => 50,
        ]);
        $this->makeManager($approver, $manager);

        $instance = $this->submitInstance('SLA_FLOW', $requester);

        $this->travel(3)->hours();

        $stats = app(EscalationService::class)->sweep();

        $this->assertGreaterThanOrEqual(1, $stats['escalated']);
        $this->assertDatabaseHas('workflow_escalations', [
            'instance_id' => $instance->id,
            'action' => 'REASSIGN',
            'level' => 1,
        ]);

        // Penugasan lama dicatat sebagai dibatalkan oleh eskalasi (tidak dihapus).
        $this->assertSame(1, WorkflowApproval::query()
            ->where('instance_id', $instance->id)
            ->where('approver_id', $approver->id)
            ->where('status', 'CANCELLED')
            ->count());

        $this->assertNotNull($this->pendingApprovalFor($instance->refresh(), $manager), 'Atasan kini memegang tugas.');
    }

    public function test_status_sla_pada_inbox_mengikuti_perkembangan_waktu(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');

        $this->registerTestProvider();
        $this->slaWorkflow($approver, 120);

        $instance = $this->submitInstance('SLA_FLOW', $requester);

        $this->travel(110)->minutes();
        app(EscalationService::class)->sweep();

        $step = $instance->steps()->latest('id')->firstOrFail();

        $this->assertSame('DUE_SOON', $step->refresh()->sla_status->value);
    }

    public function test_melewati_step_mewajibkan_alasan_dan_hanya_bila_diizinkan(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');
        $manager = $this->makeUser('WORKFLOW_MANAGER');

        $this->registerTestProvider();

        // Node menolak skip.
        $graph = $this->linearGraph([[
            'key' => 'step', 'name' => 'Approver', 'allow_skip' => false,
            'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]],
        ]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'SKIP_BLOCKED');

        $instance = $this->submitInstance('SKIP_BLOCKED', $requester);
        $step = $instance->steps()->latest('id')->firstOrFail();

        try {
            app(ApprovalService::class)->skipStep($instance, $step, $manager, 'Darurat');
            $this->fail('Skip seharusnya ditolak.');
        } catch (\App\Modules\Workflow\Domain\Exceptions\InvalidWorkflowOperation $e) {
            $this->assertSame(422, $e->statusCode());
        }

        // Node mengizinkan skip: berjalan, beralasan, dan tercatat.
        $graph = $this->linearGraph([[
            'key' => 'step', 'name' => 'Approver', 'allow_skip' => true,
            'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]],
        ]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'SKIP_ALLOWED');

        $second = $this->submitInstance('SKIP_ALLOWED', $requester);
        $secondStep = $second->steps()->latest('id')->firstOrFail();

        app(ApprovalService::class)->skipStep($second, $secondStep, $manager, 'Persetujuan lisan dari kepala sekolah');

        $second->refresh();

        $this->assertSame('APPROVED', $second->status->value);
        $this->assertSame('SKIPPED', $secondStep->refresh()->status->value);
        $this->assertDatabaseHas('workflow_audit_logs', [
            'instance_id' => $second->id,
            'action' => 'STEP_SKIPPED',
        ]);
    }

    public function test_assign_scope_direktori_mendukung_penetapan_approver_manual(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $unitHead = $this->makeUser('kepala-sekolah');

        $this->registerTestProvider();
        $this->assignScope(OrgScopeType::DEPARTMENT->value, 'SARANA', $unitHead);

        $graph = $this->linearGraph([[
            'key' => 'step', 'name' => 'Kepala Unit',
            'approvers' => [['resolver' => 'department']],
        ]]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'DEPARTMENT_FLOW', [
            'required_context' => [],
        ]);

        $instance = $this->submitInstance('DEPARTMENT_FLOW', $requester, ['department' => 'SARANA']);

        $this->assertSame('WAITING_APPROVAL', $instance->status->value);
        $this->assertNotNull($this->pendingApprovalFor($instance, $unitHead));

        $decision = app(ApprovalService::class)->decide(
            $this->pendingApprovalFor($instance, $unitHead),
            $unitHead,
            DecisionType::APPROVE,
            'Kebutuhan unit terverifikasi',
        );

        $this->assertSame('APPROVE', $decision->decision->value);
        $this->assertSame('APPROVED', $instance->refresh()->status->value);
    }
}
