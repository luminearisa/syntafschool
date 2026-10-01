<?php

namespace App\Modules\Asset\Console;

use App\Modules\Asset\Models\AssetIntegrationOutbox;
use Illuminate\Console\Command;

/**
 * Kerjakan niat integrasi yang tertunda.
 *
 * Dipakai saat modul tujuan baru dipasang, atau saat kegagalan sementara pada
 * modul Keuangan/Persediaan sudah diperbaiki. Percobaan dicatat (`attempts`) dan
 * kegagalannya disimpan (`last_error`) — bukan gagal diam-diam.
 */
class AssetDispatchIntegrationOutboxCommand extends Command
{
    protected $signature = 'asset:integration:dispatch {--limit=100}';

    protected $description = 'Coba kerjakan kembali entri outbox integrasi yang masih PENDING.';

    public function handle(): int
    {
        $entries = AssetIntegrationOutbox::query()
            ->where('status', 'PENDING')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $dispatched = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            try {
                $result = match ($entry->module) {
                    'finance' => app(\App\Modules\Asset\Domain\Contracts\FinanceProviderInterface::class)
                        ->{$this->financeMethod($entry->action)}(...$this->financeArguments($entry)),
                    'inventory' => app(\App\Modules\Asset\Domain\Contracts\InventoryProviderInterface::class)
                        ->{$entry->action}((array) $entry->payload),
                    default => ['status' => 'PENDING'],
                };

                if (($result['status'] ?? 'PENDING') === 'PENDING') {
                    $this->line('  · '.$entry->module.'.'.$entry->action.' masih menunggu (modul tujuan belum tersedia).');
                    continue;
                }

                app(\App\Modules\Asset\Infrastructure\Support\IntegrationOutbox::class)
                    ->markDispatched($entry, $result['reference'] ?? null, $result);

                $dispatched++;
                $this->line('  ✓ '.$entry->module.'.'.$entry->action.' terkirim.');
            } catch (\Throwable $exception) {
                app(\App\Modules\Asset\Infrastructure\Support\IntegrationOutbox::class)->markFailed($entry, $exception->getMessage());
                $failed++;
                $this->warn('  ✗ '.$entry->module.'.'.$entry->action.' gagal: '.$exception->getMessage());
            }
        }

        $this->info(sprintf('Outbox: %d terkirim, %d gagal, %d diperiksa.', $dispatched, $failed, $entries->count()));

        return self::SUCCESS;
    }

    protected function financeMethod(string $action): string
    {
        return match ($action) {
            'maintenance_cost' => 'recordMaintenanceCost',
            default => $action,
        };
    }

    /** @return list<mixed> */
    protected function financeArguments(AssetIntegrationOutbox $entry): array
    {
        return $entry->action === 'depreciate'
            ? [(int) ($entry->payload['asset_id'] ?? 0), (string) ($entry->payload['period'] ?? now()->format('Y-m')), (array) $entry->payload]
            : [(array) $entry->payload];
    }
}
