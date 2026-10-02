<?php

use App\Modules\Hrd\Http\Controllers\HrdDataQualityController;
use App\Modules\Hrd\Http\Controllers\HrdDocumentController;
use App\Modules\Hrd\Http\Controllers\HrdEmployeeController;
use App\Modules\Hrd\Http\Controllers\HrdEmploymentController;
use App\Modules\Hrd\Http\Controllers\HrdLifecycleController;
use App\Modules\Hrd\Http\Controllers\HrdMetaController;
use App\Modules\Hrd\Http\Controllers\HrdOrganizationController;
use App\Modules\Hrd\Http\Controllers\HrdPositionController;
use App\Modules\Hrd\Http\Controllers\HrdReportController;
use App\Modules\Hrd\Http\Controllers\HrdSelfServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route Modul HRD — seluruhnya di bawah `/api/v1/hrd/*`
|--------------------------------------------------------------------------
|
| Dua lapis otorisasi, dan keduanya diperlukan:
|
|  1. `permission:hr.*` menyaring **layar/aksi** yang boleh dibuka (middleware di
|     sini). Salah izin terlihat lebih awal dan jejaknya lebih rapi.
|  2. `HrdAccessService` + Policy menyaring **baris dan kolom** yang boleh dibaca
|     (di dalam layanan). Ini yang menahan HR unit Pesantren melihat data unit
|     lain, dan yang menyembunyikan NIK dari pemanggil tanpa izin sensitif.
|  3. `throttle` menahan pengambilan data massal (ekspor, impor, pindai) sehingga
|     modul ini tidak dapat dipakai untuk mengunduh seluruh basis pegawai.
|
*/

Route::middleware(['auth:sanctum'])->prefix('hrd')->group(function (): void {

    // ── Konteks, metadata, dasbor ────────────────────────────────────────
    Route::get('me', [HrdMetaController::class, 'me'])->name('hrd.me');
    Route::get('metadata', [HrdMetaController::class, 'metadata'])->middleware('permission:hr.employee.view')->name('hrd.metadata');
    Route::get('references/{group}', [HrdMetaController::class, 'referenceItems'])->middleware('permission:hr.employee.view')->name('hrd.references.items');
    Route::get('dashboard', [HrdMetaController::class, 'dashboard'])->middleware('permission:hr.dashboard.view')->name('hrd.dashboard');
    Route::get('dashboard/alerts', [HrdMetaController::class, 'alerts'])->middleware('permission:hr.dashboard.view')->name('hrd.dashboard.alerts');
    Route::get('health', [HrdMetaController::class, 'health'])->middleware('permission:hr.dashboard.view')->name('hrd.health');
    Route::get('calendar', [HrdMetaController::class, 'calendar'])->middleware('permission:hr.employee.view')->name('hrd.calendar');
    Route::get('notifications', [HrdMetaController::class, 'notifications'])->name('hrd.notifications');
    Route::post('notifications/{notification}/read', [HrdMetaController::class, 'markNotificationRead'])->name('hrd.notifications.read');
    Route::post('ask', [HrdMetaController::class, 'ask'])->middleware('permission:hr.employee.view')->name('hrd.ask');
    Route::get('audit', [HrdMetaController::class, 'audit'])->middleware('permission:hr.audit.view')->name('hrd.audit.index');
    Route::get('settings', [HrdMetaController::class, 'settings'])->middleware('permission:hr.settings.view')->name('hrd.settings.index');
    Route::patch('settings', [HrdMetaController::class, 'updateSettings'])->middleware('permission:hr.settings.manage')->name('hrd.settings.update');
    Route::post('references/{group}/items', [HrdMetaController::class, 'upsertReferenceItem'])->middleware('permission:hr.settings.manage')->name('hrd.references.upsert');
    Route::delete('references/{group}/items/{code}', [HrdMetaController::class, 'deactivateReferenceItem'])->middleware('permission:hr.settings.manage')->name('hrd.references.deactivate');

    // ── Pegawai ──────────────────────────────────────────────────────────
    Route::get('employees', [HrdEmployeeController::class, 'index'])->middleware('permission:hr.employee.view')->name('hrd.employees.index');
    Route::post('employees', [HrdEmployeeController::class, 'store'])->middleware('permission:hr.employee.create', 'throttle:hrd-write')->name('hrd.employees.store');
    Route::get('employees/{employee}', [HrdEmployeeController::class, 'show'])->middleware('permission:hr.employee.view')->name('hrd.employees.show');
    Route::patch('employees/{employee}', [HrdEmployeeController::class, 'update'])->middleware('permission:hr.employee.update', 'throttle:hrd-write')->name('hrd.employees.update');
    Route::get('employees/{employee}/profile', [HrdEmployeeController::class, 'profile'])->middleware('permission:hr.employee.view')->name('hrd.employees.profile');
    Route::get('employees/{employee}/summary', [HrdEmployeeController::class, 'summary'])->middleware('permission:hr.employee.view')->name('hrd.employees.summary');
    Route::get('employees/{employee}/timeline', [HrdEmployeeController::class, 'timeline'])->middleware('permission:hr.employee.view')->name('hrd.employees.timeline');
    Route::get('employees/{employee}/history', [HrdEmployeeController::class, 'history'])->middleware('permission:hr.employee.view')->name('hrd.employees.history');
    Route::post('employees/{employee}/activate', [HrdEmployeeController::class, 'activate'])->middleware('permission:hr.employee.update', 'throttle:hrd-write')->name('hrd.employees.activate');
    Route::post('employees/{employee}/deactivate', [HrdEmployeeController::class, 'deactivate'])->middleware('permission:hr.employee.update', 'throttle:hrd-write')->name('hrd.employees.deactivate');
    Route::post('employees/{employee}/status', [HrdEmployeeController::class, 'changeStatus'])->middleware('permission:hr.employee.update', 'throttle:hrd-write')->name('hrd.employees.status');
    Route::post('employees/{employee}/archive', [HrdEmployeeController::class, 'archive'])->middleware('permission:hr.employee.archive', 'throttle:hrd-write')->name('hrd.employees.archive');
    Route::post('employees/{employee}/restore', [HrdEmployeeController::class, 'restore'])->middleware('permission:hr.employee.archive', 'throttle:hrd-write')->name('hrd.employees.restore');
    Route::post('employees/{employee}/account', [HrdEmployeeController::class, 'linkAccount'])->middleware('permission:hr.employee.update')->name('hrd.employees.account');
    Route::delete('employees/{employee}/account', [HrdEmployeeController::class, 'unlinkAccount'])->middleware('permission:hr.employee.update')->name('hrd.employees.account.destroy');
    Route::post('employees/merge/preview', [HrdEmployeeController::class, 'mergePreview'])->middleware('permission:hr.employee.merge')->name('hrd.employees.merge.preview');
    Route::post('employees/merge', [HrdEmployeeController::class, 'mergeApply'])->middleware('permission:hr.employee.merge', 'throttle:hrd-write')->name('hrd.employees.merge');
    Route::post('employees/bulk', [HrdEmployeeController::class, 'bulk'])->middleware('permission:hr.employee.update', 'throttle:hrd-write')->name('hrd.employees.bulk');

    // Profil pegawai (tabel anak)
    Route::post('employees/{employee}/profile/{collection}', [HrdEmployeeController::class, 'storeProfileItem'])->middleware('permission:hr.employee.update')->name('hrd.employees.profile.store');
    Route::patch('employees/{employee}/profile/{collection}/{record}', [HrdEmployeeController::class, 'updateProfileItem'])->middleware('permission:hr.employee.update')->name('hrd.employees.profile.update');
    Route::delete('employees/{employee}/profile/{collection}/{record}', [HrdEmployeeController::class, 'destroyProfileItem'])->middleware('permission:hr.employee.update')->name('hrd.employees.profile.destroy');
    Route::get('employees/{employee}/notes', [HrdEmployeeController::class, 'notes'])->middleware('permission:hr.employee.view')->name('hrd.employees.notes.index');
    Route::post('employees/{employee}/notes', [HrdEmployeeController::class, 'addNote'])->middleware('permission:hr.note.create')->name('hrd.employees.notes.store');

    // Impor massal (unggah → validasi → konfirmasi)
    Route::post('employees/import', [HrdEmployeeController::class, 'importUpload'])->middleware('permission:hr.employee.import', 'throttle:hrd-module')->name('hrd.employees.import.upload');
    Route::get('employees/import/template', [HrdEmployeeController::class, 'importTemplate'])->middleware('permission:hr.employee.import')->name('hrd.employees.import.template');
    Route::post('employees/import/{import}/validate', [HrdEmployeeController::class, 'importValidate'])->middleware('permission:hr.employee.import')->name('hrd.employees.import.validate');
    Route::post('employees/import/{import}/confirm', [HrdEmployeeController::class, 'importConfirm'])->middleware('permission:hr.employee.import', 'throttle:hrd-module')->name('hrd.employees.import.confirm');

    // ── Organisasi, departemen, lokasi ───────────────────────────────────
    Route::get('organizations', [HrdOrganizationController::class, 'organizations'])->middleware('permission:hr.organization.view')->name('hrd.organizations.index');
    Route::post('organizations', [HrdOrganizationController::class, 'storeOrganization'])->middleware('permission:hr.organization.manage')->name('hrd.organizations.store');
    Route::get('organizations/tree', [HrdOrganizationController::class, 'tree'])->middleware('permission:hr.organization.view')->name('hrd.organizations.tree');
    Route::get('organizations/org-chart', [HrdOrganizationController::class, 'orgChart'])->middleware('permission:hr.organization.view')->name('hrd.organizations.chart');
    Route::get('organizations/{organization}', [HrdOrganizationController::class, 'showOrganization'])->middleware('permission:hr.organization.view')->name('hrd.organizations.show');
    Route::patch('organizations/{organization}', [HrdOrganizationController::class, 'updateOrganization'])->middleware('permission:hr.organization.manage')->name('hrd.organizations.update');
    Route::post('organizations/{organization}/status', [HrdOrganizationController::class, 'setOrganizationStatus'])->middleware('permission:hr.organization.manage')->name('hrd.organizations.status');
    Route::get('departments', [HrdOrganizationController::class, 'departments'])->middleware('permission:hr.organization.view')->name('hrd.departments.index');
    Route::post('departments', [HrdOrganizationController::class, 'storeDepartment'])->middleware('permission:hr.organization.manage')->name('hrd.departments.store');
    Route::patch('departments/{department}', [HrdOrganizationController::class, 'updateDepartment'])->middleware('permission:hr.organization.manage')->name('hrd.departments.update');
    Route::get('locations', [HrdOrganizationController::class, 'locations'])->middleware('permission:hr.organization.view')->name('hrd.locations.index');
    Route::post('locations', [HrdOrganizationController::class, 'storeLocation'])->middleware('permission:hr.organization.manage')->name('hrd.locations.store');
    Route::patch('locations/{location}', [HrdOrganizationController::class, 'updateLocation'])->middleware('permission:hr.organization.manage')->name('hrd.locations.update');

    // ── Jabatan & posisi ────────────────────────────────────────────────
    Route::get('jobs', [HrdPositionController::class, 'jobs'])->middleware('permission:hr.position.view')->name('hrd.jobs.index');
    Route::post('jobs', [HrdPositionController::class, 'storeJob'])->middleware('permission:hr.position.manage')->name('hrd.jobs.store');
    Route::patch('jobs/{job}', [HrdPositionController::class, 'updateJob'])->middleware('permission:hr.position.manage')->name('hrd.jobs.update');
    Route::get('job-catalog', [HrdPositionController::class, 'jobCatalog'])->middleware('permission:hr.position.view')->name('hrd.jobs.catalog');
    Route::get('positions', [HrdPositionController::class, 'index'])->middleware('permission:hr.position.view')->name('hrd.positions.index');
    Route::post('positions', [HrdPositionController::class, 'store'])->middleware('permission:hr.position.manage')->name('hrd.positions.store');
    Route::get('positions/vacancies', [HrdPositionController::class, 'vacancies'])->middleware('permission:hr.position.view')->name('hrd.positions.vacancies');
    Route::get('positions/headcount', [HrdPositionController::class, 'headcount'])->middleware('permission:hr.position.view')->name('hrd.positions.headcount');
    Route::get('positions/{position}', [HrdPositionController::class, 'show'])->middleware('permission:hr.position.view')->name('hrd.positions.show');
    Route::patch('positions/{position}', [HrdPositionController::class, 'update'])->middleware('permission:hr.position.manage')->name('hrd.positions.update');
    Route::post('positions/{position}/freeze', [HrdPositionController::class, 'freeze'])->middleware('permission:hr.position.manage')->name('hrd.positions.freeze');
    Route::post('positions/{position}/abolish', [HrdPositionController::class, 'abolish'])->middleware('permission:hr.position.manage')->name('hrd.positions.abolish');
    Route::get('positions/{position}/holders', [HrdPositionController::class, 'holders'])->middleware('permission:hr.position.view')->name('hrd.positions.holders');
    Route::get('positions/{position}/history', [HrdPositionController::class, 'history'])->middleware('permission:hr.position.view')->name('hrd.positions.history');

    // ── Kepegawaian, penugasan, kontrak ─────────────────────────────────
    Route::get('employment/records', [HrdEmploymentController::class, 'records'])->middleware('permission:hr.employment.view')->name('hrd.employment.records');
    Route::post('employment/records', [HrdEmploymentController::class, 'storeRecord'])->middleware('permission:hr.employment.manage')->name('hrd.employment.records.store');
    Route::get('employment/probation', [HrdEmploymentController::class, 'probations'])->middleware('permission:hr.employment.view')->name('hrd.employment.probation');
    Route::post('employment/probation', [HrdEmploymentController::class, 'storeProbation'])->middleware('permission:hr.employment.manage')->name('hrd.employment.probation.store');
    Route::get('employment/assignments', [HrdEmploymentController::class, 'assignments'])->middleware('permission:hr.employment.view')->name('hrd.employment.assignments');
    Route::post('employment/assignments', [HrdEmploymentController::class, 'storeAssignment'])->middleware('permission:hr.employment.manage')->name('hrd.employment.assignments.store');
    Route::post('employment/assignments/{assignment}/end', [HrdEmploymentController::class, 'endAssignment'])->middleware('permission:hr.employment.manage')->name('hrd.employment.assignments.end');
    Route::post('employment/managers', [HrdEmploymentController::class, 'setManager'])->middleware('permission:hr.employment.manage')->name('hrd.employment.managers.store');
    Route::get('employment/position-holders', [HrdEmploymentController::class, 'positionHolders'])->middleware('permission:hr.employment.view')->name('hrd.employment.position-holders');
    Route::get('employment/department-head', [HrdEmploymentController::class, 'departmentHeadOn'])->middleware('permission:hr.employment.view')->name('hrd.employment.department-head');
    Route::get('contracts', [HrdEmploymentController::class, 'contracts'])->middleware('permission:hr.contract.view')->name('hrd.contracts.index');
    Route::post('contracts', [HrdEmploymentController::class, 'storeContract'])->middleware('permission:hr.contract.manage')->name('hrd.contracts.store');
    Route::get('contracts/expiring', [HrdEmploymentController::class, 'expiringContracts'])->middleware('permission:hr.contract.view')->name('hrd.contracts.expiring');

    // ── Dokumen pegawai ────────────────────────────────────────────────
    Route::get('documents', [HrdDocumentController::class, 'index'])->middleware('permission:hr.document.view')->name('hrd.documents.index');
    Route::post('documents', [HrdDocumentController::class, 'store'])->middleware('permission:hr.document.upload', 'throttle:hrd-module')->name('hrd.documents.store');
    Route::get('documents/expiring', [HrdDocumentController::class, 'expiring'])->middleware('permission:hr.document.view')->name('hrd.documents.expiring');
    Route::get('documents/{document}', [HrdDocumentController::class, 'show'])->middleware('permission:hr.document.view')->name('hrd.documents.show');
    Route::post('documents/{document}/versions', [HrdDocumentController::class, 'storeVersion'])->middleware('permission:hr.document.upload', 'throttle:hrd-module')->name('hrd.documents.versions.store');
    Route::post('documents/{document}/verify', [HrdDocumentController::class, 'verify'])->middleware('permission:hr.document.verify')->name('hrd.documents.verify');
    Route::post('documents/{document}/revoke', [HrdDocumentController::class, 'revoke'])->middleware('permission:hr.document.verify')->name('hrd.documents.revoke');
    Route::get('documents/{document}/download', [HrdDocumentController::class, 'download'])->middleware('permission:hr.document.view', 'throttle:hrd-module')->name('hrd.documents.download');
    Route::get('document-types', [HrdDocumentController::class, 'types'])->middleware('permission:hr.document.view')->name('hrd.documents.types');

    // ── Kompetensi: keterampilan, sertifikasi, pendidikan ──────────────
    Route::get('skills', [HrdEmploymentController::class, 'skills'])->middleware('permission:hr.skill.view')->name('hrd.skills.index');
    Route::post('employees/{employee}/skills', [HrdEmploymentController::class, 'storeSkill'])->middleware('permission:hr.skill.manage')->name('hrd.skills.store');
    Route::post('skills/{skill}/verify', [HrdEmploymentController::class, 'verifySkill'])->middleware('permission:hr.skill.verify')->name('hrd.skills.verify');
    Route::get('skills/gap/{employee}', [HrdEmploymentController::class, 'skillGap'])->middleware('permission:hr.skill.view')->name('hrd.skills.gap');
    Route::get('skills/holders/{code}', [HrdEmploymentController::class, 'skillHolders'])->middleware('permission:hr.skill.view')->name('hrd.skills.holders');
    Route::get('certifications', [HrdEmploymentController::class, 'certifications'])->middleware('permission:hr.certification.view')->name('hrd.certifications.index');
    Route::post('employees/{employee}/certifications', [HrdEmploymentController::class, 'storeCertification'])->middleware('permission:hr.certification.manage')->name('hrd.certifications.store');
    Route::post('certifications/{certification}/verify', [HrdEmploymentController::class, 'verifyCertification'])->middleware('permission:hr.certification.verify')->name('hrd.certifications.verify');
    Route::get('certifications/expiring', [HrdEmploymentController::class, 'expiringCertifications'])->middleware('permission:hr.certification.view')->name('hrd.certifications.expiring');
    Route::post('employees/{employee}/educations', [HrdEmploymentController::class, 'storeEducation'])->middleware('permission:hr.employee.update')->name('hrd.educations.store');
    Route::post('educations/{education}/verify', [HrdEmploymentController::class, 'verifyEducation'])->middleware('permission:hr.document.verify')->name('hrd.educations.verify');

    // ── Transaksi siklus hidup ─────────────────────────────────────────
    Route::get('transaction-types', [HrdLifecycleController::class, 'types'])->middleware('permission:hr.lifecycle.view')->name('hrd.transactions.types');
    Route::get('transactions', [HrdLifecycleController::class, 'index'])->middleware('permission:hr.lifecycle.view')->name('hrd.transactions.index');
    Route::post('transactions', [HrdLifecycleController::class, 'store'])->middleware('permission:hr.lifecycle.create', 'throttle:hrd-write')->name('hrd.transactions.store');
    Route::get('transactions/{transaction}', [HrdLifecycleController::class, 'show'])->middleware('permission:hr.lifecycle.view')->name('hrd.transactions.show');
    Route::post('transactions/{transaction}/submit', [HrdLifecycleController::class, 'submit'])->middleware('permission:hr.lifecycle.create')->name('hrd.transactions.submit');
    Route::post('transactions/{transaction}/review', [HrdLifecycleController::class, 'review'])->middleware('permission:hr.lifecycle.approve')->name('hrd.transactions.review');
    Route::post('transactions/{transaction}/approve', [HrdLifecycleController::class, 'approve'])->middleware('permission:hr.lifecycle.approve')->name('hrd.transactions.approve');
    Route::post('transactions/{transaction}/reject', [HrdLifecycleController::class, 'reject'])->middleware('permission:hr.lifecycle.approve')->name('hrd.transactions.reject');
    Route::post('transactions/{transaction}/cancel', [HrdLifecycleController::class, 'cancel'])->middleware('permission:hr.lifecycle.create')->name('hrd.transactions.cancel');
    Route::post('transactions/{transaction}/apply', [HrdLifecycleController::class, 'apply'])->middleware('permission:hr.lifecycle.execute', 'throttle:hrd-write')->name('hrd.transactions.apply');

    // ── Onboarding & offboarding ───────────────────────────────────────
    Route::get('onboarding', [HrdLifecycleController::class, 'onboardingIndex'])->middleware('permission:hr.onboarding.view')->name('hrd.onboarding.index');
    Route::post('onboarding', [HrdLifecycleController::class, 'onboardingStart'])->middleware('permission:hr.onboarding.manage')->name('hrd.onboarding.store');
    Route::get('onboarding/{process}', [HrdLifecycleController::class, 'onboardingShow'])->middleware('permission:hr.onboarding.view')->name('hrd.onboarding.show');
    Route::post('onboarding/tasks/{task}/complete', [HrdLifecycleController::class, 'onboardingTaskComplete'])->middleware('permission:hr.onboarding.manage')->name('hrd.onboarding.tasks.complete');
    Route::get('onboarding-templates', [HrdLifecycleController::class, 'onboardingTemplates'])->middleware('permission:hr.onboarding.view')->name('hrd.onboarding.templates');
    Route::get('offboarding', [HrdLifecycleController::class, 'offboardingIndex'])->middleware('permission:hr.offboarding.view')->name('hrd.offboarding.index');
    Route::post('offboarding', [HrdLifecycleController::class, 'offboardingStart'])->middleware('permission:hr.offboarding.manage')->name('hrd.offboarding.store');
    Route::get('offboarding/{process}', [HrdLifecycleController::class, 'offboardingShow'])->middleware('permission:hr.offboarding.view')->name('hrd.offboarding.show');
    Route::post('offboarding/tasks/{task}/complete', [HrdLifecycleController::class, 'offboardingTaskComplete'])->middleware('permission:hr.offboarding.manage')->name('hrd.offboarding.tasks.complete');
    Route::post('offboarding/tasks/{task}/clearance', [HrdLifecycleController::class, 'offboardingClearance'])->middleware('permission:hr.offboarding.clearance')->name('hrd.offboarding.tasks.clearance');
    Route::post('offboarding/{process}/complete', [HrdLifecycleController::class, 'offboardingComplete'])->middleware('permission:hr.offboarding.manage')->name('hrd.offboarding.complete');

    // ── Permintaan HR ──────────────────────────────────────────────────
    Route::get('request-types', [HrdLifecycleController::class, 'requestTypes'])->middleware('permission:hr.request.view')->name('hrd.requests.types');
    Route::get('requests', [HrdLifecycleController::class, 'requestsIndex'])->middleware('permission:hr.request.view')->name('hrd.requests.index');
    Route::post('requests', [HrdLifecycleController::class, 'requestsStore'])->middleware('permission:hr.request.create', 'throttle:hrd-write')->name('hrd.requests.store');
    Route::get('requests/pending', [HrdLifecycleController::class, 'requestsPending'])->middleware('permission:hr.request.approve')->name('hrd.requests.pending');
    Route::get('requests/{hrRequest}', [HrdLifecycleController::class, 'requestsShow'])->middleware('permission:hr.request.view')->name('hrd.requests.show');
    Route::post('requests/{hrRequest}/approve', [HrdLifecycleController::class, 'requestsApprove'])->middleware('permission:hr.request.approve')->name('hrd.requests.approve');
    Route::post('requests/{hrRequest}/reject', [HrdLifecycleController::class, 'requestsReject'])->middleware('permission:hr.request.approve')->name('hrd.requests.reject');
    Route::post('requests/{hrRequest}/apply', [HrdLifecycleController::class, 'requestsApply'])->middleware('permission:hr.request.manage')->name('hrd.requests.apply');

    // ── Laporan & ekspor ───────────────────────────────────────────────
    Route::get('reports', [HrdReportController::class, 'catalog'])->middleware('permission:hr.report.view')->name('hrd.reports.catalog');
    Route::get('reports/{report}', [HrdReportController::class, 'show'])->middleware('permission:hr.report.view')->name('hrd.reports.show');
    Route::get('reports/{report}/export', [HrdReportController::class, 'export'])->middleware('permission:hr.report.export', 'throttle:hrd-module')->name('hrd.reports.export');
    Route::get('directory', [HrdReportController::class, 'directory'])->middleware('permission:hr.employee.view')->name('hrd.directory');

    // ── Kualitas data & validasi ───────────────────────────────────────
    Route::get('data-quality', [HrdDataQualityController::class, 'overview'])->middleware('permission:hr.data_quality.view')->name('hrd.data-quality.overview');
    Route::get('data-quality/issues', [HrdDataQualityController::class, 'issues'])->middleware('permission:hr.data_quality.view')->name('hrd.data-quality.issues');
    Route::post('data-quality/scan', [HrdDataQualityController::class, 'scan'])->middleware('permission:hr.data_quality.scan', 'throttle:hrd-module')->name('hrd.data-quality.scan');
    Route::post('data-quality/issues/{issue}/resolve', [HrdDataQualityController::class, 'resolve'])->middleware('permission:hr.data_quality.manage')->name('hrd.data-quality.resolve');
    Route::get('validation-rules', [HrdDataQualityController::class, 'rules'])->middleware('permission:hr.data_quality.view')->name('hrd.validation-rules.index');
    Route::patch('validation-rules/{rule}', [HrdDataQualityController::class, 'updateRule'])->middleware('permission:hr.settings.manage')->name('hrd.validation-rules.update');

    // ── Layanan mandiri (pegawai & atasan) ─────────────────────────────
    Route::get('self/profile', [HrdSelfServiceController::class, 'profile'])->middleware('permission:hr.self.view')->name('hrd.self.profile');
    Route::get('self/employment', [HrdSelfServiceController::class, 'employment'])->middleware('permission:hr.self.view')->name('hrd.self.employment');
    Route::get('self/documents', [HrdSelfServiceController::class, 'documents'])->middleware('permission:hr.self.view')->name('hrd.self.documents');
    Route::get('self/timeline', [HrdSelfServiceController::class, 'timeline'])->middleware('permission:hr.self.view')->name('hrd.self.timeline');
    Route::get('self/requests', [HrdSelfServiceController::class, 'requests'])->middleware('permission:hr.self.view')->name('hrd.self.requests');
    Route::post('self/requests', [HrdSelfServiceController::class, 'submitRequest'])->middleware('permission:hr.self.view', 'throttle:hrd-write')->name('hrd.self.requests.store');
    Route::post('self/profile-update', [HrdSelfServiceController::class, 'requestProfileChange'])->middleware('permission:hr.self.view', 'throttle:hrd-write')->name('hrd.self.profile-update');
    Route::get('self/checklist', [HrdSelfServiceController::class, 'checklist'])->middleware('permission:hr.self.view')->name('hrd.self.checklist');
    Route::get('team', [HrdSelfServiceController::class, 'team'])->middleware('permission:hr.team.view')->name('hrd.team.index');
    Route::get('team/org-chart', [HrdSelfServiceController::class, 'teamChart'])->middleware('permission:hr.team.view')->name('hrd.team.chart');
    Route::get('team/timeline', [HrdSelfServiceController::class, 'teamTimeline'])->middleware('permission:hr.team.view')->name('hrd.team.timeline');
    Route::get('team/alerts', [HrdSelfServiceController::class, 'teamAlerts'])->middleware('permission:hr.team.view')->name('hrd.team.alerts');
});
