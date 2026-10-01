<?php

use App\Http\Controllers\Api\Finance\FinanceAccountController;
use App\Http\Controllers\Api\Finance\FinanceAdjustmentController;
use App\Http\Controllers\Api\Finance\FinanceBankAccountController;
use App\Http\Controllers\Api\Finance\FinanceBankStatementController;
use App\Http\Controllers\Api\Finance\FinanceBankTransactionController;
use App\Http\Controllers\Api\Finance\FinanceBillingController;
use App\Http\Controllers\Api\Finance\FinanceBudgetController;
use App\Http\Controllers\Api\Finance\FinanceCashAccountController;
use App\Http\Controllers\Api\Finance\FinanceCashierSessionController;
use App\Http\Controllers\Api\Finance\FinanceCashTransactionController;
use App\Http\Controllers\Api\Finance\FinanceClearanceController;
use App\Http\Controllers\Api\Finance\FinanceCollectionController;
use App\Http\Controllers\Api\Finance\FinanceCostCenterController;
use App\Http\Controllers\Api\Finance\FinanceDashboardController;
use App\Http\Controllers\Api\Finance\FinanceDiscountController;
use App\Http\Controllers\Api\Finance\FinanceEligibilityController;
use App\Http\Controllers\Api\Finance\FinanceExpenseController;
use App\Http\Controllers\Api\Finance\FinanceExpenseRequestController;
use App\Http\Controllers\Api\Finance\FinanceFeePackageController;
use App\Http\Controllers\Api\Finance\FinanceFeeTypeController;
use App\Http\Controllers\Api\Finance\FinanceInvoiceController;
use App\Http\Controllers\Api\Finance\FinanceJournalController;
use App\Http\Controllers\Api\Finance\FinanceLedgerController;
use App\Http\Controllers\Api\Finance\FinanceNotificationController;
use App\Http\Controllers\Api\Finance\FinanceOperationController;
use App\Http\Controllers\Api\Finance\FinancePayableController;
use App\Http\Controllers\Api\Finance\FinancePaymentController;
use App\Http\Controllers\Api\Finance\FinancePaymentPlanController;
use App\Http\Controllers\Api\Finance\FinancePeriodController;
use App\Http\Controllers\Api\Finance\FinancePortalController;
use App\Http\Controllers\Api\Finance\FinancePurchaseOrderController;
use App\Http\Controllers\Api\Finance\FinancePurchaseRequestController;
use App\Http\Controllers\Api\Finance\FinanceReceiptController;
use App\Http\Controllers\Api\Finance\FinanceReceivableController;
use App\Http\Controllers\Api\Finance\FinanceReconciliationController;
use App\Http\Controllers\Api\Finance\FinanceRefundController;
use App\Http\Controllers\Api\Finance\FinanceReportController;
use App\Http\Controllers\Api\Finance\FinanceReRegistrationController;
use App\Http\Controllers\Api\Finance\FinanceScholarshipController;
use App\Http\Controllers\Api\Finance\FinanceSettingsController;
use App\Http\Controllers\Api\Finance\FinanceTransferController;
use App\Http\Controllers\Api\Finance\FinanceVendorController;
use App\Http\Controllers\Api\Finance\FinanceVendorInvoiceController;
use App\Http\Controllers\Api\Finance\FinanceBusinessUnitController;
use App\Http\Controllers\Api\Finance\FinanceFundController;
use App\Http\Controllers\Api\Finance\FinanceJournalVoidController;
use App\Http\Controllers\Api\Finance\FinanceOpeningBalanceController;
use App\Http\Controllers\Api\Finance\FinancePeriodReopenController;
use App\Http\Controllers\Api\Finance\FinanceStandardReportController;
use Illuminate\Support\Facades\Route;

/*
 * Finance / Keuangan — master data & konfigurasi (Slice 5).
 *
 * Dimuat otomatis oleh `routes/api.php` di dalam grup `v1` + `auth:sanctum`,
 * jadi tidak ada prefix/middleware auth di sini (pola sama dengan boarding.php).
 *
 * Otorisasi: satu `permission:` per route (§3) — tidak ada pemeriksaan role di
 * controller. Pemisahan pencatat vs penyetuju dijaga oleh permission granular:
 * `finance.budget.manage` (ajukan) ≠ `finance.budget.approve` (setujui).
 *
 * Route transaksi (billing, payment, expense, …) menyusul di slice berikutnya
 * dan akan ditambahkan ke file ini.
 */
Route::prefix('finance')->name('finance.')->group(function (): void {
    // ---------------- Chart of Accounts (§5, §6) ----------------------
    Route::get('accounts/tree', [FinanceAccountController::class, 'tree'])
        ->middleware('permission:finance.account.view')->name('accounts.tree');
    Route::get('accounts', [FinanceAccountController::class, 'index'])
        ->middleware('permission:finance.account.view')->name('accounts.index');
    Route::post('accounts', [FinanceAccountController::class, 'store'])
        ->middleware('permission:finance.account.manage')->name('accounts.store');
    Route::get('accounts/{account}', [FinanceAccountController::class, 'show'])
        ->middleware('permission:finance.account.view')->name('accounts.show');
    Route::match(['put', 'patch'], 'accounts/{account}', [FinanceAccountController::class, 'update'])
        ->middleware('permission:finance.account.manage')->name('accounts.update');
    Route::delete('accounts/{account}', [FinanceAccountController::class, 'destroy'])
        ->middleware('permission:finance.account.manage')->name('accounts.destroy');

    // ---------------- Cost center (§7) --------------------------------
    Route::get('cost-centers', [FinanceCostCenterController::class, 'index'])
        ->middleware('permission:finance.cost_center.view')->name('cost-centers.index');
    Route::post('cost-centers', [FinanceCostCenterController::class, 'store'])
        ->middleware('permission:finance.cost_center.manage')->name('cost-centers.store');
    Route::get('cost-centers/{costCenter}', [FinanceCostCenterController::class, 'show'])
        ->middleware('permission:finance.cost_center.view')->name('cost-centers.show');
    Route::match(['put', 'patch'], 'cost-centers/{costCenter}', [FinanceCostCenterController::class, 'update'])
        ->middleware('permission:finance.cost_center.manage')->name('cost-centers.update');
    Route::delete('cost-centers/{costCenter}', [FinanceCostCenterController::class, 'destroy'])
        ->middleware('permission:finance.cost_center.manage')->name('cost-centers.destroy');

    // ---------------- Anggaran (§8) + approval (§9) -------------------
    Route::get('budgets', [FinanceBudgetController::class, 'index'])
        ->middleware('permission:finance.budget.view')->name('budgets.index');
    Route::post('budgets', [FinanceBudgetController::class, 'store'])
        ->middleware('permission:finance.budget.manage')->name('budgets.store');
    // Budget vs Actual (§9) dan "Check Budget → Available?" (§10). Rutenya
    // harus sebelum `budgets/{budget}` agar `positions`/`availability` tidak
    // terbaca sebagai id anggaran.
    Route::get('budgets/positions', [FinanceBudgetController::class, 'positions'])
        ->middleware('permission:finance.budget.view')->name('budgets.positions');
    Route::get('budgets/availability', [FinanceBudgetController::class, 'availability'])
        ->middleware('permission:finance.budget.view|finance.expense.view')->name('budgets.availability');
    Route::get('budgets/{budget}', [FinanceBudgetController::class, 'show'])
        ->middleware('permission:finance.budget.view')->name('budgets.show');
    Route::match(['put', 'patch'], 'budgets/{budget}', [FinanceBudgetController::class, 'update'])
        ->middleware('permission:finance.budget.manage')->name('budgets.update');
    Route::delete('budgets/{budget}', [FinanceBudgetController::class, 'destroy'])
        ->middleware('permission:finance.budget.manage')->name('budgets.destroy');
    Route::post('budgets/{budget}/submit', [FinanceBudgetController::class, 'submit'])
        ->middleware('permission:finance.budget.manage')->name('budgets.submit');
    Route::post('budgets/{budget}/approve', [FinanceBudgetController::class, 'approve'])
        ->middleware('permission:finance.budget.approve')->name('budgets.approve');
    Route::post('budgets/{budget}/reject', [FinanceBudgetController::class, 'reject'])
        ->middleware('permission:finance.budget.approve')->name('budgets.reject');
    // Menutup dan membatalkan anggaran sama-sama mengakhiri masa berlakunya,
    // jadi keduanya di tangan penyetuju anggaran, bukan pembuat draft.
    Route::post('budgets/{budget}/close', [FinanceBudgetController::class, 'close'])
        ->middleware('permission:finance.budget.approve')->name('budgets.close');
    Route::post('budgets/{budget}/cancel', [FinanceBudgetController::class, 'cancel'])
        ->middleware('permission:finance.budget.approve')->name('budgets.cancel');

    // ---------------- Jenis tagihan (§10) -----------------------------
    Route::get('fee-types', [FinanceFeeTypeController::class, 'index'])
        ->middleware('permission:finance.fee.view')->name('fee-types.index');
    Route::post('fee-types', [FinanceFeeTypeController::class, 'store'])
        ->middleware('permission:finance.fee.manage')->name('fee-types.store');
    Route::get('fee-types/{feeType}', [FinanceFeeTypeController::class, 'show'])
        ->middleware('permission:finance.fee.view')->name('fee-types.show');
    Route::match(['put', 'patch'], 'fee-types/{feeType}', [FinanceFeeTypeController::class, 'update'])
        ->middleware('permission:finance.fee.manage')->name('fee-types.update');
    Route::delete('fee-types/{feeType}', [FinanceFeeTypeController::class, 'destroy'])
        ->middleware('permission:finance.fee.manage')->name('fee-types.destroy');

    // ---------------- Paket tagihan (§11, §12) ------------------------
    Route::get('fee-packages', [FinanceFeePackageController::class, 'index'])
        ->middleware('permission:finance.fee.view')->name('fee-packages.index');
    Route::post('fee-packages', [FinanceFeePackageController::class, 'store'])
        ->middleware('permission:finance.fee.manage')->name('fee-packages.store');
    Route::get('fee-packages/{feePackage}', [FinanceFeePackageController::class, 'show'])
        ->middleware('permission:finance.fee.view')->name('fee-packages.show');
    Route::match(['put', 'patch'], 'fee-packages/{feePackage}', [FinanceFeePackageController::class, 'update'])
        ->middleware('permission:finance.fee.manage')->name('fee-packages.update');
    Route::delete('fee-packages/{feePackage}', [FinanceFeePackageController::class, 'destroy'])
        ->middleware('permission:finance.fee.manage')->name('fee-packages.destroy');

    // ---------------- Kas (§39) + saldo awal (§50) --------------------
    Route::get('cash-accounts', [FinanceCashAccountController::class, 'index'])
        ->middleware('permission:finance.cash.view')->name('cash-accounts.index');
    Route::post('cash-accounts', [FinanceCashAccountController::class, 'store'])
        ->middleware('permission:finance.cash.manage')->name('cash-accounts.store');
    Route::get('cash-accounts/{cashAccount}', [FinanceCashAccountController::class, 'show'])
        ->middleware('permission:finance.cash.view')->name('cash-accounts.show');
    Route::match(['put', 'patch'], 'cash-accounts/{cashAccount}', [FinanceCashAccountController::class, 'update'])
        ->middleware('permission:finance.cash.manage')->name('cash-accounts.update');
    Route::delete('cash-accounts/{cashAccount}', [FinanceCashAccountController::class, 'destroy'])
        ->middleware('permission:finance.cash.manage')->name('cash-accounts.destroy');
    Route::post('cash-accounts/{cashAccount}/opening-balance', [FinanceCashAccountController::class, 'openingBalance'])
        ->middleware('permission:finance.cash.manage')->name('cash-accounts.opening-balance');

    // ---------------- Sesi kasir (§22, §23) ---------------------------
    Route::get('cashier-sessions', [FinanceCashierSessionController::class, 'index'])
        ->middleware('permission:finance.cashier.view')->name('cashier-sessions.index');
    Route::post('cashier-sessions', [FinanceCashierSessionController::class, 'store'])
        ->middleware('permission:finance.cashier.manage')->name('cashier-sessions.store');
    Route::get('cashier-sessions/{cashierSession}', [FinanceCashierSessionController::class, 'show'])
        ->middleware('permission:finance.cashier.view')->name('cashier-sessions.show');
    Route::post('cashier-sessions/{cashierSession}/close', [FinanceCashierSessionController::class, 'close'])
        ->middleware('permission:finance.cashier.manage')->name('cashier-sessions.close');
    Route::post('cashier-sessions/{cashierSession}/review', [FinanceCashierSessionController::class, 'review'])
        ->middleware('permission:finance.cashier.review')->name('cashier-sessions.review');

    // ---------------- Bank (§40) + saldo awal (§50) -------------------
    Route::get('bank-accounts', [FinanceBankAccountController::class, 'index'])
        ->middleware('permission:finance.bank.view')->name('bank-accounts.index');
    Route::post('bank-accounts', [FinanceBankAccountController::class, 'store'])
        ->middleware('permission:finance.bank.manage')->name('bank-accounts.store');
    Route::get('bank-accounts/{bankAccount}', [FinanceBankAccountController::class, 'show'])
        ->middleware('permission:finance.bank.view')->name('bank-accounts.show');
    Route::match(['put', 'patch'], 'bank-accounts/{bankAccount}', [FinanceBankAccountController::class, 'update'])
        ->middleware('permission:finance.bank.manage')->name('bank-accounts.update');
    Route::delete('bank-accounts/{bankAccount}', [FinanceBankAccountController::class, 'destroy'])
        ->middleware('permission:finance.bank.manage')->name('bank-accounts.destroy');
    Route::post('bank-accounts/{bankAccount}/opening-balance', [FinanceBankAccountController::class, 'openingBalance'])
        ->middleware('permission:finance.bank.manage')->name('bank-accounts.opening-balance');

    // ---------------- Vendor (§34) ------------------------------------
    Route::get('vendors', [FinanceVendorController::class, 'index'])
        ->middleware('permission:finance.vendor.view')->name('vendors.index');
    Route::post('vendors', [FinanceVendorController::class, 'store'])
        ->middleware('permission:finance.vendor.manage')->name('vendors.store');
    Route::get('vendors/{vendor}', [FinanceVendorController::class, 'show'])
        ->middleware('permission:finance.vendor.view')->name('vendors.show');
    Route::match(['put', 'patch'], 'vendors/{vendor}', [FinanceVendorController::class, 'update'])
        ->middleware('permission:finance.vendor.manage')->name('vendors.update');
    Route::delete('vendors/{vendor}', [FinanceVendorController::class, 'destroy'])
        ->middleware('permission:finance.vendor.manage')->name('vendors.destroy');

    // ---------------- Diskon (§17) ------------------------------------
    Route::get('discounts', [FinanceDiscountController::class, 'index'])
        ->middleware('permission:finance.fee.view')->name('discounts.index');
    Route::post('discounts', [FinanceDiscountController::class, 'store'])
        ->middleware('permission:finance.fee.manage')->name('discounts.store');
    Route::get('discounts/{discount}', [FinanceDiscountController::class, 'show'])
        ->middleware('permission:finance.fee.view')->name('discounts.show');
    Route::match(['put', 'patch'], 'discounts/{discount}', [FinanceDiscountController::class, 'update'])
        ->middleware('permission:finance.fee.manage')->name('discounts.update');
    Route::delete('discounts/{discount}', [FinanceDiscountController::class, 'destroy'])
        ->middleware('permission:finance.fee.manage')->name('discounts.destroy');

    // ---------------- Beasiswa (§18) ----------------------------------
    Route::get('scholarships', [FinanceScholarshipController::class, 'index'])
        ->middleware('permission:finance.fee.view')->name('scholarships.index');
    Route::post('scholarships', [FinanceScholarshipController::class, 'store'])
        ->middleware('permission:finance.fee.manage')->name('scholarships.store');
    Route::get('scholarships/{scholarship}', [FinanceScholarshipController::class, 'show'])
        ->middleware('permission:finance.fee.view')->name('scholarships.show');
    Route::match(['put', 'patch'], 'scholarships/{scholarship}', [FinanceScholarshipController::class, 'update'])
        ->middleware('permission:finance.fee.manage')->name('scholarships.update');
    Route::post('scholarships/{scholarship}/approve', [FinanceScholarshipController::class, 'approve'])
        ->middleware('permission:finance.fee.manage')->name('scholarships.approve');
    Route::post('scholarships/{scholarship}/reject', [FinanceScholarshipController::class, 'reject'])
        ->middleware('permission:finance.fee.manage')->name('scholarships.reject');
    Route::post('scholarships/{scholarship}/cancel', [FinanceScholarshipController::class, 'cancel'])
        ->middleware('permission:finance.fee.manage')->name('scholarships.cancel');
    Route::delete('scholarships/{scholarship}', [FinanceScholarshipController::class, 'destroy'])
        ->middleware('permission:finance.fee.manage')->name('scholarships.destroy');

    // ---------------- Billing (§13–§15) + generate (§14) --------------
    Route::post('billings/generate', [FinanceBillingController::class, 'generate'])
        ->middleware('permission:finance.billing.create')->name('billings.generate');
    Route::post('billings/other/preview', [FinanceBillingController::class, 'otherPreview'])
        ->middleware('permission:finance.billing.view')->name('billings.other.preview');
    Route::post('billings/other/generate', [FinanceBillingController::class, 'otherGenerate'])
        ->middleware('permission:finance.billing.create')->name('billings.other.generate');
    Route::get('billing-students', [FinanceBillingController::class, 'studentOptions'])
        ->middleware('permission:finance.billing.create')->name('billing-students');
    Route::get('billings', [FinanceBillingController::class, 'index'])
        ->middleware('permission:finance.billing.view')->name('billings.index');
    Route::post('billings', [FinanceBillingController::class, 'store'])
        ->middleware('permission:finance.billing.create')->name('billings.store');
    Route::get('billings/{billing}', [FinanceBillingController::class, 'show'])
        ->middleware('permission:finance.billing.view')->name('billings.show');
    Route::post('billings/{billing}/invoice', [FinanceBillingController::class, 'invoice'])
        ->middleware('permission:finance.invoice.create')->name('billings.invoice');
    Route::post('billings/{billing}/cancel', [FinanceBillingController::class, 'cancel'])
        ->middleware('permission:finance.billing.cancel')->name('billings.cancel');

    // ---------------- Invoice (§16) — baca-saja -----------------------
    Route::get('invoices', [FinanceInvoiceController::class, 'index'])
        ->middleware('permission:finance.invoice.view')->name('invoices.index');
    Route::get('invoices/{invoice}', [FinanceInvoiceController::class, 'show'])
        ->middleware('permission:finance.invoice.view')->name('invoices.show');

    // Write-off piutang (§32–§34): permintaan dari sisi invoice, otomatis
    // diajukan ke finance-manager. Hak terpisah dari adjustment umum karena
    // menghapus tagihan adalah keputusan penagihan, bukan pencatatan rutin.
    Route::post('invoices/{invoice}/write-off', [FinanceAdjustmentController::class, 'writeOff'])
        ->middleware('permission:finance.write_off.request')->name('invoices.write-off');

    // ---------------- Daftar Ulang (§2–§7, §38 Phase 2) ----------------
    // Preview/generate = otoritas generate; complete/cancel/reopen = manage
    // (keputusan penyelesaian + aktivasi akademik, bukan pencatatan rutin).
    Route::post('re-registration/preview', [FinanceReRegistrationController::class, 'preview'])
        ->middleware('permission:finance.re_registration.generate')->name('re-registration.preview');
    Route::post('re-registration/generate', [FinanceReRegistrationController::class, 'generate'])
        ->middleware('permission:finance.re_registration.generate')->name('re-registration.generate');
    Route::get('re-registration', [FinanceReRegistrationController::class, 'index'])
        ->middleware('permission:finance.re_registration.view')->name('re-registration.index');
    Route::get('re-registration/{reRegistration}', [FinanceReRegistrationController::class, 'show'])
        ->middleware('permission:finance.re_registration.view')->name('re-registration.show');
    Route::post('re-registration/{reRegistration}/complete', [FinanceReRegistrationController::class, 'complete'])
        ->middleware('permission:finance.re_registration.manage')->name('re-registration.complete');
    Route::post('re-registration/{reRegistration}/cancel', [FinanceReRegistrationController::class, 'cancel'])
        ->middleware('permission:finance.re_registration.manage')->name('re-registration.cancel');
    Route::post('re-registration/{reRegistration}/reopen', [FinanceReRegistrationController::class, 'reopen'])
        ->middleware('permission:finance.re_registration.manage')->name('re-registration.reopen');

    // ---------------- Pembayaran (§19–§22) + gateway (§20) -------------
    Route::get('payments', [FinancePaymentController::class, 'index'])
        ->middleware('permission:finance.payment.view')->name('payments.index');
    Route::post('payments', [FinancePaymentController::class, 'store'])
        ->middleware('permission:finance.payment.create')->name('payments.store');
    Route::get('payments/{payment}', [FinancePaymentController::class, 'show'])
        ->middleware('permission:finance.payment.view')->name('payments.show');
    Route::post('payments/{payment}/verify', [FinancePaymentController::class, 'verify'])
        ->middleware('permission:finance.payment.verify')->name('payments.verify');
    Route::post('payments/{payment}/reject', [FinancePaymentController::class, 'reject'])
        ->middleware('permission:finance.payment.verify')->name('payments.reject');
    Route::post('payments/{payment}/cancel', [FinancePaymentController::class, 'cancel'])
        ->middleware('permission:finance.payment.verify')->name('payments.cancel');
    Route::post('payments/{payment}/charge', [FinancePaymentController::class, 'charge'])
        ->middleware('permission:finance.payment.create')->name('payments.charge');
    Route::post('payments/{payment}/refunds', [FinanceRefundController::class, 'store'])
        ->middleware('permission:finance.payment.refund')->name('payments.refunds.store');

    // ---------------- Kuitansi (§24, §25) — batal = void, bukan hapus --
    Route::get('receipts', [FinanceReceiptController::class, 'index'])
        ->middleware('permission:finance.receipt.view')->name('receipts.index');
    Route::get('receipts/{receipt}', [FinanceReceiptController::class, 'show'])
        ->middleware('permission:finance.receipt.view')->name('receipts.show');
    Route::post('receipts/{receipt}/cancel', [FinanceReceiptController::class, 'cancel'])
        ->middleware('permission:finance.receipt.cancel')->name('receipts.cancel');

    // ---------------- Refund (§29) ------------------------------------
    Route::get('refunds', [FinanceRefundController::class, 'index'])
        ->middleware('permission:finance.payment.view')->name('refunds.index');
    Route::get('refunds/{refund}', [FinanceRefundController::class, 'show'])
        ->middleware('permission:finance.payment.view')->name('refunds.show');
    Route::post('refunds/{refund}/approve', [FinanceRefundController::class, 'approve'])
        ->middleware('permission:finance.payment.refund')->name('refunds.approve');
    Route::post('refunds/{refund}/reject', [FinanceRefundController::class, 'reject'])
        ->middleware('permission:finance.payment.refund')->name('refunds.reject');
    Route::post('refunds/{refund}/complete', [FinanceRefundController::class, 'complete'])
        ->middleware('permission:finance.payment.refund')->name('refunds.complete');

    // ---------------- Rencana pembayaran / cicilan (§7) ---------------
    // Hanya menyusun ulang JADWAL pelunasan sebuah invoice. Tidak ada rute di
    // sini yang menerima nominal terbayar atau mengubah status piutang: membayar
    // angsuran tetap lewat POST payments, sehingga uang, alokasi, dan jurnal
    // hanya punya satu pintu (dan §7 tetap berlaku: plan tidak menciptakan
    // revenue). `cancel` memakai hak sendiri karena membatalkan kesepakatan
    // penagihan bukan keputusan pencatat harian.
    Route::get('payment-plans', [FinancePaymentPlanController::class, 'index'])
        ->middleware('permission:finance.payment.plan.view')->name('payment-plans.index');
    Route::post('payment-plans', [FinancePaymentPlanController::class, 'store'])
        ->middleware('permission:finance.payment.plan.manage')->name('payment-plans.store');
    Route::get('payment-plans/{paymentPlan}', [FinancePaymentPlanController::class, 'show'])
        ->middleware('permission:finance.payment.plan.view')->name('payment-plans.show');
    Route::post('payment-plans/{paymentPlan}/cancel', [FinancePaymentPlanController::class, 'cancel'])
        ->middleware('permission:finance.payment.plan.cancel')->name('payment-plans.cancel');

    // ---------------- Pengeluaran (§30–§33): pengajuan → approval → bayar --
    Route::get('expense-requests', [FinanceExpenseRequestController::class, 'index'])
        ->middleware('permission:finance.expense.view')->name('expense-requests.index');
    Route::post('expense-requests', [FinanceExpenseRequestController::class, 'store'])
        ->middleware('permission:finance.expense.create')->name('expense-requests.store');
    Route::get('expense-requests/{expenseRequest}', [FinanceExpenseRequestController::class, 'show'])
        ->middleware('permission:finance.expense.view')->name('expense-requests.show');
    Route::post('expense-requests/{expenseRequest}/submit', [FinanceExpenseRequestController::class, 'submit'])
        ->middleware('permission:finance.expense.create')->name('expense-requests.submit');
    Route::post('expense-requests/{expenseRequest}/approve', [FinanceExpenseRequestController::class, 'approve'])
        ->middleware('permission:finance.expense.approve')->name('expense-requests.approve');
    Route::post('expense-requests/{expenseRequest}/reject', [FinanceExpenseRequestController::class, 'reject'])
        ->middleware('permission:finance.expense.reject')->name('expense-requests.reject');
    Route::post('expense-requests/{expenseRequest}/cancel', [FinanceExpenseRequestController::class, 'cancel'])
        ->middleware('permission:finance.expense.create')->name('expense-requests.cancel');
    Route::post('expense-requests/{expenseRequest}/pay', [FinanceExpenseRequestController::class, 'pay'])
        ->middleware('permission:finance.expense.pay')->name('expense-requests.pay');

    // Expense terealisasi (§30) — baca-saja.
    Route::get('expenses', [FinanceExpenseController::class, 'index'])
        ->middleware('permission:finance.expense.view')->name('expenses.index');
    Route::get('expenses/{expense}', [FinanceExpenseController::class, 'show'])
        ->middleware('permission:finance.expense.view')->name('expenses.show');

    // ---------------- Invoice vendor + AP (§35, §36) -------------------
    // Bayar invoice vendor = uang keluar, pakai otoritas `expense.pay`;
    // buat/terima/batalkan = `vendor.manage` (pemisahan pencatat vs pembayar §3).
    Route::get('vendor-invoices', [FinanceVendorInvoiceController::class, 'index'])
        ->middleware('permission:finance.vendor.view')->name('vendor-invoices.index');
    Route::post('vendor-invoices', [FinanceVendorInvoiceController::class, 'store'])
        ->middleware('permission:finance.vendor.manage')->name('vendor-invoices.store');
    Route::get('vendor-invoices/{vendorInvoice}', [FinanceVendorInvoiceController::class, 'show'])
        ->middleware('permission:finance.vendor.view')->name('vendor-invoices.show');
    Route::post('vendor-invoices/{vendorInvoice}/receive', [FinanceVendorInvoiceController::class, 'receive'])
        ->middleware('permission:finance.vendor.manage')->name('vendor-invoices.receive');
    Route::post('vendor-invoices/{vendorInvoice}/pay', [FinanceVendorInvoiceController::class, 'pay'])
        ->middleware('permission:finance.expense.pay')->name('vendor-invoices.pay');
    Route::post('vendor-invoices/{vendorInvoice}/cancel', [FinanceVendorInvoiceController::class, 'cancel'])
        ->middleware('permission:finance.vendor.manage')->name('vendor-invoices.cancel');

    // ---------------- Purchase request (§37) ---------------------------
    Route::get('purchase-requests', [FinancePurchaseRequestController::class, 'index'])
        ->middleware('permission:finance.purchase.view')->name('purchase-requests.index');
    Route::post('purchase-requests', [FinancePurchaseRequestController::class, 'store'])
        ->middleware('permission:finance.purchase.create')->name('purchase-requests.store');
    Route::get('purchase-requests/{purchaseRequest}', [FinancePurchaseRequestController::class, 'show'])
        ->middleware('permission:finance.purchase.view')->name('purchase-requests.show');
    Route::post('purchase-requests/{purchaseRequest}/submit', [FinancePurchaseRequestController::class, 'submit'])
        ->middleware('permission:finance.purchase.create')->name('purchase-requests.submit');
    Route::post('purchase-requests/{purchaseRequest}/approve', [FinancePurchaseRequestController::class, 'approve'])
        ->middleware('permission:finance.purchase.approve')->name('purchase-requests.approve');
    Route::post('purchase-requests/{purchaseRequest}/reject', [FinancePurchaseRequestController::class, 'reject'])
        ->middleware('permission:finance.purchase.approve')->name('purchase-requests.reject');
    Route::post('purchase-requests/{purchaseRequest}/cancel', [FinancePurchaseRequestController::class, 'cancel'])
        ->middleware('permission:finance.purchase.create')->name('purchase-requests.cancel');

    // ---------------- Purchase order (§38) -----------------------------
    Route::get('purchase-orders', [FinancePurchaseOrderController::class, 'index'])
        ->middleware('permission:finance.purchase.view')->name('purchase-orders.index');
    Route::post('purchase-orders', [FinancePurchaseOrderController::class, 'store'])
        ->middleware('permission:finance.purchase.create')->name('purchase-orders.store');
    Route::get('purchase-orders/{purchaseOrder}', [FinancePurchaseOrderController::class, 'show'])
        ->middleware('permission:finance.purchase.view')->name('purchase-orders.show');
    Route::post('purchase-orders/{purchaseOrder}/submit', [FinancePurchaseOrderController::class, 'submit'])
        ->middleware('permission:finance.purchase.create')->name('purchase-orders.submit');
    Route::post('purchase-orders/{purchaseOrder}/approve', [FinancePurchaseOrderController::class, 'approve'])
        ->middleware('permission:finance.purchase.approve')->name('purchase-orders.approve');
    Route::post('purchase-orders/{purchaseOrder}/order', [FinancePurchaseOrderController::class, 'order'])
        ->middleware('permission:finance.purchase.create')->name('purchase-orders.order');
    Route::post('purchase-orders/{purchaseOrder}/receive', [FinancePurchaseOrderController::class, 'receive'])
        ->middleware('permission:finance.purchase.create')->name('purchase-orders.receive');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [FinancePurchaseOrderController::class, 'cancel'])
        ->middleware('permission:finance.purchase.create')->name('purchase-orders.cancel');

    // ---------------- Transaksi kas (§2) ------------------------------
    // Tanpa update/delete: koreksi kas dicatat sebagai transaksi lawan, bukan
    // dengan mengedit dokumen yang sudah punya jurnal posted (§51).
    Route::get('cash-transactions', [FinanceCashTransactionController::class, 'index'])
        ->middleware('permission:finance.cash.view')->name('cash-transactions.index');
    Route::post('cash-transactions', [FinanceCashTransactionController::class, 'store'])
        ->middleware('permission:finance.cash.manage')->name('cash-transactions.store');
    Route::get('cash-transactions/{cashTransaction}', [FinanceCashTransactionController::class, 'show'])
        ->middleware('permission:finance.cash.view')->name('cash-transactions.show');

    // ---------------- Transaksi bank (§41) ----------------------------
    Route::get('bank-transactions', [FinanceBankTransactionController::class, 'index'])
        ->middleware('permission:finance.bank.view')->name('bank-transactions.index');
    Route::post('bank-transactions', [FinanceBankTransactionController::class, 'store'])
        ->middleware('permission:finance.bank.manage')->name('bank-transactions.store');
    Route::get('bank-transactions/{bankTransaction}', [FinanceBankTransactionController::class, 'show'])
        ->middleware('permission:finance.bank.view')->name('bank-transactions.show');

    // ---------------- Rekening koran bank + pencocokan (§4) -------------
    // Import hanya menyimpan bukti dari sisi bank: tidak ada jalur di sini yang
    // membuat jurnal. Pencocokan menautkan baris ke baris jurnal yang sudah ada,
    // dan `reconcile` menutup keduanya hanya bila selisih Book vs Bank nol —
    // kalau tidak, hasilnya discrepancy yang harus lewat adjustment (§51).
    Route::get('bank-statements', [FinanceBankStatementController::class, 'index'])
        ->middleware('permission:finance.bank.view')->name('bank-statements.index');
    Route::post('bank-statements', [FinanceBankStatementController::class, 'store'])
        ->middleware('permission:finance.bank.manage')->name('bank-statements.store');
    Route::get('bank-statements/{bankStatement}', [FinanceBankStatementController::class, 'show'])
        ->middleware('permission:finance.bank.view')->name('bank-statements.show');
    Route::get('bank-statements/{bankStatement}/summary', [FinanceBankStatementController::class, 'summary'])
        ->middleware('permission:finance.bank.view')->name('bank-statements.summary');
    Route::get('bank-statements/{bankStatement}/unclaimed', [FinanceBankStatementController::class, 'unclaimed'])
        ->middleware('permission:finance.bank.view')->name('bank-statements.unclaimed');
    Route::post('bank-statements/{bankStatement}/auto-match', [FinanceBankStatementController::class, 'autoMatch'])
        ->middleware('permission:finance.bank.manage')->name('bank-statements.auto-match');
    Route::post('bank-statements/{bankStatement}/lines', [FinanceBankStatementController::class, 'storeLine'])
        ->middleware('permission:finance.bank.manage')->name('bank-statements.lines.store');
    Route::post('bank-statements/{bankStatement}/reconcile', [FinanceBankStatementController::class, 'reconcile'])
        ->middleware('permission:finance.reconciliation.manage')->name('bank-statements.reconcile');
    Route::post('bank-statements/{bankStatement}/cancel', [FinanceBankStatementController::class, 'cancel'])
        ->middleware('permission:finance.bank.manage')->name('bank-statements.cancel');
    Route::match(['put', 'patch'], 'bank-statement-lines/{statementLine}', [FinanceBankStatementController::class, 'updateLine'])
        ->middleware('permission:finance.bank.manage')->name('bank-statement-lines.update');
    Route::delete('bank-statement-lines/{statementLine}', [FinanceBankStatementController::class, 'destroyLine'])
        ->middleware('permission:finance.bank.manage')->name('bank-statement-lines.destroy');
    Route::get('bank-statement-lines/{statementLine}/candidates', [FinanceBankStatementController::class, 'candidates'])
        ->middleware('permission:finance.bank.view')->name('bank-statement-lines.candidates');
    Route::post('bank-statement-lines/{statementLine}/match', [FinanceBankStatementController::class, 'match'])
        ->middleware('permission:finance.bank.manage')->name('bank-statement-lines.match');
    Route::post('bank-statement-lines/{statementLine}/unmatch', [FinanceBankStatementController::class, 'unmatch'])
        ->middleware('permission:finance.bank.manage')->name('bank-statement-lines.unmatch');
    Route::post('bank-statement-lines/{statementLine}/exclude', [FinanceBankStatementController::class, 'exclude'])
        ->middleware('permission:finance.bank.manage')->name('bank-statement-lines.exclude');

    // ---------------- Transfer internal (§42, §43) --------------------
    Route::get('transfers', [FinanceTransferController::class, 'index'])
        ->middleware('permission:finance.transfer.view')->name('transfers.index');
    Route::post('transfers', [FinanceTransferController::class, 'store'])
        ->middleware('permission:finance.transfer.create')->name('transfers.store');
    Route::get('transfers/{transfer}', [FinanceTransferController::class, 'show'])
        ->middleware('permission:finance.transfer.view')->name('transfers.show');
    Route::post('transfers/{transfer}/submit', [FinanceTransferController::class, 'submit'])
        ->middleware('permission:finance.transfer.create')->name('transfers.submit');
    Route::post('transfers/{transfer}/approve', [FinanceTransferController::class, 'approve'])
        ->middleware('permission:finance.transfer.approve')->name('transfers.approve');
    // §16: transfer di atas ambang nominal butuh penyetuju kedua yang berbeda
    // dari pengaju dan penyetuju pertama — karenanya hak ini sengaja jarang.
    Route::post('transfers/{transfer}/secondary-approve', [FinanceTransferController::class, 'secondaryApprove'])
        ->middleware('permission:finance.transfer.approve_secondary')->name('transfers.secondary-approve');
    Route::post('transfers/{transfer}/reject', [FinanceTransferController::class, 'reject'])
        ->middleware('permission:finance.transfer.approve')->name('transfers.reject');
    // Pembatalan boleh dari kedua sisi alur: pencatat menarik apa yang diajukannya,
    // penyetuju membatalkan apa yang sudah disetujuinya — selama uang belum bergerak.
    Route::post('transfers/{transfer}/cancel', [FinanceTransferController::class, 'cancel'])
        ->middleware('permission:finance.transfer.create|finance.transfer.approve')->name('transfers.cancel');
    Route::post('transfers/{transfer}/complete', [FinanceTransferController::class, 'complete'])
        ->middleware('permission:finance.transfer.approve')->name('transfers.complete');

    // ---------------- Rekonsiliasi bank (§44, §45) --------------------
    Route::get('reconciliations', [FinanceReconciliationController::class, 'index'])
        ->middleware('permission:finance.reconciliation.view')->name('reconciliations.index');
    Route::post('reconciliations', [FinanceReconciliationController::class, 'store'])
        ->middleware('permission:finance.reconciliation.manage')->name('reconciliations.store');
    Route::get('reconciliations/{reconciliation}', [FinanceReconciliationController::class, 'show'])
        ->middleware('permission:finance.reconciliation.view')->name('reconciliations.show');
    Route::post('reconciliations/{reconciliation}/items', [FinanceReconciliationController::class, 'addItem'])
        ->middleware('permission:finance.reconciliation.manage')->name('reconciliations.items.store');
    Route::match(['put', 'patch'], 'reconciliations/{reconciliation}/items/{reconciliationItem}', [FinanceReconciliationController::class, 'updateItem'])
        ->middleware('permission:finance.reconciliation.manage')->name('reconciliations.items.update');
    Route::post('reconciliations/{reconciliation}/finalize', [FinanceReconciliationController::class, 'finalize'])
        ->middleware('permission:finance.reconciliation.manage')->name('reconciliations.finalize');

    // ---------------- Adjustment / koreksi (§51) ----------------------
    Route::get('adjustments', [FinanceAdjustmentController::class, 'index'])
        ->middleware('permission:finance.adjustment.view')->name('adjustments.index');
    Route::post('adjustments', [FinanceAdjustmentController::class, 'store'])
        ->middleware('permission:finance.adjustment.create')->name('adjustments.store');
    Route::get('adjustments/{adjustment}', [FinanceAdjustmentController::class, 'show'])
        ->middleware('permission:finance.adjustment.view')->name('adjustments.show');
    Route::post('adjustments/{adjustment}/submit', [FinanceAdjustmentController::class, 'submit'])
        ->middleware('permission:finance.adjustment.create')->name('adjustments.submit');
    Route::post('adjustments/{adjustment}/approve', [FinanceAdjustmentController::class, 'approve'])
        ->middleware('permission:finance.adjustment.approve')->name('adjustments.approve');
    Route::post('adjustments/{adjustment}/reject', [FinanceAdjustmentController::class, 'reject'])
        ->middleware('permission:finance.adjustment.approve')->name('adjustments.reject');
    Route::post('adjustments/{adjustment}/post', [FinanceAdjustmentController::class, 'post'])
        ->middleware('permission:finance.adjustment.approve')->name('adjustments.post');

    // ---------------- Piutang / AR + aging (§26–§28) ------------------
    Route::get('receivables', [FinanceReceivableController::class, 'index'])
        ->middleware('permission:finance.report.view')->name('receivables.index');
    Route::get('receivables/aging', [FinanceReceivableController::class, 'aging'])
        ->middleware('permission:finance.report.view')->name('receivables.aging');
    Route::get('receivables/summary', [FinanceReceivableController::class, 'summary'])
        ->middleware('permission:finance.report.view')->name('receivables.summary');

    // ---------------- Kelayakan finansial (§11) ------------------------
    // Hanya baca: modul akademik/operasional bertanya, Finance menjawab. Tidak
    // ada rute di sini yang mengubah status akademik, nilai, atau kelulusan
    // santri — gerbang di sisi itu ada di modulnya sendiri, yang memanggil
    // `FinancialEligibilityService::assertEligible()` dan karena itu hanya bisa
    // membuat permintaannya gagal, bukan menulis data.
    // Rute statis `rules` didaftarkan sebelum `{student}` supaya tidak tertafsir
    // sebagai ID santri.
    Route::get('eligibility/rules', [FinanceEligibilityController::class, 'rules'])
        ->middleware('permission:finance.eligibility.view')->name('eligibility.rules');
    Route::get('eligibility/{student}', [FinanceEligibilityController::class, 'index'])
        ->whereNumber('student')
        ->middleware('permission:finance.eligibility.view')->name('eligibility.index');
    Route::get('eligibility/{student}/{action}', [FinanceEligibilityController::class, 'show'])
        ->whereNumber('student')
        ->middleware('permission:finance.eligibility.view')->name('eligibility.show');

    // ---------------- Keberesan finansial / clearance (§12) ------------
    // Dokumen keputusan, bukan dokumen uang: tidak satu pun rute di bawah ini
    // menyentuh invoice, pembayaran, atau jurnal — `waive` mengecualikan layanan
    // dari aturan, ia tidak menghapus piutang (jalurnya tetap write-off §21).
    // `check` menjawab tanpa menulis apa pun, jadi modul lain boleh menanyakannya
    // berkali-kali; `approve`/`waive` adalah satu-satunya rute yang membubuhkan
    // status final dan keduanya menolak alasan yang kosong.
    // Rute statis (`config`, `check/...`) didaftarkan sebelum `{clearance}` supaya
    // tidak tertafsir sebagai ID keberesan.
    Route::get('clearances/config', [FinanceClearanceController::class, 'config'])
        ->middleware('permission:finance.clearance.view')->name('clearances.config');
    Route::get('clearances/check/{student}/{purpose}', [FinanceClearanceController::class, 'check'])
        ->whereNumber('student')
        ->middleware('permission:finance.clearance.view')->name('clearances.check');
    Route::get('clearances', [FinanceClearanceController::class, 'index'])
        ->middleware('permission:finance.clearance.view')->name('clearances.index');
    Route::post('clearances', [FinanceClearanceController::class, 'store'])
        ->middleware('permission:finance.clearance.request')->name('clearances.store');
    Route::get('clearances/{clearance}', [FinanceClearanceController::class, 'show'])
        ->whereNumber('clearance')
        ->middleware('permission:finance.clearance.view')->name('clearances.show');
    Route::post('clearances/{clearance}/refresh', [FinanceClearanceController::class, 'refresh'])
        ->whereNumber('clearance')
        ->middleware('permission:finance.clearance.request')->name('clearances.refresh');
    Route::post('clearances/{clearance}/approve', [FinanceClearanceController::class, 'approve'])
        ->whereNumber('clearance')
        ->middleware('permission:finance.clearance.approve')->name('clearances.approve');
    Route::post('clearances/{clearance}/waive', [FinanceClearanceController::class, 'waive'])
        ->whereNumber('clearance')
        ->middleware('permission:finance.clearance.waive')->name('clearances.waive');

    // ---------------- Penagihan / collection (§6) ---------------------
    // Rute statis didaftarkan sebelum `{invoice}` supaya "dashboard" tidak
    // tertafsir sebagai ID invoice. Tidak ada satu pun rute di sini yang bisa
    // mengubah nominal piutang: POST hanya menambah baris sejarah (§6).
    Route::get('collections/dashboard', [FinanceCollectionController::class, 'dashboard'])
        ->middleware('permission:finance.collection.view')->name('collections.dashboard');
    Route::get('collections', [FinanceCollectionController::class, 'index'])
        ->middleware('permission:finance.collection.view')->name('collections.index');
    Route::get('collections/{invoice}/history', [FinanceCollectionController::class, 'history'])
        ->middleware('permission:finance.collection.view')->name('collections.history');
    Route::post('collections/{invoice}/actions', [FinanceCollectionController::class, 'store'])
        ->middleware('permission:finance.collection.manage')->name('collections.actions.store');

    // ---------------- Otomasi & observability (§28, §29) ---------------
    // Membaca register pekerjaan/kegagalan dipisah dari memanggil pekerjaan:
    // auditor dan kepemimpinan membaca saja (§66), sedangkan menjalankan generasi
    // massal atau menekan ulang pekerjaan yang gagal bisa melahirkan dokumen baru.
    // Tidak ada rute untuk mengubah barisnya — register jejak audit tidak boleh
    // bisa disunting (§25), dan angka piutang memang tidak pernah lewat sini.
    Route::get('operations', [FinanceOperationController::class, 'index'])
        ->middleware('permission:finance.operation.view')->name('operations.index');
    Route::get('operations/{operation}', [FinanceOperationController::class, 'show'])
        ->middleware('permission:finance.operation.view')->name('operations.show');
    Route::post('operations', [FinanceOperationController::class, 'store'])
        ->middleware('permission:finance.operation.manage')->name('operations.store');
    Route::post('operations/{operation}/retry', [FinanceOperationController::class, 'retry'])
        ->middleware('permission:finance.operation.manage')->name('operations.retry');

    // ---------------- Hutang / AP + aging (§24–§31) -------------------
    Route::get('payables', [FinancePayableController::class, 'index'])
        ->middleware('permission:finance.report.view')->name('payables.index');
    Route::get('payables/aging', [FinancePayableController::class, 'aging'])
        ->middleware('permission:finance.report.view')->name('payables.aging');
    Route::get('payables/summary', [FinancePayableController::class, 'summary'])
        ->middleware('permission:finance.report.view')->name('payables.summary');

    // ---------------- Jurnal umum + penelusuran (§3–§6, §34, §38, §47) --
    // `/finance/transactions` dan `/finance/journals` membaca himpunan yang
    // sama (finance_transactions = jurnal, §71); yang berbeda hanya haknya:
    // pencatat melihat hasil catatnya, pemosting/auditor melihat jurnalnya.
    // Tidak ada route edit/DELETE: koreksi hanya lewat reverse (§41).
    Route::get('transactions', [FinanceJournalController::class, 'index'])
        ->middleware('permission:finance.transaction.view')->name('transactions.index');
    Route::get('transactions/{transaction}', [FinanceJournalController::class, 'showTransaction'])
        ->middleware('permission:finance.transaction.view')->name('transactions.show');
    Route::get('journals', [FinanceJournalController::class, 'index'])
        ->middleware('permission:finance.journal.view')->name('journals.index');
    Route::get('journals/{journal}', [FinanceJournalController::class, 'show'])
        ->middleware('permission:finance.journal.view')->name('journals.show');
    Route::get('journals/{journal}/trace', [FinanceJournalController::class, 'trace'])
        ->middleware('permission:finance.journal.view')->name('journals.trace');
    Route::post('journals/{journal}/reverse', [FinanceJournalController::class, 'reverse'])
        ->middleware('permission:finance.journal.reverse')->name('journals.reverse');

    // ---------------- Buku besar per akun (§35–§37, §47) --------------
    Route::get('ledger', [FinanceLedgerController::class, 'index'])
        ->middleware('permission:finance.ledger.view')->name('ledger.index');
    Route::get('ledger/{account}', [FinanceLedgerController::class, 'account'])
        ->middleware('permission:finance.ledger.view')->name('ledger.account');
    Route::get('accounts/{account}/balance', [FinanceLedgerController::class, 'balance'])
        ->middleware('permission:finance.account.view')->name('accounts.balance');

    // ---------------- Saldo kas/bank dari buku besar (§1) -------------
    // Cached balance vs saldo yang dihitung ulang dari jurnal berstempel rekening,
    // plus mutasi satu rekening. Sumber angkanya sama dengan laporan `cash_bank`.
    Route::get('subledgers', [FinanceLedgerController::class, 'subledgers'])
        ->middleware('permission:finance.ledger.view')->name('subledgers.index');
    Route::get('subledgers/{type}/{subledger}/movements', [FinanceLedgerController::class, 'subledgerMovements'])
        ->middleware('permission:finance.ledger.view')->whereNumber('subledger')
        ->name('subledgers.movements');

    // ---------------- Dashboard (§67) ---------------------------------
    Route::get('dashboard', [FinanceDashboardController::class, 'index'])
        ->middleware('permission:finance.view')->name('dashboard');

    // ---------------- Laporan keuangan + CSV (§67, §68) ---------------
    // `reports/kinds` harus terdaftar sebelum `reports/{kind}` agar tidak
    // tertangkap sebagai parameter jenis laporan.
    Route::get('reports/kinds', [FinanceReportController::class, 'kinds'])
        ->middleware('permission:finance.report.view')->name('reports.kinds');
    Route::get('reports/{kind}', [FinanceReportController::class, 'show'])
        ->middleware('permission:finance.report.view')->name('reports.show');
    Route::get('reports/{kind}/export', [FinanceReportController::class, 'export'])
        ->middleware('permission:finance.report.export')->name('reports.export');

    // ---------------- Periode & closing (§48, §49) --------------------
    Route::get('periods/summary', [FinancePeriodController::class, 'summary'])
        ->middleware('permission:finance.closing.view')->name('periods.summary');
    Route::post('periods/generate', [FinancePeriodController::class, 'generate'])
        ->middleware('permission:finance.closing.manage')->name('periods.generate');
    Route::get('periods', [FinancePeriodController::class, 'index'])
        ->middleware('permission:finance.closing.view')->name('periods.index');
    Route::get('periods/{period}', [FinancePeriodController::class, 'show'])
        ->middleware('permission:finance.closing.view')->name('periods.show');
    Route::post('periods/{period}/close', [FinancePeriodController::class, 'close'])
        ->middleware('permission:finance.closing.manage')->name('periods.close');
    Route::post('periods/{period}/reopen', [FinancePeriodController::class, 'reopen'])
        ->middleware('permission:finance.closing.manage')->name('periods.reopen');

    // ---------------- Pengaturan modul (§73) --------------------------
    Route::get('settings', [FinanceSettingsController::class, 'show'])
        ->middleware('permission:finance.settings.view')->name('settings.show');
    Route::match(['put', 'patch'], 'settings', [FinanceSettingsController::class, 'update'])
        ->middleware('permission:finance.settings.manage')->name('settings.update');

    // ---------------- Kotak masuk notifikasi (§30–§31) ----------------
    // Sengaja TANPA permission modul: inbox sudah terisolasi per penerima
    // (`notifiable_*`), sehingga wali/santri bisa membaca tagihannya sendiri
    // tanpa diberi hak akses data keuangan orang lain.
    Route::prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/', [FinanceNotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [FinanceNotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [FinanceNotificationController::class, 'markAllAsRead'])->name('read-all');
        Route::post('{notification}/read', [FinanceNotificationController::class, 'markAsRead'])->name('read');
    });

    // ---------------- Portal orang tua (§9, §10) ----------------------
    // Tanpa permission finance: orang tua login lewat alur PPDB yang sudah ada
    // dan tidak memegang permission admin. Keamanannya kepemilikan anak,
    // ditegakkan FinancePortalService::assertChild() di setiap endpoint — pola
    // yang sama dengan portal asrama.
    Route::prefix('portal')->name('portal.')->group(function (): void {
        Route::get('children', [FinancePortalController::class, 'children'])->name('children');
        Route::get('children/{student}/summary', [FinancePortalController::class, 'summary'])->name('children.summary');
        Route::get('children/{student}/invoices', [FinancePortalController::class, 'invoices'])->name('children.invoices');
        Route::get('children/{student}/re-registrations', [FinancePortalController::class, 'reRegistrations'])->name('children.re-registrations');
        Route::get('children/{student}/payments', [FinancePortalController::class, 'payments'])->name('children.payments');
        Route::get('children/{student}/receipts', [FinancePortalController::class, 'receipts'])->name('children.receipts');
        Route::get('children/{student}/receivables', [FinancePortalController::class, 'receivables'])->name('children.receivables');
        Route::get('children/{student}/payment-plans', [FinancePortalController::class, 'paymentPlans'])->name('children.payment-plans');
    });

    // --------------------------------------------------------------------
    // Standar akuntansi: unit bisnis, dana, saldo awal, permohonan, laporan
    // --------------------------------------------------------------------
    Route::get('business-units', [FinanceBusinessUnitController::class, 'index'])
        ->middleware('permission:finance.account.view')->name('business-units.index');
    Route::post('business-units', [FinanceBusinessUnitController::class, 'store'])
        ->middleware('permission:finance.account.manage')->name('business-units.store');
    Route::patch('business-units/{code}', [FinanceBusinessUnitController::class, 'update'])
        ->middleware('permission:finance.account.manage')->name('business-units.update');
    Route::post('business-units/{code}/toggle', [FinanceBusinessUnitController::class, 'toggle'])
        ->middleware('permission:finance.account.manage')->name('business-units.toggle');
    Route::post('business-units/defaults', [FinanceBusinessUnitController::class, 'setDefault'])
        ->middleware('permission:finance.account.manage')->name('business-units.defaults');

    Route::get('funds', [FinanceFundController::class, 'index'])
        ->middleware('permission:finance.account.view')->name('funds.index');
    Route::post('funds', [FinanceFundController::class, 'store'])
        ->middleware('permission:finance.account.manage')->name('funds.store');
    Route::patch('funds/{id}', [FinanceFundController::class, 'update'])
        ->middleware('permission:finance.account.manage')->name('funds.update');
    Route::post('funds/{id}/accounts', [FinanceFundController::class, 'syncAccounts'])
        ->middleware('permission:finance.account.manage')->name('funds.accounts');
    Route::delete('funds/{id}', [FinanceFundController::class, 'destroy'])
        ->middleware('permission:finance.account.manage')->name('funds.destroy');

    Route::get('opening-balances', [FinanceOpeningBalanceController::class, 'index'])
        ->middleware('permission:finance.account.view')->name('opening-balances.index');
    Route::post('opening-balances', [FinanceOpeningBalanceController::class, 'save'])
        ->middleware('permission:finance.account.manage')->name('opening-balances.save');
    Route::delete('opening-balances/{id}', [FinanceOpeningBalanceController::class, 'destroy'])
        ->middleware('permission:finance.account.manage')->name('opening-balances.destroy');
    Route::post('opening-balances/post', [FinanceOpeningBalanceController::class, 'post'])
        ->middleware('permission:finance.account.manage')->name('opening-balances.post');

    Route::get('period-reopen-requests', [FinancePeriodReopenController::class, 'index'])
        ->middleware('permission:finance.closing.view')->name('period-reopen.index');
    Route::post('period-reopen-requests', [FinancePeriodReopenController::class, 'request'])
        ->middleware('permission:finance.closing.manage')->name('period-reopen.request');
    Route::post('period-reopen-requests/{id}/decide', [FinancePeriodReopenController::class, 'decide'])
        ->middleware('permission:finance.closing.approve')->name('period-reopen.decide');
    Route::get('periods/{periodId}/reopen-history', [FinancePeriodReopenController::class, 'history'])
        ->middleware('permission:finance.closing.view')->name('period-reopen.history');

    Route::get('journal-void-requests', [FinanceJournalVoidController::class, 'index'])
        ->middleware('permission:finance.journal.view')->name('journal-void.index');
    Route::post('journal-void-requests', [FinanceJournalVoidController::class, 'request'])
        ->middleware('permission:finance.journal.reverse')->name('journal-void.request');
    Route::post('journal-void-requests/{id}/decide', [FinanceJournalVoidController::class, 'decide'])
        ->middleware('permission:finance.journal.approve')->name('journal-void.decide');

    Route::get('reports/trial-balance', [FinanceStandardReportController::class, 'trialBalance'])
        ->middleware('permission:finance.report.view')->name('reports.trial-balance');
    Route::get('reports/profit-loss-by-unit', [FinanceStandardReportController::class, 'profitLossByUnit'])
        ->middleware('permission:finance.report.view')->name('reports.profit-loss-by-unit');
    Route::get('reports/fund-statement', [FinanceStandardReportController::class, 'fundStatement'])
        ->middleware('permission:finance.report.view')->name('reports.fund-statement');
    Route::get('reports/modules', [FinanceStandardReportController::class, 'modules'])
        ->middleware('permission:finance.report.view')->name('reports.modules');

});
