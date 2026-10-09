<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Modules\Workflow\Application\Services\WorkflowBuilderService;
use App\Modules\Workflow\Application\Services\WorkflowDefinitionService;
use App\Modules\Workflow\Domain\Contracts\ApproverResolverInterface;
use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowActionContext;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Domain\Contracts\WorkflowContextProviderInterface;
use App\Modules\Workflow\Domain\Contracts\WorkflowSubjectStatusInterface;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowOrgAssignment;
use App\Modules\Workflow\Models\WorkflowOrgReport;
use App\Modules\Workflow\Models\WorkflowVersion;
use App\Modules\Workflow\Database\Seeders\WorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dasar pengujian Workflow & Approval Engine.
 *
 * Menyiapkan: peran & izin workflow, helper pembuat definisi (lewat builder yang
 * sama dengan yang dipakai admin), peta organisasi, serta **provider konteks
 * uji** dan **aksi uji** yang mendaftarkan diri lewat `WorkflowRegistrar` —
 * membuktikan bahwa titik ekstensi benar-benar dapat dipakai modul lain.
 */
abstract class WorkflowTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ROLES = [
        'super-admin', 'admin-sekolah', 'kepala-sekolah', 'bendahara',
        'ERP_ADMIN', 'WORKFLOW_ADMIN', 'WORKFLOW_MANAGER', 'AUDITOR',
        // Peran ERP lain yang dipakai sebagai approver pada pengujian resolusi.
        'guru', 'musyrif', 'orang-tua',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(WorkflowPermissionSeeder::class);
    }

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function actAs(User $user): User
    {
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    /** Atasan langsung untuk resolusi `manager`. */
    protected function makeManager(User $subordinate, User $manager, ?User $backup = null): WorkflowOrgReport
    {
        return WorkflowOrgReport::query()->updateOrCreate(
            ['user_id' => $subordinate->id],
            ['manager_user_id' => $manager->id, 'backup_user_id' => $backup?->id],
        );
    }

    /** Kepala unit (department) atau pemegang jabatan/cost center. */
    protected function assignScope(string $scopeType, string $scopeKey, User $user, bool $primary = true): WorkflowOrgAssignment
    {
        return WorkflowOrgAssignment::query()->updateOrCreate(
            ['scope_type' => $scopeType, 'scope_key' => $scopeKey, 'user_id' => $user->id],
            ['is_primary' => $primary, 'is_active' => true],
        );
    }

    /**
     * Buat definisi + versi terbit lengkap dengan graf.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $config
     */
    public function makeWorkflow(
        array $nodes,
        array $edges,
        string $code = 'TEST_APPROVAL',
        array $definition = [],
        array $config = [],
        bool $publish = true,
        ?User $actor = null,
    ): WorkflowDefinition {
        $actor ??= $this->makeUser('WORKFLOW_ADMIN');

        $definitions = app(WorkflowDefinitionService::class);
        $builder = app(WorkflowBuilderService::class);

        $model = WorkflowDefinition::query()->where('code', $code)->first()
            ?? $definitions->create(array_merge([
                'code' => $code,
                'name' => 'Uji '.$code,
                'module' => 'testing',
                'entity_type' => TestSubjectProvider::SUBJECT_TYPE,
                'required_context' => ['amount'],
            ], $definition), $actor);

        $version = $builder->createVersion($model, $actor, null, 'v1', false);

        $builder->saveGraph($version, $nodes, $edges, [], [], $actor, $config + [
            'comment_required_on' => ['REJECT', 'RETURN', 'REQUEST_CHANGES'],
            'empty_approver' => 'PAUSE',
        ]);

        if ($publish) {
            $builder->publish($version->refresh(), $actor, 'versi uji');
        }

        return $model->refresh();
    }

    /** Graf linear sederhana: START → N step → END. */
    public function linearGraph(array $steps, ?array $endActions = null): array
    {
        $nodes = [['key' => 'start', 'type' => 'START', 'name' => 'Mulai', 'sequence' => 1]];
        $edges = [];

        $previous = 'start';

        foreach (array_values($steps) as $index => $step) {
            $key = (string) ($step['key'] ?? 'step_'.($index + 1));
            $nodes[] = array_merge([
                'key' => $key,
                'type' => 'APPROVAL',
                'name' => 'Step '.($index + 1),
                'sequence' => $index + 2,
                'approval_mode' => 'PARALLEL',
                'quorum_policy' => 'ALL',
            ], $step);

            $edges[] = ['from_key' => $previous, 'to_key' => $key, 'priority' => 0];
            $previous = $key;
        }

        $nodes[] = array_merge(
            ['key' => 'end', 'type' => 'END', 'name' => 'Selesai', 'sequence' => count($steps) + 2],
            $endActions === null ? [] : ['actions' => $endActions],
        );
        $edges[] = ['from_key' => $previous, 'to_key' => 'end', 'priority' => 0];

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Provider konteks uji untuk subject_type `test.request`.
     *
     * Menerima data tetap (array) atau pemeta per-subjek (callable) supaya satu
     * pengujian dapat mensimulasikan beberapa nominal dari entitas yang sama.
     */
    protected function registerTestProvider(array|\Closure $data = [], string $statusTo = 'blank'): TestSubjectProvider
    {
        $provider = new TestSubjectProvider($data, $statusTo);
        app(WorkflowRegistrar::class)->registerContextProvider($provider);

        return $provider;
    }

    /** Resolver uji untuk membuktikan titik ekstensi resolver (§22). */
    protected function registerTestResolver(string $alias, array $userIds): void
    {
        app(WorkflowRegistrar::class)->registerResolver(new class($alias, $userIds) implements ApproverResolverInterface
        {
            public function __construct(private string $alias, private array $userIds)
            {
            }

            public function alias(): string
            {
                return $this->alias;
            }

            public function description(): string
            {
                return 'Resolver uji dari modul lain.';
            }

            public function requiredSpec(): array
            {
                return [];
            }

            public function resolve(WorkflowContext $context, array $spec = []): array
            {
                return array_map(
                    fn (int $id) => new ResolvedApprover($id, ApproverSource::DYNAMIC, $this->alias),
                    $this->userIds,
                );
            }
        });
    }

    /** Aksi uji untuk membuktikan titik ekstensi aksi (§43). */
    protected function registerTestAction(string $alias, ?callable $handler = null): void
    {
        app(WorkflowRegistrar::class)->registerAction(new class($alias, $handler) implements WorkflowActionInterface
        {
            /** @var list<array<string, mixed>> */
            public static array $calls = [];

            public function __construct(private string $alias, private mixed $handler)
            {
            }

            public function alias(): string
            {
                return $this->alias;
            }

            public function description(): string
            {
                return 'Aksi uji dari modul lain.';
            }

            public function execute(WorkflowActionContext $context, array $params = []): array
            {
                self::$calls[] = ['instance' => $context->instance->code, 'params' => $params];

                if ($this->handler !== null) {
                    ($this->handler)($context, $params);
                }

                return ['ok' => true];
            }
        });
    }

    /** Buat instance lengkap lalu submit. */
    protected function submitInstance(string $definitionCode, User $requester, array $context = [], ?string $title = null): WorkflowInstance
    {
        $instances = app(\App\Modules\Workflow\Application\Services\WorkflowInstanceService::class);

        $payload = array_merge(['amount' => 1500000], $context);

        $instance = $instances->create([
            'definition_code' => $definitionCode,
            'subject_type' => TestSubjectProvider::SUBJECT_TYPE,
            'subject_id' => (string) ($context['subject_id'] ?? 'REQ-'.random_int(1000, 9999)),
            'title' => $title ?? 'Permintaan uji',
            'requester_id' => $requester->id,
            'context' => $payload,
        ], $requester);

        return $instances->submit($instance, $requester, null, ['context' => $payload]);
    }

    /** Ambil penugasan yang menunggu untuk seorang pengguna pada instance. */
    protected function pendingApprovalFor(WorkflowInstance $instance, User $user): ?\App\Modules\Workflow\Models\WorkflowApproval
    {
        return $instance->approvals()
            ->where('approver_id', $user->id)
            ->where('status', 'PENDING')
            ->latest('id')
            ->first();
    }

    protected function currentVersion(WorkflowDefinition $definition): WorkflowVersion
    {
        return $definition->refresh()->currentVersion;
    }
}

/**
 * Provider konteks uji — mewakili modul ERP yang menyumbang data ke workflow.
 */
class TestSubjectProvider implements WorkflowContextProviderInterface, WorkflowSubjectStatusInterface
{
    public const SUBJECT_TYPE = 'test.request';

    /** @var list<array<string, mixed>> */
    public array $appliedStatus = [];

    public function __construct(private array|\Closure $data = [], private string $statusTo = 'blank')
    {
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function requiredKeys(): array
    {
        return ['amount'];
    }

    public function provide(string|int $subjectId): array
    {
        $data = is_callable($this->data) ? ($this->data)($subjectId) : $this->data;

        return array_merge([
            'amount' => 1500000,
            'department' => 'SARANA',
            'cost_center_id' => null,
            'category' => 'general',
        ], $data);
    }

    public function describe(string|int $subjectId): array
    {
        return ['reference' => (string) $subjectId, 'title' => 'Permintaan uji '.$subjectId];
    }

    public function applyStatus(string|int $subjectId, string $status, array $meta = []): void
    {
        $this->appliedStatus[] = ['subject' => (string) $subjectId, 'status' => $status, 'meta' => $meta];
    }
}
