<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\AttendanceGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Attendance Provider bersama (§22, §39).
 *
 * Modul Religious BUKAN mesin presensi baru: ia menulis catatan operasional
 * (`prayer_attendance_records`) lalu memantulkan faktanya ke mesin presensi
 * institusi bila tersedia. Bila tidak tersedia, modul tetap berjalan mandiri
 * dan menyatakan keterbatasan itu secara eksplisit.
 */
class AttendanceModuleGateway implements AttendanceGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Hrd\\Core\\Domain\\Contracts\\AttendanceProviderInterface')
            ?? $this->delegate('App\\Modules\\Hrd\\Domain\\Contracts\\AttendanceProviderInterface')
            ?? $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\AttendanceProviderInterface')
            ?? $this->delegate('App\\Modules\\LMS\\Domain\\Contracts\\AttendanceProviderInterface');
    }

    public function record(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['mirror', 'record', 'store'] as $method) {
                    if (method_exists($provider, $method)) {
                        $reference = $provider->{$method}($payload);

                        return [
                            'mirrored' => true,
                            'reference' => is_string($reference) ? $reference : ($reference['reference'] ?? null),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                return ['mirrored' => false, 'error' => $e->getMessage()];
            }
        }

        return ['mirrored' => false, 'reason' => $this->unavailableReason()];
    }

    public function bulkRecord(array $payloads): array
    {
        $results = [];
        foreach ($payloads as $index => $payload) {
            $results[$index] = $this->record($payload);
        }

        return $results;
    }

    public function devices(array $filters = []): array
    {
        $table = $this->firstTable(['hr_attendance_devices', 'attendance_devices']);
        if ($table === null) {
            return [];
        }

        return array_map(fn ($row): array => (array) $row,
            DB::table($table)->limit(200)->get()->all());
    }

    public function capabilities(): array
    {
        return [
            'provider_available' => $this->available(),
            'methods' => (array) config('religious.attendance.methods', ['MANUAL']),
            'mirroring' => $this->available(),
            'note' => $this->available()
                ? 'Presensi keagamaan dipantulkan ke Attendance Provider institusi.'
                : 'Attendance Provider belum terpasang; catatan disimpan pada tabel operasional modul.',
        ];
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Attendance Provider belum terpasang pada instalasi ini.';
    }
}
