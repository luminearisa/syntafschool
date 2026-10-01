<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Application\Support\ApproverResolution;
use App\Modules\Workflow\Domain\Contracts\ApproverResolverInterface;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;

/**
 * Menerjemahkan konfigurasi approver menjadi daftar pengguna nyata (§22).
 *
 * Aturan yang dipegang:
 *
 *  - **Tanpa approver hard-code**: semua berasal dari resolver terdaftar.
 *  - **Deduplikasi**: satu orang satu penugasan, walau disebut dua kali.
 *  - **Pengguna aktif saja**: akun nonaktif/dihapus tidak pernah menjadi approver.
 *  - **Self-approval ditolak secara default** (§36): requester dibuang dari
 *    daftar kecuali definisi/node secara eksplisit mengizinkan.
 *  - **Kegagalan terlihat**: spesifikasi tanpa resolver, resolver tanpa hasil,
 *    atau semua approver tersaring menghasilkan `warnings` — bukan kegagalan senyap.
 */
class ApproverResolverService
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
    ) {
    }

    /**
     * Resolusi untuk sebuah node graf.
     *
     * @param  array<string, mixed>  $node
     */
    public function resolveForNode(array $node, WorkflowContext $context, bool $allowSelfApproval = false): ApproverResolution
    {
        $warnings = [];
        $specs = (array) ($node['approvers'] ?? []);
        $tiers = (array) ($node['tiers'] ?? []);

        $nodeRule = strtoupper((string) ($node['self_approval_rule'] ?? 'INHERIT'));
        $allowSelf = match ($nodeRule) {
            'ALLOW' => true,
            'FORBID' => false,
            default => $allowSelfApproval,
        };

        $collected = [];
        $tierUserIds = [];

        if ($tiers !== []) {
            foreach ($tiers as $index => $tierSpecs) {
                $resolution = $this->runSpecs((array) $tierSpecs, $context, $warnings, $node);
                $ids = $this->filterSelfApproval($resolution, $context, $allowSelf, $warnings);
                $tierUserIds[] = $ids['ids'];
                $collected = array_merge($collected, $ids['approvers']);
            }
        } else {
            $resolution = $this->runSpecs($specs, $context, $warnings, $node);
            $ids = $this->filterSelfApproval($resolution, $context, $allowSelf, $warnings);
            $collected = $ids['approvers'];
            $tierUserIds = [$ids['ids']];
        }

        $deduped = $this->dedupe($collected);

        if ($deduped === [] && $warnings === []) {
            $warnings[] = 'Tidak ada approver yang memenuhi syarat untuk step ini.';
        }

        return new ApproverResolution(
            array_values(array_filter($deduped, fn (ResolvedApprover $a) => $a->userId > 0)),
            $warnings,
            array_map('array_values', $tierUserIds),
        );
    }

    /**
     * Resolusi ad-hoc (dipakai eskalasi & penetapan approver manual).
     *
     * @param  list<array<string, mixed>>  $specs
     */
    public function resolveSpecs(array $specs, WorkflowContext $context): ApproverResolution
    {
        $warnings = [];
        $approvers = $this->runSpecs($specs, $context, $warnings, []);

        return new ApproverResolution(array_values($this->dedupe($approvers)), $warnings);
    }

    /** Kunci approver kandidat (mis. untuk simulasi & validasi). */
    public function resolveUserIds(array $specs, WorkflowContext $context): array
    {
        return $this->resolveSpecs($specs, $context)->userIds();
    }

    /**
     * Konfigurasi approver untuk sebuah node — dipakai builder & validator.
     *
     * @param  array<string, mixed>  $node
     * @return list<string> pesan kesalahan; kosong berarti sah
     */
    public function validateNodeConfig(array $node): array
    {
        $errors = [];
        $specs = array_merge((array) ($node['approvers'] ?? []), ...array_map('array_values', array_filter((array) ($node['tiers'] ?? []), 'is_array')));

        if ($specs === []) {
            return ['Step "'.($node['name'] ?? $node['key']).'" tidak memiliki resolver approver.'];
        }

        foreach ($specs as $index => $spec) {
            if (! is_array($spec)) {
                $errors[] = 'Spesifikasi approver #'.($index + 1).' pada step '.$node['key'].' tidak valid.';

                continue;
            }

            $alias = (string) ($spec['resolver'] ?? '');
            $resolver = $alias === '' ? null : $this->registrar->resolver($alias);

            if ($resolver === null) {
                $errors[] = "Step {$node['key']}: resolver `{$alias}` tidak terdaftar.";

                continue;
            }

            if ($alias === 'dynamic') {
                $inner = (string) ($spec['resolver_alias'] ?? '');

                if ($inner === '' || $this->registrar->resolver($inner) === null) {
                    $errors[] = "Step {$node['key']}: resolver dinamis `{$inner}` tidak terdaftar.";

                    continue;
                }

                $resolver = $this->registrar->resolver($inner);
            }

            foreach ($this->missingKeys($resolver, $spec) as $missing) {
                $errors[] = "Step {$node['key']}: resolver `{$alias}` membutuhkan `{$missing}`.";
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $spec */
    protected function missingKeys(ApproverResolverInterface $resolver, array $spec): array
    {
        $missing = [];

        foreach ($resolver->requiredSpec() as $key => $type) {
            if (! array_key_exists($key, $spec) && $key !== 'user_id') {
                $missing[] = $key;

                continue;
            }

            if ($key === 'user_id' && empty($spec['user_id'])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  list<array<string, mixed>>  $specs
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $node
     * @return list<ResolvedApprover>
     */
    protected function runSpecs(array $specs, WorkflowContext $context, array &$warnings, array $node): array
    {
        $approvers = [];

        foreach ($specs as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $alias = (string) ($spec['resolver'] ?? '');
            $resolver = $this->registrar->resolver($alias);

            if ($resolver === null) {
                $warnings[] = "Resolver `{$alias}` belum terdaftar; spesifikasi dilewati.";

                continue;
            }

            try {
                $result = $resolver->resolve($context, $spec);
            } catch (\Throwable $e) {
                $warnings[] = "Resolver `{$alias}` gagal: {$e->getMessage()}";

                continue;
            }

            if ($result === []) {
                $warnings[] = "Resolver `{$alias}` tidak menemukan approver untuk request ini.";
            }

            foreach ($result as $approver) {
                $approvers[] = $approver;
            }
        }

        return $approvers;
    }

    /**
     * Buang approver yang tidak aktif, tidak ada, atau requester sendiri.
     *
     * @param  list<ResolvedApprover>  $approvers
     * @param  list<string>  $warnings
     * @return array{approvers: list<ResolvedApprover>, ids: list<int>}
     */
    protected function filterSelfApproval(array $approvers, WorkflowContext $context, bool $allowSelfApproval, array &$warnings): array
    {
        $requesterId = $context->requesterId();

        $candidateIds = array_values(array_unique(array_map(fn (ResolvedApprover $a) => $a->userId, $approvers)));

        $activeIds = User::query()
            ->whereIn('id', $candidateIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $kept = [];
        $ids = [];

        foreach ($approvers as $approver) {
            if (! in_array($approver->userId, $activeIds, true)) {
                $warnings[] = "Pengguna #{$approver->userId} tidak aktif atau tidak ditemukan; dikeluarkan dari daftar approver.";

                continue;
            }

            if (! $allowSelfApproval && $requesterId !== null && $approver->userId === $requesterId) {
                $warnings[] = 'Requester dikeluarkan dari daftar approver (self-approval dilarang).';

                continue;
            }

            $kept[] = $approver;
            $ids[] = $approver->userId;
        }

        return ['approvers' => $kept, 'ids' => array_values(array_unique($ids))];
    }

    /**
     * @param  list<ResolvedApprover>  $approvers
     * @return list<ResolvedApprover>
     */
    protected function dedupe(array $approvers): array
    {
        $byUser = [];

        foreach ($approvers as $approver) {
            if (! isset($byUser[$approver->userId])) {
                $byUser[$approver->userId] = $approver;

                continue;
            }

            // Delegasi menang atas penugasan langsung: kewenangan sudah pindah.
            if ($approver->source === ApproverSource::DELEGATION && ! $byUser[$approver->userId]->isDelegate) {
                $byUser[$approver->userId] = $approver;
            }
        }

        return array_values($byUser);
    }
}
