<?php

namespace App\Modules\Procurement\Database\Seeders;

use App\Modules\Procurement\Application\Services\ProcurementNumberingService;
use App\Modules\Procurement\Domain\Enums\VendorClassification;
use App\Modules\Procurement\Domain\Enums\VendorStatus;
use App\Modules\Procurement\Domain\Enums\VendorTier;
use App\Modules\Procurement\Domain\Enums\VendorType;
use App\Modules\Procurement\Models\Category;
use App\Modules\Procurement\Models\Setting;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Master awal Modul Procurement: kategori bawaan, pengaturan, dan penomoran.
 * Idempoten — aman dijalankan berulang.
 */
class ProcurementMasterSeeder extends Seeder
{
    /** @var list<array{code: string, name: string, item_type: string}> */
    private const CATEGORIES = [
        ['code' => 'IT', 'name' => 'Teknologi Informasi', 'item_type' => 'GOODS'],
        ['code' => 'OFFICE', 'name' => 'Alat Tulis Kantor', 'item_type' => 'GOODS'],
        ['code' => 'FURNITURE', 'name' => 'Mebel', 'item_type' => 'GOODS'],
        ['code' => 'BOOKS', 'name' => 'Buku', 'item_type' => 'GOODS'],
        ['code' => 'LAB', 'name' => 'Laboratorium', 'item_type' => 'GOODS'],
        ['code' => 'FOOD', 'name' => 'Makanan & Minuman', 'item_type' => 'GOODS'],
        ['code' => 'CLEANING', 'name' => 'Kebersihan', 'item_type' => 'GOODS'],
        ['code' => 'MAINTENANCE', 'name' => 'Pemeliharaan', 'item_type' => 'MAINTENANCE'],
        ['code' => 'CONSTRUCTION', 'name' => 'Konstruksi', 'item_type' => 'CONSTRUCTION'],
        ['code' => 'TRANSPORT', 'name' => 'Transportasi', 'item_type' => 'SERVICE'],
        ['code' => 'CONSULTING', 'name' => 'Konsultansi', 'item_type' => 'CONSULTING'],
        ['code' => 'PROF_SERVICES', 'name' => 'Jasa Profesional', 'item_type' => 'SERVICE'],
        ['code' => 'UTILITIES', 'name' => 'Utilitas', 'item_type' => 'RECURRING'],
        ['code' => 'SOFTWARE', 'name' => 'Perangkat Lunak', 'item_type' => 'SUBSCRIPTION'],
        ['code' => 'SUBSCRIPTION', 'name' => 'Langganan', 'item_type' => 'SUBSCRIPTION'],
        ['code' => 'MEDICAL', 'name' => 'Medis', 'item_type' => 'GOODS'],
        ['code' => 'SECURITY', 'name' => 'Keamanan', 'item_type' => 'SERVICE'],
        ['code' => 'UNIFORM', 'name' => 'Seragam', 'item_type' => 'GOODS'],
    ];

    public function run(): void
    {
        app(ProcurementNumberingService::class)->ensureDefaults();

        foreach (self::CATEGORIES as $category) {
            Category::query()->updateOrCreate(['code' => $category['code']], $category + ['is_active' => true]);
        }

        Setting::query()->updateOrCreate(
            ['key' => 'procurement.matching'],
            [
                'value' => config('procurement.matching'),
                'group' => 'matching',
                'description' => 'Toleransi 3-way matching (PO vs penerimaan vs invoice).',
            ],
        );

        Setting::query()->updateOrCreate(
            ['key' => 'procurement.receiving'],
            [
                'value' => config('procurement.receiving'),
                'group' => 'receiving',
                'description' => 'Toleransi penerimaan barang (over/under delivery).',
            ],
        );

        // Vendor contoh agar sekolah punya titik awal (dapat dinonaktifkan).
        if (! Vendor::query()->exists()) {
            Vendor::query()->create([
                'code' => 'VND-00001',
                'legal_name' => 'PT Contoh Pemasok Nusantara',
                'trading_name' => 'Contoh Nusantara',
                'vendor_type' => VendorType::SUPPLIER->value,
                'entity_type' => 'COMPANY',
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'email' => 'sales@contoh-nusantara.test',
                'phone' => '021-0000000',
                'currency' => 'IDR',
                'payment_terms' => 'NET 30',
                'status' => VendorStatus::ACTIVE->value,
                'classification' => VendorClassification::NORMAL->value,
                'tier' => VendorTier::TIER_2->value,
                'risk_level' => 'LOW',
            ]);
        }
    }
}
