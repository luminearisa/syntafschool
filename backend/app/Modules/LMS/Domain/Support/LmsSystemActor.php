<?php

namespace App\Modules\LMS\Domain\Support;

use App\Modules\LMS\Domain\Enums\ActorRole;

/**
 * Aktor sistem untuk pekerjaan otomatis (§11, §23).
 *
 * Job, perintah artisan, dan penjadwal tidak punya pengguna yang login — tanpa
 * aktor khusus, jejak auditnya akan tercatat "tanpa pelaku" dan sulit
 * dipertanggungjawabkan. Aktor sistem diberi izin eksplisit (bukan bypass
 * otorisasi): yang dibutuhkan hanyalah aksi otomatis yang memang diizinkan.
 */
final class LmsSystemActor
{
    public static function make(string $name = 'Sistem LMS'): LmsActor
    {
        return new LmsActor(
            id: 0,
            name: $name,
            email: null,
            avatarPath: null,
            primaryRole: ActorRole::ADMIN,
            roles: ['system'],
            permissions: [
                'lms.manage',
                'lms.grade.sync',
                'lms.grade.correct',
                'lms.certificate.issue',
                'lms.badge.award',
                'lms.report.export',
                'lms.integrity.run',
                'lms.health.view',
            ],
            superAdmin: false,
            standalone: (bool) config('lms.standalone', false),
        );
    }
}
