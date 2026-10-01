<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * §71 — otorisasi API, kotak masuk, pembatalan, dan alur requester.
 */
class WorkflowApiTest extends WorkflowTestCase
{
    protected function simpleWorkflow(string $code = 'API_FLOW'): array
    {
        $requester = $this->makeUser('admin-sekolah');
        $approver = $this->makeUser('bendahara');

        $graph = $this->linearGraph([[
            'key' => 'step', 'name' => 'Bendahara',
            'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]],
        ]]);

        $this->makeWorkflow($graph['nodes'], $graph['edges'], $code);

        return ['requester' => $requester, 'approver' => $approver];
    }

    public function test_requester_membuat_dan_melihat_requestnya_lewat_api(): void
    {
        ['requester' => $requester] = $this->simpleWorkflow('API_FLOW_A');
        $this->registerTestProvider();
        $this->actAs($requester);

        $created = $this->postJson('/api/v1/workflows/instances', [
            'definition_code' => 'API_FLOW_A',
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => 'PR-2026-000777',
            'title' => 'Pembelian ATK',
            'context' => ['amount' => 2500000],
            'submit' => false,
        ])->assertCreated();

        $instanceId = $created->json('data.id');

        $this->assertSame('DRAFT', $created->json('data.status'), 'Tanpa `submit`, request hanya menjadi draf.');
        $this->assertNotEmpty($created->json('data.code'));

        $submitted = $this->postJson("/api/v1/workflows/instances/{$instanceId}/submit")->assertOk();

        $this->assertSame('WAITING_APPROVAL', $submitted->json('data.status'));
        $this->assertSame(1, (int) $submitted->json('data.submission_number'));

        $this->getJson("/api/v1/workflows/instances/{$instanceId}")->assertOk()
            ->assertJsonPath('data.code', $created->json('data.code'));

        $this->getJson('/api/v1/workflows/inbox/requests')->assertOk()
            ->assertJsonPath('data.0.code', $created->json('data.code'));

        $counter = $this->getJson('/api/v1/workflows/inbox/counter')->assertOk();
        $this->assertSame(1, $counter->json('data.my_requests_open'));
    }

    public function test_approver_melihat_penugasan_dan_dapat_memberi_keputusan(): void
    {
        ['requester' => $requester, 'approver' => $approver] = $this->simpleWorkflow('API_FLOW_B');
        $this->registerTestProvider();

        $this->actAs($requester);
        $id = $this->postJson('/api/v1/workflows/instances', [
            'definition_code' => 'API_FLOW_B',
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => 'PR-API-1',
            'subject_reference' => 'PR-API-1',
            'title' => 'Pembelian Proyektor',
            'context' => ['amount' => 3000000],
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $this->actAs($approver);

        $inbox = $this->getJson('/api/v1/workflows/inbox/approvals')->assertOk();
        $this->assertSame(1, $inbox->json('meta.total'));
        $this->assertSame('PR-API-1', $inbox->json('data.0.subject_reference'));
        $this->assertStringStartsWith('WF-', $inbox->json('data.0.instance_code'));

        $approvalId = $inbox->json('data.0.id');

        $decision = $this->withHeaders(['Idempotency-Key' => 'kunci-1'])
            ->postJson("/api/v1/workflows/approvals/{$approvalId}/approve", ['comment' => 'Disetujui'])
            ->assertOk();

        $this->assertSame('APPROVE', $decision->json('data.decision'));
        $this->assertSame('APPROVED', $decision->json('data.instance.status'));

        // Klik ulang dengan kunci idempoten yang sama: hasil yang sama, bukan galat.
        $this->withHeaders(['Idempotency-Key' => 'kunci-1'])
            ->postJson("/api/v1/workflows/approvals/{$approvalId}/approve", ['comment' => 'Disetujui'])
            ->assertOk();

        // Kunci idempoten berbeda untuk penugasan yang sudah diputuskan:
        // konflik yang jelas (HTTP 409), bukan keputusan kedua.
        $this->withHeaders(['Idempotency-Key' => 'kunci-2'])
            ->postJson("/api/v1/workflows/approvals/{$approvalId}/approve", ['comment' => 'Lagi'])
            ->assertStatus(409);

        $instance = WorkflowInstance::query()->findOrFail($id);
        $this->assertSame(1, $instance->decisions()->count());
    }

    public function test_komentar_wajib_pada_penolakan_ditegakkan(): void
    {
        ['requester' => $requester, 'approver' => $approver] = $this->simpleWorkflow('API_FLOW_C');
        $this->registerTestProvider();

        $this->actAs($requester);
        $this->postJson('/api/v1/workflows/instances', [
            'definition_code' => 'API_FLOW_C',
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => 'PR-API-2',
            'title' => 'Pembelian Kursi',
            'context' => ['amount' => 4000000],
            'submit' => true,
        ])->assertCreated();

        $this->actAs($approver);
        $approvalId = $this->getJson('/api/v1/workflows/inbox/approvals')->json('data.0.id');

        $this->postJson("/api/v1/workflows/approvals/{$approvalId}/reject", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keputusan Tolak mewajibkan komentar/alasan.');

        $this->postJson("/api/v1/workflows/approvals/{$approvalId}/reject", ['comment' => 'Harga di atas pasar'])
            ->assertOk();
    }

    public function test_pengguna_tanpa_izin_tidak_boleh_mengubah_definisi(): void
    {
        $this->simpleWorkflow('API_FLOW_D');

        $teacher = $this->makeUser('guru');
        $this->actAs($teacher);

        $this->getJson('/api/v1/workflows/definitions')->assertStatus(403);

        $this->postJson('/api/v1/workflows/definitions', [
            'code' => 'SELUNDUPAN',
            'name' => 'Tidak boleh',
        ])->assertStatus(403);
    }

    public function test_workflow_admin_tidak_dapat_menyetujui_request_bisnis(): void
    {
        ['requester' => $requester, 'approver' => $approver] = $this->simpleWorkflow('API_FLOW_E');
        $this->registerTestProvider();

        $this->actAs($requester);
        $this->postJson('/api/v1/workflows/instances', [
            'definition_code' => 'API_FLOW_E',
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => 'PR-API-3',
            'title' => 'Pembelian Meja',
            'context' => ['amount' => 1000000],
            'submit' => true,
        ])->assertCreated();

        $admin = $this->makeUser('WORKFLOW_ADMIN');
        $this->actAs($admin);

        // Boleh melihat konfigurasi…
        $this->getJson('/api/v1/workflows/definitions')->assertOk();

        // …tetapi bukan memutuskan persetujuan bisnis (§10).
        $this->postJson('/api/v1/workflows/approvals/1/approve')->assertStatus(403);
    }

    public function test_requester_membatalkan_request_dan_approver_tidak_bisa_lagi_memutuskan(): void
    {
        ['requester' => $requester, 'approver' => $approver] = $this->simpleWorkflow('API_FLOW_F');
        $this->registerTestProvider();

        $this->actAs($requester);
        $id = $this->postJson('/api/v1/workflows/instances', [
            'definition_code' => 'API_FLOW_F',
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => 'PR-API-4',
            'title' => 'Pembelian Tenda',
            'context' => ['amount' => 2000000],
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $approvalId = $this->pendingApprovalFor(WorkflowInstance::query()->findOrFail($id), $approver)->id;

        $this->postJson("/api/v1/workflows/instances/{$id}/cancel", ['reason' => 'Salah input nominal'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->actAs($approver);
        $this->postJson("/api/v1/workflows/approvals/{$approvalId}/approve")
            ->assertStatus(409);

        $instance = WorkflowInstance::query()->findOrFail($id);
        $this->assertSame('CANCELLED', $instance->status->value);
        $this->assertSame('Salah input nominal', $instance->cancel_reason);
    }

    public function test_request_milik_orang_lain_tidak_dapat_dibaca(): void
    {
        ['requester' => $requester] = $this->simpleWorkflow('API_FLOW_G');
        $this->registerTestProvider();

        $this->actAs($requester);
        $id = $this->postJson('/api/v1/workflows/instances', [
            'definition_code' => 'API_FLOW_G',
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => 'PR-API-5',
            'title' => 'Pembelian Rak',
            'context' => ['amount' => 2000000],
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $outsider = $this->makeUser('guru');
        $this->actAs($outsider);

        $this->getJson("/api/v1/workflows/instances/{$id}")->assertStatus(403);

        // Auditor memang boleh membaca semuanya (§12), tanpa hak menulis.
        $auditor = $this->makeUser('AUDITOR');
        $this->actAs($auditor);

        $this->getJson("/api/v1/workflows/instances/{$id}")->assertOk();
        $this->postJson("/api/v1/workflows/instances/{$id}/cancel", ['reason' => 'mencoba'])->assertStatus(403);
    }

    public function test_meta_dan_daftar_definisi_tersedia_bagi_admin_workflow(): void
    {
        $this->simpleWorkflow('API_FLOW_H');
        $admin = $this->makeUser('WORKFLOW_ADMIN');
        $this->actAs($admin);

        $this->getJson('/api/v1/workflows/meta')->assertOk()
            ->assertJsonStructure(['data' => ['resolvers', 'actions', 'node_types', 'quorum_policies', 'condition_operators']]);

        $this->getJson('/api/v1/workflows/definitions')->assertOk()
            ->assertJsonPath('data.0.code', 'API_FLOW_H');

        $definition = WorkflowDefinition::query()->where('code', 'API_FLOW_H')->firstOrFail();

        $this->getJson("/api/v1/workflows/definitions/{$definition->id}")->assertOk()
            ->assertJsonStructure(['data' => ['versions', 'current_version']]);
    }

    public function test_instance_tidak_dapat_dibuat_dari_definisi_nonaktif(): void
    {
        $this->registerTestProvider();

        $actor = $this->makeUser('WORKFLOW_ADMIN');
        $approver = $this->makeUser('bendahara');

        $graph = $this->linearGraph([['key' => 'step', 'name' => 'Approver', 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]]]);

        $definition = $this->makeWorkflow($graph['nodes'], $graph['edges'], 'API_FLOW_I');

        app(\App\Modules\Workflow\Application\Services\WorkflowDefinitionService::class)
            ->deactivate($definition, $actor, 'Alur diganti');

        $requester = $this->makeUser('admin-sekolah');
        $this->actAs($requester);

        $this->postJson('/api/v1/workflows/instances', [
            'definition_code' => 'API_FLOW_I',
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => 'PR-API-6',
            'title' => 'Tidak boleh',
            'context' => ['amount' => 1000000],
        ])->assertStatus(422);
    }
}
