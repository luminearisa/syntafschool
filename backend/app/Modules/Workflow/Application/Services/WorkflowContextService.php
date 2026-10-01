<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowContextSnapshot;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Perakit & pembeku konteks workflow (§31, §65).
 *
 * Engine tidak pernah membaca tabel bisnis. Ia meminta konteks kepada provider
 * yang didaftarkan modul pemilik subjek (`WorkflowContextProviderInterface`),
 * lalu menyimpannya sebagai snapshot dengan `hash` yang dapat diverifikasi.
 *
 * Alur:
 * ```
 * subject (PR-2026-000123)
 *      ↓ provider modul (finance.purchase_request)
 * WorkflowContext (amount, department, cost_center, ...)
 *      ↓ divalidasi terhadap required_context definisi
 * snapshot beku + hash  → workflow_contexts
 * ```
 *
 * Untuk request administratif/manual (TriggerType::MANUAL) — mis. pengajuan
 * yang datanya diisi langsung di layar — konteks diambil dari payload yang
 * dikirim pemanggil. Ini tetap satu-satunya jalur masuk data ke engine, jadi
 * tidak ada pintu belakang menuju basis data bisnis.
 */
class WorkflowContextService
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * Rakit konteks untuk sebuah subjek.
     *
     * @param  array<string, mixed>  $payload  data tambahan/manual dari pemanggil
     * @param  list<string>  $requiredKeys  kunci yang wajib ada (dari definisi)
     */
    public function build(
        string $subjectType,
        string|int $subjectId,
        ?User $requester = null,
        array $payload = [],
        array $requiredKeys = [],
        ?WorkflowDefinition $definition = null,
    ): WorkflowContext {
        $provider = $this->registrar->contextProvider($subjectType);

        $data = $provider !== null
            ? array_merge($payload, $provider->provide($subjectId))
            : $payload;

        $requiredKeys = $requiredKeys !== []
            ? $requiredKeys
            : ($provider?->requiredKeys() ?? []);

        $missing = array_values(array_filter(
            $requiredKeys,
            fn (string $key) => ! array_key_exists($key, $data) || $data[$key] === null,
        ));

        if ($missing !== []) {
            throw new DomainException(sprintf(
                'Konteks workflow belum lengkap: %s. Modul %s harus menyediakan data ini sebelum submit.',
                implode(', ', $missing),
                $subjectType,
            ));
        }

        if ($definition !== null) {
            $this->assertDefinitionContext($definition, $data);
        }

        return WorkflowContext::fromArray($data, [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'requester_id' => $requester?->id,
            'provider' => $provider !== null ? $provider::class : 'manual',
        ]);
    }

    /**
     * Bekukan konteks menjadi snapshot (dipanggil saat submit/revisi).
     */
    public function freeze(WorkflowInstance $instance, WorkflowContext $context, int $submissionNumber): WorkflowContextSnapshot
    {
        $snapshot = WorkflowContextSnapshot::query()->create([
            'instance_id' => $instance->id,
            'submission_number' => $submissionNumber,
            'provider' => (string) ($context->meta()['provider'] ?? 'manual'),
            'snapshot' => $context->toArray(),
            'hash' => $context->hash(),
            'resolved_at' => now(),
            'created_at' => now(),
        ]);

        $this->audit->record(
            AuditAction::CONTEXT_CAPTURED,
            $instance,
            $snapshot,
            after: ['hash' => $snapshot->hash, 'submission_number' => $submissionNumber],
            extra: ['step_instance_id' => null],
        );

        return $snapshot;
    }

    /** Konteks beku terbaru sebuah instance (dipakai resolver, kondisi, aksi). */
    public function forInstance(WorkflowInstance $instance, ?int $submissionNumber = null): WorkflowContext
    {
        $query = WorkflowContextSnapshot::query()->where('instance_id', $instance->id);

        if ($submissionNumber !== null) {
            $query->where('submission_number', $submissionNumber);
        }

        /** @var WorkflowContextSnapshot|null $snapshot */
        $snapshot = $query->orderByDesc('submission_number')->first();

        if ($snapshot === null) {
            return WorkflowContext::fromArray(
                (array) ($instance->context_summary ?? []),
                [
                    'subject_type' => $instance->subject_type,
                    'subject_id' => $instance->subject_id,
                    'requester_id' => $instance->requester_id,
                    'instance_id' => $instance->id,
                ],
            );
        }

        return WorkflowContext::fromSnapshot($snapshot->snapshot, [
            'instance_id' => $instance->id,
        ]);
    }

    /** Ringkasan konteks untuk kartu inbox (tanpa membocorkan seluruh isi). */
    public function summarize(WorkflowContext $context): array
    {
        $keys = ['amount', 'currency', 'department', 'cost_center_id', 'category', 'priority', 'program', 'campus', 'request_type'];

        $summary = [];

        foreach ($keys as $key) {
            if ($context->has($key) && ! $context->isEmpty($key)) {
                $summary[$key] = $context->get($key);
            }
        }

        $summary['hash'] = $context->hash();

        return $summary;
    }

    /** Label dokumen untuk daftar/kartu. */
    public function describe(string $subjectType, string|int $subjectId): array
    {
        $provider = $this->registrar->contextProvider($subjectType);

        if ($provider === null) {
            return [];
        }

        return $provider->describe($subjectId);
    }

    /** Apakah ada provider yang mengenali subjek ini. */
    public function hasProvider(string $subjectType): bool
    {
        return $this->registrar->contextProvider($subjectType) !== null;
    }

    /** @return list<string> */
    public function knownSubjectTypes(): array
    {
        return array_keys($this->registrar->contextProviders());
    }

    /**
     * Kesegaran snapshot: berapa hari sejak konteks dibekukan. Request yang
     * menganggur lama tetap dapat diaudit tanpa berpura-pura datanya baru.
     */
    public function ageInDays(WorkflowInstance $instance): ?int
    {
        $resolved = WorkflowContextSnapshot::query()
            ->where('instance_id', $instance->id)
            ->max('resolved_at');

        return $resolved === null ? null : (int) now()->diffInDays($resolved);
    }

    protected function assertDefinitionContext(WorkflowDefinition $definition, array $data): void
    {
        $required = (array) ($definition->required_context ?? []);

        $missing = array_values(array_filter(
            $required,
            fn (string $key) => ! array_key_exists($key, $data) || $data[$key] === null,
        ));

        if ($missing !== []) {
            throw new DomainException(sprintf(
                'Definisi %s mensyaratkan konteks: %s.',
                $definition->code,
                implode(', ', $missing),
            ));
        }
    }

    /** Statistik ringkas pemakaian provider (dasbor admin). */
    public function providerUsage(int $days = 30): array
    {
        return WorkflowContextSnapshot::query()
            ->select('provider', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('provider')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['provider' => $row->provider, 'total' => (int) $row->total])
            ->all();
    }
}
