<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Modules\Workflow\Application\Services\WorkflowAuditService;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Modules\Workflow\Application\Services\WorkflowSimulationService;
use App\Modules\Workflow\Domain\Contracts\WorkflowTriggerInterface;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * §44, §45, §47, §48, §56, §57, §65 — simulasi, dry run, jejak audit berantai,
 * dan jalur integrasi modul (trigger).
 */
class WorkflowSimulationAuditTest extends WorkflowTestCase
{
    protected function amountWorkflow(): void
    {
        $supervisor = $this->makeUser('kepala-sekolah');
        $finance = $this->makeUser('bendahara');
        $director = $this->makeUser('super-admin');

        $nodes = [
            ['key' => 'start', 'type' => 'START', 'name' => 'Mulai', 'sequence' => 1],
            ['key' => 'supervisor', 'type' => 'APPROVAL', 'name' => 'Atasan', 'sequence' => 2, 'sla_minutes' => 1440,
                'approvers' => [['resolver' => 'user', 'user_id' => $supervisor->id]]],
            ['key' => 'gate', 'type' => 'CONDITION', 'name' => 'Cek Nominal', 'sequence' => 3],
            ['key' => 'finance', 'type' => 'APPROVAL', 'name' => 'Bendahara', 'sequence' => 4, 'sla_minutes' => 1440,
                'approvers' => [['resolver' => 'user', 'user_id' => $finance->id]]],
            ['key' => 'director', 'type' => 'APPROVAL', 'name' => 'Kepala Sekolah', 'sequence' => 5, 'sla_minutes' => 2880,
                'approvers' => [['resolver' => 'user', 'user_id' => $director->id]]],
            ['key' => 'end', 'type' => 'END', 'name' => 'Selesai', 'sequence' => 6],
        ];

        $edges = [
            ['from_key' => 'start', 'to_key' => 'supervisor'],
            ['from_key' => 'supervisor', 'to_key' => 'gate'],
            ['from_key' => 'gate', 'to_key' => 'director', 'priority' => 0, 'condition' => ['field' => 'amount', 'operator' => '>', 'value' => 10000000]],
            ['from_key' => 'gate', 'to_key' => 'finance', 'priority' => 1, 'condition' => ['field' => 'amount', 'operator' => '>=', 'value' => 1000000]],
            ['from_key' => 'gate', 'to_key' => 'end', 'priority' => 9, 'is_default' => true],
            ['from_key' => 'finance', 'to_key' => 'end'],
            ['from_key' => 'director', 'to_key' => 'end'],
        ];

        $this->makeWorkflow($nodes, $edges, 'SIM_FLOW');
    }

    public function test_simulasi_menampilkan_jalur_approver_tanpa_membuat_request(): void
    {
        $this->registerTestProvider();
        $this->amountWorkflow();

        $definition = WorkflowDefinition::query()->where('code', 'SIM_FLOW')->firstOrFail();
        $actor = $this->makeUser('WORKFLOW_ADMIN');

        $result = app(WorkflowSimulationService::class)->simulate($definition, [
            'amount' => 15000000,
            'department' => 'SARANA',
        ], $actor);

        $steps = collect($result['path'])->keyBy('node_key');

        $this->assertTrue($result['valid']);
        $this->assertSame(2, $result['estimated_steps'], 'Atasan + kepala sekolah; cabang bendahara tidak ditempuh.');
        $this->assertNotEmpty($steps['director']['approvers'], 'Simulasi menyelesaikan approver nyata lewat resolver.');
        $this->assertTrue($steps['finance']['skipped'] ?? false, 'Cabang yang tidak ditempuh ditandai dilewati.');
        $this->assertSame(4320, (int) $result['estimated_work_minutes'], 'SLA langkah yang ditempuh dijumlahkan (1440 + 2880).');
        $this->assertNotEmpty($steps['supervisor']['sla_human']);

        // Simulasi tidak membuat instance, keputusan, maupun notifikasi.
        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(0, DB::table('workflow_decisions')->count());
        $this->assertSame(0, DB::table('workflow_notifications')->count());
        $this->assertSame(0, DB::table('workflow_contexts')->count());
    }

    public function test_simulasi_memperlihatkan_jalur_berbeda_untuk_nominal_kecil(): void
    {
        $this->registerTestProvider();
        $this->amountWorkflow();

        $definition = WorkflowDefinition::query()->where('code', 'SIM_FLOW')->firstOrFail();

        $small = app(WorkflowSimulationService::class)->simulate($definition, ['amount' => 2500000]);

        $steps = collect($small['path'])->keyBy('node_key');

        $this->assertFalse($steps['finance']['skipped'] ?? false);
        $this->assertTrue($steps['director']['skipped'] ?? false);
    }

    public function test_simulasi_memperingatkan_ketika_konteks_tidak_lengkap(): void
    {
        $this->registerTestProvider();
        $this->amountWorkflow();

        $definition = WorkflowDefinition::query()->where('code', 'SIM_FLOW')->firstOrFail();

        $result = app(WorkflowSimulationService::class)->simulate($definition, []);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('amount', implode(' ', $result['warnings']));
    }

    public function test_dry_run_menunjukkan_step_tersangkut_pada_request_berjalan(): void
    {
        $this->registerTestProvider();

        // Resolver manager tanpa hierarki → step tertahan, bukan dilewati.
        $graph = $this->linearGraph([['key' => 'step', 'name' => 'Atasan', 'approvers' => [['resolver' => 'manager']]]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'DRY_RUN_FLOW');

        $instance = $this->submitInstance('DRY_RUN_FLOW', $this->makeUser('admin-sekolah'));

        $result = app(WorkflowSimulationService::class)->dryRun($instance);

        $this->assertFalse($result['healthy']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertSame('WAITING_FOR_APPROVER', $instance->refresh()->status->value);

        // Setelah hierarki diisi dan approver ditetapkan, request menjadi sehat.
        $manager = $this->makeUser('kepala-sekolah');
        $this->makeManager($instance->requester, $manager);

        $steps = app(\App\Modules\Workflow\Application\Services\WorkflowStepService::class);
        $steps->assignManually(
            $instance,
            $instance->steps()->latest('id')->firstOrFail(),
            [$manager->id],
            $this->makeUser('WORKFLOW_MANAGER'),
            'Penetapan manual',
        );

        $after = app(WorkflowSimulationService::class)->dryRun($instance->refresh());

        $this->assertTrue($after['healthy'], 'Dry run bersih setelah approver ditetapkan.');
    }

    public function test_rantai_audit_mendeteksi_penyuntingan_diam_diam(): void
    {
        $this->registerTestProvider();
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');

        $graph = $this->linearGraph([['key' => 'step', 'name' => 'Approver', 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'AUDIT_FLOW');

        $instance = $this->submitInstance('AUDIT_FLOW', $requester);

        $audit = app(WorkflowAuditService::class);

        $this->assertTrue($audit->verifyChain($instance->id)['valid']);

        // Penyuntingan langsung di basis data (di luar aplikasi).
        DB::table('workflow_audit_logs')
            ->where('instance_id', $instance->id)
            ->orderBy('id')
            ->limit(1)
            ->update(['reason' => 'alasan yang diubah']);

        $verification = $audit->verifyChain($instance->id);

        $this->assertFalse($verification['valid']);
        $this->assertNotNull($verification['broken_at']);
    }

    public function test_konteks_dibekukan_dengan_hash_yang_dapat_diverifikasi(): void
    {
        $this->registerTestProvider(['amount' => 1500000, 'department' => 'KEUANGAN']);
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');

        $graph = $this->linearGraph([['key' => 'step', 'name' => 'Approver', 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]]]);
        $this->makeWorkflow($graph['nodes'], $graph['edges'], 'CONTEXT_FLOW', ['required_context' => ['amount', 'department']]);

        $instance = $this->submitInstance('CONTEXT_FLOW', $requester);

        $snapshot = $instance->contextSnapshots()->firstOrFail();

        $this->assertSame(1, $snapshot->submission_number);
        $this->assertSame('KEUANGAN', $snapshot->snapshot['department']);
        $this->assertSame($snapshot->hash, WorkflowContext::fromArray($snapshot->snapshot)->hash(), 'Hash snapshot dapat dihitung ulang dari isinya.');
    }

    public function test_trigger_modul_dapat_memulai_workflow_lewat_kontrak(): void
    {
        $this->registerTestProvider();
        $this->amountWorkflow();

        $requester = $this->makeUser('admin-sekolah');

        $trigger = new class implements WorkflowTriggerInterface
        {
            public function key(): string
            {
                return 'testing.purchase_request.submitted';
            }

            public function definitionCode(): string
            {
                return 'SIM_FLOW';
            }

            public function start(string|int $subjectId, array $payload = [], ?User $actor = null, bool $autoSubmit = true): WorkflowInstance
            {
                $service = app(WorkflowInstanceService::class);

                $instance = $service->create([
                    'definition_code' => $this->definitionCode(),
                    'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
                    'subject_id' => (string) $subjectId,
                    'subject_reference' => (string) $subjectId,
                    'title' => 'Pengadaan '.$subjectId,
                    'requester_id' => $actor?->id,
                    'context' => $payload['context'] ?? [],
                ], $actor);

                return $autoSubmit ? $service->submit($instance, $actor, null, $payload) : $instance;
            }
        };

        app(WorkflowRegistrar::class)->registerTrigger($trigger);

        $this->assertSame($trigger, app(WorkflowRegistrar::class)->trigger('testing.purchase_request.submitted'));

        $instance = $trigger->start('PR-2026-001', ['context' => ['amount' => 2500000]], $requester);

        $this->assertSame('WAITING_APPROVAL', $instance->status->value);
        $this->assertSame('PR-2026-001', $instance->subject_reference);
        $this->assertSame('SIM_FLOW', $instance->definition?->code ?? WorkflowDefinition::query()->find($instance->definition_id)->code);
    }

    public function test_perintah_artisan_trigger_dan_health_berjalan(): void
    {
        $this->registerTestProvider();
        $this->amountWorkflow();

        $requester = $this->makeUser('admin-sekolah');

        $exit = Artisan::call('workflow:trigger', [
            'definition' => 'SIM_FLOW',
            'subject' => 'PR-CLI-1',
            '--requester' => $requester->id,
            '--context' => json_encode(['amount' => 15000000]),
        ]);

        if ($exit !== 0) {
            fwrite(STDERR, "\nTRIGGER OUTPUT: ".Artisan::output()."\n");
        }

        $this->assertSame(0, $exit);
        $this->assertSame(1, WorkflowInstance::query()->count());

        $instance = WorkflowInstance::query()->firstOrFail();
        $this->assertSame('WAITING_APPROVAL', $instance->status->value);

        $health = Artisan::call('workflow:health');
        $this->assertSame(0, $health);

        // Perintah kedaluwarsa juga dapat dijalankan tanpa galat.
        $this->assertSame(0, Artisan::call('workflow:expire'));
    }
}
