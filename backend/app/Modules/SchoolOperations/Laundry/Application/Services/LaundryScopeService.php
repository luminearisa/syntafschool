<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\ParentProviderInterface;

/**
 * Cakupan akses & master izin modul laundry (§2, §64).
 *
 * Portal: wali hanya anak yang menjadi authorized dependent (Rule 20); santri
 * hanya datanya sendiri (diresolusi lewat ContextService mobile bila ada).
 */
class LaundryScopeService
{
    /** Seluruh izin modul (§64 + operasional). */
    public const PERMISSIONS = [
        'laundry.view', 'laundry.create', 'laundry.update', 'laundry.receive',
        'laundry.weigh', 'laundry.process', 'laundry.qc', 'laundry.deliver', 'laundry.cancel',
        'laundry.quota.view', 'laundry.quota.adjust', 'laundry.quota.manage',
        'laundry.payment.view', 'laundry.payment.override', 'laundry.refund',
        'laundry.incident.view', 'laundry.incident.manage',
        'laundry.report', 'laundry.export', 'laundry.audit',
        // Operasional tambahan.
        'laundry.dashboard.view', 'laundry.service.manage', 'laundry.pricing.manage',
        'laundry.policy.manage', 'laundry.bag.manage', 'laundry.batch.manage',
        'laundry.machine.manage', 'laundry.identify', 'laundry.notification.send',
        'laundry.portal.view',
    ];

    /** Peta peran → izin (§2). */
    public const ROLE_PERMISSIONS = [
        'LAUNDRY_ADMIN' => '*',
        'LAUNDRY_MANAGER' => [
            'laundry.view', 'laundry.create', 'laundry.update', 'laundry.receive', 'laundry.weigh',
            'laundry.process', 'laundry.qc', 'laundry.deliver', 'laundry.cancel',
            'laundry.quota.view', 'laundry.quota.adjust', 'laundry.quota.manage',
            'laundry.payment.view', 'laundry.payment.override', 'laundry.refund',
            'laundry.incident.view', 'laundry.incident.manage',
            'laundry.report', 'laundry.export', 'laundry.audit',
            'laundry.dashboard.view', 'laundry.service.manage', 'laundry.pricing.manage',
            'laundry.policy.manage', 'laundry.bag.manage', 'laundry.batch.manage',
            'laundry.machine.manage', 'laundry.identify', 'laundry.notification.send',
        ],
        'LAUNDRY_OPERATOR' => [
            'laundry.view', 'laundry.receive', 'laundry.weigh', 'laundry.process',
            'laundry.qc', 'laundry.deliver', 'laundry.identify', 'laundry.dashboard.view',
            'laundry.incident.view', 'laundry.incident.manage', 'laundry.batch.manage',
        ],
        'LAUNDRY_RECEIVER' => [
            'laundry.view', 'laundry.create', 'laundry.receive', 'laundry.weigh',
            'laundry.identify', 'laundry.bag.manage', 'laundry.dashboard.view',
        ],
        'LAUNDRY_PROCESSOR' => ['laundry.view', 'laundry.process', 'laundry.batch.manage', 'laundry.identify'],
        'LAUNDRY_QC' => ['laundry.view', 'laundry.qc', 'laundry.incident.view', 'laundry.incident.manage', 'laundry.identify'],
        'LAUNDRY_PACKER' => ['laundry.view', 'laundry.process', 'laundry.identify'],
        'LAUNDRY_DISTRIBUTOR' => ['laundry.view', 'laundry.deliver', 'laundry.identify'],
        'LAUNDRY_CASHIER' => [
            'laundry.view', 'laundry.payment.view', 'laundry.refund', 'laundry.identify',
            'laundry.dashboard.view',
        ],
        'BOARDING_ADMIN' => ['laundry.view', 'laundry.quota.view', 'laundry.report', 'laundry.dashboard.view'],
        'FINANCE' => ['laundry.view', 'laundry.payment.view', 'laundry.report', 'laundry.export', 'laundry.audit'],
        'STUDENT' => ['laundry.portal.view'],
        'PARENT' => ['laundry.portal.view'],
    ];

    public function __construct(private readonly ParentProviderInterface $parents)
    {
    }

    /** Santri yang boleh dilihat pengguna portal (wali: anaknya; santri: dirinya). */
    public function canAccessStudent(?User $user, int $studentId): bool
    {
        if (! $user) {
            return false;
        }

        // Staf dengan izin lihat penuh boleh semua santri.
        if ($user->can('laundry.view') || $user->can('laundry.quota.view')) {
            return true;
        }

        if ($this->studentIdForUser($user) === $studentId) {
            return true;
        }

        return $this->parents->canActFor((int) $user->getKey(), $studentId);
    }

    /** Student id bila akun ini adalah santri (lewat ContextService mobile). */
    public function studentIdForUser(User $user): ?int
    {
        try {
            if (class_exists(\App\Services\Mobile\ContextService::class)) {
                return app(\App\Services\Mobile\ContextService::class)->studentId($user);
            }
        } catch (\Throwable) {
            // identitas tidak tersedia — perlakukan sebagai non-santri
        }

        return null;
    }

    /** Anak-anak akun wali (untuk daftar portal orang tua). */
    public function childStudentIds(User $user): array
    {
        try {
            if (class_exists(\App\Services\Mobile\ContextService::class)) {
                $ids = app(\App\Services\Mobile\ContextService::class)->childStudentIds($user);
                if ($ids !== []) {
                    return $ids;
                }
            }
        } catch (\Throwable) {
        }

        return $this->parents->childrenOfUser((int) $user->getKey());
    }
}
