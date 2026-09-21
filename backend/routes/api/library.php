<?php

use App\Http\Controllers\Api\LibraryAuditLogController;
use App\Http\Controllers\Api\LibraryBibliographyItemController;
use App\Http\Controllers\Api\LibraryCatalogController;
use App\Http\Controllers\Api\LibraryAcquisitionController;
use App\Http\Controllers\Api\LibraryCirculationController;
use App\Http\Controllers\Api\LibraryClosureController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\LibraryDocumentController;
use App\Http\Controllers\Api\LibraryFineController;
use App\Http\Controllers\Api\LibraryInventoryController;
use App\Http\Controllers\Api\LibraryReservationController;
use App\Http\Controllers\Api\LibraryItemController;
use App\Http\Controllers\Api\LibraryLocationController;
use App\Http\Controllers\Api\LibraryMasterController;
use App\Http\Controllers\Api\LibraryMemberCardController;
use App\Http\Controllers\Api\LibraryMemberController;
use App\Http\Controllers\Api\LibraryNotificationController;
use App\Http\Controllers\Api\LibraryNumberingController;
use App\Http\Controllers\Api\LibraryReportController;
use App\Http\Controllers\Api\LibraryOpacController;
use App\Http\Controllers\Api\LibraryOperatingHourController;
use App\Http\Controllers\Api\LibrarySettingsController;
use App\Services\Library\MasterData\LibraryMasterRegistry;
use Illuminate\Support\Facades\Route;

/*
 * Modul Library / Perpustakaan — Step 1 (Foundation).
 *
 * Dimuat otomatis oleh `routes/api.php` di dalam grup `auth:sanctum` +
 * prefix `v1`, jadi setiap rute di sini sudah terautentikasi.
 *
 * Pembagian permission:
 * - library.view/create/update/delete         → CRUD perpustakaan
 * - library.settings.view/update              → konfigurasi key-value
 * - library.location.*                        → hierarki lokasi
 * - library.operating_hours.view/update       → jam operasional
 * - library.closure.*                         → penutupan
 * - library.numbering.view/update             → format penomoran
 * - library.dashboard.view                    → dashboard & status kelengkapan
 * - library.audit.view                        → jejak audit
 *
 * `scopeBindings()` memastikan anak (lokasi/penutupan) harus benar-benar
 * milik perpustakaan pada URL — id dari perpustakaan lain menghasilkan 404.
 */
Route::prefix('library')->scopeBindings()->group(function (): void {
    // ================= PERPUSTAKAAN =================
    Route::get('libraries', [LibraryController::class, 'index'])
        ->middleware('permission:library.view')->name('library.libraries.index');
    Route::post('libraries', [LibraryController::class, 'store'])
        ->middleware('permission:library.create')->name('library.libraries.store');
    Route::get('libraries/{library}', [LibraryController::class, 'show'])
        ->middleware('permission:library.view')->name('library.libraries.show');
    Route::match(['put', 'patch'], 'libraries/{library}', [LibraryController::class, 'update'])
        ->middleware('permission:library.update')->name('library.libraries.update');
    Route::delete('libraries/{library}', [LibraryController::class, 'destroy'])
        ->middleware('permission:library.delete')->name('library.libraries.destroy');

    Route::post('libraries/{library}/transition', [LibraryController::class, 'transition'])
        ->middleware('permission:library.update')->name('library.libraries.transition');

    Route::get('libraries/{library}/dashboard', [LibraryController::class, 'dashboard'])
        ->middleware('permission:library.dashboard.view')->name('library.libraries.dashboard');
    Route::get('libraries/{library}/setup-status', [LibraryController::class, 'setupStatus'])
        ->middleware('permission:library.dashboard.view')->name('library.libraries.setup-status');

    // ================= KONFIGURASI =================
    Route::get('libraries/{library}/settings', [LibrarySettingsController::class, 'show'])
        ->middleware('permission:library.settings.view')->name('library.settings.show');
    Route::put('libraries/{library}/settings', [LibrarySettingsController::class, 'update'])
        ->middleware('permission:library.settings.update')->name('library.settings.update');

    // ================= LOKASI =================
    Route::get('libraries/{library}/locations', [LibraryLocationController::class, 'index'])
        ->middleware('permission:library.location.view')->name('library.locations.index');
    Route::post('libraries/{library}/locations', [LibraryLocationController::class, 'store'])
        ->middleware('permission:library.location.create')->name('library.locations.store');
    Route::match(['put', 'patch'], 'libraries/{library}/locations/{location}', [LibraryLocationController::class, 'update'])
        ->middleware('permission:library.location.update')->name('library.locations.update');
    Route::delete('libraries/{library}/locations/{location}', [LibraryLocationController::class, 'destroy'])
        ->middleware('permission:library.location.delete')->name('library.locations.destroy');
    Route::post('libraries/{library}/locations/{location}/move', [LibraryLocationController::class, 'move'])
        ->middleware('permission:library.location.update')->name('library.locations.move');

    // ================= JAM OPERASIONAL =================
    Route::get('libraries/{library}/operating-hours', [LibraryOperatingHourController::class, 'index'])
        ->middleware('permission:library.operating_hours.view')->name('library.operating-hours.index');
    Route::put('libraries/{library}/operating-hours', [LibraryOperatingHourController::class, 'update'])
        ->middleware('permission:library.operating_hours.update')->name('library.operating-hours.update');

    // ================= PENUTUPAN =================
    Route::get('libraries/{library}/closures', [LibraryClosureController::class, 'index'])
        ->middleware('permission:library.closure.view')->name('library.closures.index');
    Route::post('libraries/{library}/closures', [LibraryClosureController::class, 'store'])
        ->middleware('permission:library.closure.create')->name('library.closures.store');
    Route::match(['put', 'patch'], 'libraries/{library}/closures/{closure}', [LibraryClosureController::class, 'update'])
        ->middleware('permission:library.closure.update')->name('library.closures.update');
    Route::delete('libraries/{library}/closures/{closure}', [LibraryClosureController::class, 'destroy'])
        ->middleware('permission:library.closure.delete')->name('library.closures.destroy');

    // ================= PENOMORAN =================
    Route::get('libraries/{library}/numbering', [LibraryNumberingController::class, 'index'])
        ->middleware('permission:library.numbering.view')->name('library.numbering.index');
    Route::put('libraries/{library}/numbering', [LibraryNumberingController::class, 'update'])
        ->middleware('permission:library.numbering.update')->name('library.numbering.update');

    // ================= JEJAK AUDIT =================
    Route::get('audit-logs', [LibraryAuditLogController::class, 'index'])
        ->middleware('permission:library.audit.view')->name('library.audit-logs.index');

    // ================= MASTER DATA (Step 2) =================
    // Metadata master (grup, scope, hierarki, definisi field) untuk antarmuka.
    Route::get('master-meta', [LibraryMasterController::class, 'meta'])
        ->middleware('permission:library.master.view')
        ->name('library.masters.meta');

    // Rute dibangkitkan per master dari registry supaya setiap master memakai
    // middleware permission granular-nya sendiri
    // (mis. `library.collection_type.create`). Parameter `master` diteruskan
    // sebagai default rute, sehingga controller tidak perlu menebak dari path.
    foreach (LibraryMasterRegistry::keys() as $masterKey) {
        $permission = LibraryMasterRegistry::find($masterKey)['permission'];

        Route::get($masterKey, [LibraryMasterController::class, 'index'])
            ->defaults('master', $masterKey)
            ->middleware('permission:library.master.view')
            ->name("library.masters.{$masterKey}.index");

        Route::post($masterKey, [LibraryMasterController::class, 'store'])
            ->defaults('master', $masterKey)
            ->middleware("permission:library.{$permission}.create")
            ->name("library.masters.{$masterKey}.store");

        Route::post("{$masterKey}/bulk-status", [LibraryMasterController::class, 'bulkStatus'])
            ->defaults('master', $masterKey)
            ->middleware("permission:library.{$permission}.update")
            ->name("library.masters.{$masterKey}.bulk-status");

        Route::get("{$masterKey}/{id}", [LibraryMasterController::class, 'show'])
            ->whereNumber('id')
            ->defaults('master', $masterKey)
            ->middleware('permission:library.master.view')
            ->name("library.masters.{$masterKey}.show");

        Route::match(['put', 'patch'], "{$masterKey}/{id}", [LibraryMasterController::class, 'update'])
            ->whereNumber('id')
            ->defaults('master', $masterKey)
            ->middleware("permission:library.{$permission}.update")
            ->name("library.masters.{$masterKey}.update");

        Route::delete("{$masterKey}/{id}", [LibraryMasterController::class, 'destroy'])
            ->whereNumber('id')
            ->defaults('master', $masterKey)
            ->middleware("permission:library.{$permission}.delete")
            ->name("library.masters.{$masterKey}.destroy");
    }

    // ================= KATALOG BIBLIOGRAFI (Fase 3) =================
    // Rute literal didaftarkan sebelum `{bibliography}` agar tidak tertangkap
    // sebagai parameter.
    Route::get('catalog', [LibraryCatalogController::class, 'index'])
        ->middleware('permission:library.catalog.view')->name('library.catalog.index');
    Route::post('catalog', [LibraryCatalogController::class, 'store'])
        ->middleware('permission:library.catalog.create')->name('library.catalog.store');
    Route::post('catalog/duplicate-check', [LibraryCatalogController::class, 'duplicateCheck'])
        ->middleware('permission:library.catalog.view')->name('library.catalog.duplicate-check');
    Route::post('catalog/bulk-status', [LibraryCatalogController::class, 'bulkStatus'])
        ->middleware('permission:library.catalog.update')->name('library.catalog.bulk-status');

    Route::get('catalog/{bibliography}', [LibraryCatalogController::class, 'show'])
        ->middleware('permission:library.catalog.view')->name('library.catalog.show');
    Route::match(['put', 'patch'], 'catalog/{bibliography}', [LibraryCatalogController::class, 'update'])
        ->middleware('permission:library.catalog.update')->name('library.catalog.update');
    Route::delete('catalog/{bibliography}', [LibraryCatalogController::class, 'destroy'])
        ->middleware('permission:library.catalog.delete')->name('library.catalog.destroy');
    Route::get('catalog/{bibliography}/availability', [LibraryCatalogController::class, 'availability'])
        ->middleware('permission:library.catalog.view')->name('library.catalog.availability');
    Route::get('catalog/{bibliography}/opac-preview', [LibraryCatalogController::class, 'opacPreview'])
        ->middleware('permission:library.opac.view')->name('library.catalog.opac-preview');

    // Eksemplar milik satu record.
    Route::get('catalog/{bibliography}/items', [LibraryBibliographyItemController::class, 'index'])
        ->middleware('permission:library.item.view')->name('library.catalog.items.index');
    Route::post('catalog/{bibliography}/items', [LibraryBibliographyItemController::class, 'store'])
        ->middleware('permission:library.item.create')->name('library.catalog.items.store');
    Route::post('catalog/{bibliography}/items/bulk', [LibraryBibliographyItemController::class, 'bulk'])
        ->middleware('permission:library.item.create')->name('library.catalog.items.bulk');

    // ================= EKSEMPLAR (lintas record) =================
    Route::get('items', [LibraryItemController::class, 'index'])
        ->middleware('permission:library.item.view')->name('library.items.index');
    Route::post('items/bulk-assign', [LibraryItemController::class, 'bulkAssign'])
        ->middleware('permission:library.item.update')->name('library.items.bulk-assign');
    Route::post('items/bulk-barcode', [LibraryItemController::class, 'bulkGenerateBarcode'])
        ->middleware('permission:library.item.update')->name('library.items.bulk-barcode');

    Route::get('items/{item}', [LibraryItemController::class, 'show'])
        ->middleware('permission:library.item.view')->name('library.items.show');
    Route::match(['put', 'patch'], 'items/{item}', [LibraryItemController::class, 'update'])
        ->middleware('permission:library.item.update')->name('library.items.update');
    Route::delete('items/{item}', [LibraryItemController::class, 'destroy'])
        ->middleware('permission:library.item.delete')->name('library.items.destroy');
    Route::post('items/{item}/status', [LibraryItemController::class, 'setStatus'])
        ->middleware('permission:library.item.update')->name('library.items.status');

    // ================= ANGGOTA & KEANGGOTAAN =================
    Route::get('members', [LibraryMemberController::class, 'index'])
        ->middleware('permission:library.member.view')->name('library.members.index');
    Route::post('members', [LibraryMemberController::class, 'store'])
        ->middleware('permission:library.member.create')->name('library.members.store');
    Route::get('members/summary', [LibraryMemberController::class, 'summary'])
        ->middleware('permission:library.member.view')->name('library.members.summary');
    Route::post('members/evaluate-statuses', [LibraryMemberController::class, 'evaluateExpirations'])
        ->middleware('permission:library.membership.update')->name('library.members.evaluate-statuses');

    // "Kartu Saya" — cukup autentikasi, tanpa permission modul (§40).
    Route::get('members/me/card', [LibraryMemberCardController::class, 'mine'])
        ->name('library.members.me-card');

    // Batch kartu (literal, sebelum `{member}`).
    Route::post('members/cards/batch', [LibraryMemberCardController::class, 'batch'])
        ->middleware('permission:library.member_card.generate')->name('library.member-cards.batch');

    Route::get('members/{member}', [LibraryMemberController::class, 'show'])
        ->middleware('permission:library.member.view')->name('library.members.show');
    Route::match(['put', 'patch'], 'members/{member}', [LibraryMemberController::class, 'update'])
        ->middleware('permission:library.member.update')->name('library.members.update');
    Route::delete('members/{member}', [LibraryMemberController::class, 'destroy'])
        ->middleware('permission:library.member.delete')->name('library.members.destroy');
    Route::post('members/{member}/status', [LibraryMemberController::class, 'setStatus'])
        ->middleware('permission:library.membership.update')->name('library.members.status');
    Route::post('members/{member}/renew', [LibraryMemberController::class, 'renew'])
        ->middleware('permission:library.membership.update')->name('library.members.renew');

    // Kartu anggota.
    Route::get('members/{member}/card', [LibraryMemberCardController::class, 'show'])
        ->middleware('permission:library.member_card.view')->name('library.members.card.show');
    Route::post('members/{member}/card/generate', [LibraryMemberCardController::class, 'generate'])
        ->middleware('permission:library.member_card.generate')->name('library.members.card.generate');
    Route::post('member-cards/{card}/printed', [LibraryMemberCardController::class, 'markPrinted'])
        ->middleware('permission:library.member_card.view')->name('library.member-cards.printed');
    Route::post('member-cards/{card}/revoke', [LibraryMemberCardController::class, 'revoke'])
        ->middleware('permission:library.member_card.generate')->name('library.member-cards.revoke');

    // ================= SIRKULASI (Fase 4) =================
    // Layar cepat (§7) & pencarian anggota (§8).
    Route::get('circulation/stats', [LibraryCirculationController::class, 'stats'])
        ->middleware('permission:library.circulation.view')->name('library.circulation.stats');
    Route::get('circulation/lookup', [LibraryCirculationController::class, 'lookup'])
        ->middleware('permission:library.circulation.view')->name('library.circulation.lookup');
    Route::get('circulation/members/{member}/summary', [LibraryCirculationController::class, 'memberSummary'])
        ->middleware('permission:library.circulation.view')->name('library.circulation.member-summary');
    Route::post('circulation/checkout', [LibraryCirculationController::class, 'checkout'])
        ->middleware('permission:library.circulation.checkout')->name('library.circulation.checkout');
    Route::post('circulation/overdue/mark', [LibraryCirculationController::class, 'markOverdue'])
        ->middleware('permission:library.circulation.manage')->name('library.circulation.mark-overdue');

    Route::get('loans', [LibraryCirculationController::class, 'index'])
        ->middleware('permission:library.circulation.view')->name('library.loans.index');
    Route::get('loans/{loan}', [LibraryCirculationController::class, 'show'])
        ->middleware('permission:library.circulation.view')->name('library.loans.show');
    Route::post('loans/{loan}/return', [LibraryCirculationController::class, 'returnItem'])
        ->middleware('permission:library.circulation.return')->name('library.loans.return');
    Route::post('loans/{loan}/renew', [LibraryCirculationController::class, 'renew'])
        ->middleware('permission:library.circulation.renew')->name('library.loans.renew');

    // ================= DENDA & PEMBAYARAN =================
    Route::get('fines', [LibraryFineController::class, 'index'])
        ->middleware('permission:library.fine.view')->name('library.fines.index');
    Route::get('fines/summary', [LibraryFineController::class, 'summary'])
        ->middleware('permission:library.fine.view')->name('library.fines.summary');
    Route::post('fines', [LibraryFineController::class, 'store'])
        ->middleware('permission:library.fine.manage')->name('library.fines.store');
    Route::get('fines/{fine}', [LibraryFineController::class, 'show'])
        ->middleware('permission:library.fine.view')->name('library.fines.show');
    Route::post('fines/{fine}/pay', [LibraryFineController::class, 'pay'])
        ->middleware('permission:library.fine.manage')->name('library.fines.pay');
    Route::post('fines/{fine}/waive', [LibraryFineController::class, 'waive'])
        ->middleware('permission:library.fine.manage')->name('library.fines.waive');

    // ================= RESERVASI =================
    Route::get('reservations', [LibraryReservationController::class, 'index'])
        ->middleware('permission:library.reservation.view')->name('library.reservations.index');
    Route::get('reservations/summary', [LibraryReservationController::class, 'summary'])
        ->middleware('permission:library.reservation.view')->name('library.reservations.summary');
    Route::post('reservations', [LibraryReservationController::class, 'store'])
        ->middleware('permission:library.reservation.create')->name('library.reservations.store');
    Route::post('reservations/expire', [LibraryReservationController::class, 'expire'])
        ->middleware('permission:library.reservation.manage')->name('library.reservations.expire');
    Route::get('reservations/{reservation}', [LibraryReservationController::class, 'show'])
        ->middleware('permission:library.reservation.view')->name('library.reservations.show');
    Route::post('reservations/{reservation}/pickup', [LibraryReservationController::class, 'pickup'])
        ->middleware('permission:library.reservation.manage')->name('library.reservations.pickup');
    Route::post('reservations/{reservation}/cancel', [LibraryReservationController::class, 'cancel'])
        ->middleware('permission:library.reservation.manage')->name('library.reservations.cancel');

    // ================= PENGADAAN =================
    Route::get('acquisitions', [LibraryAcquisitionController::class, 'index'])
        ->middleware('permission:library.acquisition.view')->name('library.acquisitions.index');
    Route::get('acquisitions/summary', [LibraryAcquisitionController::class, 'summary'])
        ->middleware('permission:library.acquisition.view')->name('library.acquisitions.summary');
    Route::post('acquisitions', [LibraryAcquisitionController::class, 'store'])
        ->middleware('permission:library.acquisition.create')->name('library.acquisitions.store');
    Route::get('acquisitions/{acquisition}', [LibraryAcquisitionController::class, 'show'])
        ->middleware('permission:library.acquisition.view')->name('library.acquisitions.show');
    Route::match(['put', 'patch'], 'acquisitions/{acquisition}', [LibraryAcquisitionController::class, 'update'])
        ->middleware('permission:library.acquisition.create')->name('library.acquisitions.update');
    Route::delete('acquisitions/{acquisition}', [LibraryAcquisitionController::class, 'destroy'])
        ->middleware('permission:library.acquisition.manage')->name('library.acquisitions.destroy');
    Route::post('acquisitions/{acquisition}/transition', [LibraryAcquisitionController::class, 'transition'])
        ->middleware('permission:library.acquisition.manage')->name('library.acquisitions.transition');
    Route::post('acquisitions/{acquisition}/receive', [LibraryAcquisitionController::class, 'receive'])
        ->middleware('permission:library.acquisition.manage')->name('library.acquisitions.receive');

    // ================= LAPORAN, EKSPOR & IMPOR =================
    Route::middleware('permission:library.report.view')->prefix('reports')->group(function (): void {
        Route::get('/collection', [LibraryReportController::class, 'collection'])->name('library.reports.collection');
        Route::get('/circulation', [LibraryReportController::class, 'circulation'])->name('library.reports.circulation');
        Route::get('/member', [LibraryReportController::class, 'member'])->name('library.reports.member');
        Route::get('/fine', [LibraryReportController::class, 'fine'])->name('library.reports.fine');
        Route::get('/acquisition', [LibraryReportController::class, 'acquisition'])->name('library.reports.acquisition');
        Route::get('/inventory', [LibraryReportController::class, 'inventory'])->name('library.reports.inventory');
        Route::get('/reservation', [LibraryReportController::class, 'reservation'])->name('library.reports.reservation');
    });

    Route::get('export', [LibraryReportController::class, 'export'])
        ->middleware('permission:library.report.export')->name('library.export');

    Route::get('imports/{type}/template', [LibraryReportController::class, 'template'])
        ->middleware('permission:library.report.view')->name('library.imports.template');
    Route::post('imports/{type}/preview', [LibraryReportController::class, 'preview'])
        ->middleware('permission:library.report.view')->name('library.imports.preview');
    Route::post('imports/{type}/commit', [LibraryReportController::class, 'commit'])
        ->middleware('permission:library.report.export')->name('library.imports.commit');

    // ================= NOTIFIKASI (milik pengguna sendiri) =================
    Route::prefix('notifications')->group(function (): void {
        Route::get('/', [LibraryNotificationController::class, 'index'])->name('library.notifications.index');
        Route::get('/unread-count', [LibraryNotificationController::class, 'unreadCount'])->name('library.notifications.unread-count');
        Route::post('/read-all', [LibraryNotificationController::class, 'markAllAsRead'])->name('library.notifications.read-all');
        Route::post('/{notification}/read', [LibraryNotificationController::class, 'markAsRead'])->name('library.notifications.read');
    });

    // ================= INVENTARIS / STOCK OPNAME =================
    Route::get('inventory-sessions', [LibraryInventoryController::class, 'index'])
        ->middleware('permission:library.inventory.view')->name('library.inventory.index');
    Route::post('inventory-sessions', [LibraryInventoryController::class, 'store'])
        ->middleware('permission:library.inventory.manage')->name('library.inventory.store');
    Route::get('inventory-sessions/{session}', [LibraryInventoryController::class, 'show'])
        ->middleware('permission:library.inventory.view')->name('library.inventory.show');
    Route::post('inventory-sessions/{session}/scan', [LibraryInventoryController::class, 'scan'])
        ->middleware('permission:library.inventory.manage')->name('library.inventory.scan');
    Route::post('inventory-sessions/{session}/finalize', [LibraryInventoryController::class, 'finalize'])
        ->middleware('permission:library.inventory.manage')->name('library.inventory.finalize');
    Route::get('inventory-report', [LibraryInventoryController::class, 'report'])
        ->middleware('permission:library.inventory.view')->name('library.inventory.report');

    // ================= DOKUMEN PDF (kartu, label, laporan) =================
    Route::get('members/{member}/card/pdf', [LibraryDocumentController::class, 'memberCard'])
        ->middleware('permission:library.member_card.view')->name('library.members.card.pdf');
    Route::match(['get', 'post'], 'labels/pdf', [LibraryDocumentController::class, 'labels'])
        ->middleware('permission:library.item.view')->name('library.labels.pdf');
    Route::get('reports/{type}/pdf', [LibraryDocumentController::class, 'report'])
        ->middleware('permission:library.report.view')->name('library.reports.pdf');

    // ================= OPAC (PUBLIK, tanpa login) =================
    // Katalog publik bersifat terbuka untuk data yang memang publik (§42);
    // penyaringan data sensitif dilakukan di OpacService.
    Route::get('opac', [LibraryOpacController::class, 'index'])
        ->withoutMiddleware(['auth:sanctum'])->name('library.opac.index');
    Route::get('opac/{slug}', [LibraryOpacController::class, 'show'])
        ->withoutMiddleware(['auth:sanctum'])->name('library.opac.show');
    Route::get('opac/{slug}/availability', [LibraryOpacController::class, 'availability'])
        ->withoutMiddleware(['auth:sanctum'])->name('library.opac.availability');
});