<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeRelease;
use Illuminate\Support\Collection;

/**
 * Ekspor nilai (§38).
 *
 * CSV selalu tersedia tanpa dependensi tambahan; Excel/PDF memerlukan
 * infrastructure/exporter yang belum tentu ada, sehingga diminta secara
 * eksplisit dan gagal dengan pesan yang jelas bila tidak tersedia.
 */
class GradeExportService
{
    public function __construct(
        private readonly GradeCalculationService $calculation,
        private readonly GradeStatisticsService $statistics,
    ) {}

    /**
     * Bangun tabel ekspor (mengikuti filter aktif §38).
     *
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|float|null>>, items: Collection<int, LmsGradeItem>}
     */
    public function table(LmsGradebook $gradebook, array $filters = []): array
    {
        $gradebook->loadMissing('items.category');

        $items = $gradebook->items
            ->when(! empty($filters['category_id']), fn (Collection $collection) => $collection->where('category_id', (int) $filters['category_id']))
            ->when(! empty($filters['grade_item_id']), fn (Collection $collection) => $collection->where('id', (int) $filters['grade_item_id']))
            ->sortBy('sort_order')
            ->values();

        $course = $gradebook->course()->firstOrFail();
        $userIds = $this->statistics->studentIds($course);

        if (! empty($filters['user_ids'])) {
            $userIds = array_values(array_intersect($userIds, array_map('intval', (array) $filters['user_ids'])));
        }

        $releases = LmsGradeRelease::query()
            ->where('gradebook_id', $gradebook->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $headers = ['Siswa', 'User ID'];

        foreach ($items as $item) {
            $headers[] = $item->name.' (maks '.rtrim(rtrim(number_format((float) $item->maximum_grade, 2, '.', ''), '0'), '.').')';
            $headers[] = $item->name.' — status';
        }

        $headers[] = 'Nilai akhir';
        $headers[] = 'Huruf';
        $headers[] = 'Lulus';
        $headers[] = 'Status rilis';

        $rows = [];

        foreach ($userIds as $userId) {
            $calculation = $this->calculation->calculate($gradebook, $userId);
            $release = $releases->get($userId);
            $row = [$this->studentName($userId, $course->id), $userId];

            foreach ($items as $item) {
                $match = collect($calculation['items'])->firstWhere('grade_item_id', (int) $item->id);
                $row[] = $match['final_score'] ?? ($match['is_exempt'] ? 'EXEMPT' : null);
                $row[] = $match['status_label'] ?? 'Belum ada nilai';
            }

            $row[] = $calculation['normalized'];
            $row[] = $calculation['letter'];
            $row[] = $calculation['passed'] === null ? null : ($calculation['passed'] ? 'Ya' : 'Tidak');
            $row[] = $release?->status->label() ?? 'Belum dirilis';

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows, 'items' => $items];
    }

    /** CSV siap unduh (§38). */
    public function csv(LmsGradebook $gradebook, array $filters = []): string
    {
        $table = $this->table($gradebook, $filters);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            LmsFault::conflict('Tidak dapat menyiapkan berkas ekspor.');
        }

        fputcsv($handle, $table['headers']);

        foreach ($table['rows'] as $row) {
            fputcsv($handle, array_map(fn ($value) => $value === null ? '' : $value, $row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** Nama berkas ekspor. */
    public function filename(LmsGradebook $gradebook, string $extension = 'csv'): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', (string) $gradebook->name) ?? 'gradebook';

        return trim(strtolower($slug), '-').'-'.now()->format('Ymd-His').'.'.$extension;
    }

    /**
     * Format selain CSV hanya bila exporter/infrastructure tersedia (§38).
     */
    public function assertFormatSupported(string $format): void
    {
        $supported = array_map('strtolower', (array) config('lms.export.formats', ['csv']));

        if (! in_array(strtolower($format), $supported, true)) {
            LmsFault::invalid(sprintf(
                'Format %s belum tersedia pada instalasi ini. Gunakan CSV.',
                strtoupper($format),
            ), 'format');
        }
    }

    private function studentName(int $userId, int $courseId): ?string
    {
        $provider = app(\App\Modules\LMS\Domain\Contracts\UserProviderInterface::class);
        $actor = $provider->find($userId);

        if ($actor !== null) {
            return $actor->name;
        }

        $enrollment = \App\Modules\LMS\Models\LmsEnrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->first();

        return $enrollment?->student_id === null ? 'Siswa #'.$userId : 'Siswa #'.$enrollment->student_id;
    }
}
