<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Modules\Workflow\Application\Services\ApprovalService;
use App\Modules\Workflow\Application\Services\WorkflowAuditService;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Modules\Workflow\Domain\Enums\DecisionType;
use App\Modules\Workflow\Domain\Exceptions\WorkflowAccessDenied;
use App\Modules\Workflow\Domain\Exceptions\WorkflowConflict;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Events\WorkflowDomainEvent;
use Illuminate\Support\Facades\Event;

/**
 * §19–§21, §36, §46, §58, §64 — eksekusi alur: serial, paralel, kondisi,
 * kuorum, idempotensi, self-approval, aksi otomatis, dan approver kosong.
 */
class WorkflowExecutionTest extends WorkflowTestCase
{
    protected function approvals(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    protected function decide(WorkflowInstance $instance, User $actor, DecisionType $decision, ?string $comment = null): void
    {
        $approval = $this->pendingApprovalFor($instance, $actor);

        $this->assertNotNull($approval, "Tidak ada penugasan menunggu untuk {$actor->name}.");

        $this->approvals()->decide($approval, $actor, $decision, $comment);
    }

    public function test_alur_serial_tiga_tingkat_selesai_dan_tercatat_penuh(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $supervisor = $this->makeUser('kepala-sekolah');
        $finance = $this->makeUser('bendahara');
        $director = $this->makeUser('super-admin');

        $this->registerTestProvider();

        $graph = $this->linearGraph([
            ['key' => 'supervisor', 'name' => 'Supervisor', 'approvers' => [['resolver' => 'user', 'user_id' => $supervisor->id]]],
            ['key' => 'finance', 'name' => 'Keuangan', 'approvers' => [['resolver' => 'user', 'user_id' => $finance->id]]],
            ['key' => 'director', 'name' => 'Direktur', 'approvers' => [['resolver' => 'user', 'user_id' => $director->id]]],
        ]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'SERIAL_FLOW');

        $instance = $this->submitInstance('SERIAL_FLOW', $requester);

        $this->assertSame('WAITING_APPROVAL', $instance->status->value);
        $this->assertSame('supervisor', $instance->currentKeys()[0]);

        $this->decide($instance->refresh(), $supervisor, DecisionType::APPROVE, 'Setuju');
        $this->assertSame('finance', $instance->refresh()->currentKeys()[0]);

        $this->decide($instance->refresh(), $finance, DecisionType::APPROVE, 'Anggaran tersedia');
        $this->assertSame('director', $instance->refresh()->currentKeys()[0]);

        $this->decide($instance->refresh(), $director, DecisionType::APPROVE);

        $instance->refresh();

        $this->assertSame('APPROVED', $instance->status->value);
        $this->assertNotNull($instance->completed_at);
        $this->assertSame(3, $instance->decisions()->count());
        $this->assertSame(
            3,
            app(WorkflowInstanceService::class)->progress($instance)['completed'],
            'Progress request dihitung dari step manusia.',
        );

        // Timeline immutable: event + keputusan + submission.
        $timeline = app(WorkflowInstanceService::class)->timeline($instance);
        $this->assertNotEmpty($timeline);
        $this->assertContains('decision.APPROVE', array_column($timeline, 'type'));

        // Rantai audit utuh dan dapat diverifikasi.
        $chain = app(WorkflowAuditService::class)->verifyChain($instance->id);
        $this->assertTrue($chain['valid']);
    }

    public function test_kondisi_nominal_mengarahkan_ke_approver_berbeda(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $finance = $this->makeUser('bendahara');
        $director = $this->makeUser('kepala-sekolah');

        // Provider modul adalah **sumber kebenaran**: engine tidak mengambil
        // nominal dari payload klien. Karena itu nominal uji disetel di sini.
        $this->registerTestProvider(fn (string $subjectId) => match ($subjectId) {
            'BIG' => ['amount' => 15000000],
            'MID' => ['amount' => 2500000],
            default => ['amount' => 500000],
        });

        $nodes = [
            ['key' => 'start', 'type' => 'START', 'name' => 'Mulai', 'sequence' => 1],
            ['key' => 'gate', 'type' => 'CONDITION', 'name' => 'Cek Nominal', 'sequence' => 2],
            ['key' => 'finance', 'type' => 'APPROVAL', 'name' => 'Bendahara', 'sequence' => 3, 'approvers' => [['resolver' => 'user', 'user_id' => $finance->id]]],
            ['key' => 'director', 'type' => 'APPROVAL', 'name' => 'Kepala Sekolah', 'sequence' => 4, 'approvers' => [['resolver' => 'user', 'user_id' => $director->id]]],
            ['key' => 'end', 'type' => 'END', 'name' => 'Selesai', 'sequence' => 5],
        ];

        $edges = [
            ['from_key' => 'start', 'to_key' => 'gate'],
            ['from_key' => 'gate', 'to_key' => 'director', 'priority' => 0, 'condition' => ['field' => 'amount', 'operator' => '>', 'value' => 10000000]],
            ['from_key' => 'gate', 'to_key' => 'finance', 'priority' => 1, 'condition' => ['field' => 'amount', 'operator' => '>=', 'value' => 1000000]],
            ['from_key' => 'gate', 'to_key' => 'end', 'priority' => 9, 'is_default' => true],
            ['from_key' => 'director', 'to_key' => 'end'],
            ['from_key' => 'finance', 'to_key' => 'end'],
        ];

        $this->makeWorkflow($nodes, $edges, 'AMOUNT_FLOW');

        // Nominal besar → kepala sekolah.
        $big = $this->submitInstance('AMOUNT_FLOW', $requester, ['subject_id' => 'BIG', 'amount' => 15000000]);
        $this->assertSame('director', $big->currentKeys()[0]);
        $this->assertNull($this->pendingApprovalFor($big, $finance), 'Bendahara tidak boleh dimintai persetujuan untuk alur nominal besar.');

        // Nominal sedang → bendahara.
        $medium = $this->submitInstance('AMOUNT_FLOW', $requester, ['subject_id' => 'MID', 'amount' => 2500000]);
        $this->assertSame('finance', $medium->currentKeys()[0]);

        // Nominal kecil → langsung selesai tanpa approver.
        $small = $this->submitInstance('AMOUNT_FLOW', $requester, ['subject_id' => 'SMALL', 'amount' => 500000]);
        $this->assertSame('APPROVED', $small->status->value);
        $this->assertSame([], $small->currentKeys());
    }

    public function test_kuorum_all_any_dan_minimum_n(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $this->registerTestProvider();

        $approvers = [
            $this->makeUser('bendahara'),
            $this->makeUser('kepala-sekolah'),
            $this->makeUser('super-admin'),
        ];

        $specs = array_map(fn (User $user) => ['resolver' => 'user', 'user_id' => $user->id], $approvers);

        // ALL: dua setuju masih menunggu, setuju ketiga menyelesaikan.
        $graph = $this->linearGraph([['key' => 'all_step', 'name' => 'Semua', 'quorum_policy' => 'ALL', 'approvers' => $specs]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'QUORUM_ALL');

        $instance = $this->submitInstance('QUORUM_ALL', $requester);

        $this->decide($instance->refresh(), $approvers[0], DecisionType::APPROVE);
        $this->decide($instance->refresh(), $approvers[1], DecisionType::APPROVE);
        $this->assertSame('WAITING_APPROVAL', $instance->refresh()->status->value);

        $this->decide($instance->refresh(), $approvers[2], DecisionType::APPROVE);
        $this->assertSame('APPROVED', $instance->refresh()->status->value);

        // ANY: satu setuju menyelesaikan, sisanya ditutup sebagai CANCELLED.
        $graph = $this->linearGraph([['key' => 'any_step', 'name' => 'Salah Satu', 'quorum_policy' => 'ANY', 'approvers' => $specs]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'QUORUM_ANY');

        $instance = $this->submitInstance('QUORUM_ANY', $requester);
        $this->decide($instance->refresh(), $approvers[1], DecisionType::APPROVE);

        $instance->refresh();
        $this->assertSame('APPROVED', $instance->status->value);
        $this->assertSame(2, $instance->approvals()->where('status', 'CANCELLED')->count(), 'Penugasan yang tidak lagi diperlukan ditutup, bukan dibiarkan menggantung.');

        // MINIMUM_N: 2 dari 3.
        $graph = $this->linearGraph([[
            'key' => 'min_step', 'name' => 'Minimum Dua', 'quorum_policy' => 'MINIMUM_N', 'quorum_value' => 2,
            'approvers' => $specs,
        ]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'QUORUM_MIN');

        $instance = $this->submitInstance('QUORUM_MIN', $requester);

        $this->decide($instance->refresh(), $approvers[0], DecisionType::APPROVE);
        $this->assertSame('WAITING_APPROVAL', $instance->refresh()->status->value);

        $this->decide($instance->refresh(), $approvers[2], DecisionType::APPROVE);
        $this->assertSame('APPROVED', $instance->refresh()->status->value);
    }

    public function test_keputusan_ganda_idempoten_dan_penolakan_menghentikan_request(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');
        $second = $this->makeUser('kepala-sekolah');
        $this->registerTestProvider();

        $graph = $this->linearGraph([[
            'key' => 'step', 'name' => 'Bersama', 'quorum_policy' => 'ALL',
            'approvers' => [
                ['resolver' => 'user', 'user_id' => $approver->id],
                ['resolver' => 'user', 'user_id' => $second->id],
            ],
        ]]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'IDEMPOTENT_FLOW');

        $instance = $this->submitInstance('IDEMPOTENT_FLOW', $requester);
        $approval = $this->pendingApprovalFor($instance, $approver);

        $first = $this->approvals()->decide($approval, $approver, DecisionType::APPROVE, 'Setuju', [
            'idempotency_key' => 'klik-ganda-1',
        ]);

        // Permintaan ulang dengan kunci sama mengembalikan keputusan yang sama.
        $replay = $this->approvals()->decide($approval->refresh(), $approver, DecisionType::APPROVE, 'Setuju', [
            'idempotency_key' => 'klik-ganda-1',
        ]);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, $instance->decisions()->count(), 'Klik ganda tidak boleh menghasilkan keputusan kedua.');

        // Tanpa kunci idempoten: ditolak sebagai konflik, bukan duplikat.
        try {
            $this->approvals()->decide($approval->refresh(), $approver, DecisionType::APPROVE, 'Setuju lagi');
            $this->fail('Keputusan kedua seharusnya ditolak.');
        } catch (WorkflowConflict $e) {
            $this->assertSame(409, $e->statusCode());
        }

        // Penolakan oleh satu approver menghentikan seluruh request (kuorum ALL).
        $this->decide($instance->refresh(), $second, DecisionType::REJECT, 'Anggaran belum tersedia');

        $instance->refresh();
        $this->assertSame('REJECTED', $instance->status->value);
        $this->assertNotNull($instance->completed_at);
    }

    public function test_self_approval_ditolak_secara_default_dan_hanya_boleh_bila_diizinkan_eksplisit(): void
    {
        $requester = $this->makeUser('bendahara');
        $other = $this->makeUser('kepala-sekolah');
        $this->registerTestProvider();

        // Default: requester tidak pernah muncul sebagai approver.
        $graph = $this->linearGraph([[
            'key' => 'step', 'name' => 'Bersama', 'quorum_policy' => 'ALL',
            'approvers' => [
                ['resolver' => 'user', 'user_id' => $requester->id],
                ['resolver' => 'user', 'user_id' => $other->id],
            ],
        ]]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'SELF_BLOCKED');

        $instance = $this->submitInstance('SELF_BLOCKED', $requester);

        $this->assertNull(
            $this->pendingApprovalFor($instance, $requester),
            'Requester tidak boleh memiliki penugasan persetujuan atas requestnya sendiri.',
        );
        $this->assertNotNull($this->pendingApprovalFor($instance, $other));

        // Diizinkan eksplisit: definisi menyatakan allow_self_approval = true.
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'SELF_ALLOWED', [
            'allow_self_approval' => true,
        ]);

        $allowed = $this->submitInstance('SELF_ALLOWED', $requester);
        $ownApproval = $this->pendingApprovalFor($allowed, $requester);

        $this->assertNotNull($ownApproval, 'Kebijakan eksplisit memungkinkan requester menyetujui requestnya sendiri.');

        app(ApprovalService::class)->decide($ownApproval, $requester, DecisionType::APPROVE, 'Disetujui sendiri sesuai kebijakan');

        $this->assertSame(1, $allowed->refresh()->decisions()->count());
    }

    public function test_return_lalu_resubmit_melanjutkan_dari_step_yang_mengembalikan(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $supervisor = $this->makeUser('kepala-sekolah');
        $finance = $this->makeUser('bendahara');
        $this->registerTestProvider();

        $graph = $this->linearGraph([
            ['key' => 'supervisor', 'name' => 'Supervisor', 'approvers' => [['resolver' => 'user', 'user_id' => $supervisor->id]]],
            ['key' => 'finance', 'name' => 'Keuangan', 'approvers' => [['resolver' => 'user', 'user_id' => $finance->id]]],
        ]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'RETURN_FLOW');

        $instance = $this->submitInstance('RETURN_FLOW', $requester);

        $this->decide($instance->refresh(), $supervisor, DecisionType::APPROVE);
        $this->decide($instance->refresh(), $finance, DecisionType::RETURN, 'Lampiran nota belum lengkap');

        $instance->refresh();
        $this->assertSame('RETURNED', $instance->status->value);
        $this->assertNotNull($instance->returned_at);

        // Revisi + submit ulang: hanya step pengembali yang aktif kembali
        // (strategy RESUME_FROM_PREVIOUS_STEP), ditandai ronde ke-2.
        $instances = app(WorkflowInstanceService::class);
        $instance = $instances->revise($instance, $requester, ['context' => ['amount' => 2000000]], 'Nota sudah dilampirkan');

        $this->assertSame(2, $instance->submission_number);
        $this->assertSame('finance', $instance->currentKeys()[0]);

        $financeStep = $instance->steps()->reorder('round', 'desc')->where('node_key', 'finance')->first();
        $this->assertSame(2, (int) $financeStep->round);
        $this->assertSame(2, $instance->submissions()->count(), 'Riwayat submission tidak hilang.');

        $this->decide($instance->refresh(), $finance, DecisionType::APPROVE);

        $instance->refresh();
        $this->assertSame('APPROVED', $instance->status->value);
        // Ketiga keputusan tetap tersimpan: setuju supervisor, kembalikan
        // (revisi), lalu setuju setelah revisi — riwayat tidak dihapus.
        $this->assertSame(3, $instance->decisions()->count());
        $this->assertSame(1, $instance->decisions()->where('decision', 'RETURN')->count());
    }

    public function test_aksi_otomatis_dijalankan_saat_selesai_dan_peristiwa_dipublikasikan(): void
    {
        Event::fake([WorkflowDomainEvent::class]);

        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');
        $provider = $this->registerTestProvider();

        $this->registerTestAction('testing.create_po');

        $graph = $this->linearGraph(
            [['key' => 'step', 'name' => 'Approver', 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]]],
            [
                'approved' => [
                    ['handler' => 'testing.create_po', 'params' => ['reference' => 'PO-1']],
                    ['handler' => 'event.publish', 'params' => ['name' => 'PurchaseRequestApproved']],
                    ['handler' => 'subject.update_status', 'params' => ['status' => 'APPROVED']],
                ],
            ],
        );

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'ACTION_FLOW');

        $instance = $this->submitInstance('ACTION_FLOW', $requester);
        $this->decide($instance->refresh(), $approver, DecisionType::APPROVE);

        $instance->refresh();

        $this->assertSame('APPROVED', $instance->status->value);
        $this->assertNotEmpty($provider->appliedStatus, 'Aksi subject.update_status memanggil provider modul.');
        $this->assertSame('APPROVED', $provider->appliedStatus[0]['status']);

        Event::assertDispatched(WorkflowDomainEvent::class, fn (WorkflowDomainEvent $event) => $event->name === 'PurchaseRequestApproved');

        $this->assertDatabaseHas('workflow_events', [
            'instance_id' => $instance->id,
            'type' => 'domain.PurchaseRequestApproved',
        ]);
    }

    public function test_kebijakan_approver_kosong_menahan_request_dan_dapat_ditugaskan_manual(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $manager = $this->makeUser('kepala-sekolah');
        $this->registerTestProvider();

        // Resolver manager, tetapi hierarki organisasi belum diisi.
        $graph = $this->linearGraph([[
            'key' => 'step', 'name' => 'Atasan Langsung',
            'approvers' => [['resolver' => 'manager']],
        ]]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'EMPTY_APPROVER_FLOW');

        $instance = $this->submitInstance('EMPTY_APPROVER_FLOW', $requester);

        $this->assertSame('WAITING_FOR_APPROVER', $instance->status->value, 'Request ditahan, bukan dilewati.');
        $this->assertSame('WAITING_FOR_APPROVER', $instance->steps()->latest('id')->first()->status->value);

        $steps = app(\App\Modules\Workflow\Application\Services\WorkflowStepService::class);
        $step = $instance->steps()->latest('id')->firstOrFail();

        $steps->assignManually($instance, $step, [$manager->id], $this->makeUser('WORKFLOW_MANAGER'), 'Kepala unit ditetapkan manual');

        $instance->refresh();
        $this->assertSame('WAITING_APPROVAL', $instance->status->value);

        $this->decide($instance->refresh(), $manager, DecisionType::APPROVE);
        $this->assertSame('APPROVED', $instance->refresh()->status->value);
    }

    public function test_cabang_paralel_menunggu_semua_cabang_selesai_sebelum_bergabung(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $finance = $this->makeUser('bendahara');
        $legal = $this->makeUser('kepala-sekolah');
        $director = $this->makeUser('super-admin');
        $this->registerTestProvider();

        $nodes = [
            ['key' => 'start', 'type' => 'START', 'name' => 'Mulai', 'sequence' => 1],
            ['key' => 'fork', 'type' => 'PARALLEL', 'name' => 'Cabang', 'sequence' => 2, 'config' => ['merge_strategy' => 'ALL']],
            ['key' => 'finance', 'type' => 'APPROVAL', 'name' => 'Keuangan', 'sequence' => 3, 'approvers' => [['resolver' => 'user', 'user_id' => $finance->id]]],
            ['key' => 'legal', 'type' => 'APPROVAL', 'name' => 'Legal', 'sequence' => 4, 'approvers' => [['resolver' => 'user', 'user_id' => $legal->id]]],
            ['key' => 'director', 'type' => 'APPROVAL', 'name' => 'Direktur', 'sequence' => 5, 'approvers' => [['resolver' => 'user', 'user_id' => $director->id]]],
            ['key' => 'end', 'type' => 'END', 'name' => 'Selesai', 'sequence' => 6],
        ];

        $edges = [
            ['from_key' => 'start', 'to_key' => 'fork'],
            ['from_key' => 'fork', 'to_key' => 'finance'],
            ['from_key' => 'fork', 'to_key' => 'legal'],
            ['from_key' => 'finance', 'to_key' => 'director'],
            ['from_key' => 'legal', 'to_key' => 'director'],
            ['from_key' => 'director', 'to_key' => 'end'],
        ];

        $this->makeWorkflow($nodes, $edges, 'PARALLEL_FLOW');

        $instance = $this->submitInstance('PARALLEL_FLOW', $requester);

        $this->assertEqualsCanonicalizing(['finance', 'legal'], $instance->currentKeys(), 'Kedua cabang aktif bersamaan.');

        $this->decide($instance->refresh(), $finance, DecisionType::APPROVE);

        $this->assertSame('WAITING_APPROVAL', $instance->refresh()->status->value);
        $this->assertSame(['legal'], $instance->refresh()->currentKeys(), 'Direktur menunggu sampai kedua cabang selesai.');

        $this->decide($instance->refresh(), $legal, DecisionType::APPROVE);
        $this->assertSame('director', $instance->refresh()->currentKeys()[0]);

        $this->decide($instance->refresh(), $director, DecisionType::APPROVE);
        $this->assertSame('APPROVED', $instance->refresh()->status->value);
    }
}
