<?php

use App\Modules\Procurement\Http\Controllers\CatalogController;
use App\Modules\Procurement\Http\Controllers\InvoiceController;
use App\Modules\Procurement\Http\Controllers\ProcurementMetaController;
use App\Modules\Procurement\Http\Controllers\ProcurementReportController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequestController;
use App\Modules\Procurement\Http\Controllers\ReceivingController;
use App\Modules\Procurement\Http\Controllers\QuotationController;
use App\Modules\Procurement\Http\Controllers\RfqController;
use App\Modules\Procurement\Http\Controllers\VendorController;
use App\Modules\Procurement\Http\Controllers\VendorSelectionController;
use Illuminate\Support\Facades\Route;

/*
| Route Modul Procurement & Vendor — seluruhnya di bawah `/api/v1/procurement/*`.
|
| Middleware `permission:procurement.*` menentukan *layar/aksi* yang boleh
| dibuka (§45). Cakupan *baris* (siapa boleh melihat dokumen milik unit mana)
| ditegakkan di lapisan service, bukan di sini.
|
| Tidak ada endpoint yang menulis jurnal, payable, atau kartu stok: semua itu
| melewati kontrak (Accounting/Inventory/Asset) sehingga modul lain tetap
| menjadi sumber kebenarannya.
*/

Route::middleware(['auth:sanctum', 'throttle:procurement-module'])->prefix('procurement')->group(function (): void {

    // ---------------- Konteks, metadata, dasbor ----------------
    Route::get('me', [ProcurementMetaController::class, 'me'])->middleware('permission:procurement.view')->name('procurement.me');
    Route::get('metadata', [ProcurementMetaController::class, 'metadata'])->middleware('permission:procurement.view')->name('procurement.metadata');
    Route::get('dashboard', [ProcurementMetaController::class, 'dashboard'])->middleware('permission:procurement.view')->name('procurement.dashboard');
    Route::get('health', [ProcurementMetaController::class, 'health'])->middleware('permission:procurement.manage_settings')->name('procurement.health');
    Route::get('options', [ProcurementMetaController::class, 'options'])->middleware('throttle:procurement-search')->middleware('permission:procurement.view')->name('procurement.options');

    // ---------------- Vendor ----------------
    Route::get('vendors', [VendorController::class, 'index'])->middleware('throttle:procurement-search')->middleware('permission:procurement.view')->name('procurement.vendors.index');
    Route::post('vendors', [VendorController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.store');
    Route::get('vendors/expiring-documents', [VendorController::class, 'expiringDocuments'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.expiring-documents');
    Route::get('vendors/{vendor}', [VendorController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.vendors.show');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->middleware('throttle:procurement-write')->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.update');
    Route::post('vendors/{vendor}/submit', [VendorController::class, 'submit'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.submit');
    Route::post('vendors/{vendor}/verify', [VendorController::class, 'verify'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.verify');
    Route::post('vendors/{vendor}/approve', [VendorController::class, 'approve'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.approve');
    Route::post('vendors/{vendor}/reject', [VendorController::class, 'reject'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.reject');
    Route::post('vendors/{vendor}/suspend', [VendorController::class, 'suspend'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.suspend');
    Route::post('vendors/{vendor}/deactivate', [VendorController::class, 'deactivate'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.deactivate');
    Route::post('vendors/{vendor}/classify', [VendorController::class, 'classify'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.classify');
    Route::post('vendors/{vendor}/documents', [VendorController::class, 'storeDocument'])->middleware('throttle:procurement-write')->middleware('permission:procurement.manage_vendor')->name('procurement.vendors.documents.store');
    Route::get('vendors/{vendor}/performance', [VendorController::class, 'performance'])->middleware('permission:procurement.view_reports')->name('procurement.vendors.performance');
    Route::post('vendor-documents/{document}/verify', [VendorController::class, 'verifyDocument'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendor-documents.verify');
    Route::post('vendor-documents/{document}/reject', [VendorController::class, 'rejectDocument'])->middleware('permission:procurement.manage_vendor')->name('procurement.vendor-documents.reject');

    // ---------------- Katalog & kategori ----------------
    Route::get('categories', [CatalogController::class, 'categories'])->middleware('permission:procurement.view')->name('procurement.categories.index');
    Route::post('categories', [CatalogController::class, 'storeCategory'])->middleware('permission:procurement.manage_settings')->name('procurement.categories.store');
    Route::put('categories/{category}', [CatalogController::class, 'updateCategory'])->middleware('permission:procurement.manage_settings')->name('procurement.categories.update');
    Route::get('items', [CatalogController::class, 'items'])->middleware('throttle:procurement-search')->middleware('permission:procurement.view')->name('procurement.items.index');
    Route::post('items', [CatalogController::class, 'storeItem'])->middleware('permission:procurement.manage_settings')->name('procurement.items.store');
    Route::put('items/{item}', [CatalogController::class, 'updateItem'])->middleware('permission:procurement.manage_settings')->name('procurement.items.update');

    // ---------------- Purchase Request ----------------
    Route::get('purchase-requests', [PurchaseRequestController::class, 'index'])->middleware('throttle:procurement-search')->middleware('permission:procurement.view')->name('procurement.purchase-requests.index');
    Route::post('purchase-requests', [PurchaseRequestController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.create_pr')->name('procurement.purchase-requests.store');
    Route::post('purchase-requests/consolidate', [PurchaseRequestController::class, 'consolidate'])->middleware('permission:procurement.create_pr')->name('procurement.purchase-requests.consolidate');
    Route::get('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.purchase-requests.show');
    Route::put('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update'])->middleware('throttle:procurement-write')->middleware('permission:procurement.create_pr')->name('procurement.purchase-requests.update');
    Route::post('purchase-requests/{purchaseRequest}/validate', [PurchaseRequestController::class, 'validateRequest'])->middleware('permission:procurement.view')->name('procurement.purchase-requests.validate');
    Route::post('purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:procurement.create_pr')->name('procurement.purchase-requests.submit');
    Route::post('purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->middleware('permission:procurement.approve_pr')->name('procurement.purchase-requests.approve');
    Route::post('purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->middleware('permission:procurement.approve_pr')->name('procurement.purchase-requests.reject');
    Route::post('purchase-requests/{purchaseRequest}/return', [PurchaseRequestController::class, 'returnRequest'])->middleware('permission:procurement.approve_pr')->name('procurement.purchase-requests.return');
    Route::get('purchase-requests/{purchaseRequest}/timeline', [PurchaseRequestController::class, 'timeline'])->middleware('permission:procurement.view')->name('procurement.purchase-requests.timeline');

    // ---------------- RFQ ----------------
    Route::get('rfqs', [RfqController::class, 'index'])->middleware('throttle:procurement-search')->middleware('permission:procurement.view')->name('procurement.rfqs.index');
    Route::post('rfqs', [RfqController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.create_rfq')->name('procurement.rfqs.store');
    Route::post('events/{event}/rfq', [RfqController::class, 'createFromEvent'])->middleware('permission:procurement.create_rfq')->name('procurement.events.rfq');
    Route::get('rfqs/{rfq}', [RfqController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.rfqs.show');
    Route::post('rfqs/{rfq}/invite', [RfqController::class, 'invite'])->middleware('permission:procurement.create_rfq')->name('procurement.rfqs.invite');
    Route::post('rfqs/{rfq}/issue', [RfqController::class, 'issue'])->middleware('permission:procurement.create_rfq')->name('procurement.rfqs.issue');
    Route::post('rfqs/{rfq}/close', [RfqController::class, 'close'])->middleware('permission:procurement.create_rfq')->name('procurement.rfqs.close');

    // ---------------- Penawaran & evaluasi ----------------
    Route::get('quotations', [QuotationController::class, 'index'])->middleware('permission:procurement.view')->name('procurement.quotations.index');
    Route::post('quotations', [QuotationController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.manage_quotation')->name('procurement.quotations.store');
    Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.quotations.show');
    Route::put('quotations/{quotation}', [QuotationController::class, 'update'])->middleware('throttle:procurement-write')->middleware('permission:procurement.manage_quotation')->name('procurement.quotations.update');
    Route::get('rfqs/{rfq}/comparison', [QuotationController::class, 'comparison'])->middleware('permission:procurement.view')->name('procurement.rfqs.comparison');
    Route::get('rfqs/{rfq}/criteria', [QuotationController::class, 'criteria'])->middleware('permission:procurement.view')->name('procurement.rfqs.criteria');
    Route::post('rfqs/{rfq}/criteria', [QuotationController::class, 'defineCriteria'])->middleware('permission:procurement.evaluate_vendor')->name('procurement.rfqs.criteria.store');
    Route::post('quotations/{quotation}/evaluate', [QuotationController::class, 'evaluate'])->middleware('permission:procurement.evaluate_vendor')->name('procurement.quotations.evaluate');
    Route::get('rfqs/{rfq}/ranking', [QuotationController::class, 'ranking'])->middleware('permission:procurement.view')->name('procurement.rfqs.ranking');

    // ---------------- Pemilihan vendor ----------------
    Route::get('vendor-selections', [VendorSelectionController::class, 'index'])->middleware('permission:procurement.view')->name('procurement.vendor-selections.index');
    Route::post('rfqs/{rfq}/select', [VendorSelectionController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.evaluate_vendor')->name('procurement.rfqs.select');
    Route::get('vendor-selections/{selection}', [VendorSelectionController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.vendor-selections.show');
    Route::post('vendor-selections/{selection}/approve', [VendorSelectionController::class, 'approve'])->middleware('permission:procurement.approve_po')->name('procurement.vendor-selections.approve');

    // ---------------- Purchase Order ----------------
    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('throttle:procurement-search')->middleware('permission:procurement.view')->name('procurement.purchase-orders.index');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.create_po')->name('procurement.purchase-orders.store');
    Route::post('purchase-orders/from-selection', [PurchaseOrderController::class, 'storeFromSelection'])->middleware('permission:procurement.create_po')->name('procurement.purchase-orders.from-selection');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.purchase-orders.show');
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:procurement.create_po')->name('procurement.purchase-orders.submit');
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:procurement.approve_po')->name('procurement.purchase-orders.approve');
    Route::post('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->middleware('permission:procurement.approve_po')->name('procurement.purchase-orders.reject');
    Route::post('purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->middleware('permission:procurement.issue_po')->name('procurement.purchase-orders.issue');
    Route::post('purchase-orders/{purchaseOrder}/amend', [PurchaseOrderController::class, 'amend'])->middleware('throttle:procurement-write')->middleware('permission:procurement.create_po')->name('procurement.purchase-orders.amend');
    Route::get('purchase-orders/{purchaseOrder}/versions', [PurchaseOrderController::class, 'versions'])->middleware('permission:procurement.view')->name('procurement.purchase-orders.versions');
    Route::post('purchase-orders/{purchaseOrder}/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('permission:procurement.create_po')->name('procurement.purchase-orders.confirm');
    Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:procurement.approve_po')->name('procurement.purchase-orders.close');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:procurement.approve_po')->name('procurement.purchase-orders.cancel');

    // ---------------- Penerimaan ----------------
    Route::get('goods-receipts', [ReceivingController::class, 'index'])->middleware('permission:procurement.view')->name('procurement.goods-receipts.index');
    Route::post('purchase-orders/{purchaseOrder}/receipts', [ReceivingController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.receive')->name('procurement.purchase-orders.receipts.store');
    Route::get('goods-receipts/{goodsReceipt}', [ReceivingController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.goods-receipts.show');
    Route::post('goods-receipts/{goodsReceipt}/inspect', [ReceivingController::class, 'inspect'])->middleware('throttle:procurement-write')->middleware('permission:procurement.inspect')->name('procurement.goods-receipts.inspect');
    Route::get('service-receipts', [ReceivingController::class, 'serviceReceipts'])->middleware('permission:procurement.view')->name('procurement.service-receipts.index');
    Route::post('purchase-orders/{purchaseOrder}/service-receipts', [ReceivingController::class, 'storeServiceReceipt'])->middleware('permission:procurement.receive')->name('procurement.purchase-orders.service-receipts.store');
    Route::post('service-receipts/{serviceReceipt}/accept', [ReceivingController::class, 'acceptServiceReceipt'])->middleware('permission:procurement.receive')->name('procurement.service-receipts.accept');
    Route::post('service-receipts/{serviceReceipt}/reject', [ReceivingController::class, 'rejectServiceReceipt'])->middleware('permission:procurement.receive')->name('procurement.service-receipts.reject');

    // ---------------- Invoice, matching, handoff ----------------
    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('throttle:procurement-search')->middleware('permission:procurement.view')->name('procurement.invoices.index');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware('throttle:procurement-write')->middleware('permission:procurement.manage_invoice')->name('procurement.invoices.store');
    Route::get('invoices/{vendorInvoice}', [InvoiceController::class, 'show'])->middleware('permission:procurement.view')->name('procurement.invoices.show');
    Route::put('invoices/{vendorInvoice}', [InvoiceController::class, 'update'])->middleware('permission:procurement.manage_invoice')->name('procurement.invoices.update');
    Route::post('invoices/{vendorInvoice}/match', [InvoiceController::class, 'match'])->middleware('permission:procurement.match_invoice')->name('procurement.invoices.match');
    Route::post('invoices/{vendorInvoice}/override', [InvoiceController::class, 'override'])->middleware('permission:procurement.match_invoice')->name('procurement.invoices.override');
    Route::post('invoices/{vendorInvoice}/approve', [InvoiceController::class, 'approve'])->middleware('permission:procurement.match_invoice')->name('procurement.invoices.approve');
    Route::post('invoices/{vendorInvoice}/handoff', [InvoiceController::class, 'handoff'])->middleware('permission:procurement.send_to_accounting')->name('procurement.invoices.handoff');
    Route::post('invoices/{vendorInvoice}/retry-handoff', [InvoiceController::class, 'retryHandoff'])->middleware('permission:procurement.send_to_accounting')->name('procurement.invoices.retry-handoff');
    Route::post('invoices/{vendorInvoice}/sync-status', [InvoiceController::class, 'syncStatus'])->middleware('permission:procurement.send_to_accounting')->name('procurement.invoices.sync-status');
    Route::get('invoices/{vendorInvoice}/payment-status', [InvoiceController::class, 'paymentStatus'])->middleware('permission:procurement.view')->name('procurement.invoices.payment-status');
    Route::get('invoice-exceptions', [InvoiceController::class, 'exceptions'])->middleware('permission:procurement.view')->name('procurement.invoice-exceptions.index');
    Route::post('invoice-exceptions/{exception}/investigate', [InvoiceController::class, 'investigate'])->middleware('permission:procurement.match_invoice')->name('procurement.invoice-exceptions.investigate');
    Route::post('invoice-exceptions/{exception}/resolve', [InvoiceController::class, 'resolve'])->middleware('permission:procurement.match_invoice')->name('procurement.invoice-exceptions.resolve');

    // ---------------- Laporan & pengaturan ----------------
    Route::get('reports/{type}', [ProcurementReportController::class, 'report'])->middleware('throttle:procurement-report')->middleware('permission:procurement.view_reports')->name('procurement.reports.show');
    Route::get('reports/spend/analytics', [ProcurementReportController::class, 'spend'])->middleware('throttle:procurement-report')->middleware('permission:procurement.view_reports')->name('procurement.reports.spend');
    Route::get('reports/cycle-times', [ProcurementReportController::class, 'cycleTimes'])->middleware('permission:procurement.view_reports')->name('procurement.reports.cycle-times');
    Route::get('budget/availability', [ProcurementReportController::class, 'budgetAvailability'])->middleware('permission:procurement.view')->name('procurement.budget.availability');
    Route::get('settings', [ProcurementReportController::class, 'settings'])->middleware('permission:procurement.manage_settings')->name('procurement.settings.index');
    Route::post('settings', [ProcurementReportController::class, 'updateSetting'])->middleware('permission:procurement.manage_settings')->name('procurement.settings.update');
});
