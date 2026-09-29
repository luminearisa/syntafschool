<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\ServiceHealthStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Health check LMS (§42).
 *
 * Memeriksa basis data, cache, antrean, penyimpanan, integrasi ERP/SIAKAD,
 * penyedia pemberitahuan, dan penjadwalan. Ringkasan publik (`publicSummary()`)
 * hanya menyebut status agregat — tanpa detail infrastruktur yang dapat
 * membantu penyerang.
 */
class SystemHealthService
{
    public function __construct(private readonly LmsGateway $gateway) {}

    /**
     * Pemeriksaan lengkap (untuk admin).
     *
     * @return array<string, mixed>
     */
    public function check(bool $includeDetails = true): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'erp_integration' => $this->checkErp(),
            'siakad_integration' => $this->checkSiakad(),
            'notification' => $this->checkNotification(),
            'scheduler' => $this->checkScheduler(),
        ];

        $status = $this->aggregate($checks);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'tone' => $status->tone(),
            'mode' => $this->gateway->mode(),
            'checked_at' => now()->toIso8601String(),
            'checks' => $includeDetails ? $checks : array_map(
                fn (array $check) => ['status' => $check['status']],
                $checks,
            ),
            'providers' => $includeDetails ? $this->gateway->describe() : [],
        ];
    }

    /**
     * Ringkasan aman untuk endpoint publik (§42):
     * tidak ada nama tabel, disk, host, atau pesan galat.
     *
     * @return array<string, mixed>
     */
    public function publicSummary(): array
    {
        $report = $this->check(includeDetails: false);

        return [
            'status' => $report['status'],
            'status_label' => $report['status_label'],
            'checked_at' => $report['checked_at'],
            'learning_available' => ServiceHealthStatus::tryFrom($report['status'])?->allowsLearning() ?? true,
        ];
    }

    /** @return array{status:string,label:string,detail:string|null,latency_ms:int|null} */
    private function checkDatabase(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');

            return $this->ok('Basis data merespons normal.', $this->latency($started));
        } catch (Throwable $exception) {
            return $this->down('Basis data tidak dapat dihubungi.', $exception->getMessage());
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'lms:health:'.now()->timestamp;
            Cache::put($key, 'ok', 5);
            $value = Cache::get($key);
            Cache::forget($key);

            return $value === 'ok'
                ? $this->ok('Cache dapat ditulis dan dibaca.')
                : $this->degraded('Cache tidak mengembalikan nilai yang baru ditulis.');
        } catch (Throwable $exception) {
            return $this->degraded('Cache bermasalah: '.$exception->getMessage());
        }
    }

    private function checkQueue(): array
    {
        $connection = (string) config('queue.default', 'sync');

        if ($connection === 'sync') {
            return $this->degraded('Antrean berjalan sinkron (sync): pekerjaan berat akan menahan permintaan HTTP. Jalankan worker untuk produksi.');
        }

        $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        if ($failed > 50) {
            return $this->degraded('Ada '.$failed.' pekerjaan gagal di antrean.', null);
        }

        return $this->ok('Antrean '.$connection.' aktif (menunggu: '.$pending.', gagal: '.$failed.').');
    }

    private function checkStorage(): array
    {
        $files = $this->gateway->files();

        try {
            $probe = $files->put('lms/health', 'probe.txt', 'ok', 'PRIVATE');
            $exists = $files->exists($probe['path']);
            $files->delete($probe['path']);

            return $exists
                ? $this->ok('Penyimpanan '.$files->disk().' dapat ditulis.')
                : $this->down('Penyimpanan tidak dapat dibaca kembali setelah ditulis.');
        } catch (Throwable $exception) {
            return $this->down('Penyimpanan bermasalah: '.$exception->getMessage());
        }
    }

    private function checkErp(): array
    {
        // Provider inti Phase 1 tidak semuanya mendeklarasikan `isAvailable()`,
        // jadi ketersediaannya diperiksa dari tabel sumbernya — bukan dengan
        // menebak dari kelas.
        $providers = [
            'users' => [$this->gateway->users(), 'users'],
            'students' => [$this->gateway->students(), 'students'],
            'teachers' => [$this->gateway->teachers(), 'employees'],
            'classes' => [$this->gateway->classes(), 'school_classes'],
        ];

        $unavailable = [];

        foreach ($providers as $name => [$provider, $table]) {
            if (! $this->providerAvailable($provider, $table)) {
                $unavailable[] = $name;
            }
        }

        if ($unavailable !== []) {
            return $this->gateway->mode() === 'integrated'
                ? $this->degraded('Provider ERP tidak siap: '.implode(', ', $unavailable).'.')
                : $this->ok('Mode mandiri: provider ERP memang tidak dipakai.');
        }

        return $this->ok('Provider ERP siap.');
    }

    private function checkSiakad(): array
    {
        $grades = $this->gateway->academicGrades();
        $enrollments = $this->gateway->enrollments();

        $skipped = $this->pendingSyncCount();

        if (! $grades->isAvailable()) {
            return $this->gateway->mode() === 'integrated'
                ? $this->degraded('Integrasi nilai SIAKAD tidak tersedia; nilai tetap tersimpan di LMS dan antrean sinkronisasi menunggu.')
                : $this->ok('Mode mandiri: LMS menjadi sumber nilai resmi.');
        }

        if ($skipped > 0) {
            return $this->degraded('Integrasi nilai aktif, tetapi ada '.$skipped.' nilai menunggu sinkronisasi.');
        }

        return $this->ok('Integrasi nilai SIAKAD siap'.($enrollments->isAuthoritative() ? ' dan menjadi sumber keikutsertaan resmi.' : '.'));
    }

    private function checkNotification(): array
    {
        $notifications = $this->gateway->notifications();
        $status = $notifications->status();

        if (! $notifications->isAvailable()) {
            return $this->down('Penyedia pemberitahuan tidak tersedia.');
        }

        $missing = $status['missing_channels'] ?? [];

        return $missing === []
            ? $this->ok('Pemberitahuan siap pada kanal: '.implode(', ', $notifications->supportedChannels()).'.')
            : $this->degraded('Kanal belum tersedia: '.implode(', ', $missing).' (peristiwa lain tetap terkirim in-app).');
    }

    private function checkScheduler(): array
    {
        $lastRun = Cache::get('lms:scheduler:last_run');

        if ($lastRun === null) {
            return $this->degraded('Belum ada tanda penjadwal berjalan. Pastikan cron `schedule:run` aktif.');
        }

        return $this->ok('Penjadwal terakhir berjalan '.$lastRun.'.');
    }

    /**
     * Provider siap dipakai? Kontrak Phase 5 mendeklarasikan `isAvailable()`;
     * provider lama diperiksa dari keberadaan tabel sumbernya.
     */
    private function providerAvailable(object $provider, ?string $table = null): bool
    {
        if (method_exists($provider, 'isAvailable')) {
            return (bool) $provider->isAvailable();
        }

        return $table !== null ? Schema::hasTable($table) : true;
    }

    private function pendingSyncCount(): int
    {
        if (! Schema::hasTable('lms_grade_syncs')) {
            return 0;
        }

        return (int) DB::table('lms_grade_syncs')
            ->whereIn('status', ['PENDING', 'PROCESSING', 'RETRYING', 'SYNCING'])
            ->count();
    }

    /** @param array<string, array{status:string}> $checks */
    private function aggregate(array $checks): ServiceHealthStatus
    {
        $statuses = array_map(fn (array $check) => $check['status'], $checks);

        if (in_array(ServiceHealthStatus::UNAVAILABLE->value, $statuses, true)) {
            return ServiceHealthStatus::UNAVAILABLE;
        }

        if (in_array(ServiceHealthStatus::DEGRADED->value, $statuses, true)) {
            return ServiceHealthStatus::DEGRADED;
        }

        return ServiceHealthStatus::HEALTHY;
    }

    private function latency(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** @return array{status:string,label:string,detail:string|null,latency_ms:int|null} */
    private function ok(string $detail, ?int $latency = null): array
    {
        return ['status' => ServiceHealthStatus::HEALTHY->value, 'label' => 'Normal', 'detail' => $detail, 'latency_ms' => $latency];
    }

    private function degraded(string $detail, ?int $latency = null): array
    {
        return ['status' => ServiceHealthStatus::DEGRADED->value, 'label' => 'Terbatas', 'detail' => $detail, 'latency_ms' => $latency];
    }

    private function down(string $detail, ?int $latency = null): array
    {
        return ['status' => ServiceHealthStatus::UNAVAILABLE->value, 'label' => 'Tidak tersedia', 'detail' => $detail, 'latency_ms' => $latency];
    }
}
