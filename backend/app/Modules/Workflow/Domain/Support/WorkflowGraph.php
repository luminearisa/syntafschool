<?php

namespace App\Modules\Workflow\Domain\Support;

use App\Modules\Workflow\Domain\Enums\NodeType;

/**
 * Graf workflow yang sudah dikompilasi (nodes + edges) beserta operasi navigasi.
 *
 * Kelas ini dipakai bersama oleh tiga hal yang tidak boleh berbeda pendapat:
 *  - **validator** saat publish (§55),
 *  - **executor** saat menjalankan alur (§46),
 *  - **simulator** saat menampilkan pratinjau jalur (§56).
 *
 * Semua data berbentuk array (bukan model Eloquent) supaya versi yang sudah
 * diarsipkan tetap dapat dibaca dari `compiled_graph` tanpa menyentuh tabel.
 */
final class WorkflowGraph
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public function __construct(
        private readonly array $nodes,
        private readonly array $edges,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public static function make(array $nodes, array $edges): self
    {
        return new self(array_values($nodes), array_values($edges));
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return array<string, mixed>|null */
    public function node(string $key): ?array
    {
        foreach ($this->nodes as $node) {
            if (($node['key'] ?? null) === $key) {
                return $node;
            }
        }

        return null;
    }

    public function typeOf(string $key): ?NodeType
    {
        $node = $this->node($key);
        $type = $node['type'] ?? null;

        return $type === null ? null : (NodeType::tryFrom((string) $type));
    }

    public function nameOf(string $key): string
    {
        return (string) ($this->node($key)['name'] ?? $key);
    }

    public function sequenceOf(string $key): int
    {
        return (int) ($this->node($key)['sequence'] ?? 0);
    }

    /** Node START pertama (validator menjamin hanya ada satu). */
    public function startNode(): ?array
    {
        foreach ($this->nodes as $node) {
            if (($node['type'] ?? null) === NodeType::START->value) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function endNodes(): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn (array $node) => ($node['type'] ?? null) === NodeType::END->value,
        ));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (array $node) => (string) $node['key'], $this->nodes);
    }

    /** @return list<array<string, mixed>> edge yang keluar dari node */
    public function outgoing(string $key): array
    {
        $edges = array_values(array_filter(
            $this->edges,
            fn (array $edge) => ($edge['from_key'] ?? null) === $key,
        ));

        usort($edges, fn (array $a, array $b) => ((int) ($a['priority'] ?? 0)) <=> ((int) ($b['priority'] ?? 0)));

        return $edges;
    }

    /** @return list<array<string, mixed>> */
    public function incoming(string $key): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (array $edge) => ($edge['to_key'] ?? null) === $key,
        ));
    }

    /** @return list<string> */
    public function successors(string $key): array
    {
        return array_values(array_unique(array_map(
            fn (array $edge) => (string) $edge['to_key'],
            $this->outgoing($key),
        )));
    }

    /** @return list<string> */
    public function predecessors(string $key): array
    {
        return array_values(array_unique(array_map(
            fn (array $edge) => (string) $edge['from_key'],
            $this->incoming($key),
        )));
    }

    /**
     * Node mana saja yang dapat dicapai dari `$key` (termasuk dirinya).
     *
     * @return list<string>
     */
    public function reachableFrom(string $key): array
    {
        $seen = [];
        $stack = [$key];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($this->successors($current) as $next) {
                if (! isset($seen[$next])) {
                    $stack[] = $next;
                }
            }
        }

        return array_keys($seen);
    }

    public function canReach(string $from, string $to): bool
    {
        return in_array($to, $this->reachableFrom($from), true);
    }

    /**
     * Siklus pada graf (§55 — circular dependency dilarang).
     *
     * @return list<list<string>> daftar siklus yang ditemukan
     */
    public function cycles(): array
    {
        $cycles = [];
        $visiting = [];
        $visited = [];

        $walk = function (string $node) use (&$walk, &$cycles, &$visiting, &$visited): void {
            if (isset($visited[$node])) {
                return;
            }

            if (isset($visiting[$node])) {
                $cycle = array_keys($visiting);
                $index = array_search($node, $cycle, true);

                if ($index !== false) {
                    $cycles[] = array_slice($cycle, $index);
                }

                return;
            }

            $visiting[$node] = true;

            foreach ($this->successors($node) as $next) {
                $walk($next);
            }

            unset($visiting[$node]);
            $visited[$node] = true;
        };

        foreach ($this->keys() as $key) {
            $walk($key);
        }

        return $cycles;
    }

    /**
     * Node yang tidak dapat dicapai dari START (orphan / unreachable, §55).
     *
     * @return list<string>
     */
    public function unreachableNodes(): array
    {
        $start = $this->startNode();

        if ($start === null) {
            return $this->keys();
        }

        $reachable = $this->reachableFrom((string) $start['key']);

        return array_values(array_diff($this->keys(), $reachable));
    }

    /** Titik temu pertama setelah cabang paralel, bila ada. */
    public function convergenceAfter(string $parallelKey): ?string
    {
        $branches = $this->successors($parallelKey);

        if ($branches === []) {
            return null;
        }

        $common = null;

        foreach ($branches as $branch) {
            $reachable = $this->reachableFrom($branch);
            $common = $common === null ? $reachable : array_values(array_intersect($common, $reachable));
        }

        // Konvergensi tidak boleh node percabangan/start itu sendiri.
        $candidates = array_values(array_filter(
            $common ?? [],
            fn (string $key) => $key !== $parallelKey && ! in_array($key, $branches, true),
        ));

        if ($candidates === []) {
            return null;
        }

        // Pilih yang paling dekat dari semua cabang.
        usort($candidates, function (string $a, string $b) use ($branches): int {
            $distance = fn (string $key): int => max(array_map(
                fn (string $branch) => count($this->reachableFrom($branch)) - count($this->reachableFrom($key)),
                $branches,
            ));

            return $distance($a) <=> $distance($b);
        });

        return $candidates[0];
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['nodes' => $this->nodes, 'edges' => $this->edges];
    }
}
