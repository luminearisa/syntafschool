<?php

use App\Modules\SchoolOperations\Laundry\Http\Controllers\{
    BagController, CatalogController, DashboardController, DeliveryController,
    EntitlementController, IncidentController, OrderController, PolicyController,
    ProcessingController
};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Laundry Management API (v1) — /api/v1/laundry
|--------------------------------------------------------------------------
| Semua route memakai Sanctum + izin `laundry.*` dan batas laju modul.
| Otorisasi baris (wali hanya anaknya, santri hanya dirinya) ditegakkan di
| PortalController/service, bukan hanya di middleware.
*/

// ── Dashboard & laporan (§48–§51, §73) ───────────────────────────────────
Route::get('dashboard', [DashboardController::class, 'dashboard']);
Route::get('reports/daily', [DashboardController::class, 'dailyOperations']);
Route::get('reports/free-vs-paid', [DashboardController::class, 'freeVsPaid']);
Route::get('reports/quota', [DashboardController::class, 'quotaReport']);
Route::get('reports/wallet', [DashboardController::class, 'walletReport']);
Route::get('reports/operational', [DashboardController::class, 'operationalReport']);
Route::get('audit-logs', [DashboardController::class, 'auditLogs']);

// ── Identifikasi RFID/QR (§17, §70) ──────────────────────────────────────
Route::post('identify', [OrderController::class, 'identify'])->middleware('throttle:laundry-write');

// ── Katalog: layanan, harga, mesin (§3, §4, §34) ─────────────────────────
Route::get('services', [CatalogController::class, 'services']);
Route::post('services', [CatalogController::class, 'storeService'])->middleware('throttle:laundry-write');
Route::put('services/{service}', [CatalogController::class, 'updateService']);
Route::get('pricing-rules', [CatalogController::class, 'pricingRules']);
Route::post('pricing-rules', [CatalogController::class, 'storePricingRule'])->middleware('throttle:laundry-write');
Route::post('pricing-rules/{pricingRule}/deactivate', [CatalogController::class, 'deactivatePricingRule']);
Route::get('pricing', [CatalogController::class, 'pricingRules']);
Route::get('machines', [CatalogController::class, 'machines']);
Route::post('machines', [CatalogController::class, 'storeMachine'])->middleware('throttle:laundry-write');
Route::put('machines/{machine}', [CatalogController::class, 'updateMachine']);

// ── Policy jatah (§5, §7, §8) ────────────────────────────────────────────
Route::get('policies', [PolicyController::class, 'index']);
Route::post('policies', [PolicyController::class, 'store'])->middleware('throttle:laundry-write');
Route::get('policies/resolve/{studentId}', [PolicyController::class, 'resolve']);
Route::post('policies/{policy}/versions', [PolicyController::class, 'storeVersion'])->middleware('throttle:laundry-write');
Route::post('policy-versions/{version}/publish', [PolicyController::class, 'publishVersion']);
Route::post('policy-versions/{version}/archive', [PolicyController::class, 'archiveVersion']);

// ── Entitlement & ledger (§6, §13, §14, §52) ─────────────────────────────
Route::get('entitlements', [EntitlementController::class, 'index']);
Route::get('entitlements/{studentId}', [EntitlementController::class, 'show']);
Route::get('entitlements/{studentId}/transactions', [EntitlementController::class, 'transactions']);
Route::post('entitlements/{studentId}/adjust', [EntitlementController::class, 'adjust'])->middleware('throttle:laundry-write');
Route::get('entitlements/{studentId}/statement', [EntitlementController::class, 'statement']);

// ── Orders (§9–§12, §29–§31, §54–§57, §61) ───────────────────────────────
Route::get('orders', [OrderController::class, 'index']);
Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:laundry-write');
Route::post('orders/preview', [OrderController::class, 'preview']);
Route::get('orders/{order}', [OrderController::class, 'show']);
Route::get('orders/{order}/timeline', [OrderController::class, 'timeline']);
Route::post('orders/{order}/receive', [OrderController::class, 'receive'])->middleware('throttle:laundry-write');
Route::post('orders/{order}/weigh', [OrderController::class, 'weigh'])->middleware('throttle:laundry-write');
Route::post('orders/{order}/calculate', [OrderController::class, 'calculate'])->middleware('throttle:laundry-write');
Route::get('orders/{order}/payment-preview', [OrderController::class, 'paymentPreview']);
Route::post('orders/{order}/pay', [OrderController::class, 'pay'])->middleware('throttle:laundry-payment');
Route::post('orders/{order}/refund', [OrderController::class, 'refund'])->middleware('throttle:laundry-payment');
Route::post('orders/{order}/queue', [OrderController::class, 'queue']);
Route::post('orders/{order}/start', [OrderController::class, 'queue']);
Route::post('orders/{order}/transition', [OrderController::class, 'transition']);
Route::post('orders/{order}/complete', [OrderController::class, 'complete']);
Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:laundry-write');
Route::post('orders/{order}/override', [OrderController::class, 'override'])->middleware('throttle:laundry-write');
Route::get('orders/{order}/label', [OrderController::class, 'label']);

// ── Pemrosesan: kanban, batch, langkah, QC, kemasan (§32–§38, §42) ───────
Route::get('queue', [ProcessingController::class, 'kanban']);
Route::get('batches', [ProcessingController::class, 'batches']);
Route::post('batches', [ProcessingController::class, 'storeBatch'])->middleware('throttle:laundry-write');
Route::get('batches/{batch}', [ProcessingController::class, 'showBatch']);
Route::post('batches/{batch}/orders', [ProcessingController::class, 'addBatchOrders']);
Route::post('batches/{batch}/start', [ProcessingController::class, 'startBatch']);
Route::post('batches/{batch}/complete', [ProcessingController::class, 'completeBatch']);
Route::post('orders/{order}/steps', [ProcessingController::class, 'recordStep'])->middleware('throttle:laundry-write');
Route::post('orders/{order}/qc', [ProcessingController::class, 'qualityCheck'])->middleware('throttle:laundry-write');
Route::post('orders/{order}/package', [ProcessingController::class, 'package']);

// ── Insiden & kompensasi (§39–§41) ───────────────────────────────────────
Route::get('incidents', [IncidentController::class, 'index']);
Route::post('orders/{order}/incidents', [IncidentController::class, 'store'])->middleware('throttle:laundry-write');
Route::post('incidents/{incident}/status', [IncidentController::class, 'updateStatus']);
Route::post('incidents/{incident}/compensation', [IncidentController::class, 'proposeCompensation']);
Route::post('incidents/{incident}/compensation/approve', [IncidentController::class, 'approveCompensation'])->middleware('throttle:laundry-payment');

// ── Laundry bag (§16) ────────────────────────────────────────────────────
Route::get('bags', [BagController::class, 'index']);
Route::post('bags', [BagController::class, 'store'])->middleware('throttle:laundry-write');
Route::put('bags/{bag}', [BagController::class, 'update']);
Route::post('bags/{bag}/assign', [BagController::class, 'assign']);
Route::post('bags/{bag}/release', [BagController::class, 'release']);

// ── Serah terima (§43, §44) ──────────────────────────────────────────────
Route::post('orders/{order}/ready', [DeliveryController::class, 'ready']);
Route::post('orders/{order}/pickup', [DeliveryController::class, 'pickup'])->middleware('throttle:laundry-write');
Route::post('orders/{order}/deliver', [DeliveryController::class, 'deliver'])->middleware('throttle:laundry-write');
