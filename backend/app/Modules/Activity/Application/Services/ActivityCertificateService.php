<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\CertificateStatus;
use App\Modules\Activity\Domain\Enums\CertificateType;
use App\Modules\Activity\Domain\Enums\CertificateVerificationResult;
use App\Modules\Activity\Domain\Enums\CompletionStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\Activity\Models\ActivityCertificate;
use App\Modules\Activity\Models\ActivityCertificateVerification;
use App\Modules\Activity\Models\ActivityCompletion;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sertifikat aktivitas (§52–53, §105).
 *
 * Penerbitan selalu **beralasan**: sertifikat hanya keluar bila kelayakannya
 * terpenuhi (kehadiran, nilai akhir, penyelesaian, atau kemenangan lomba), dan
 * alasan itu tersimpan sebagai snapshot pada sertifikat. Verifikasi publik
 * hanya mengembalikan **fakta sertifikat** — bukan data pribadi siswa.
 */
class ActivityCertificateService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityIntegrationService $integrations,
        private readonly StudentProviderInterface $students,
        private readonly ActivityNotificationService $notifications,
        private readonly ActivityAssessmentService $assessments,
        private readonly ActivityAccessService $access,
    ) {}

    /** @return LengthAwarePaginator */
    public function paginate(array $filters = [], ?User $viewer = null): LengthAwarePaginator
    {
        $query = ActivityCertificate::query()
            ->with(['student:id,full_name,nis', 'program:id,name', 'achievement:id,title'])
            ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
            ->when(isset($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('issued_date');

        // Cakupan baris: pemegang izin penerbitan melihat semua, selain itu hanya
        // sertifikat milik diri sendiri atau anaknya (\§88).
        if ($viewer !== null && ! $viewer->can('activity.certificate.view')) {
            $studentIds = $this->access->visibleStudentIds($viewer);
            $query->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * Terbitkan sertifikat penyelesaian aktivitas.
     *
     * Menolak bila kelayakan belum terpenuhi — sekolah dapat memaksa dengan
     * `force` (tetap tercatat di audit), tetapi tidak dapat melakukannya tanpa
     * menyadari konsekuensinya.
     */
    public function issueForCompletion(ActivityCompletion $completion, User $actor, array $payload = []): ActivityCertificate
    {
        $membership = ActivityMembership::query()->findOrFail($completion->membership_id);
        $program = $membership->program;

        if ($program === null) {
            throw ActivityValidationException::withErrors(['Keanggotaan tidak memiliki program.']);
        }

        $existing = ActivityCertificate::query()
            ->where('completion_id', $completion->id)
            ->where('type', CertificateType::COMPLETION->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // WAIVED berarti sekolah sudah memutuskan penyelesaian secara eksplisit
        // (mis. siswa pindahan) — keputusan manusia itu tetap dihormati, dengan
        // catatan pada metadata sertifikat.
        $waived = $completion->status === CompletionStatus::WAIVED;

        if (! in_array($completion->status, [CompletionStatus::COMPLETED, CompletionStatus::WAIVED], true)
            && ! ($payload['force'] ?? false)) {
            throw ActivityConflictException::make('Sertifikat hanya diterbitkan untuk aktivitas yang telah diselesaikan.');
        }

        $evaluation = $this->assessments->evaluateCompletion($membership);

        if (! $waived && ! ($completion->certificate_eligible ?? false) && ! ($payload['force'] ?? false)) {
            throw ActivityConflictException::make(
                'Peserta belum memenuhi syarat sertifikat.',
                ['blockers' => $evaluation['blockers'], 'snapshot' => $evaluation['snapshot']],
            );
        }

        return $this->issue([
            'type' => CertificateType::COMPLETION->value,
            'student_id' => (int) $completion->student_id,
            'program_id' => (int) $program->id,
            'period_id' => $completion->period_id === null ? null : (int) $completion->period_id,
            'completion_id' => (int) $completion->id,
            'title' => 'Sertifikat Penyelesaian '.$program->name,
            'description' => 'Diterbitkan atas penyelesaian aktivitas '.$program->name.($membership->period?->name === null ? '' : ' periode '.$membership->period->name).'.',
            'metadata' => [
                'eligibility' => $evaluation['snapshot'],
                'completion_status' => $completion->status->value,
                'rules_waived' => $waived,
            ],
        ], $actor);
    }

    /** Terbitkan sertifikat prestasi/kompetisi (§44, §102). */
    public function issueForAchievement(ActivityAchievement $achievement, User $actor, array $payload = []): array
    {
        if ($achievement->verification_status?->value !== 'VERIFIED' && ! ($payload['force'] ?? false)) {
            throw ActivityConflictException::make('Prestasi belum diverifikasi — sertifikat belum dapat diterbitkan.');
        }

        $studentIds = $achievement->members()
            ->where('certificate_eligible', true)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($studentIds === [] && $achievement->student_id !== null) {
            $studentIds = [(int) $achievement->student_id];
        }

        if ($studentIds === []) {
            throw ActivityValidationException::withErrors(['Prestasi ini tidak memiliki penerima sertifikat.']);
        }

        $certificates = [];

        foreach ($studentIds as $studentId) {
            $existing = ActivityCertificate::query()
                ->where('achievement_id', $achievement->id)
                ->where('student_id', $studentId)
                ->first();

            if ($existing !== null) {
                $certificates[] = $existing;

                continue;
            }

            $certificates[] = $this->issue([
                'type' => CertificateType::ACHIEVEMENT->value,
                'student_id' => $studentId,
                'program_id' => $achievement->program_id === null ? null : (int) $achievement->program_id,
                'achievement_id' => (int) $achievement->id,
                'competition_id' => $achievement->competition_id === null ? null : (int) $achievement->competition_id,
                'title' => $achievement->title,
                'description' => 'Prestasi '.$achievement->level->label().($achievement->organizer === null ? '' : ' — '.$achievement->organizer).'.',
                'metadata' => ['level' => $achievement->level->value, 'rank' => $achievement->rank?->value],
            ], $actor);
        }

        return $certificates;
    }

    /** @param array<string, mixed> $attributes */
    public function issue(array $attributes, User $actor): ActivityCertificate
    {
        if (! $actor->can('activity.certificate.issue')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menerbitkan sertifikat aktivitas.');
        }

        $studentId = (int) $attributes['student_id'];
        $snapshot = $this->students->snapshot($studentId);
        $number = $this->nextNumber();
        $verificationCode = strtoupper(Str::random(10));

        return DB::transaction(function () use ($attributes, $actor, $studentId, $snapshot, $number, $verificationCode) {
            $certificate = ActivityCertificate::query()->create([
                'certificate_number' => $number,
                'verification_code' => $verificationCode,
                'type' => $attributes['type'],
                'student_id' => $studentId,
                'program_id' => $attributes['program_id'] ?? null,
                'period_id' => $attributes['period_id'] ?? null,
                'achievement_id' => $attributes['achievement_id'] ?? null,
                'competition_id' => $attributes['competition_id'] ?? null,
                'completion_id' => $attributes['completion_id'] ?? null,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'issued_date' => $attributes['issued_date'] ?? now()->toDateString(),
                'issued_by' => $actor->id,
                'status' => CertificateStatus::ISSUED->value,
                'metadata' => array_merge((array) ($attributes['metadata'] ?? []), [
                    'student_name' => $snapshot['name'] ?? null,
                    'student_nis' => $snapshot['nis'] ?? null,
                ]),
            ]);

            $rendered = $this->integrations->call('CERTIFICATE', 'render', [
                'subject_type' => ActivityCertificate::class,
                'subject_id' => $certificate->id,
            ], fn () => array_merge(['status' => 'OK'], $this->integrations->certificates()->render([
                'certificate_number' => $number,
                'title' => $certificate->title,
                'student_name' => $snapshot['name'] ?? null,
                'issued_date' => $certificate->issued_date?->toDateString(),
                'verification_code' => $verificationCode,
                'description' => $certificate->description,
            ])));

            $certificate->forceFill([
                'pdf_path' => $rendered['path'] ?? null,
                'document_reference' => $rendered['reference'] ?? null,
            ])->save();

            $this->audit->record(AuditAction::CERTIFICATE_ISSUED, [
                'subject' => $certificate,
                'program_id' => $certificate->program_id === null ? null : (int) $certificate->program_id,
                'student_id' => $studentId,
                'after' => $certificate,
                'actor' => $actor->id,
            ]);

            $this->notifications->notify([
                'event' => 'CERTIFICATE_ISSUED',
                'program_id' => $certificate->program_id === null ? null : (int) $certificate->program_id,
                'student_id' => $studentId,
                'body' => 'Sertifikat '.$certificate->title.' telah diterbitkan ('.$number.').',
                'marker' => 'certificate|'.$certificate->id,
            ]);

            return $certificate->refresh();
        });
    }

    public function revoke(ActivityCertificate $certificate, User $actor, string $reason): ActivityCertificate
    {
        if (! $actor->can('activity.certificate.revoke')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang mencabut sertifikat.');
        }

        if (trim($reason) === '') {
            throw ActivityValidationException::withErrors(['Alasan pencabutan wajib diisi.']);
        }

        if ($certificate->status === CertificateStatus::REVOKED) {
            return $certificate;
        }

        $certificate->forceFill([
            'status' => CertificateStatus::REVOKED->value,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();

        if ($certificate->pdf_path !== null) {
            $this->integrations->call('CERTIFICATE', 'revoke', [
                'subject_type' => ActivityCertificate::class,
                'subject_id' => $certificate->id,
            ], fn () => ['status' => 'OK', 'revoked' => $this->integrations->certificates()->revoke((string) $certificate->pdf_path)]);
        }

        $this->audit->record(AuditAction::CERTIFICATE_REVOKED, [
            'subject' => $certificate,
            'program_id' => $certificate->program_id === null ? null : (int) $certificate->program_id,
            'student_id' => (int) $certificate->student_id,
            'metadata' => ['reason' => $reason],
            'actor' => $actor->id,
        ]);

        return $certificate->refresh();
    }

    /**
     * Verifikasi publik (§53).
     *
     * Sengaja mengembalikan sedikit: nomor, jenis, judul, tanggal, dan status.
     * **Tidak ada** nama siswa, NIS, kelas, atau nilai — nomor sertifikat dapat
     * beredar luas, dan pemegangnya tidak otomatis berhak atas data anak.
     */
    public function verify(string $certificateNumber, ?string $ip = null, ?string $userAgent = null): array
    {
        $certificate = ActivityCertificate::query()
            ->with(['program:id,name'])
            ->where('certificate_number', $certificateNumber)
            ->orWhere('verification_code', $certificateNumber)
            ->first();

        $result = match (true) {
            $certificate === null => CertificateVerificationResult::NOT_FOUND,
            $certificate->status === CertificateStatus::REVOKED => CertificateVerificationResult::REVOKED,
            $certificate->status === CertificateStatus::EXPIRED => CertificateVerificationResult::EXPIRED,
            $certificate->status === CertificateStatus::ISSUED => CertificateVerificationResult::VALID,
            default => CertificateVerificationResult::NOT_YET_ISSUED,
        };

        ActivityCertificateVerification::query()->create([
            'certificate_id' => $certificate?->id,
            'certificate_number' => $certificateNumber,
            'result' => $result->value,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 250),
            'verified_at' => now(),
        ]);

        return [
            'result' => $result->value,
            'result_label' => $result->label(),
            'valid' => $result === CertificateVerificationResult::VALID,
            'certificate' => $certificate === null ? null : [
                'number' => $certificate->certificate_number,
                'type' => $certificate->type?->value,
                'type_label' => $certificate->type?->label(),
                'title' => $certificate->title,
                'program' => $certificate->program?->name,
                'issued_date' => $certificate->issued_date?->toDateString(),
                'status' => $certificate->status?->value,
            ],
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<array> sertifikat seorang siswa (portal & portofolio) */
    public function forStudent(int $studentId): array
    {
        return ActivityCertificate::query()
            ->with('program:id,name')
            ->where('student_id', $studentId)
            ->orderByDesc('issued_date')
            ->get()
            ->map(fn (ActivityCertificate $certificate) => [
                'id' => (int) $certificate->id,
                'number' => $certificate->certificate_number,
                'type' => $certificate->type?->value,
                'type_label' => $certificate->type?->label(),
                'title' => $certificate->title,
                'program' => $certificate->program?->name,
                'issued_date' => $certificate->issued_date?->toDateString(),
                'status' => $certificate->status?->value,
                'status_label' => $certificate->status?->label(),
                'download_url' => $certificate->document_reference,
            ])
            ->all();
    }

    protected function nextNumber(): string
    {
        $prefix = 'CERT-ACT-'.now()->format('Y').'-';

        $last = ActivityCertificate::withTrashed()
            ->where('certificate_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('certificate_number');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
