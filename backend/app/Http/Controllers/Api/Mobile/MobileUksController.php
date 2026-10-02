<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Student;
use App\Modules\UKS\Application\Services\LetterService;
use App\Modules\UKS\Application\Services\VisitService;
use App\Modules\UKS\Models\UksConsent;
use App\Modules\UKS\Models\UksHealthProfile;
use App\Modules\UKS\Models\UksImmunization;
use App\Modules\UKS\Models\UksIncident;
use App\Modules\UKS\Models\UksLetter;
use App\Modules\UKS\Models\UksScreening;
use App\Modules\UKS\Models\UksVisit;
use App\Services\Mobile\ContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UKS untuk wali murid (anak sendiri) dan siswa (diri sendiri).
 *
 * Modul UKS mencatat banyak data internal petugas (catatan internal, nama
 * petugas, saksi kejadian = siswa lain, stok obat). Endpoint web mengembalikan
 * baris mentah, jadi untuk aplikasi mobile dipakai proyeksi terkurasi di sini
 * dan endpoint mentah `uks/*` tetap tidak masuk daftar jalur yang diizinkan.
 * Persetujuan tetap diputuskan VisitService (aturan & notifikasi yang sama).
 */
class MobileUksController extends BaseApiController
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly VisitService $visits,
        private readonly LetterService $letters,
    ) {}

    public function overview(Request $request, Student $student): JsonResponse
    {
        $id = $this->assertOwn($request, (int) $student->id);

        $profile = UksHealthProfile::query()->where('student_id', $id)->first();

        $visits = UksVisit::query()
            ->with(['medications.medicine:id,name', 'consents', 'referral', 'letters:id,visit_id'])
            ->where('student_id', $id)->orderByDesc('arrived_at')->limit(20)->get();

        return $this->successResponse([
            'profile' => $profile === null ? null : [
                'blood_type' => $profile->blood_type,
                'allergies' => $profile->allergies,
                'chronic_conditions' => $profile->chronic_conditions,
                'regular_medications' => $profile->regular_medications,
                'special_needs' => $profile->special_needs,
                'emergency_contact_name' => $profile->emergency_contact_name,
                'emergency_contact_phone' => $profile->emergency_contact_phone,
                'emergency_contact_relation' => $profile->emergency_contact_relation,
            ],
            'visits' => $visits->map(fn (UksVisit $v) => [
                'id' => $v->id,
                'code' => $v->code,
                'arrived_at' => $v->arrived_at?->toIso8601String(),
                'left_at' => $v->left_at?->toIso8601String(),
                'visit_type' => $v->visit_type->label(),
                'complaint' => $v->complaint,
                'severity' => $v->severity->value,
                'severity_label' => $v->severity->label(),
                'status' => $v->status->value,
                'status_label' => $v->status->label(),
                'treatment' => $v->treatment,
                'advice' => $v->advice,
                'needs_rest' => (bool) $v->needs_rest,
                'sent_home' => (bool) $v->sent_home,
                'medications' => $v->medications->map(fn ($m) => [
                    'name' => $m->medicine?->name,
                    'quantity' => $m->quantity,
                    'dosage' => $m->dosage,
                ])->values(),
                'referral' => $v->referral === null ? null : [
                    'facility_name' => $v->referral->facility_name,
                    'reason' => $v->referral->reason,
                    'status' => $v->referral->status->value,
                    'status_label' => $v->referral->status->label(),
                ],
                'consent' => $v->consents->sortByDesc('decided_at')->first()?->decision->value,
                // Persetujuan diminta hanya untuk kunjungan yang dirujuk dan belum diputuskan.
                'needs_consent' => $v->referral !== null && $v->consents->isEmpty(),
                'letters' => $v->letters->pluck('id')->values(),
            ])->values(),
            'screenings' => UksScreening::query()->where('student_id', $id)->orderByDesc('screened_at')->limit(20)->get()
                ->map(fn (UksScreening $s) => [
                    'type' => $s->screening_type->value ?? $s->screening_type,
                    'screened_at' => $s->screened_at?->toDateString(),
                    'height_cm' => $s->height_cm,
                    'weight_kg' => $s->weight_kg,
                    'bmi' => $s->bmi,
                    'vision_left' => $s->vision_left,
                    'vision_right' => $s->vision_right,
                    'dental_status' => $s->dental_status,
                    'result' => $s->result->value ?? $s->result,
                    'findings' => $s->findings,
                    'recommendation' => $s->recommendation,
                ])->values(),
            'immunizations' => UksImmunization::query()->where('student_id', $id)->orderByDesc('given_at')->limit(30)->get()
                ->map(fn (UksImmunization $i) => [
                    'vaccine_name' => $i->vaccine_name,
                    'dose_number' => $i->dose_number,
                    'given_at' => $i->given_at?->toDateString(),
                    'next_due_at' => $i->next_due_at?->toDateString(),
                ])->values(),
            'incidents' => UksIncident::query()->where('student_id', $id)->orderByDesc('occurred_at')->limit(10)->get()
                ->map(fn (UksIncident $i) => [
                    'code' => $i->code,
                    'occurred_at' => $i->occurred_at?->toIso8601String(),
                    'location' => $i->location,
                    'severity' => $i->severity->value ?? $i->severity,
                    'description' => $i->description,
                    'first_aid' => $i->first_aid,
                    'status' => $i->status->value ?? $i->status,
                ])->values(),
            'letters' => UksLetter::query()->where('student_id', $id)->orderByDesc('date_from')->limit(20)->get()
                ->map(fn (UksLetter $l) => [
                    'id' => $l->id,
                    'number' => $l->number,
                    'letter_type' => $l->letter_type->label(),
                    'date_from' => $l->date_from?->toDateString(),
                    'date_to' => $l->date_to?->toDateString(),
                ])->values(),
        ], 'Kesehatan sekolah (UKS) berhasil diambil');
    }

    /** Wali memutuskan tindakan atas anaknya (rujukan). Siswa tidak dapat memberi persetujuan. */
    public function consent(Request $request, UksVisit $visit): JsonResponse
    {
        abort_unless($request->attributes->get('mobile_context') === ContextService::GUARDIAN, 403, 'Persetujuan hanya dapat diberikan oleh wali murid.');
        $this->assertOwn($request, (int) $visit->student_id);

        $data = $request->validate([
            'decision' => ['required', 'in:DISETUJUI,DITOLAK'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $visit->loadMissing(['referral', 'consents']);
        if ($visit->referral === null || $visit->consents->isNotEmpty()) {
            throw ValidationException::withMessages(['visit' => 'Kunjungan ini tidak sedang meminta persetujuan.']);
        }

        // Saluran & identitas wali ditentukan server (bukan dari klien).
        $consent = $this->visits->consent($visit, ['decision' => $data['decision'], 'note' => $data['note'] ?? null, 'channel' => 'APLIKASI'], $request->user());

        return $this->createdResponse($consent instanceof UksConsent ? ['decision' => $consent->decision->value] : null, 'Persetujuan dicatat');
    }

    public function letterPdf(Request $request, UksLetter $letter): StreamedResponse
    {
        $this->assertOwn($request, (int) $letter->student_id);

        $pdf = $this->letters->pdf($request->user(), $letter);

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf['binary'];
            },
            preg_replace('/[^A-Za-z0-9._-]+/', '-', $pdf['filename']) ?: 'surat-uks.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** Kepemilikan: bukan anak/diri akun ini → 404 (tidak membocorkan keberadaan id). */
    private function assertOwn(Request $request, int $studentId): int
    {
        $ids = $request->attributes->get('mobile_context') === ContextService::STUDENT
            ? array_filter([$this->contexts->studentId($request->user())])
            : $this->contexts->childStudentIds($request->user());

        abort_unless(in_array($studentId, $ids, true), 404, 'Data tidak ditemukan pada akun Anda.');

        return $studentId;
    }
}
