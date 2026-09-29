<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\ActivityStatus;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Models\LmsActivity;

/**
 * Validasi aktivitas (§17).
 *
 * Konfigurasi per tipe diperiksa oleh validator tipenya sendiri
 * (`QuizValidator`, `QuestionValidator`), sehingga pesannya tepat sasaran.
 */
class ActivityValidator
{
    public function __construct(private readonly QuizValidator $quizzes) {}

    public function validate(LmsActivity $activity): ValidationReport
    {
        $report = new ValidationReport;

        if (! filled($activity->title)) {
            $report->error('activity.title_missing', 'Judul aktivitas wajib diisi.', 'activity', (int) $activity->id);
        }

        if ($activity->course_id === null) {
            $report->error('activity.course_missing', 'Aktivitas tidak terhubung ke course.', 'activity', (int) $activity->id);
        }

        if ($activity->learning_item_id === null) {
            $report->error('activity.item_missing', 'Aktivitas belum memiliki materi penampung sehingga tidak muncul di kurikulum.', 'activity', (int) $activity->id);
        }

        $type = $activity->activity_type;

        if ($type === ActivityType::ASSIGNMENT || $type === ActivityType::QUIZ) {
            if ($activity->due_at === null) {
                $report->warning('activity.due_missing', 'Aktivitas "'.$activity->title.'" tidak punya tenggat.', 'activity', (int) $activity->id, 'Tambahkan tenggat agar siswa punya target.');
            }

            if ($activity->is_graded && $activity->completion_type === CompletionType::MANUAL) {
                $report->info('activity.manual_completion_graded', 'Aktivitas dinilai tetapi penyelesaiannya manual; pertimbangkan aturan otomatis.', 'activity', (int) $activity->id);
            }
        }

        if ($activity->start_at !== null && $activity->due_at !== null && $activity->due_at->lt($activity->start_at)) {
            $report->error('activity.window_inverted', 'Tenggat lebih awal daripada waktu mulai.', 'activity', (int) $activity->id);
        }

        if ($activity->close_at !== null && $activity->due_at !== null && $activity->close_at->lt($activity->due_at)) {
            $report->error('activity.close_before_due', 'Waktu penutupan lebih awal daripada tenggat.', 'activity', (int) $activity->id);
        }

        if ($activity->status !== ActivityStatus::PUBLISHED && ($activity->relationLoaded('learningItem') ? $activity->learningItem?->is_required : $activity->learningItem()->value('is_required'))) {
            $report->warning(
                'activity.required_not_published',
                'Aktivitas wajib "'.$activity->title.'" belum diterbitkan; ketuntasan siswa akan tertahan.',
                'activity',
                (int) $activity->id,
            );
        }

        $availability = (array) ($activity->availability ?? []);
        $prerequisiteIds = (array) ($availability['prerequisite_item_ids'] ?? []);

        foreach ($prerequisiteIds as $itemId) {
            if (! $activity->course?->items()->whereKey((int) $itemId)->exists()) {
                $report->error(
                    'activity.prerequisite_missing',
                    'Prasyarat aktivitas menunjuk materi yang sudah dihapus (#'.$itemId.').',
                    'activity',
                    (int) $activity->id,
                    'Pilih ulang materi prasyarat.',
                );
            }
        }

        if ($type === ActivityType::QUIZ) {
            $quiz = $activity->relationLoaded('quiz') ? $activity->quiz : $activity->quiz()->first();

            if ($quiz === null) {
                $report->error('activity.quiz_config_missing', 'Aktivitas kuis belum memiliki konfigurasi kuis.', 'activity', (int) $activity->id);
            } else {
                $report->merge($this->quizzes->validate($quiz));
            }
        }

        return $report;
    }
}
