<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\UKS\Notifications\UksNotification;
use Illuminate\Support\Facades\DB;

/**
 * Pengingat harian UKS: stok menipis, obat menjelang kedaluwarsa, imunisasi
 * jatuh tempo, dan kunjungan terbuka yang tertinggal.
 *
 * Idempoten: satu pengingat per (penerima, jenis, kunci) per hari, sehingga
 * aman dijalankan penjadwal berulang kali.
 */
class UksReminderService
{
    /** @return array<string, int> */
    public function run(bool $dryRun = false): array
    {
        $counts = [
            'low_stock' => 0,
            'expiring' => 0,
            'immunization_due' => 0,
            'stale_visits' => 0,
        ];

        $officers = $this->officerUsers();
        if ($officers === []) {
            return $counts;
        }

        $lowStock = DB::table('uks_medicines')->where('is_active', true)->whereColumn('stock', '<=', 'min_stock')->get(['code', 'name', 'stock', 'unit']);
        if ($lowStock->isNotEmpty()) {
            $counts['low_stock'] = $this->send($officers, 'uks.stock.low', 'Stok obat menipis',
                $lowStock->count().' obat berada di bawah stok minimum: '.$lowStock->take(5)->pluck('name')->implode(', '),
                '/uks/medicines', $dryRun);
        }

        $days = (int) config('uks.expiry_warning_days', 30);
        $expiring = DB::table('uks_medicines')->where('is_active', true)->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expiry_date')->get(['name', 'expiry_date']);
        if ($expiring->isNotEmpty()) {
            $counts['expiring'] = $this->send($officers, 'uks.stock.expiring', 'Obat menjelang kedaluwarsa',
                $expiring->count().' obat kedaluwarsa sebelum '.now()->addDays($days)->translatedFormat('d M Y').'.',
                '/uks/medicines', $dryRun);
        }

        $dueDays = (int) config('uks.immunization_due_days', 14);
        $due = DB::table('uks_immunizations')->whereNotNull('next_due_at')
            ->whereDate('next_due_at', '<=', now()->addDays($dueDays)->toDateString())
            ->whereDate('next_due_at', '>=', now()->subDays(30)->toDateString())
            ->count();
        if ($due > 0) {
            $counts['immunization_due'] = $this->send($officers, 'uks.immunization.due', 'Imunisasi jatuh tempo',
                $due.' jadwal imunisasi susulan jatuh tempo dalam '.$dueDays.' hari.',
                '/uks/immunizations', $dryRun);
        }

        $stale = DB::table('uks_visits')->whereNotIn('status', ['PULANG', 'DIRUJUK', 'SELESAI'])
            ->where('arrived_at', '<', now()->subDays(2))->count();
        if ($stale > 0) {
            $counts['stale_visits'] = $this->send($officers, 'uks.visit.stale', 'Kunjungan UKS tertinggal',
                $stale.' kunjungan masih terbuka lebih dari 2 hari. Mohon diperbarui statusnya.',
                '/uks/visits', $dryRun);
        }

        return $counts;
    }

    /** @param list<User> $users */
    private function send(array $users, string $type, string $title, string $body, string $url, bool $dryRun): int
    {
        $sent = 0;
        $key = $type.':'.now()->toDateString();

        foreach ($users as $user) {
            $already = DB::table('notifications')
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->where('data', 'like', '%'.$key.'%')
                ->exists();

            if ($already) {
                continue;
            }

            $sent++;
            if (! $dryRun) {
                $user->notify(new UksNotification([
                    'type' => $type,
                    'idempotency_key' => $key,
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                ]));
            }
        }

        return $sent;
    }

    /** @return list<User> */
    private function officerUsers(): array
    {
        $employeeIds = DB::table('uks_officers')->where('is_active', true)->pluck('employee_id');
        if ($employeeIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->where('userable_type', (new Employee)->getMorphClass())
            ->whereIn('userable_id', $employeeIds)
            ->where('is_active', true)
            ->get()
            ->all();
    }
}
