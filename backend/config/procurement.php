<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modul Procurement & Vendor Management
    |--------------------------------------------------------------------------
    |
    | Seluruh ambang (threshold) dan toleransi di bawah ini dapat diubah tanpa
    | deploy karena dimuat dari konfigurasi/env. Prinsip modul: **jangan
    | hardcode** aturan bisnis yang bisa berbeda antar sekolah/yayasan.
    |
    */

    // Batas laju (request per menit per pengguna) untuk endpoint sensitif.
    'rate_limits' => [
        'module' => env('PROC_RL_MODULE', 300),
        'search' => env('PROC_RL_SEARCH', 120),
        'write' => env('PROC_RL_WRITE', 120),
        'report' => env('PROC_RL_REPORT', 20),
        'download' => env('PROC_RL_DOWNLOAD', 60),
    ],

    /*
    | Prefiks penomoran dokumen. Nomor final: {prefix}-{tahun}-{urut 6 digit},
    | mis. PR-2026-000001. Penomoran transaksional lewat DocumentSequence.
    */
    'numbering' => [
        'purchase_request' => 'PR',
        'rfq' => 'RFQ',
        'quotation' => 'QT',
        'purchase_order' => 'PO',
        'goods_receipt' => 'GR',
        'service_receipt' => 'SR',
        'vendor_invoice' => 'INV',
        'contract' => 'CTR',
    ],

    /*
    | Metode pengadaan & aturan jumlah penawaran minimum. Dipakai
    | ProcurementControlService untuk memutuskan jalur yang sah (direct
    | purchase vs RFQ kompetitif) dan berapa vendor minimum yang diundang.
    */
    'methods' => [
        'direct' => [
            'max_amount' => (float) env('PROC_DIRECT_MAX', 1_000_000),
            'min_quotations' => 0,
        ],
        'rfq' => [
            'max_amount' => (float) env('PROC_RFQ_MAX', 10_000_000),
            'min_quotations' => (int) env('PROC_RFQ_MIN_QUOTATIONS', 2),
        ],
        'competitive' => [
            'max_amount' => null,
            'min_quotations' => (int) env('PROC_COMPETITIVE_MIN_QUOTATIONS', 3),
        ],
    ],

    /*
    | Toleransi 3-way matching (PO vs Receiving vs Invoice). Persen dihitung
    | terhadap nilai PO; amount adalah toleransi absolut (rupiah).
    */
    'matching' => [
        'quantity_tolerance_percent' => (float) env('PROC_MATCH_QTY_PCT', 0),
        'price_tolerance_percent' => (float) env('PROC_MATCH_PRICE_PCT', 1),
        'amount_tolerance' => (float) env('PROC_MATCH_AMOUNT', 10_000),
    ],

    // Toleransi penerimaan barang (over/under delivery) dalam persen.
    'receiving' => [
        'over_delivery_tolerance_percent' => (float) env('PROC_OVER_DELIVERY_PCT', 5),
        'under_delivery_tolerance_percent' => (float) env('PROC_UNDER_DELIVERY_PCT', 5),
        'require_inspection' => (bool) env('PROC_REQUIRE_INSPECTION', false),
    ],

    /*
    | Bobot evaluasi vendor bawaan (dapat ditimpa per RFQ lewat tabel
    | quotation_evaluations). Total = 100.
    */
    'evaluation_weights' => [
        'price' => (float) env('PROC_EVAL_PRICE', 40),
        'technical' => (float) env('PROC_EVAL_TECHNICAL', 25),
        'delivery' => (float) env('PROC_EVAL_DELIVERY', 15),
        'quality' => (float) env('PROC_EVAL_QUALITY', 10),
        'warranty' => (float) env('PROC_EVAL_WARRANTY', 5),
        'performance' => (float) env('PROC_EVAL_PERFORMANCE', 5),
    ],

    /*
    | Ambang approval berbasis nilai (dipakai hanya sebagai *bahan* aturan
    | Workflow — keputusan akhir tetap milik Workflow Engine). Contoh:
    | < 5 juta -> Dept Head; 5-50 juta -> +Finance; > 50 juta -> +Manajemen.
    */
    'approval_thresholds' => [
        ['max' => (float) env('PROC_APPR_TIER1', 5_000_000), 'steps' => ['department_head']],
        ['max' => (float) env('PROC_APPR_TIER2', 50_000_000), 'steps' => ['department_head', 'budget_reviewer']],
        ['max' => null, 'steps' => ['department_head', 'budget_reviewer', 'procurement_manager', 'management']],
    ],

    // Kode workflow yang dipanggil di Workflow Engine (definisi bawaan "Pengadaan").
    'workflow' => [
        'purchase_request' => env('PROC_WF_PR', 'purchase-request'),
        'purchase_order' => env('PROC_WF_PO', 'purchase-order'),
        'vendor_onboarding' => env('PROC_WF_VENDOR', 'vendor-onboarding'),
    ],

    // Vendor wajib lolos status ini agar dapat dipakai pada PO.
    'vendor' => [
        'usable_statuses' => ['ACTIVE'],
        'blocked_risk_levels' => ['HIGH'],
        'document_expiry_warning_days' => (int) env('PROC_VENDOR_DOC_WARN_DAYS', 30),
    ],

    // Vendor performance scorecard — bobot KPI.
    'performance_weights' => [
        'on_time_delivery' => 0.30,
        'quality_acceptance' => 0.25,
        'order_fulfillment' => 0.20,
        'invoice_accuracy' => 0.15,
        'responsiveness' => 0.10,
    ],
];
