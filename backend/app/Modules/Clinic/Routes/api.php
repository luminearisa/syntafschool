<?php

use App\Modules\Clinic\Http\Controllers\ClinicCertificateController;
use App\Modules\Clinic\Http\Controllers\ClinicEmergencyController;
use App\Modules\Clinic\Http\Controllers\ClinicMetaController;
use App\Modules\Clinic\Http\Controllers\ClinicPatientController;
use App\Modules\Clinic\Http\Controllers\ClinicPharmacyController;
use App\Modules\Clinic\Http\Controllers\ClinicPortalController;
use App\Modules\Clinic\Http\Controllers\ClinicQueueController;
use App\Modules\Clinic\Http\Controllers\ClinicReferralController;
use App\Modules\Clinic\Http\Controllers\ClinicReportController;
use App\Modules\Clinic\Http\Controllers\ClinicScreeningController;
use App\Modules\Clinic\Http\Controllers\ClinicVisitController;
use Illuminate\Support\Facades\Route;

/*
| Route Modul Clinic / UKS — seluruhnya di bawah `/api/v1/clinic/*`.
|
| Middleware `permission:clinic.*` menentukan *layar/aksi* yang boleh dibuka.
| Cakupan *baris* (siapa boleh melihat rekam medis siapa) ditegakkan terpisah
| di `ClinicAccessService`, sehingga pemegang `clinic.visit.view` yang bukan
| petugas klinik tetap hanya melihat data dalam lingkupnya sendiri.
|
| Tidak ada endpoint yang mengubah saldo stok: stok hanya berubah lewat
| InventoryProviderInterface di dalam alur dispensing.
*/

Route::middleware(['auth:sanctum', 'throttle:clinic-module'])->prefix('clinic')->group(function (): void {

    // ---------------- Konteks, dashboard, kesehatan modul ----------------
    Route::get('me', [ClinicMetaController::class, 'me'])
        ->middleware('permission:clinic.view')->name('clinic.me');
    Route::get('metadata', [ClinicMetaController::class, 'metadata'])
        ->middleware('permission:clinic.view')->name('clinic.metadata');
    Route::get('dashboard', [ClinicMetaController::class, 'dashboard'])
        ->middleware('permission:clinic.view')->name('clinic.dashboard');
    Route::get('health', [ClinicMetaController::class, 'health'])
        ->middleware('permission:clinic.manage')->name('clinic.health');
    Route::post('patients/alerts', [ClinicMetaController::class, 'patientAlerts'])
        ->middleware('throttle:clinic-search')
        ->middleware('permission:clinic.patient.view')->name('clinic.patients.alerts');

    // ---------------- Pasien & rekam medis ----------------
    Route::get('patients', [ClinicPatientController::class, 'index'])
        ->middleware('throttle:clinic-search')
        ->middleware('permission:clinic.patient.view')->name('clinic.patients.index');
    Route::get('patients/search-students', [ClinicPatientController::class, 'searchStudents'])
        ->middleware('throttle:clinic-search')
        ->middleware('permission:clinic.patient.create')->name('clinic.patients.search-students');
    Route::post('patients', [ClinicPatientController::class, 'store'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.patient.create')->name('clinic.patients.store');
    Route::get('patients/{patient}', [ClinicPatientController::class, 'show'])
        ->middleware('permission:clinic.record.view|clinic.patient.view')->name('clinic.patients.show');
    Route::put('patients/{patient}', [ClinicPatientController::class, 'update'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.patient.manage')->name('clinic.patients.update');
    Route::get('patients/{patient}/vitals', [ClinicPatientController::class, 'vitals'])
        ->middleware('permission:clinic.record.view')->name('clinic.patients.vitals');
    Route::get('patients/{patient}/visits', [ClinicPatientController::class, 'visits'])
        ->middleware('permission:clinic.record.view|clinic.visit.view')->name('clinic.patients.visits');
    Route::get('patients/{patient}/operational-notes', [ClinicPatientController::class, 'operationalNotes'])
        ->middleware('permission:clinic.record.view')->name('clinic.patients.operational-notes');

    Route::post('patients/{patient}/allergies', [ClinicPatientController::class, 'storeAllergy'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.record.manage')->name('clinic.patients.allergies.store');
    Route::put('allergies/{allergy}', [ClinicPatientController::class, 'resolveAllergy'])
        ->middleware('permission:clinic.record.manage')->name('clinic.allergies.update');

    Route::post('patients/{patient}/conditions', [ClinicPatientController::class, 'storeCondition'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.record.manage')->name('clinic.patients.conditions.store');
    Route::post('patients/{patient}/histories', [ClinicPatientController::class, 'storeHistory'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.record.manage')->name('clinic.patients.histories.store');

    // ---------------- Kunjungan & alur klinis ----------------
    Route::get('visits', [ClinicVisitController::class, 'index'])
        ->middleware('permission:clinic.visit.view')->name('clinic.visits.index');
    Route::post('visits', [ClinicVisitController::class, 'store'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.visit.create')->name('clinic.visits.store');
    Route::get('visits/{visit}', [ClinicVisitController::class, 'show'])
        ->middleware('permission:clinic.record.view|clinic.visit.view')->name('clinic.visits.show');
    Route::post('visits/{visit}/triage', [ClinicVisitController::class, 'triage'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.visit.triage')->name('clinic.visits.triage');
    Route::post('visits/{visit}/vitals', [ClinicVisitController::class, 'vitals'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.visit.triage|clinic.record.manage')->name('clinic.visits.vitals');
    Route::post('visits/{visit}/assessment', [ClinicVisitController::class, 'assessment'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.assessment.create')->name('clinic.visits.assessment');
    Route::post('visits/{visit}/diagnoses', [ClinicVisitController::class, 'diagnosis'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.diagnosis.create')->name('clinic.visits.diagnoses.store');
    Route::post('visits/{visit}/procedures', [ClinicVisitController::class, 'procedure'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.procedure.create')->name('clinic.visits.procedures.store');
    Route::post('visits/{visit}/observations', [ClinicVisitController::class, 'startObservation'])
        ->middleware('permission:clinic.observation.manage')->name('clinic.visits.observations.store');
    Route::post('visits/{visit}/follow-ups', [ClinicVisitController::class, 'followUp'])
        ->middleware('permission:clinic.visit.manage')->name('clinic.visits.follow-ups.store');
    Route::post('visits/{visit}/discharge', [ClinicVisitController::class, 'discharge'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.visit.manage')->name('clinic.visits.discharge');
    Route::put('visits/{visit}/status', [ClinicVisitController::class, 'updateStatus'])
        ->middleware('permission:clinic.visit.manage')->name('clinic.visits.status');

    Route::put('diagnoses/{diagnosis}', [ClinicVisitController::class, 'amendDiagnosis'])
        ->middleware('permission:clinic.diagnosis.create')->name('clinic.diagnoses.amend');
    Route::put('discharges/{discharge}', [ClinicVisitController::class, 'amendDischarge'])
        ->middleware('permission:clinic.visit.manage')->name('clinic.discharges.amend');

    Route::post('observations/{observation}/records', [ClinicVisitController::class, 'observationRecord'])
        ->middleware('permission:clinic.observation.manage')->name('clinic.observations.records.store');
    Route::put('observations/{observation}/end', [ClinicVisitController::class, 'endObservation'])
        ->middleware('permission:clinic.observation.manage')->name('clinic.observations.end');

    // ---------------- Antrean ----------------
    Route::get('queue', [ClinicQueueController::class, 'index'])
        ->middleware('permission:clinic.visit.view')->name('clinic.queue.index');
    Route::post('queue/call-next', [ClinicQueueController::class, 'callNext'])
        ->middleware('permission:clinic.queue.manage')->name('clinic.queue.call-next');
    Route::put('queue/{visit}/stage', [ClinicQueueController::class, 'move'])
        ->middleware('permission:clinic.queue.manage')->name('clinic.queue.move');

    // ---------------- Farmasi: master obat, batch, resep, dispensing ----------------
    Route::get('medications', [ClinicPharmacyController::class, 'medications'])
        ->middleware('throttle:clinic-search')
        ->middleware('permission:clinic.medication.view')->name('clinic.medications.index');
    Route::post('medications', [ClinicPharmacyController::class, 'storeMedication'])
        ->middleware('permission:clinic.medication.manage')->name('clinic.medications.store');
    Route::put('medications/{medication}', [ClinicPharmacyController::class, 'updateMedication'])
        ->middleware('permission:clinic.medication.manage')->name('clinic.medications.update');
    Route::get('medications/{medication}/batches', [ClinicPharmacyController::class, 'batches'])
        ->middleware('permission:clinic.medication.view')->name('clinic.medications.batches');
    Route::post('medications/{medication}/batches', [ClinicPharmacyController::class, 'storeBatch'])
        ->middleware('permission:clinic.medication.manage')->name('clinic.medications.batches.store');
    Route::post('pharmacy/allergy-check', [ClinicPharmacyController::class, 'allergyCheck'])
        ->middleware('throttle:clinic-search')
        ->middleware('permission:clinic.patient.view')->name('clinic.pharmacy.allergy-check');

    Route::get('prescriptions', [ClinicPharmacyController::class, 'prescriptions'])
        ->middleware('permission:clinic.prescription.view')->name('clinic.prescriptions.index');
    Route::get('prescriptions/pending', [ClinicPharmacyController::class, 'pending'])
        ->middleware('permission:clinic.prescription.view')->name('clinic.prescriptions.pending');
    Route::get('prescriptions/{prescription}', [ClinicPharmacyController::class, 'showPrescription'])
        ->middleware('permission:clinic.prescription.view')->name('clinic.prescriptions.show');
    Route::post('visits/{visit}/prescriptions', [ClinicPharmacyController::class, 'storePrescription'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.prescription.create')->name('clinic.prescriptions.store');
    Route::put('prescriptions/{prescription}/cancel', [ClinicPharmacyController::class, 'cancelPrescription'])
        ->middleware('permission:clinic.prescription.create')->name('clinic.prescriptions.cancel');
    Route::post('prescriptions/{prescription}/dispense', [ClinicPharmacyController::class, 'dispense'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.prescription.dispense')->name('clinic.prescriptions.dispense');

    Route::post('visits/{visit}/administrations', [ClinicPharmacyController::class, 'administer'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.administration.create')->name('clinic.administrations.store');
    Route::put('administrations/{administration}', [ClinicPharmacyController::class, 'correctAdministration'])
        ->middleware('permission:clinic.administration.create')->name('clinic.administrations.correct');

    // ---------------- Gawat darurat & cedera ----------------
    Route::get('emergencies', [ClinicEmergencyController::class, 'index'])
        ->middleware('permission:clinic.emergency.view')->name('clinic.emergencies.index');
    Route::post('emergencies', [ClinicEmergencyController::class, 'store'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.emergency.manage')->name('clinic.emergencies.store');
    Route::get('emergencies/{emergency}', [ClinicEmergencyController::class, 'show'])
        ->middleware('permission:clinic.emergency.view')->name('clinic.emergencies.show');
    Route::put('emergencies/{emergency}', [ClinicEmergencyController::class, 'update'])
        ->middleware('permission:clinic.emergency.manage')->name('clinic.emergencies.update');
    Route::put('emergencies/{emergency}/close', [ClinicEmergencyController::class, 'close'])
        ->middleware('permission:clinic.emergency.manage')->name('clinic.emergencies.close');

    Route::get('injuries', [ClinicEmergencyController::class, 'injuries'])
        ->middleware('permission:clinic.injury.view')->name('clinic.injuries.index');
    Route::post('injuries', [ClinicEmergencyController::class, 'storeInjury'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.injury.manage')->name('clinic.injuries.store');
    Route::put('injuries/{injury}', [ClinicEmergencyController::class, 'updateInjury'])
        ->middleware('permission:clinic.injury.manage')->name('clinic.injuries.update');
    Route::put('injuries/{injury}/close', [ClinicEmergencyController::class, 'closeInjury'])
        ->middleware('permission:clinic.injury.manage')->name('clinic.injuries.close');

    // ---------------- Rujukan & tindak lanjut ----------------
    Route::get('referrals', [ClinicReferralController::class, 'index'])
        ->middleware('permission:clinic.referral.view')->name('clinic.referrals.index');
    Route::post('visits/{visit}/referrals', [ClinicReferralController::class, 'store'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.referral.manage')->name('clinic.referrals.store');
    Route::get('referrals/{referral}', [ClinicReferralController::class, 'show'])
        ->middleware('permission:clinic.referral.view')->name('clinic.referrals.show');
    Route::put('referrals/{referral}', [ClinicReferralController::class, 'update'])
        ->middleware('permission:clinic.referral.manage')->name('clinic.referrals.update');

    Route::get('follow-ups', [ClinicReferralController::class, 'followUps'])
        ->middleware('permission:clinic.visit.view')->name('clinic.follow-ups.index');
    Route::put('follow-ups/{followUp}/complete', [ClinicReferralController::class, 'completeFollowUp'])
        ->middleware('permission:clinic.visit.manage')->name('clinic.follow-ups.complete');

    // ---------------- Skrining & imunisasi ----------------
    Route::get('screening-programs', [ClinicScreeningController::class, 'programs'])
        ->middleware('permission:clinic.screening.view')->name('clinic.screening-programs.index');
    Route::post('screening-programs', [ClinicScreeningController::class, 'storeProgram'])
        ->middleware('permission:clinic.screening.manage')->name('clinic.screening-programs.store');
    Route::get('screening-programs/{program}/targets', [ClinicScreeningController::class, 'targets'])
        ->middleware('throttle:clinic-search')
        ->middleware('permission:clinic.screening.view')->name('clinic.screening-programs.targets');
    Route::get('screenings', [ClinicScreeningController::class, 'index'])
        ->middleware('permission:clinic.screening.view')->name('clinic.screenings.index');
    Route::post('screening-programs/{program}/screenings', [ClinicScreeningController::class, 'store'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.screening.manage')->name('clinic.screenings.store');
    Route::get('vaccinations', [ClinicScreeningController::class, 'vaccinations'])
        ->middleware('permission:clinic.screening.view')->name('clinic.vaccinations.index');
    Route::post('vaccinations', [ClinicScreeningController::class, 'storeVaccination'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.vaccination.manage')->name('clinic.vaccinations.store');

    // ---------------- Surat keterangan & dokumen ----------------
    Route::get('certificates', [ClinicCertificateController::class, 'index'])
        ->middleware('permission:clinic.certificate.view')->name('clinic.certificates.index');
    Route::post('certificates', [ClinicCertificateController::class, 'store'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.certificate.request')->name('clinic.certificates.store');
    Route::get('certificates/{certificate}', [ClinicCertificateController::class, 'show'])
        ->middleware('permission:clinic.certificate.view')->name('clinic.certificates.show');
    Route::put('certificates/{certificate}/review', [ClinicCertificateController::class, 'review'])
        ->middleware('permission:clinic.certificate.issue')->name('clinic.certificates.review');
    Route::put('certificates/{certificate}/issue', [ClinicCertificateController::class, 'issue'])
        ->middleware('permission:clinic.certificate.issue')->name('clinic.certificates.issue');

    Route::post('documents', [ClinicCertificateController::class, 'storeDocument'])
        ->middleware('throttle:clinic-write')
        ->middleware('permission:clinic.document.manage')->name('clinic.documents.store');
    Route::get('patients/{patient}/documents', [ClinicCertificateController::class, 'documents'])
        ->middleware('permission:clinic.document.view')->name('clinic.documents.index');
    Route::get('documents/{document}/download', [ClinicCertificateController::class, 'download'])
        ->middleware('throttle:clinic-download')
        ->middleware('permission:clinic.document.view')->name('clinic.documents.download');

    // ---------------- Laporan ----------------
    Route::prefix('reports')->middleware('throttle:clinic-report')->group(function (): void {
        Route::get('overview', [ClinicReportController::class, 'overview'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.overview');
        Route::get('daily', [ClinicReportController::class, 'daily'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.daily');
        Route::get('medications', [ClinicReportController::class, 'medications'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.medications');
        Route::get('referrals', [ClinicReportController::class, 'referrals'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.referrals');
        Route::get('emergencies', [ClinicReportController::class, 'emergencies'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.emergencies');
        Route::get('screenings', [ClinicReportController::class, 'screenings'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.screenings');
        Route::get('follow-ups', [ClinicReportController::class, 'followUps'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.follow-ups');
        Route::get('staff-activity', [ClinicReportController::class, 'staffActivity'])
            ->middleware('permission:clinic.report.view')->name('clinic.reports.staff-activity');
        Route::get('access-log', [ClinicReportController::class, 'accessLog'])
            ->middleware('permission:clinic.audit.view')->name('clinic.reports.access-log');
        Route::get('export/{report}', [ClinicReportController::class, 'export'])
            ->middleware('permission:clinic.report.export')->name('clinic.reports.export');
    });

    // ---------------- Portal orang tua & siswa ----------------
    Route::prefix('portal')->middleware('permission:clinic.portal.view')->group(function (): void {
        Route::get('children', [ClinicPortalController::class, 'children'])->name('clinic.portal.children');
        Route::get('patients/{patient}', [ClinicPortalController::class, 'child'])->name('clinic.portal.patient');
        Route::get('patients/{patient}/visits', [ClinicPortalController::class, 'visits'])->name('clinic.portal.visits');
        Route::get('patients/{patient}/certificates', [ClinicPortalController::class, 'certificates'])->name('clinic.portal.certificates');
        Route::get('patients/{patient}/documents', [ClinicPortalController::class, 'documents'])->name('clinic.portal.documents');
    });
});
