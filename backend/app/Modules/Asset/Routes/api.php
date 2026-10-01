<?php

use App\Modules\Asset\Http\Controllers\AssetBulkController;
use App\Modules\Asset\Http\Controllers\AssetController;
use App\Modules\Asset\Http\Controllers\AssetDashboardController;
use App\Modules\Asset\Http\Controllers\AssetDisposalController;
use App\Modules\Asset\Http\Controllers\AssetImportController;
use App\Modules\Asset\Http\Controllers\AssetInspectionController;
use App\Modules\Asset\Http\Controllers\AssetLoanController;
use App\Modules\Asset\Http\Controllers\AssetMetaController;
use App\Modules\Asset\Http\Controllers\AssetQrController;
use App\Modules\Asset\Http\Controllers\AssetStockOpnameController;
use App\Modules\Asset\Http\Controllers\AssetTransferController;
use App\Modules\Asset\Http\Controllers\DamageReportController;
use App\Modules\Asset\Http\Controllers\FacilityBookingController;
use App\Modules\Asset\Http\Controllers\FacilityController;
use App\Modules\Asset\Http\Controllers\LocationController;
use App\Modules\Asset\Http\Controllers\MaintenanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route Modul Asset & Sarpras
|--------------------------------------------------------------------------
|
| Semua route di bawah `/api/v1`. Cakupan baris dan kerahasiaan ditegakkan
| layanan; middleware di sini hanya menyaring pemanggil yang sudah pasti tidak
| berhak, sehingga kesalahan izin terlihat lebih awal dan jejaknya lebih rapi.
|
*/

// ── Pencarian aset dari hasil pindai (label QR/barcode) ─────────────────────
// Tanpa otentikasi, dengan batas laju: siapa pun yang memindai label harus
// mendapat jawaban, tetapi TIDAK PERNAH angka keuangan.
Route::get('assets/lookup/{code}', [AssetQrController::class, 'lookup'])
    ->middleware('throttle:asset-scan')
    ->name('assets.lookup');

Route::middleware('auth:sanctum')->group(function (): void {

    // ── Aset ────────────────────────────────────────────────────────────────
    Route::get('assets', [AssetController::class, 'index'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('assets.index');
    Route::post('assets', [AssetController::class, 'store'])->middleware(['permission:asset.register', 'throttle:asset-write'])->name('assets.store');
    Route::get('assets/{asset}', [AssetController::class, 'show'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('assets.show');
    Route::patch('assets/{asset}', [AssetController::class, 'update'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.update');
    Route::post('assets/{asset}/status', [AssetController::class, 'changeStatus'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.status');
    Route::post('assets/{asset}/condition', [AssetController::class, 'changeCondition'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.condition');
    Route::post('assets/{asset}/place', [AssetController::class, 'place'])->middleware(['permission:asset.assign', 'throttle:asset-write'])->name('assets.place');
    Route::post('assets/{asset}/archive', [AssetController::class, 'archive'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.archive');
    Route::get('assets/{asset}/timeline', [AssetController::class, 'timeline'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('assets.timeline');
    Route::get('assets/{asset}/history', [AssetController::class, 'history'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('assets.history');
    Route::get('assets/{asset}/financial', [AssetController::class, 'financial'])->middleware(['permission:asset.financial.view', 'throttle:asset-module'])->name('assets.financial');
    Route::get('assets/{asset}/label', [AssetQrController::class, 'label'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('assets.label');
    Route::post('assets/{asset}/label/print', [AssetQrController::class, 'markPrinted'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.label.print');
    Route::post('assets/{asset}/label/attach', [AssetQrController::class, 'attach'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.label.attach');
    Route::post('assets/{asset}/label/replace', [AssetQrController::class, 'replace'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.label.replace');
    Route::get('assets/{asset}/documents', [AssetController::class, 'documents'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('assets.documents');

    // Alur dari halaman detail aset.
    Route::post('assets/{asset}/assign', [AssetController::class, 'assign'])->middleware(['permission:asset.assign', 'throttle:asset-write'])->name('assets.assign');
    Route::post('assets/{asset}/transfer', [AssetController::class, 'transfer'])->middleware(['permission:asset.transfer.request', 'throttle:asset-write'])->name('assets.transfer');
    Route::post('assets/{asset}/loan', [AssetController::class, 'loan'])->middleware(['permission:asset.loan.request', 'throttle:asset-write'])->name('assets.loan');
    Route::post('assets/{asset}/return', [AssetController::class, 'returnLoan'])->middleware(['permission:asset.loan.manage', 'throttle:asset-write'])->name('assets.return');
    Route::post('assets/{asset}/inspect', [AssetController::class, 'inspect'])->middleware(['permission:asset.inspect.manage', 'throttle:asset-write'])->name('assets.inspect');
    Route::post('assets/{asset}/maintenance-request', [AssetController::class, 'maintenanceRequest'])->middleware(['permission:asset.maintenance.request', 'throttle:asset-write'])->name('assets.maintenance.request');
    Route::post('assets/{asset}/dispose', [AssetController::class, 'dispose'])->middleware(['permission:asset.disposal.request', 'throttle:asset-write'])->name('assets.dispose');
    Route::post('assets/{asset}/damage', [DamageReportController::class, 'store'])->middleware(['permission:asset.maintenance.request', 'throttle:asset-write'])->name('assets.damage');

    // Operasi massal.
    Route::post('assets/bulk/status', [AssetBulkController::class, 'status'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.bulk.status');
    Route::post('assets/bulk/assign', [AssetBulkController::class, 'assign'])->middleware(['permission:asset.assign', 'throttle:asset-write'])->name('assets.bulk.assign');
    Route::post('assets/bulk/inspect', [AssetBulkController::class, 'inspect'])->middleware(['permission:asset.inspect.manage', 'throttle:asset-write'])->name('assets.bulk.inspect');
    Route::post('assets/bulk/print-labels', [AssetBulkController::class, 'printLabels'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('assets.bulk.print');

    // Impor massal (unggah → pratinjau → konfirmasi).
    Route::post('assets/import', [AssetImportController::class, 'upload'])->middleware(['permission:asset.import', 'throttle:asset-write'])->name('assets.import.upload');
    Route::post('assets/import/{import}/confirm', [AssetImportController::class, 'confirm'])->middleware(['permission:asset.import', 'throttle:asset-write'])->name('assets.import.confirm');

    // ── Lokasi ──────────────────────────────────────────────────────────────
    Route::get('locations', [LocationController::class, 'index'])->middleware(['permission:location.view', 'throttle:asset-module'])->name('locations.index');
    Route::get('locations/tree', [LocationController::class, 'tree'])->middleware(['permission:location.view', 'throttle:asset-module'])->name('locations.tree');
    Route::post('locations', [LocationController::class, 'store'])->middleware(['permission:location.manage', 'throttle:asset-write'])->name('locations.store');
    Route::get('locations/{location}', [LocationController::class, 'show'])->middleware(['permission:location.view', 'throttle:asset-module'])->name('locations.show');
    Route::patch('locations/{location}', [LocationController::class, 'update'])->middleware(['permission:location.manage', 'throttle:asset-write'])->name('locations.update');
    Route::post('locations/{location}/archive', [LocationController::class, 'archive'])->middleware(['permission:location.manage', 'throttle:asset-write'])->name('locations.archive');

    // ── Fasilitas ───────────────────────────────────────────────────────────
    Route::get('facilities', [FacilityController::class, 'index'])->middleware(['permission:facility.view', 'throttle:asset-module'])->name('facilities.index');
    Route::post('facilities', [FacilityController::class, 'store'])->middleware(['permission:facility.manage', 'throttle:asset-write'])->name('facilities.store');
    Route::get('facilities/{facility}', [FacilityController::class, 'show'])->middleware(['permission:facility.view', 'throttle:asset-module'])->name('facilities.show');
    Route::patch('facilities/{facility}', [FacilityController::class, 'update'])->middleware(['permission:facility.manage', 'throttle:asset-write'])->name('facilities.update');
    Route::get('facilities/{facility}/availability', [FacilityController::class, 'availability'])->middleware(['permission:facility.view', 'throttle:asset-module'])->name('facilities.availability');
    Route::get('facilities/{facility}/utilization', [FacilityController::class, 'utilization'])->middleware(['permission:facility.view', 'throttle:asset-module'])->name('facilities.utilization');
    Route::post('facilities/{facility}/book', [FacilityController::class, 'book'])->middleware(['permission:facility.book', 'throttle:asset-write'])->name('facilities.book');
    Route::post('facilities/{facility}/close', [FacilityController::class, 'close'])->middleware(['permission:facility.close', 'throttle:asset-write'])->name('facilities.close');
    Route::post('facilities/{facility}/reopen', [FacilityController::class, 'reopen'])->middleware(['permission:facility.close', 'throttle:asset-write'])->name('facilities.reopen');
    Route::post('facilities/{facility}/blackouts', [FacilityController::class, 'addBlackout'])->middleware(['permission:facility.close', 'throttle:asset-write'])->name('facilities.blackouts.store');
    Route::post('facilities/{facility}/operating-hours', [FacilityController::class, 'setOperatingHours'])->middleware(['permission:facility.manage', 'throttle:asset-write'])->name('facilities.hours');

    // Booking fasilitas.
    Route::get('facility-bookings', [FacilityBookingController::class, 'index'])->middleware(['permission:facility.book', 'throttle:asset-module'])->name('bookings.index');
    Route::get('facility-bookings/{booking}', [FacilityBookingController::class, 'show'])->middleware(['permission:facility.book', 'throttle:asset-module'])->name('bookings.show');
    Route::post('facility-bookings/{booking}/approve', [FacilityBookingController::class, 'approve'])->middleware(['permission:facility.booking.approve', 'throttle:asset-write'])->name('bookings.approve');
    Route::post('facility-bookings/{booking}/reject', [FacilityBookingController::class, 'reject'])->middleware(['permission:facility.booking.approve', 'throttle:asset-write'])->name('bookings.reject');
    Route::post('facility-bookings/{booking}/check-in', [FacilityBookingController::class, 'checkIn'])->middleware(['permission:facility.book', 'throttle:asset-write'])->name('bookings.checkin');
    Route::post('facility-bookings/{booking}/complete', [FacilityBookingController::class, 'complete'])->middleware(['permission:facility.book', 'throttle:asset-write'])->name('bookings.complete');
    Route::post('facility-bookings/{booking}/cancel', [FacilityBookingController::class, 'cancel'])->middleware(['permission:facility.book', 'throttle:asset-write'])->name('bookings.cancel');

    // ── Pemeliharaan ────────────────────────────────────────────────────────
    Route::get('maintenance/requests', [MaintenanceController::class, 'requests'])->middleware(['permission:asset.maintenance.view', 'throttle:asset-module'])->name('maintenance.requests.index');
    Route::post('maintenance/requests', [MaintenanceController::class, 'storeRequest'])->middleware(['permission:asset.maintenance.request', 'throttle:asset-write'])->name('maintenance.requests.store');
    Route::get('maintenance/requests/{maintenanceRequest}', [MaintenanceController::class, 'showRequest'])->middleware(['permission:asset.maintenance.view', 'throttle:asset-module'])->name('maintenance.requests.show');
    Route::post('maintenance/requests/{maintenanceRequest}/triage', [MaintenanceController::class, 'triage'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('maintenance.requests.triage');
    Route::post('maintenance/requests/{maintenanceRequest}/assign', [MaintenanceController::class, 'assign'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('maintenance.requests.assign');

    Route::get('maintenance/work-orders', [MaintenanceController::class, 'workOrders'])->middleware(['permission:asset.maintenance.view', 'throttle:asset-module'])->name('maintenance.work-orders.index');
    Route::get('maintenance/work-orders/{workOrder}', [MaintenanceController::class, 'showWorkOrder'])->middleware(['permission:asset.maintenance.view', 'throttle:asset-module'])->name('maintenance.work-orders.show');
    Route::post('maintenance/work-orders/{workOrder}/start', [MaintenanceController::class, 'start'])->middleware(['permission:asset.maintenance.execute', 'throttle:asset-write'])->name('maintenance.work-orders.start');
    Route::post('maintenance/work-orders/{workOrder}/parts', [MaintenanceController::class, 'requestParts'])->middleware(['permission:asset.maintenance.execute', 'throttle:asset-write'])->name('maintenance.work-orders.parts');
    Route::post('maintenance/work-orders/{workOrder}/external-service', [MaintenanceController::class, 'externalService'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('maintenance.work-orders.external');
    Route::post('maintenance/work-orders/{workOrder}/tasks', [MaintenanceController::class, 'addTask'])->middleware(['permission:asset.maintenance.execute', 'throttle:asset-write'])->name('maintenance.work-orders.tasks.store');
    Route::post('maintenance/work-orders/{workOrder}/tasks/{task}/complete', [MaintenanceController::class, 'completeTask'])->middleware(['permission:asset.maintenance.execute', 'throttle:asset-write'])->name('maintenance.work-orders.tasks.complete');
    Route::post('maintenance/work-orders/{workOrder}/complete', [MaintenanceController::class, 'complete'])->middleware(['permission:asset.maintenance.execute', 'throttle:asset-write'])->name('maintenance.work-orders.complete');
    Route::post('maintenance/work-orders/{workOrder}/verify', [MaintenanceController::class, 'verify'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('maintenance.work-orders.verify');
    Route::post('maintenance/work-orders/{workOrder}/cancel', [MaintenanceController::class, 'cancel'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('maintenance.work-orders.cancel');

    // Jadwal preventif.
    Route::get('maintenance/schedules', [MaintenanceController::class, 'schedules'])->middleware(['permission:asset.maintenance.view', 'throttle:asset-module'])->name('maintenance.schedules.index');
    Route::post('maintenance/schedules', [MaintenanceController::class, 'storeSchedule'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('maintenance.schedules.store');
    Route::post('maintenance/schedules/run', [MaintenanceController::class, 'runSchedule'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('maintenance.schedules.run');

    // ── Inspeksi ────────────────────────────────────────────────────────────
    Route::get('inspections', [AssetInspectionController::class, 'index'])->middleware(['permission:asset.inspect.view', 'throttle:asset-module'])->name('inspections.index');
    Route::post('inspections', [AssetInspectionController::class, 'store'])->middleware(['permission:asset.inspect.manage', 'throttle:asset-write'])->name('inspections.store');
    Route::get('inspections/{inspection}', [AssetInspectionController::class, 'show'])->middleware(['permission:asset.inspect.view', 'throttle:asset-module'])->name('inspections.show');
    Route::post('inspections/{inspection}/perform', [AssetInspectionController::class, 'perform'])->middleware(['permission:asset.inspect.manage', 'throttle:asset-write'])->name('inspections.perform');
    Route::get('inspections/templates', [AssetInspectionController::class, 'templates'])->middleware(['permission:asset.inspect.view', 'throttle:asset-module'])->name('inspections.templates');

    // ── Kerusakan & insiden ─────────────────────────────────────────────────
    Route::get('damage-reports', [DamageReportController::class, 'index'])->middleware(['permission:asset.maintenance.view', 'throttle:asset-module'])->name('damage.index');
    Route::post('damage-reports', [DamageReportController::class, 'store'])->middleware(['permission:asset.maintenance.request', 'throttle:asset-write'])->name('damage.store');
    Route::get('damage-reports/{report}', [DamageReportController::class, 'show'])->middleware(['permission:asset.maintenance.view', 'throttle:asset-module'])->name('damage.show');
    Route::post('damage-reports/{report}/verify', [DamageReportController::class, 'verify'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('damage.verify');
    Route::post('damage-reports/{report}/maintenance', [DamageReportController::class, 'createMaintenance'])->middleware(['permission:asset.maintenance.manage', 'throttle:asset-write'])->name('damage.maintenance');

    // ── Peminjaman ──────────────────────────────────────────────────────────
    Route::get('loans', [AssetLoanController::class, 'index'])->middleware(['permission:asset.loan.view', 'throttle:asset-module'])->name('loans.index');
    Route::post('loans', [AssetLoanController::class, 'store'])->middleware(['permission:asset.loan.request', 'throttle:asset-write'])->name('loans.store');
    Route::get('loans/{loan}', [AssetLoanController::class, 'show'])->middleware(['permission:asset.loan.view', 'throttle:asset-module'])->name('loans.show');
    Route::post('loans/{loan}/approve', [AssetLoanController::class, 'approve'])->middleware(['permission:asset.loan.approve', 'throttle:asset-write'])->name('loans.approve');
    Route::post('loans/{loan}/reserve', [AssetLoanController::class, 'reserve'])->middleware(['permission:asset.loan.manage', 'throttle:asset-write'])->name('loans.reserve');
    Route::post('loans/{loan}/handover', [AssetLoanController::class, 'handover'])->middleware(['permission:asset.loan.manage', 'throttle:asset-write'])->name('loans.handover');
    Route::post('loans/{loan}/activate', [AssetLoanController::class, 'activate'])->middleware(['permission:asset.loan.manage', 'throttle:asset-write'])->name('loans.activate');
    Route::post('loans/{loan}/return', [AssetLoanController::class, 'returnLoan'])->middleware(['permission:asset.loan.manage', 'throttle:asset-write'])->name('loans.return');
    Route::post('loans/{loan}/cancel', [AssetLoanController::class, 'cancel'])->middleware(['permission:asset.loan.manage', 'throttle:asset-write'])->name('loans.cancel');

    // ── Pemindahan ──────────────────────────────────────────────────────────
    Route::get('transfers', [AssetTransferController::class, 'index'])->middleware(['permission:asset.transfer.view', 'throttle:asset-module'])->name('transfers.index');
    Route::post('transfers', [AssetTransferController::class, 'store'])->middleware(['permission:asset.transfer.request', 'throttle:asset-write'])->name('transfers.store');
    Route::get('transfers/{transfer}', [AssetTransferController::class, 'show'])->middleware(['permission:asset.transfer.view', 'throttle:asset-module'])->name('transfers.show');
    Route::post('transfers/{transfer}/approve', [AssetTransferController::class, 'approve'])->middleware(['permission:asset.transfer.approve', 'throttle:asset-write'])->name('transfers.approve');
    Route::post('transfers/{transfer}/handover', [AssetTransferController::class, 'handover'])->middleware(['permission:asset.transfer.approve', 'throttle:asset-write'])->name('transfers.handover');
    Route::post('transfers/{transfer}/receive', [AssetTransferController::class, 'receive'])->middleware(['permission:asset.transfer.view', 'throttle:asset-write'])->name('transfers.receive');
    Route::post('transfers/{transfer}/cancel', [AssetTransferController::class, 'cancel'])->middleware(['permission:asset.transfer.approve', 'throttle:asset-write'])->name('transfers.cancel');

    // ── Stok opname ─────────────────────────────────────────────────────────
    Route::get('stock-opnames', [AssetStockOpnameController::class, 'index'])->middleware(['permission:asset.stockopname.view', 'throttle:asset-module'])->name('opnames.index');
    Route::post('stock-opnames', [AssetStockOpnameController::class, 'store'])->middleware(['permission:asset.stockopname.create', 'throttle:asset-write'])->name('opnames.store');
    Route::get('stock-opnames/{opname}', [AssetStockOpnameController::class, 'show'])->middleware(['permission:asset.stockopname.view', 'throttle:asset-module'])->name('opnames.show');
    Route::post('stock-opnames/{opname}/start', [AssetStockOpnameController::class, 'start'])->middleware(['permission:asset.stockopname.manage', 'throttle:asset-write'])->name('opnames.start');
    Route::post('stock-opnames/{opname}/scan', [AssetStockOpnameController::class, 'scan'])->middleware(['permission:asset.stockopname.manage', 'throttle:asset-scan'])->name('opnames.scan');
    Route::post('stock-opnames/{opname}/verify', [AssetStockOpnameController::class, 'verify'])->middleware(['permission:asset.stockopname.manage', 'throttle:asset-write'])->name('opnames.verify');
    Route::post('stock-opnames/{opname}/variances/{variance}/decide', [AssetStockOpnameController::class, 'decideVariance'])->middleware(['permission:asset.stockopname.approve', 'throttle:asset-write'])->name('opnames.variances.decide');
    Route::post('stock-opnames/{opname}/complete', [AssetStockOpnameController::class, 'complete'])->middleware(['permission:asset.stockopname.approve', 'throttle:asset-write'])->name('opnames.complete');
    Route::get('stock-opnames/{opname}/summary', [AssetStockOpnameController::class, 'summary'])->middleware(['permission:asset.stockopname.view', 'throttle:asset-module'])->name('opnames.summary');

    // ── Penghapusan ─────────────────────────────────────────────────────────
    Route::get('disposals', [AssetDisposalController::class, 'index'])->middleware(['permission:asset.disposal.view', 'throttle:asset-module'])->name('disposals.index');
    Route::post('disposals', [AssetDisposalController::class, 'store'])->middleware(['permission:asset.disposal.request', 'throttle:asset-write'])->name('disposals.store');
    Route::get('disposals/{disposal}', [AssetDisposalController::class, 'show'])->middleware(['permission:asset.disposal.view', 'throttle:asset-module'])->name('disposals.show');
    Route::post('disposals/{disposal}/inspect', [AssetDisposalController::class, 'inspect'])->middleware(['permission:asset.disposal.request', 'throttle:asset-write'])->name('disposals.inspect');
    Route::post('disposals/{disposal}/value', [AssetDisposalController::class, 'value'])->middleware(['permission:asset.financial.view', 'throttle:asset-write'])->name('disposals.value');
    Route::post('disposals/{disposal}/approve', [AssetDisposalController::class, 'approve'])->middleware(['permission:asset.disposal.approve', 'throttle:asset-write'])->name('disposals.approve');
    Route::post('disposals/{disposal}/reject', [AssetDisposalController::class, 'reject'])->middleware(['permission:asset.disposal.approve', 'throttle:asset-write'])->name('disposals.reject');
    Route::post('disposals/{disposal}/execute', [AssetDisposalController::class, 'execute'])->middleware(['permission:asset.disposal.execute', 'throttle:asset-write'])->name('disposals.execute');
    Route::post('disposals/{disposal}/retire', [AssetDisposalController::class, 'retire'])->middleware(['permission:asset.disposal.execute', 'throttle:asset-write'])->name('disposals.retire');

    // ── Penggantian ─────────────────────────────────────────────────────────
    Route::get('replacements', [AssetDisposalController::class, 'replacements'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('replacements.index');
    Route::get('assets/{asset}/replacement', [AssetDisposalController::class, 'evaluateReplacement'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('assets.replacement');
    Route::post('replacements/{replacement}/review', [AssetDisposalController::class, 'reviewReplacement'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('replacements.review');
    Route::post('replacements/{replacement}/procurement', [AssetDisposalController::class, 'forwardReplacement'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('replacements.procurement');

    // ── Dashboard & laporan ─────────────────────────────────────────────────
    Route::get('dashboard/assets', [AssetDashboardController::class, 'assets'])->middleware(['permission:asset.dashboard.view', 'throttle:asset-module'])->name('dashboard.assets');
    Route::get('dashboard/maintenance', [AssetDashboardController::class, 'maintenance'])->middleware(['permission:asset.dashboard.view', 'throttle:asset-module'])->name('dashboard.maintenance');
    Route::get('dashboard/facilities', [AssetDashboardController::class, 'facilities'])->middleware(['permission:asset.dashboard.view', 'throttle:asset-module'])->name('dashboard.facilities');
    Route::get('dashboard/stock-opnames', [AssetDashboardController::class, 'stockOpnames'])->middleware(['permission:asset.dashboard.view', 'throttle:asset-module'])->name('dashboard.opnames');
    Route::get('dashboard/reconciliation', [AssetDashboardController::class, 'reconciliation'])->middleware(['permission:asset.dashboard.view', 'throttle:asset-module'])->name('dashboard.reconciliation');
    Route::get('reports/{kind}', [AssetDashboardController::class, 'report'])->middleware(['permission:asset.report.view', 'throttle:asset-module'])->name('reports.show');
    Route::get('reports/{kind}/export', [AssetDashboardController::class, 'export'])->middleware(['permission:asset.export', 'throttle:asset-module'])->name('reports.export');

    // ── Meta (untuk layar: pilihan enum, katalog, pemegang, vendor) ─────────
    Route::get('meta/enums', [AssetMetaController::class, 'enums'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('meta.enums');
    Route::get('meta/categories', [AssetMetaController::class, 'categories'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('meta.categories');
    Route::post('meta/categories', [AssetMetaController::class, 'storeCategory'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('meta.categories.store');
    Route::post('meta/types', [AssetMetaController::class, 'storeType'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('meta.types.store');
    Route::get('meta/custodians', [AssetMetaController::class, 'custodians'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('meta.custodians');
    Route::post('meta/custodians', [AssetMetaController::class, 'storeCustodian'])->middleware(['permission:asset.manage', 'throttle:asset-write'])->name('meta.custodians.store');
    Route::get('meta/vendors', [AssetMetaController::class, 'vendors'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('meta.vendors');
    Route::get('meta/search', [AssetMetaController::class, 'search'])->middleware(['permission:asset.view', 'throttle:asset-module'])->name('meta.search');
});

// Penyusutan: dijalankan terjadwal dari perintah, tetapi juga dapat dipicu
// pengguna berizin keuangan (pratinjau selalu tersedia lebih dulu).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('depreciations/preview', [AssetDashboardController::class, 'depreciationPreview'])->middleware(['permission:asset.financial.view', 'throttle:asset-module'])->name('depreciations.preview');
    Route::post('depreciations/run', [AssetDashboardController::class, 'depreciationRun'])->middleware(['permission:asset.financial.manage', 'throttle:asset-write'])->name('depreciations.run');
    Route::post('assets/{asset}/revalue', [AssetController::class, 'revalue'])->middleware(['permission:asset.financial.manage', 'throttle:asset-write'])->name('assets.revalue');
});
