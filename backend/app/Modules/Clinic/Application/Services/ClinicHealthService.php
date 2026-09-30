<?php

namespace App\Modules\Clinic\Application\Services;

use App\Modules\Clinic\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Clinic\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Clinic\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Clinic\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Clinic\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Clinic\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Infrastructure\Providers\InventoryItemProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pemeriksaan kesehatan Modul Clinic (`GET /api/v1/clinic/health`).
 *
 * Memeriksa kesiapan basis data dan setiap integrasi. **Tidak pernah**
 * mengembalikan data klinis maupun data pribadi pasien — hanya status dan
 * jumlah baris, sehingga endpoint ini aman dipantau tim operasi.
 */
class ClinicHealthService
{
    public function __construct(
        private readonly InventoryProviderInterface $inventory,
        private readonly FinanceProviderInterface $finance,
        private readonly NotificationProviderInterface $notifications,
        private readonly DocumentProviderInterface $documents,
        private readonly StudentProviderInterface $students,
        private readonly AttendanceProviderInterface $attendance,
        private readonly BoardingProviderInterface $boarding,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $checks = [
            'database' => $this->database(),
            'student_provider' => $this->studentProvider(),
            'inventory_provider' => $this->inventoryProvider(),
            'finance_provider' => $this->financeProvider(),
            'notification_provider' => $this->notificationProvider(),
            'document_storage' => $this->documentStorage(),
            'attendance_provider' => $this->attendanceProvider(),
            'boarding_provider' => $this->boardingProvider(),
        ];

        $failed = collect($checks)->filter(fn (array $c): bool => $c['status'] === 'FAIL')->keys()->all();

        return [
            'status' => $failed === [] ? 'OK' : 'DEGRADED',
            'checked_at' => now()->toIso8601String(),
            'failed' => $failed,
            'checks' => $checks,
        ];
    }

    /** @return array{status: string, detail: string} */
    private function database(): array
    {
        try {
            $core = DB::table('clinic_patients')->count();
            $visits = DB::table('clinic_visits')->count();
            $pending = DB::table('clinic_visits')->where('status', 'REGISTERED')->count();

            return [
                'status' => 'OK',
                'detail' => "patients={$core}, visits={$visits}, menunggu_triase={$pending}",
            ];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => 'Tabel Clinic belum termigrasi: '.$e->getMessage()];
        }
    }

    /** @return array{status: string, detail: string} */
    private function studentProvider(): array
    {
        try {
            $withoutProfile = DB::table('students')
                ->leftJoin('clinic_patients', 'clinic_patients.student_id', '=', 'students.id')
                ->whereNull('clinic_patients.id')
                ->where('students.is_active', true)
                ->count();

            return ['status' => 'OK', 'detail' => "siswa aktif tanpa rekam medis: {$withoutProfile}"];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => $e->getMessage()];
        }
    }

    /** @return array{status: string, detail: string} */
    private function inventoryProvider(): array
    {
        try {
            $linked = DB::table('clinic_medications')->whereNotNull('inventory_item_id')->count();
            $total = DB::table('clinic_medications')->count();
            $reachable = $this->inventory instanceof InventoryItemProvider
                ? $this->inventory->isReachable()
                : true;

            return [
                'status' => $reachable ? 'OK' : 'DEGRADED',
                'detail' => "obat terhubung Inventory: {$linked}/{$total}",
            ];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => $e->getMessage()];
        }
    }

    /** @return array{status: string, detail: string} */
    private function financeProvider(): array
    {
        return [
            'status' => 'OK',
            'detail' => $this->finance->isEnabled()
                ? 'Penagihan layanan klinis aktif.'
                : 'Penagihan tidak aktif (layanan kesehatan tidak ditagihkan).',
        ];
    }

    /** @return array{status: string, detail: string} */
    private function notificationProvider(): array
    {
        try {
            $queued = DB::table('clinic_notification_logs')->where('status', 'QUEUED')->count();

            return [
                'status' => 'OK',
                'detail' => 'kanal='.implode(',', $this->notifications->availableChannels()).", dalam_antrean={$queued}",
            ];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => $e->getMessage()];
        }
    }

    /** @return array{status: string, detail: string} */
    private function documentStorage(): array
    {
        try {
            $disk = (string) config('clinic.document_disk', 'clinic');
            $writable = Storage::disk($disk)->exists('/') || Storage::disk($disk)->put('.health', 'ok');
            Storage::disk($disk)->delete('.health');

            return [
                'status' => $writable ? 'OK' : 'FAIL',
                'detail' => 'disk='.$disk.($writable ? ' dapat ditulis' : ' tidak dapat ditulis'),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => $e->getMessage()];
        }
    }

    /** @return array{status: string, detail: string} */
    private function attendanceProvider(): array
    {
        $enabled = (bool) config('clinic.attendance.auto_mark', false);

        return [
            'status' => 'OK',
            'detail' => $enabled
                ? 'Presensi otomatis AKTIF (baris dibuat sebagai belum terverifikasi).'
                : 'Presensi otomatis nonaktif — Clinic tidak mengubah presensi kelas.',
        ];
    }

    /** @return array{status: string, detail: string} */
    private function boardingProvider(): array
    {
        try {
            $boarders = DB::table('boarding_placements')->where('status', 'active')->count();

            return ['status' => 'OK', 'detail' => "penghuni asrama aktif: {$boarders}"];
        } catch (\Throwable $e) {
            return ['status' => 'DEGRADED', 'detail' => 'Modul asrama belum tersedia: '.$e->getMessage()];
        }
    }
}
