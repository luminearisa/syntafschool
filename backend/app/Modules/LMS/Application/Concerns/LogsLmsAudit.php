<?php

namespace App\Modules\LMS\Application\Concerns;

use App\Modules\LMS\Application\Services\LmsAuditService;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Jejak audit peristiwa yang tidak terwakili perubahan model (§36, §23):
 * penerbitan course, penangguhan enrollment, penetapan guru, dan sejenisnya.
 *
 * Sejak Phase 5 peristiwa ini juga disalin ke `lms_audit_logs` — tabel audit
 * LMS yang menyimpan ip, user agent, dan correlation id. Penyalinan bersifat
 * *best effort*: kegagalan menulis audit tidak boleh menggagalkan aksi belajar.
 */
trait LogsLmsAudit
{
    /** @param array<string, mixed> $properties */
    protected function audit(string $action, ?Model $subject = null, array $properties = []): void
    {
        $logger = activity('lms')->event($action)->withProperties($properties);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if (auth()->check()) {
            $logger->causedBy(auth()->user());
        } else {
            $logger->causedByAnonymous();
        }

        $logger->log($action);

        $this->mirrorToAuditTable($action, $subject, $properties);
    }

    /** @param array<string, mixed> $properties */
    private function mirrorToAuditTable(string $action, ?Model $subject, array $properties): void
    {
        if (config('lms.audit.mirror_to_table', true) !== true) {
            return;
        }

        try {
            app(LmsAuditService::class)->record(
                action: $action,
                entityType: $subject !== null ? $subject::class : 'LMS',
                entityId: $subject !== null ? (int) $subject->getKey() : null,
                before: null,
                after: $properties === [] ? null : $properties,
                courseId: $this->auditCourseId($subject, $properties),
                context: ['source' => 'activity_log'],
            );
        } catch (Throwable) {
            // Audit tidak boleh menjadi penyebab kegagalan alur belajar.
        }
    }

    /** @param array<string, mixed> $properties */
    private function auditCourseId(?Model $subject, array $properties): ?int
    {
        $explicit = $properties['course_id'] ?? null;

        if (is_numeric($explicit)) {
            return (int) $explicit;
        }

        if ($subject instanceof LmsCourse) {
            return (int) $subject->getKey();
        }

        if ($subject !== null && method_exists($subject, 'getAttribute')) {
            $courseId = $subject->getAttribute('course_id');

            if (is_numeric($courseId)) {
                return (int) $courseId;
            }
        }

        return null;
    }
}
