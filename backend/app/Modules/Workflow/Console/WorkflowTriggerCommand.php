<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Application\Services\WorkflowDefinitionService;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `workflow:trigger` — jalur integrasi modul & integrasi sistem (§44, §45).
 *
 * Modul ERP normalnya memanggil `WorkflowTriggerInterface` dari kode PHP. Perintah
 * ini untuk kasus lain: pemulihan manual oleh ops, impor massal, atau integrasi
 * dari sistem luar yang belum punya kode di dalam ERP ini.
 *
 * ```bash
 * php artisan workflow:trigger PURCHASE_REQUEST_APPROVAL PR-2026-000123 \
 *     --requester=1 --context='{"amount":15000000,"department":"FINANCE"}'
 * ```
 */
class WorkflowTriggerCommand extends Command
{
    protected $signature = 'workflow:trigger
        {definition : Kode definisi workflow (mis. PURCHASE_REQUEST_APPROVAL)}
        {subject : Id subjek bisnis (mis. PR-2026-000123)}
        {--requester= : id pengguna pemohon}
        {--title= : Judul request}
        {--priority=NORMAL : LOW|NORMAL|HIGH|URGENT}
        {--context= : JSON konteks, mis. {"amount":15000000}}
        {--subject-type= : subject_type (default dari definisi)}
        {--no-submit : Hanya buat draf, jangan langsung dikirim}';

    protected $description = 'Mulai workflow untuk sebuah subjek bisnis (jalur integrasi/ops)';

    public function handle(WorkflowDefinitionService $definitions, WorkflowInstanceService $instances): int
    {
        $code = strtoupper((string) $this->argument('definition'));
        $subject = (string) $this->argument('subject');

        $definition = \App\Modules\Workflow\Models\WorkflowDefinition::query()->where('code', $code)->first();

        if ($definition === null) {
            $this->error("Definisi {$code} tidak ditemukan.");

            return self::FAILURE;
        }

        $requesterId = (int) ($this->option('requester') ?: 0);
        $requester = $requesterId > 0 ? User::query()->find($requesterId) : null;

        if ($requester === null) {
            $this->error('Pemohon wajib disebutkan dan harus pengguna aktif.');

            return self::FAILURE;
        }

        $context = [];

        if ($raw = (string) $this->option('context')) {
            $context = json_decode($raw, true) ?? [];

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Konteks bukan JSON yang sah: '.json_last_error_msg());

                return self::FAILURE;
            }
        }

        $instance = $instances->create([
            'definition_code' => $code,
            'subject_type' => (string) ($this->option('subject-type') ?: $definition->entity_type ?: Str::slug($definition->module, '_')),
            'subject_id' => $subject,
            'subject_reference' => $subject,
            'title' => (string) ($this->option('title') ?: ($definition->name.' '.$subject)),
            'priority' => (string) $this->option('priority'),
            'requester_id' => $requester->id,
            'context' => $context,
            'trigger_type' => 'API',
        ], $requester);

        if (! $this->option('no-submit')) {
            $instance = $instances->submit($instance, $requester, null, ['context' => $context]);
        }

        $this->info("Workflow {$instance->code} ({$instance->status->value}) dibuat untuk {$code} / {$subject}.");

        return self::SUCCESS;
    }
}
