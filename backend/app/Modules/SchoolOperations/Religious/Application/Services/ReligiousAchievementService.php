<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{CertificateGatewayInterface, StudentActivityGatewayInterface};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{ReligiousAchievement, ReligiousActivityParticipant, ReligiousCertificate};

/**
 * Prestasi & sertifikat keagamaan (§55, §56, §57).
 *
 * Portofolio keagamaan siswa dibangun sebagai lapisan integrasi — prestasi tetap
 * dapat diteruskan ke modul Student Activity/Achievement.
 */
class ReligiousAchievementService
{
    public function __construct(
        protected ReligiousContext $context,
        protected StudentActivityGatewayInterface $studentActivity,
        protected CertificateGatewayInterface $certificates,
    ) {
    }

    public function record(array $payload): ReligiousAchievement
    {
        $achievement = ReligiousAchievement::query()->create([
            'student_id' => $payload['student_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
            'participant_id' => $payload['participant_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('PRS', 'religious_achievements'),
            'title' => $payload['title'],
            'achievement_type' => strtoupper((string) ($payload['achievement_type'] ?? 'QURAN_MEMORIZATION')),
            'category' => $payload['category'] ?? null,
            'level' => strtoupper((string) ($payload['level'] ?? 'SCHOOL')),
            'rank_label' => $payload['rank_label'] ?? null,
            'score' => $payload['score'] ?? null,
            'activity_id' => $payload['activity_id'] ?? null,
            'assessment_id' => $payload['assessment_id'] ?? null,
            'achievement_date' => $payload['achievement_date'] ?? now()->toDateString(),
            'organizer' => $payload['organizer'] ?? null,
            'location' => $payload['location'] ?? null,
            'description' => $payload['description'] ?? null,
            'created_by' => $this->context->actorId(),
            'status' => 'RECORDED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $forwarded = null;
        if ($achievement->student_id) {
            $forwarded = $this->studentActivity->recordAchievement([
                'student_id' => $achievement->student_id,
                'title' => $achievement->title,
                'type' => $achievement->achievement_type,
                'level' => $achievement->level,
                'rank' => $achievement->rank_label,
                'date' => (string) $achievement->achievement_date,
                'source' => 'RELIGIOUS',
                'reference' => $achievement->code,
            ]);

            if (! empty($forwarded['id'])) {
                $achievement->forceFill(['student_activity_id' => $forwarded['id']])->save();
            }
        }

        $this->context->audit('religious.achievement.recorded', $achievement, ['forwarded' => $forwarded]);
        $this->context->publish('ReligiousAchievementRecorded', $achievement, [
            'type' => $achievement->achievement_type, 'level' => $achievement->level,
        ]);

        return $achievement;
    }

    /** Terbitkan sertifikat (§57) lewat CertificateProvider bila tersedia. */
    public function issueCertificate(array $payload): array
    {
        $certificate = ReligiousCertificate::query()->create([
            'achievement_id' => $payload['achievement_id'] ?? null,
            'participant_id' => $payload['participant_id'] ?? null,
            'student_id' => $payload['student_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
            'code' => $payload['code'] ?? $this->context->nextCode('SRT', 'religious_certificates'),
            'certificate_type' => strtoupper((string) ($payload['certificate_type'] ?? 'COMPLETION')),
            'title' => $payload['title'],
            'issued_date' => $payload['issued_date'] ?? now()->toDateString(),
            'issued_by' => $this->context->actorId(),
            'status' => 'DRAFT',
            'valid_until' => $payload['valid_until'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $render = $this->certificates->issue([
            'reference' => $certificate->code,
            'title' => $certificate->title,
            'recipient' => $payload['recipient_name'] ?? null,
            'student_id' => $certificate->student_id,
            'type' => $certificate->certificate_type,
            'issued_date' => (string) $certificate->issued_date,
            'achievement_id' => $certificate->achievement_id,
        ]);

        $certificate->forceFill([
            'status' => ($render['issued'] ?? false) ? 'ISSUED' : (($render['pending_provider'] ?? false) ? 'PENDING_PROVIDER' : 'DRAFT'),
            'provider_reference' => $render['reference'] ?? ($render['path'] ?? null),
            'document_id' => $render['document_id'] ?? null,
        ])->save();

        $this->context->audit('religious.certificate.issued', $certificate, ['render' => $render]);
        $this->context->publish('ReligiousCertificateIssued', $certificate, ['code' => $certificate->code]);

        return ['certificate' => $certificate->only(['id', 'code', 'status', 'certificate_type']), 'provider' => $render];
    }

    /** Portofolio keagamaan siswa (§55, §68). */
    public function portfolio(int $studentId): array
    {
        $achievements = ReligiousAchievement::query()->where('student_id', $studentId)
            ->orderByDesc('achievement_date')->limit(100)->get();

        $certificates = ReligiousCertificate::query()->where('student_id', $studentId)
            ->orderByDesc('issued_date')->limit(100)->get();

        $participations = ReligiousActivityParticipant::query()->where('student_id', $studentId)
            ->orderByDesc('joined_at')->limit(200)->get();

        return [
            'student_id' => $studentId,
            'achievements' => $achievements->map(fn (ReligiousAchievement $a): array => [
                'code' => $a->code, 'title' => $a->title, 'type' => $a->achievement_type,
                'level' => $a->level, 'rank' => $a->rank_label,
                'date' => $a->achievement_date?->toDateString(), 'score' => $a->score,
            ])->all(),
            'certificates' => $certificates->map(fn (ReligiousCertificate $c): array => [
                'code' => $c->code, 'title' => $c->title, 'type' => $c->certificate_type,
                'issued_date' => $c->issued_date?->toDateString(), 'status' => $c->status,
            ])->all(),
            'participation_count' => $participations->count(),
            'by_type' => $participations->groupBy('participant_type')->map->count()->all(),
            'activity_count' => $participations->pluck('activity_id')->unique()->count(),
            'note' => 'Portofolio ini dapat dipakai modul Student Activity sesuai kebijakan privasi institusi.',
        ];
    }

    public function report(array $filters = []): array
    {
        $query = ReligiousAchievement::query()
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('achievement_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('achievement_date', '<=', $filters['to']))
            ->when(! empty($filters['level']), fn ($q) => $q->where('level', strtoupper((string) $filters['level'])));

        return [
            'total' => (clone $query)->count(),
            'by_type' => (clone $query)->selectRaw('achievement_type, count(*) as total')
                ->groupBy('achievement_type')->get()
                ->map(fn ($r): array => ['type' => $r->achievement_type, 'total' => (int) $r->total])->all(),
            'by_level' => (clone $query)->selectRaw('level, count(*) as total')
                ->groupBy('level')->get()
                ->map(fn ($r): array => ['level' => $r->level, 'total' => (int) $r->total])->all(),
            'certificates_issued' => ReligiousCertificate::query()->whereIn('status', ['ISSUED', 'PENDING_PROVIDER'])->count(),
        ];
    }
}
