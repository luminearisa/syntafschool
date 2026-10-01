<?php

namespace Tests\Feature\Bk;

use App\Models\School;
use App\Modules\Bk\Application\Support\BkTenantResolver;
use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\ReferralSource;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkReferral;
use App\Models\User;

/**
 * Dasar pengujian Phase 2.
 *
 * Menambahkan dua hal yang tidak dimiliki Phase 1: penyiapan unit sekolah (untuk
 * menguji isolasi antar-unit) dan penyiapan penyedia rujukan luar.
 */
abstract class BkPhase2TestCase extends BkTestCase
{
    protected function makeSchool(string $name): School
    {
        return School::query()->create([
            'name' => $name,
            'jenjang' => 'SMP',
            'status' => 'Swasta',
        ]);
    }

    /** Menjalankan callback seolah-olah permintaan datang dari unit sekolah tertentu. */
    protected function asTenant(?int $schoolId, callable $callback): mixed
    {
        $resolver = app(BkTenantResolver::class);
        $previousEnabled = config('bk.tenant.enabled');
        config(['bk.tenant.enabled' => $schoolId !== null]);
        $resolver->force($schoolId);

        try {
            return $callback();
        } finally {
            $resolver->force(null);
            config(['bk.tenant.enabled' => $previousEnabled]);
        }
    }

    protected function makeProvider(string $name = 'Psikolog Mitra', string $type = 'PSYCHOLOGIST'): \App\Modules\Bk\Models\BkReferralProvider
    {
        return app(\App\Modules\Bk\Application\Services\BkExternalReferralService::class)
            ->createProvider($this->makeCounselor('BK_ADMIN'), [
                'name' => $name,
                'provider_type' => $type,
                'contact_person' => 'Petugas Penerima',
                'phone' => '021000000',
            ]);
    }

    protected function makeReferralRow(User $actor, int $studentId, array $overrides = []): BkReferral
    {
        return BkReferral::query()->create(array_merge([
            'referral_number' => 'BK-R-TEST-'.random_int(100000, 999999),
            'student_id' => $studentId,
            'source_type' => ReferralSource::TEACHER,
            'category' => ReferralCategory::ACADEMIC,
            'priority' => 'NORMAL',
            'status' => 'SUBMITTED',
            'confidentiality_level' => 'RESTRICTED',
            'description' => 'Nilai menurun sejak ujian tengah semester.',
            'referred_at' => now(),
            'created_by' => $actor->id,
        ], $overrides));
    }

    protected function makeOpenCase(User $actor, int $studentId, array $overrides = []): BkCase
    {
        $case = $this->makeCase($actor, $this->findStudent($studentId));

        if ($overrides !== []) {
            $case->forceFill($overrides)->save();
        }

        return $case->refresh();
    }

    /** Membuat pengguna dengan peran tanpa mengharapkan perilaku apa pun darinya. */
    protected function makeUserWithRole(string $role, string $name): User
    {
        return $this->actingAsRole($role, ['name' => $name]);
    }

    protected function findStudent(int $studentId): \App\Models\Student
    {
        return \App\Models\Student::query()->findOrFail($studentId);
    }
}
