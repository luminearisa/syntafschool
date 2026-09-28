<?php

namespace App\Modules\LMS\Application\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Jejak audit peristiwa yang tidak terwakili perubahan model (§36):
 * penerbitan course, penangguhan enrollment, penetapan guru, dan sejenisnya.
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
    }
}
