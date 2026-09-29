<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsItemPrerequisite;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Support\Facades\DB;

/**
 * Graf prasyarat & deteksi siklus (§17, §18).
 *
 * Dua graf dijaga:
 *   1. **Item**   — `lms_item_prerequisites` di dalam satu course.
 *   2. **Course** — `lms_course_prerequisites` untuk rantai kurikulum.
 *
 * Siklus membuat keputusan akses berputar tanpa hasil (siswa terkunci
 * selamanya), jadi siklus harus ditolak **sebelum** aturan disimpan/terbit.
 */
class PrerequisiteGraphService
{
    /**
     * Siklus pada graf prasyarat item sebuah course.
     *
     * @return list<array{path:list<int>,labels:list<string>}>
     */
    public function itemCycles(LmsCourse $course): array
    {
        $rows = LmsItemPrerequisite::query()
            ->where('course_id', $course->id)
            ->whereNotNull('item_id')
            ->whereNotNull('prerequisite_item_id')
            ->get(['item_id', 'prerequisite_item_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $graph = [];
        foreach ($rows as $row) {
            $graph[(int) $row->item_id][] = (int) $row->prerequisite_item_id;
        }

        $labels = LmsLearningItem::query()
            ->whereIn('id', $this->nodeIds($graph))
            ->pluck('title', 'id')
            ->all();

        return $this->cycles($graph, $labels);
    }

    /**
     * Siklus pada graf prasyarat antar-course.
     *
     * @return list<array{path:list<int>,labels:list<string>}>
     */
    public function courseCycles(?LmsCourse $course = null): array
    {
        $query = DB::table('lms_course_prerequisites')
            ->select('course_id', 'prerequisite_course_id');

        if ($course !== null) {
            $query->where(fn ($q) => $q->where('course_id', $course->id)->orWhere('prerequisite_course_id', $course->id));
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $graph = [];
        foreach ($rows as $row) {
            $graph[(int) $row->course_id][] = (int) $row->prerequisite_course_id;
        }

        $labels = LmsCourse::query()->whereIn('id', $this->nodeIds($graph))->pluck('name', 'id')->all();

        return $this->cycles($graph, $labels);
    }

    /**
     * Apakah menambahkan `prerequisite` → `target` membuat siklus?
     *
     * Dipakai sebelum menyimpan aturan supaya pesan kesalahan dapat menyebut
     * jalur siklusnya, bukan sekadar "tidak boleh".
     *
     * @param  array<int, list<int>>|null  $graph  graf khusus (untuk pengujian)
     */
    public function wouldCreateItemCycle(int $target, int $prerequisite, ?array $graph = null): ?array
    {
        $graph ??= $this->itemGraph($prerequisite);

        $graph[$target] = array_values(array_unique(array_merge($graph[$target] ?? [], [$prerequisite])));

        $cycles = $this->cycles($graph, []);

        return $cycles === [] ? null : $cycles[0];
    }

    /** @return array<int, list<int>> */
    public function itemGraph(?int $courseId = null): array
    {
        $rows = LmsItemPrerequisite::query()
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->whereNotNull('item_id')
            ->whereNotNull('prerequisite_item_id')
            ->get(['item_id', 'prerequisite_item_id']);

        $graph = [];

        foreach ($rows as $row) {
            $graph[(int) $row->item_id][] = (int) $row->prerequisite_item_id;
        }

        return $graph;
    }

    /**
     * Deteksi siklus dengan DFS berwarna (iteratif — aman untuk graf besar).
     *
     * @param  array<int, list<int>>  $graph
     * @param  array<int, string>  $labels
     * @return list<array{path:list<int>,labels:list<string>}>
     */
    private function cycles(array $graph, array $labels): array
    {
        $white = 0;
        $gray = 1;
        $black = 2;
        $color = [];
        $cycles = [];
        $seen = [];

        $visit = function (int $start) use (&$graph, &$color, &$cycles, &$seen, $white, $gray, $black, $labels): void {
            $stack = [[$start, 0]];
            $path = [];

            while ($stack !== []) {
                $index = count($stack) - 1;
                [$node, $position] = $stack[$index];

                if ($position === 0) {
                    $color[$node] = $gray;
                    $path[] = $node;
                }

                $neighbours = $graph[$node] ?? [];

                if ($position < count($neighbours)) {
                    $stack[$index][1] = $position + 1;
                    $next = $neighbours[$position];

                    if (($color[$next] ?? $white) === $gray) {
                        // Siklus: potong jalur sejak kemunculan pertama node itu.
                        $offset = array_search($next, $path, true);
                        $cyclePath = $offset === false ? [$next] : array_slice($path, $offset);
                        $key = implode('-', $cyclePath);

                        if (! isset($seen[$key])) {
                            $seen[$key] = true;
                            $cycles[] = [
                                'path' => array_values($cyclePath),
                                'labels' => array_map(
                                    fn (int $id) => $labels[$id] ?? ('#'.$id),
                                    $cyclePath,
                                ),
                            ];
                        }

                        continue;
                    }

                    if (($color[$next] ?? $white) === $white) {
                        $stack[] = [$next, 0];
                    }

                    continue;
                }

                $color[$node] = $black;
                array_pop($path);
                array_pop($stack);
            }
        };

        foreach (array_keys($graph) as $node) {
            if (($color[$node] ?? $white) === $white) {
                $visit($node);
            }
        }

        return $cycles;
    }

    /**
     * @param  array<int, list<int>>  $graph
     * @return list<int>
     */
    private function nodeIds(array $graph): array
    {
        $ids = array_keys($graph);

        foreach ($graph as $neighbours) {
            $ids = array_merge($ids, $neighbours);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
