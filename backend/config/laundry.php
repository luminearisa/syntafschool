<?php

/**
 * Konfigurasi Laundry Management (School Operations).
 *
 * Seluruh daftar master di bawah configurable tanpa menyentuh kode, karena
 * kebijakan laundry tiap pesantren/sekolah berbeda (jatah gratis, harga,
 * tahapan proses, checklist QC). Binding gateway mengikuti pola modul
 * Transportation: implementasi dapat ditimpa per lingkungan.
 */
return [
    'module' => [
        'code' => 'school-operations.laundry',
        'name' => 'Laundry Management',
        'version' => 1,
    ],

    // ── Layanan laundry (§3) ─────────────────────────────────────────────
    'services' => [
        'REGULAR' => ['name' => 'Laundry Regular', 'pricing_model' => 'WEIGHT', 'unit' => 'KG', 'processing_hours' => 48],
        'EXPRESS' => ['name' => 'Laundry Express', 'pricing_model' => 'WEIGHT', 'unit' => 'KG', 'processing_hours' => 12],
        'BEDDING' => ['name' => 'Laundry Bedding', 'pricing_model' => 'PIECE', 'unit' => 'PIECE', 'processing_hours' => 72],
        'BLANKET' => ['name' => 'Laundry Blanket', 'pricing_model' => 'PIECE', 'unit' => 'PIECE', 'processing_hours' => 72],
        'SHOES' => ['name' => 'Laundry Shoes', 'pricing_model' => 'PIECE', 'unit' => 'PIECE', 'processing_hours' => 72],
        'SPECIAL' => ['name' => 'Laundry Special', 'pricing_model' => 'SERVICE', 'unit' => 'ORDER', 'processing_hours' => 96],
    ],

    // ── Model harga (§4) ─────────────────────────────────────────────────
    'pricing_models' => ['WEIGHT', 'PIECE', 'SERVICE'],

    // Jenis item untuk harga per potong (PIECE).
    'item_types' => [
        'SHIRT' => 'Baju',
        'PANTS' => 'Celana',
        'JACKET' => 'Jaket',
        'TOWEL' => 'Handuk',
        'BEDSHEET' => 'Sprei',
        'BLANKET' => 'Selimut',
        'PILLOWCASE' => 'Sarung Bantal',
        'SHOES' => 'Sepatu',
        'OTHER' => 'Lainnya',
    ],

    // Biaya tambahan (§4) — fixed amount atau persen dari harga dasar.
    'fee_types' => [
        'EXPRESS' => 'Express',
        'SPECIAL_TREATMENT' => 'Special Treatment',
        'STAIN_TREATMENT' => 'Stain Treatment',
        'BEDDING' => 'Bedding',
        'DELICATE' => 'Delicate',
        'EMERGENCY' => 'Emergency',
    ],

    // ── Jatah gratis (§6) ────────────────────────────────────────────────
    'quota_types' => ['WEIGHT', 'ORDER', 'PIECE', 'AMOUNT'],

    // ── Periode jatah (§7) ───────────────────────────────────────────────
    'period_types' => ['DAILY', 'WEEKLY', 'MONTHLY', 'SEMESTER', 'ACADEMIC_YEAR', 'CUSTOM'],

    // ── Carry over (§8) ──────────────────────────────────────────────────
    'carry_over_modes' => ['NO_CARRY_OVER', 'FULL_CARRY_OVER', 'LIMITED_CARRY_OVER'],

    // ── Status order (§28) ───────────────────────────────────────────────
    'order_statuses' => [
        'DRAFT', 'RECEIVED', 'WEIGHED', 'PAYMENT_REQUIRED', 'PAID', 'QUEUED',
        'WASHING', 'DRYING', 'IRONING', 'FOLDING', 'QUALITY_CHECK', 'PACKAGED',
        'READY', 'DELIVERED', 'COMPLETED', 'CANCELLED', 'LOST', 'DAMAGED',
    ],

    // Kelompok status untuk penyederhanaan UI (§28).
    'status_groups' => [
        'INTAKE' => ['DRAFT', 'RECEIVED', 'WEIGHED'],
        'PAYMENT' => ['PAYMENT_REQUIRED', 'PAID'],
        'PROCESSING' => ['QUEUED', 'WASHING', 'DRYING', 'IRONING', 'FOLDING', 'QUALITY_CHECK', 'PACKAGED'],
        'HANDOVER' => ['READY', 'DELIVERED', 'COMPLETED'],
        'EXCEPTION' => ['CANCELLED', 'LOST', 'DAMAGED'],
    ],

    // Tahapan pemrosesan yang dapat dijalankan lewat batch (§33).
    'processing_stages' => ['WASHING', 'DRYING', 'IRONING', 'FOLDING'],

    // ── Quality control (§38) ────────────────────────────────────────────
    'qc_checklist' => [
        'clean' => 'Bersih',
        'dry' => 'Kering',
        'no_odor' => 'Tidak berbau',
        'no_stain' => 'Tidak ada noda',
        'complete_items' => 'Item lengkap',
        'correct_student' => 'Sesuai santri',
        'proper_folding' => 'Lipatan rapi',
        'proper_packaging' => 'Kemasan rapi',
    ],
    'qc_results' => ['PASSED', 'FAILED', 'REWORK'],

    // ── Insiden (§39) ────────────────────────────────────────────────────
    'incident_types' => ['MISSING', 'DAMAGED', 'STAIN', 'COLOR_TRANSFER', 'OTHER'],
    'incident_statuses' => ['OPEN', 'INVESTIGATING', 'RESOLVED', 'CLOSED'],

    // ── Kompensasi (§41) — tidak pernah otomatis memotong saldo ──────────
    'compensation_types' => ['REPLACEMENT', 'REFUND', 'CREDIT', 'FREE_QUOTA', 'WALLET_REFUND', 'MANUAL'],

    // ── Serah terima (§43/§44) ───────────────────────────────────────────
    'pickup_methods' => ['PICKUP', 'DORMITORY_DELIVERY'],
    'verification_methods' => ['RFID', 'QR', 'PIN', 'STUDENT_ID', 'AUTHORIZED_PERSON'],

    // ── Pembayaran (§19–§23) ─────────────────────────────────────────────
    'payment_methods' => ['WALLET', 'WALLET_RFID', 'MANUAL'],
    'payment_statuses' => ['PENDING', 'NOT_REQUIRED', 'PAYMENT_REQUIRED', 'PAID', 'FAILED', 'REFUNDED', 'WAIVED'],

    // Notifikasi saldo rendah (§46): saldo di bawah nilai ini memicu pesan ke wali.
    'low_balance_threshold' => 25000,

    // §56/Rule 27: order dengan porsi berbayar belum lunas tidak boleh diproses.
    'require_payment_before_processing' => true,

    // ── Penomoran ────────────────────────────────────────────────────────
    'numbering' => [
        'order_prefix' => 'LDR',
        'batch_prefix' => 'BATCH',
        'payment_prefix' => 'LPY',
        'refund_prefix' => 'LRF',
        'incident_prefix' => 'INC',
        'label_prefix' => 'LBL',
    ],

    // ── Jenis notifikasi wali (§74) ──────────────────────────────────────
    'notification_types' => [
        'ORDER_RECEIVED', 'PAYMENT_REQUIRED', 'PAYMENT_COMPLETED', 'PROCESSING',
        'READY', 'DELIVERED', 'INSUFFICIENT_BALANCE', 'LOW_BALANCE', 'INCIDENT',
    ],

    // ── Rate limiting ────────────────────────────────────────────────────
    'rate_limits' => [
        'module' => 180,
        'write' => 90,
        'payment' => 30,
    ],

    // ── Gateway (§59) — sumber kebenaran tetap modul masing-masing ──────
    'gateways' => [
        'student' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\EloquentStudentGateway::class,
        'parent' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\EloquentParentGateway::class,
        'boarding' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\BoardingPlacementGateway::class,
        'wallet' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\WalletModuleGateway::class,
        'rfid' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\WalletRfidGateway::class,
        'finance' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\FinancePostingGateway::class,
        'notification' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\DatabaseNotificationGateway::class,
        'asset' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\NullAssetGateway::class,
        'inventory' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\NullInventoryGateway::class,
        'workflow' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\NullWorkflowGateway::class,
        'document' => \App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways\NullDocumentGateway::class,
    ],
];
