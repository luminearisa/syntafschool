<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementMethod;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\Setting;
use App\Modules\Procurement\Models\Vendor;

/**
 * Aturan & kontrol pengadaan (§39, §52, §53).
 *
 * Seluruh ambang dibaca dari `config('procurement.*')` dan dapat ditimpa lewat
 * tabel `proc_settings` — **tidak ada threshold yang di-hardcode**.
 */
class ProcurementControlService
{
    /** Nilai konfigurasi dengan kemungkinan timpaan dari Setting. */
    public function config(string $key, mixed $default = null): mixed
    {
        $override = Setting::get('procurement.'.$key);

        return $override ?? config('procurement.'.$key, $default);
    }

    /** Tentukan metode pengadaan yang sah untuk nilai tertentu (§39). */
    public function resolveMethod(float $amount, ?ProcurementMethod $requested = null): ProcurementMethod
    {
        if ($requested === ProcurementMethod::EMERGENCY || $requested === ProcurementMethod::CONTRACT) {
            return $requested;
        }

        $methods = (array) config('procurement.methods', []);

        foreach (['direct', 'rfq', 'competitive'] as $key) {
            $max = $methods[$key]['max_amount'] ?? null;

            if ($max === null || $amount <= (float) $max) {
                return match ($key) {
                    'direct' => ProcurementMethod::DIRECT,
                    'rfq' => ProcurementMethod::RFQ,
                    default => ProcurementMethod::COMPETITIVE,
                };
            }
        }

        return ProcurementMethod::COMPETITIVE;
    }

    /** Jumlah penawaran minimum untuk metode tertentu. */
    public function minQuotationsFor(ProcurementMethod $method): int
    {
        $methods = (array) config('procurement.methods', []);

        return (int) ($methods[$method->configKey()]['min_quotations'] ?? 0);
    }

    /** Pastikan jumlah penawaran memenuhi aturan, kecuali ada override berizin. */
    public function assertMinQuotations(ProcurementMethod $method, int $count, bool $overridden = false): void
    {
        $required = $this->minQuotationsFor($method);

        if ($count < $required && ! $overridden) {
            throw ProcurementException::because(
                "Metode {$method->label()} membutuhkan minimal {$required} penawaran, tersedia {$count}.",
                'MIN_QUOTATIONS',
            );
        }
    }

    /** Langkah persetujuan yang disarankan berdasarkan nilai (§10). */
    public function approvalSteps(float $amount): array
    {
        foreach ((array) config('procurement.approval_thresholds', []) as $tier) {
            $max = $tier['max'] ?? null;

            if ($max === null || $amount <= (float) $max) {
                return (array) ($tier['steps'] ?? []);
            }
        }

        return [];
    }

    /** Toleransi 3-way matching (§29). */
    public function matchingTolerance(): array
    {
        return (array) $this->config('matching', ['quantity_tolerance_percent' => 0, 'price_tolerance_percent' => 0, 'amount_tolerance' => 0]);
    }

    /** Toleransi penerimaan (§23). */
    public function receivingTolerance(): array
    {
        return (array) $this->config('receiving', []);
    }

    /**
     * Vendor harus berstatus ACTIVE dan tidak restricted/blacklisted (§52.16),
     * dan dokumen wajibnya tidak boleh kedaluwarsa (§52.17), kecuali override.
     */
    public function assertVendorUsable(Vendor $vendor, bool $overridden = false): void
    {
        if ($overridden) {
            return;
        }

        if (! $vendor->isUsable()) {
            throw ProcurementException::because(
                "Vendor {$vendor->legal_name} tidak dapat digunakan (status/klasifikasi tidak memenuhi syarat).",
                'VENDOR_NOT_USABLE',
            );
        }

        if ($vendor->hasExpiredMandatoryDocuments()) {
            throw ProcurementException::because(
                "Vendor {$vendor->legal_name} memiliki dokumen wajib yang kedaluwarsa.",
                'VENDOR_DOCUMENT_EXPIRED',
            );
        }
    }

    /** Apakah PO wajib melewati 3-way matching sebelum handoff (§52.6). */
    public function requiresThreeWayMatch(): bool
    {
        return (bool) $this->config('matching.required', true);
    }

    /** Apakah inspeksi mutu wajib sebelum penerimaan diakui (§24). */
    public function requiresInspection(): bool
    {
        return (bool) ($this->receivingTolerance()['require_inspection'] ?? false);
    }
}
