<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Core\Services\AlertService;
use App\Modules\Hrd\Core\Support\HrdAutomationRunner;
use App\Modules\Hrd\Learning\Models\HrTrainingAssignment;
use Illuminate\Console\Command;

/** Menandai pelatihan wajib yang melewati tenggat, lalu memunculkan peringatan. */
class MarkOverdueTrainingCommand extends Command
{
    protected $signature = 'hrd:mark-overdue-training';

    protected $description = 'Menandai pelatihan wajib yang terlewat dan menaikkan peringatan.';

    public function handle(AlertService $alerts, HrdAutomationRunner $runner): int
    {
        $run = $runner->run('TRAINING_EXPIRY', 'training-overdue:'.now()->toDateString(), function () {
            $updated = HrTrainingAssignment::query()
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->toDateString())
                ->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'FAILED'])
                ->update(['status' => 'OVERDUE']);

            return ['processed' => $updated];
        });

        $this->info('Pelatihan ditandai terlewat: '.$run->processed);

        return self::SUCCESS;
    }
}
