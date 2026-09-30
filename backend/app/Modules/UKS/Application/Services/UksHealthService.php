<?php

namespace App\Modules\UKS\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * Pemeriksaan kesehatan data UKS (perintah `php artisan uks:health` dan
 * endpoint admin). Hanya membaca — tidak pernah mengubah data.
 */
class UksHealthService
{
    /** @return array{status: string, checks: list<array<string, mixed>>, needs_attention: int} */
    public function report(): array
    {
        $checks = [
            $this->check('visit_closed_without_handler', 'Kunjungan selesai tanpa petugas penangan', 'WARNING',
                DB::table('uks_visits')->whereIn('status', ['SELESAI', 'PULANG', 'DIRUJUK'])->whereNull('handled_by')->limit(20)->pluck('code')->all(),
                'Tugaskan petugas penangan agar jejak tanggung jawab lengkap.'),
            $this->check('severe_without_parent_notice', 'Kasus berat/darurat yang orang tuanya belum dikabari', 'CRITICAL',
                DB::table('uks_visits')->whereIn('severity', ['BERAT', 'DARURAT'])->where('parent_notified', false)->limit(20)->pluck('code')->all(),
                'Hubungi orang tua dan tandai sudah diberitahu.'),
            $this->check('referral_without_outcome', 'Rujukan selesai tanpa hasil', 'WARNING',
                DB::table('uks_referrals')->where('status', 'SELESAI')->whereNull('outcome')->limit(20)->pluck('id')->all(),
                'Lengkapi hasil rujukan dari fasilitas kesehatan.'),
            $this->check('negative_stock', 'Obat dengan stok negatif', 'CRITICAL',
                DB::table('uks_medicines')->where('stock', '<', 0)->limit(20)->pluck('code')->all(),
                'Periksa mutasi stok; stok tidak boleh negatif.'),
            $this->check('medication_without_movement', 'Pemberian obat tanpa mutasi stok', 'CRITICAL',
                DB::table('uks_visit_medications as m')
                    ->leftJoin('uks_stock_movements as s', function ($join): void {
                        $join->on('s.visit_id', '=', 'm.visit_id')->on('s.medicine_id', '=', 'm.medicine_id');
                    })
                    ->whereNotNull('m.medicine_id')->whereNull('s.id')->limit(20)->pluck('m.id')->all(),
                'Pemberian obat harus selalu mengurangi stok.'),
            $this->check('expired_still_active', 'Obat kedaluwarsa yang masih aktif', 'WARNING',
                DB::table('uks_medicines')->where('is_active', true)->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<', now()->toDateString())->limit(20)->pluck('code')->all(),
                'Nonaktifkan atau buang obat kedaluwarsa lewat mutasi stok.'),
            $this->check('letter_without_code', 'Surat tanpa kode verifikasi', 'CRITICAL',
                DB::table('uks_letters')->where(fn ($q) => $q->whereNull('verification_code')->orWhere('verification_code', ''))
                    ->limit(20)->pluck('number')->all(),
                'Setiap surat wajib bisa diperiksa keasliannya.'),
            $this->check('open_visit_stale', 'Kunjungan terbuka lebih dari 2 hari', 'WARNING',
                DB::table('uks_visits')->whereNotIn('status', ['PULANG', 'DIRUJUK', 'SELESAI'])
                    ->where('arrived_at', '<', now()->subDays(2))->limit(20)->pluck('code')->all(),
                'Tutup atau perbarui status kunjungan yang tertinggal.'),
        ];

        $needsAttention = count(array_filter($checks, fn (array $c): bool => $c['count'] > 0));
        $hasCritical = count(array_filter($checks, fn (array $c): bool => $c['count'] > 0 && $c['severity'] === 'CRITICAL')) > 0;

        return [
            'status' => $needsAttention === 0 ? 'ok' : ($hasCritical ? 'critical' : 'warning'),
            'checked_at' => now()->toDateTimeString(),
            'checks' => $checks,
            'needs_attention' => $needsAttention,
            'open_visits' => DB::table('uks_visits')->whereNotIn('status', ['PULANG', 'DIRUJUK', 'SELESAI'])->count(),
            'visits_today' => DB::table('uks_visits')->whereDate('arrived_at', now()->toDateString())->count(),
        ];
    }

    /** @param list<int|string> $sample */
    private function check(string $key, string $label, string $severity, array $sample, string $suggestion): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'severity' => $severity,
            'count' => count($sample),
            'sample' => $sample,
            'suggestion' => $suggestion,
        ];
    }
}
