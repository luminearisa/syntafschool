<?php

namespace App\Modules\LMS\Application\Services;

/**
 * Deteksi siklus pada graf prasyarat course (§18).
 *
 * Dipisah dari `PrerequisiteGraphService` karena bekerja pada graf yang
 * **belum** disimpan (aturan baru sedang diuji) — service itu membaca dari
 * basis data.
 */
final class PrerequisiteCycleProbe
{
    /** @param array<int, list<int>> $graph */
    public function __construct(private readonly array $graph) {}

    /** @return list<int>|null jalur siklus pertama yang ditemukan */
    public function firstCycle(): ?array
    {
        $state = [];
        $path = [];

        foreach (array_keys($this->graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $cycle = $this->visit((int) $node, $state, $path);

                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $state
     * @param  list<int>  $path
     * @return list<int>|null
     */
    private function visit(int $node, array &$state, array &$path): ?array
    {
        $state[$node] = 1;
        $path[] = $node;

        foreach ($this->graph[$node] ?? [] as $next) {
            $next = (int) $next;

            if (($state[$next] ?? 0) === 1) {
                $offset = array_search($next, $path, true);

                return array_values($offset === false ? [$next] : array_slice($path, $offset));
            }

            if (($state[$next] ?? 0) === 0) {
                $cycle = $this->visit($next, $state, $path);

                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        $state[$node] = 2;
        array_pop($path);

        return null;
    }
}
