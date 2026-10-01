<?php

/*
|--------------------------------------------------------------------------
| Modul Asset & Sarpras
|--------------------------------------------------------------------------
|
| Setiap aturan yang "seharusnya bisa diubah sekolah" ada di sini, bukan di
| dalam kode: ambang kapitalisasi, pola kode aset, jendela peringatan, dan
| kebijakan peminjaman. Nilai bawaan dibuat untuk sekolah/pesantren, tetapi
| tidak ada satu pun yang wajib.
|
*/

return [

    // Isolasi unit sekolah (tenant). Bila mati, modul berperilaku seperti
    // instalasi satu sekolah: tidak ada penyaringan tambahan. Bila menyala,
    // setiap baris otomatis dibatasi pada unitnya — termasuk kueri yang lupa.
    'tenant' => [
        'enabled' => (bool) env('ASSET_TENANT_SCOPE', false),
        'default_school_id' => env('ASSET_TENANT_SCHOOL_ID'),
    ],

    // Pola kode aset. `{KAT}` diisi kode kategori, `{TAHUN}` tahun perolehan,
    // `{URUT}` nomor urut enam digit. Contoh: AST-IT-2026-000001
    'numbering' => [
        'asset' => env('ASSET_CODE_PATTERN', 'AST-{KAT}-{TAHUN}-{URUT}'),
        'tag' => env('ASSET_TAG_PATTERN', 'TAG-{URUT}'),
        'facility' => env('FACILITY_CODE_PATTERN', 'FCL-{KAT}-{URUT}'),
        'location' => env('LOCATION_CODE_PATTERN', 'LOC-{URUT}'),
        'stock_opname' => env('STOCK_OPNAME_CODE_PATTERN', 'OPN-{TAHUN}-{URUT}'),
        'work_order' => env('WORK_ORDER_CODE_PATTERN', 'WO-{TAHUN}-{URUT}'),
        'disposal' => env('DISPOSAL_CODE_PATTERN', 'DSP-{TAHUN}-{URUT}'),
        'transfer' => env('TRANSFER_CODE_PATTERN', 'TRF-{TAHUN}-{URUT}'),
        'loan' => env('LOAN_CODE_PATTERN', 'LOAN-{TAHUN}-{URUT}'),
        'booking' => env('BOOKING_CODE_PATTERN', 'BKG-{TAHUN}-{URUT}'),
    ],

    // Ambang kapitalisasi bawaan (dalam rupiah, dibandingkan sebagai string).
    // Kategori dapat menimpanya masing-masing; keputusan disimpan di aset
    // sehingga perubahan kebijakan tidak mengubah masa lalu.
    'capitalization' => [
        'default_threshold' => env('ASSET_DEFAULT_CAPITALIZATION_THRESHOLD', '1000000'),
        'consumable_categories' => ['ATK', 'KEBERSIHAN', 'BAHAN'],
    ],

    // Pengingat & otomasi. Semua jendela dalam hari.
    'reminders' => [
        'warranty_days' => [90, 60, 30],
        'maintenance_due_days' => 7,
        'inspection_due_days' => 7,
        'loan_overdue_grace_days' => 0,
        'stock_opname_cycle_days' => env('ASSET_STOCK_OPNAME_CYCLE_DAYS', 180),
    ],

    // Peminjaman.
    'loan' => [
        'default_days' => 3,
        'max_days' => 30,
        'requires_approval' => env('ASSET_LOAN_REQUIRES_APPROVAL', true),
    ],

    // Peminjaman & booking fasilitas.
    'booking' => [
        'default_min_duration_minutes' => 30,
        'default_max_duration_minutes' => 480,
        'advance_booking_days' => 90,
        'conflict_policy' => env('FACILITY_CONFLICT_POLICY', 'reject'), // reject|approve
    ],

    // Maintenance & inspeksi: SLA dalam jam menurut prioritas.
    'sla' => [
        'response_hours' => [
            'CRITICAL' => 2,
            'URGENT' => 4,
            'HIGH' => 8,
            'NORMAL' => 24,
            'LOW' => 72,
        ],
        'work_order_hours' => [
            'CRITICAL' => 8,
            'URGENT' => 24,
            'HIGH' => 48,
            'NORMAL' => 120,
            'LOW' => 240,
        ],
    ],

    // Frequency penggantian: ambang usulan penggantian (operasional, bukan beli).
    'replacement' => [
        'age_ratio_monitor' => 0.6,      // > 60% umur → MONITOR
        'age_ratio_replace' => 0.9,      // > 90% umur → REPLACE_REVIEW
        'condition_replace_in' => ['POOR', 'DAMAGED', 'CRITICAL', 'UNUSABLE'],
        'maintenance_cost_ratio' => 0.5, // biaya maintenance > 50% harga perolehan → REPLACE_REVIEW
        'downtime_days_replace' => 30,
    ],

    // QR publik: HANYA kolom di sini yang boleh keluar tanpa otentikasi.
    'qr' => [
        'public_fields' => ['asset_code', 'name', 'location_name', 'condition', 'status'],
    ],

    'routes' => [
        'prefix' => env('ASSET_ROUTE_PREFIX', 'api/v1'),
        'middleware' => ['api'],
    ],
];
