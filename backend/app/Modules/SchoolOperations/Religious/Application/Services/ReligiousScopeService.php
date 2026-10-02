<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousAccessProviderInterface;

/**
 * Cakupan & izin modul Religious (§4, §94).
 *
 * Menyatukan daftar izin, pemetaan peran, dan cakupan baris (kampus, organisasi,
 * masjid, asrama). Service lain memakai kelas ini agar aturan akses tidak
 * tersebar dan mudah diaudit.
 */
class ReligiousScopeService
{
    /** Seluruh izin modul. */
    public const PERMISSIONS = [
        'religious.view',
        'religious.manage',

        'religious.mosque.view',
        'religious.mosque.manage',
        'religious.booking.view',
        'religious.booking.request',
        'religious.booking.approve',

        'religious.prayer.view',
        'religious.prayer.manage',
        'religious.prayer.schedule.manage',

        'religious.attendance.view',
        'religious.attendance.record',
        'religious.attendance.adjust',
        'religious.attendance.approve',

        'religious.personnel.view',
        'religious.personnel.manage',

        'religious.roster.view',
        'religious.roster.manage',
        'religious.roster.publish',

        'religious.friday.view',
        'religious.friday.manage',
        'religious.khutbah.view',
        'religious.khutbah.create',
        'religious.khutbah.review',
        'religious.khutbah.approve',
        'religious.khutbah.deliver',

        'religious.activity.view',
        'religious.activity.manage',
        'religious.activity.register',
        'religious.activity.attend',
        'religious.activity.assess',

        'religious.ramadan.view',
        'religious.ramadan.manage',
        'religious.eid.view',
        'religious.eid.manage',

        'religious.achievement.view',
        'religious.achievement.manage',
        'religious.certificate.issue',

        'religious.analytics.view',
        'religious.report.view',
        'religious.report.export',
        'religious.import',
        'religious.audit.view',
        'religious.settings.manage',

        'religious.portal.student',
        'religious.portal.parent',
        'religious.portal.boarding',
        'religious.display.view',
    ];

    /** Peran → izin. `*` berarti seluruh izin modul. */
    public const ROLE_PERMISSIONS = [
        'RELIGIOUS_ADMIN' => '*',
        'RELIGIOUS_MANAGER' => [
            'religious.view', 'religious.manage',
            'religious.mosque.view', 'religious.mosque.manage',
            'religious.booking.view', 'religious.booking.request', 'religious.booking.approve',
            'religious.prayer.view', 'religious.prayer.manage', 'religious.prayer.schedule.manage',
            'religious.attendance.view', 'religious.attendance.record', 'religious.attendance.adjust', 'religious.attendance.approve',
            'religious.personnel.view', 'religious.personnel.manage',
            'religious.roster.view', 'religious.roster.manage', 'religious.roster.publish',
            'religious.friday.view', 'religious.friday.manage',
            'religious.khutbah.view', 'religious.khutbah.create', 'religious.khutbah.review', 'religious.khutbah.approve',
            'religious.activity.view', 'religious.activity.manage', 'religious.activity.assess',
            'religious.ramadan.view', 'religious.ramadan.manage',
            'religious.eid.view', 'religious.eid.manage',
            'religious.achievement.view', 'religious.achievement.manage', 'religious.certificate.issue',
            'religious.analytics.view', 'religious.report.view', 'religious.report.export',
            'religious.import', 'religious.audit.view', 'religious.display.view',
            'religious.portal.boarding',
        ],
        'MOSQUE_ADMIN' => [
            'religious.view', 'religious.mosque.view', 'religious.mosque.manage',
            'religious.booking.view', 'religious.booking.request', 'religious.booking.approve',
            'religious.prayer.view', 'religious.prayer.manage', 'religious.prayer.schedule.manage',
            'religious.attendance.view', 'religious.attendance.record',
            'religious.roster.view', 'religious.roster.manage',
            'religious.friday.view', 'religious.khutbah.view',
            'religious.activity.view', 'religious.display.view',
            'religious.ramadan.view', 'religious.report.view',
        ],
        // Personel keagamaan: melihat penugasan sendiri dan mengakses portal.
        'IMAM' => ['religious.view', 'religious.prayer.view', 'religious.roster.view', 'religious.attendance.view', 'religious.display.view'],
        'MUADZIN' => ['religious.view', 'religious.prayer.view', 'religious.roster.view', 'religious.display.view'],
        'KHATIB' => ['religious.view', 'religious.friday.view', 'religious.khutbah.view', 'religious.khutbah.create', 'religious.khutbah.deliver'],
        'USTADZ' => ['religious.view', 'religious.activity.view', 'religious.activity.manage', 'religious.activity.assess', 'religious.prayer.view'],
        'USTADZAH' => ['religious.view', 'religious.activity.view', 'religious.activity.manage', 'religious.activity.assess', 'religious.prayer.view'],
        'RELIGIOUS_TEACHER' => ['religious.view', 'religious.activity.view', 'religious.activity.assess', 'religious.achievement.view'],
        'RELIGIOUS_ACTIVITY_COORDINATOR' => [
            'religious.view', 'religious.activity.view', 'religious.activity.manage',
            'religious.activity.register', 'religious.activity.attend', 'religious.activity.assess',
            'religious.achievement.view', 'religious.achievement.manage', 'religious.report.view',
        ],
        'MUSYRIF' => ['religious.view', 'religious.attendance.view', 'religious.attendance.record', 'religious.portal.boarding', 'religious.prayer.view'],
        'BOARDING_STAFF' => ['religious.view', 'religious.attendance.view', 'religious.attendance.record', 'religious.portal.boarding'],
        'SCHOOL_ADMIN' => ['religious.view', 'religious.mosque.view', 'religious.prayer.view', 'religious.attendance.view', 'religious.activity.view', 'religious.friday.view', 'religious.ramadan.view', 'religious.report.view'],
        'TEACHER' => ['religious.view', 'religious.prayer.view', 'religious.activity.view', 'religious.attendance.view'],
        'STUDENT' => ['religious.portal.student', 'religious.display.view'],
        'PARENT' => ['religious.portal.parent'],
        'FACILITY_MANAGER' => ['religious.view', 'religious.mosque.view', 'religious.booking.view', 'religious.booking.approve'],
        'ASSET_MANAGER' => ['religious.view', 'religious.mosque.view'],
        'INVENTORY_MANAGER' => ['religious.view', 'religious.mosque.view'],
        'CATERING_MANAGER' => ['religious.view', 'religious.ramadan.view', 'religious.report.view'],
        'EVENT_MANAGER' => ['religious.view', 'religious.activity.view', 'religious.booking.view', 'religious.booking.request'],
        'TRANSPORTATION_MANAGER' => ['religious.view', 'religious.activity.view', 'religious.ramadan.view'],
        'FINANCE' => ['religious.view', 'religious.report.view', 'religious.report.export'],
        'AUDITOR' => ['religious.view', 'religious.audit.view', 'religious.report.view', 'religious.attendance.view', 'religious.prayer.view'],
    ];

    public function __construct(
        protected ReligiousAccessProviderInterface $access,
        protected \App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext $context,
    ) {
    }

    public function can(string $ability, mixed $user = null): bool
    {
        return $this->context->can($ability, $user);
    }

    /** Cakupan efektif pengguna (kampus/organisasi/masjid/asrama). */
    public function scope(mixed $user = null): array
    {
        return $this->context->scopeFor($user);
    }

    public function isGlobal(mixed $user = null): bool
    {
        return (bool) ($this->scope($user)['is_global'] ?? false);
    }

    /**
     * Terapkan cakupan baris pada query.
     * Moderator global (mis. RELIGIOUS_ADMIN) melihat seluruh data.
     */
    public function apply(mixed $query, mixed $user = null, string $campusColumn = 'campus_id', string $mosqueColumn = 'mosque_id'): mixed
    {
        return $this->context->applyScope($query, $user, $campusColumn, $mosqueColumn);
    }

    /** Cakupan untuk siswa: siswa hanya melihat dirinya, wali hanya anaknya (§14). */
    public function studentScope(mixed $user = null): array
    {
        $user ??= auth()->user();

        if ($user === null) {
            return ['visible' => false, 'student_ids' => [], 'reason' => 'Belum masuk.'];
        }

        if ($this->can('religious.view', $user)) {
            return ['visible' => true, 'student_ids' => null, 'reason' => 'Cakupan pengelola.'];
        }

        $own = $user->student_id ?? null;

        return [
            'visible' => true,
            'student_ids' => $own ? [(int) $own] : [],
            'reason' => 'Portal siswa: hanya data sendiri.',
        ];
    }
}
