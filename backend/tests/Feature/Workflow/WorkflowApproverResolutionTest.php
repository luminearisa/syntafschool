<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Application\Services\ApproverResolverService;
use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Enums\OrgScopeType;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * §22–§27, §38 — resolusi approver: user, role, manager, departemen, cost center,
 * jabatan, resolver dinamis modul, penyaringan pengguna aktif, dan delegasi.
 */
class WorkflowApproverResolutionTest extends WorkflowTestCase
{
    protected function resolver(): ApproverResolverService
    {
        return app(ApproverResolverService::class);
    }

    protected function context(?int $requesterId, array $extra = []): WorkflowContext
    {
        return WorkflowContext::fromArray(array_merge([
            'amount' => 2000000,
            'department' => 'SARANA',
            'cost_center_id' => 7,
        ], $extra), [
            'requester_id' => $requesterId,
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'definition_code' => 'TEST_APPROVAL',
            'module' => 'testing',
        ]);
    }

    public function test_resolver_user_dan_role_menghasilkan_approver_aktif(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $bendahara = $this->makeUser('bendahara', ['name' => 'Bendahara Uji']);
        $inactive = $this->makeUser('bendahara', ['is_active' => false]);

        $node = ['approvers' => [
            ['resolver' => 'user', 'user_id' => $bendahara->id],
            ['resolver' => 'user', 'user_id' => $inactive->id],
            ['resolver' => 'role', 'role' => 'kepala-sekolah'],
        ]];

        $kepala = $this->makeUser('kepala-sekolah');

        $resolution = $this->resolver()->resolveForNode($node, $this->context($requester->id));

        $this->assertContains($bendahara->id, $resolution->userIds());
        $this->assertContains($kepala->id, $resolution->userIds());
        $this->assertNotContains($inactive->id, $resolution->userIds(), 'Akun nonaktif tidak boleh menjadi approver.');
    }

    public function test_resolver_manager_departemen_jabatan_dan_cost_center(): void
    {
        $requester = $this->makeUser('guru');
        $manager = $this->makeUser('kepala-sekolah');
        $unitHead = $this->makeUser('admin-sekolah');
        $positionHolder = $this->makeUser('bendahara');
        $costCenterManager = $this->makeUser('bendahara');

        $this->makeManager($requester, $manager);
        $this->assignScope(OrgScopeType::DEPARTMENT->value, 'SARANA', $unitHead);
        $this->assignScope(OrgScopeType::POSITION->value, 'Kepala Sekolah', $positionHolder);
        $this->assignScope(OrgScopeType::COST_CENTER->value, '7', $costCenterManager);

        $context = $this->context($requester->id);

        $this->assertSame([$manager->id], $this->resolver()->resolveUserIds([['resolver' => 'manager']], $context));
        $this->assertSame([$unitHead->id], $this->resolver()->resolveUserIds([['resolver' => 'department']], $context));
        $this->assertSame([$positionHolder->id], $this->resolver()->resolveUserIds([['resolver' => 'position', 'position' => 'Kepala Sekolah']], $context));
        $this->assertSame([$costCenterManager->id], $this->resolver()->resolveUserIds([['resolver' => 'cost_center']], $context));
    }

    public function test_requester_dikeluarkan_dari_daftar_approver_kecuali_diizinkan(): void
    {
        $requester = $this->makeUser('bendahara');

        $node = ['approvers' => [['resolver' => 'user', 'user_id' => $requester->id]]];

        $blocked = $this->resolver()->resolveForNode($node, $this->context($requester->id), false);
        $this->assertTrue($blocked->isEmpty());
        $this->assertNotEmpty($blocked->warnings);

        $allowed = $this->resolver()->resolveForNode(['approvers' => $node['approvers'], 'self_approval_rule' => 'ALLOW'], $this->context($requester->id), false);
        $this->assertSame([$requester->id], $allowed->userIds());
    }

    public function test_resolver_dinamis_modul_lain_dapat_didaftarkan(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $waliKamar = $this->makeUser('musyrif');

        $this->registerTestResolver('boarding.musyrif_kamar', [$waliKamar->id]);

        $specs = [['resolver' => 'dynamic', 'resolver_alias' => 'boarding.musyrif_kamar', 'params' => ['dormitory_id' => 3]]];

        $this->assertSame([$waliKamar->id], $this->resolver()->resolveUserIds($specs, $this->context($requester->id)));

        $validation = $this->resolver()->validateNodeConfig([
            'key' => 'check', 'name' => 'Cek Musyrif', 'approvers' => $specs,
        ]);

        $this->assertSame([], $validation);

        $unknown = $this->resolver()->validateNodeConfig([
            'key' => 'check', 'name' => 'Cek Musyrif', 'approvers' => [['resolver' => 'dynamic', 'resolver_alias' => 'boarding.tidak_ada']],
        ]);

        $this->assertNotEmpty($unknown);
    }

    public function test_delegasi_aktif_mengalihkan_penugasan_tanpa_menghapus_identitas_delegator(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $delegator = $this->makeUser('bendahara');
        $delegate = $this->makeUser('kepala-sekolah');

        app(DelegationService::class)->create($delegator, [
            'delegate_id' => $delegate->id,
            'start_at' => now()->subDay()->toDateTimeString(),
            'end_at' => now()->addWeek()->toDateTimeString(),
            'reason' => 'Dinas luar kota',
            'scope' => ['definitions' => ['TEST_APPROVAL']],
        ], $delegator);

        $resolution = $this->resolver()->resolveForNode(
            ['approvers' => [['resolver' => 'user', 'user_id' => $delegator->id]]],
            $this->context($requester->id),
        );

        $this->assertSame([$delegate->id], $resolution->userIds());
        $this->assertTrue($resolution->approvers[0]->isDelegate);
        $this->assertSame($delegator->id, $resolution->approvers[0]->originalUserId);
        $this->assertNotNull($resolution->approvers[0]->delegationId);
    }

    public function test_delegasi_dengan_cakupan_lain_tidak_dipakai(): void
    {
        $requester = $this->makeUser('admin-sekolah');
        $delegator = $this->makeUser('bendahara');
        $delegate = $this->makeUser('kepala-sekolah');

        app(DelegationService::class)->create($delegator, [
            'delegate_id' => $delegate->id,
            'start_at' => now()->subDay()->toDateTimeString(),
            'end_at' => now()->addWeek()->toDateTimeString(),
            'scope' => ['definitions' => ['LEAVE_REQUEST_APPROVAL']],
        ], $delegator);

        $resolution = $this->resolver()->resolveForNode(
            ['approvers' => [['resolver' => 'user', 'user_id' => $delegator->id]]],
            $this->context($requester->id),
        );

        $this->assertSame([$delegator->id], $resolution->userIds(), 'Cakupan delegasi yang tidak cocok tidak boleh mengalihkan tugas.');
    }
}
