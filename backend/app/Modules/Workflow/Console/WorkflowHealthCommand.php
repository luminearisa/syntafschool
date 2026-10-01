<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Application\Services\WorkflowAnalyticsService;
use App\Modules\Workflow\Application\Services\WorkflowAuditService;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowCalendar;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Console\Command;

/**
 * `workflow:health` — pemeriksaan cepat sebelum/sesudah deploy.
 *
 * Menjawab pertanyaan operasional: apakah registry terisi, apakah kalender ada,
 * apakah ada step yang menunggu penetapan approver (tanda direktori organisasi
 * belum lengkap), apakah ada instance gagal, dan apakah rantai audit utuh.
 */
class WorkflowHealthCommand extends Command
{
    protected $signature = 'workflow:health';

    protected $description = 'Periksa kesehatan Workflow & Approval Engine';

    public function handle(
        WorkflowRegistrar $registrar,
        WorkflowAuditService $audit,
        WorkflowAnalyticsService $analytics,
    ): int {
        $chain = $audit->verifyChain(null, 1000);

        $checks = [
            'Resolver approver terdaftar' => count($registrar->resolvers()),
            'Aksi otomatis terdaftar' => count($registrar->actions()),
            'Context provider terdaftar' => count($registrar->contextProviders()),
            'Kalender aktif' => WorkflowCalendar::query()->where('is_active', true)->count(),
            'Definisi aktif' => WorkflowDefinition::query()->where('status', 'ACTIVE')->count(),
            'Request berjalan' => WorkflowInstance::query()->open()->count(),
            'Step menunggu penetapan approver' => WorkflowStepInstance::query()->where('status', 'WAITING_FOR_APPROVER')->count(),
            'Instance gagal' => WorkflowInstance::query()->where('status', 'FAILED')->count(),
            'Rantai audit utuh' => $chain['valid'] ? 'ya' : 'TIDAK (periksa '.$chain['broken_at'].')',
        ];

        $this->table(['Pemeriksaan', 'Nilai'], array_map(
            fn (string $label, $value) => [$label, (string) $value],
            array_keys($checks),
            array_values($checks),
        ));

        $dashboard = $analytics->dashboard(30);
        $this->line(sprintf(
            '30 hari terakhir: %d request, %d disetujui, %d ditolak, %d dikembalikan, %d gagal.',
            $dashboard['instances_created'],
            $dashboard['approved'],
            $dashboard['rejected'],
            $dashboard['returned'],
            $dashboard['failed'],
        ));

        return $chain['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
