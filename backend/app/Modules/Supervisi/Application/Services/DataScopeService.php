<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Menentukan cakupan data seorang pengguna (seluruh sekolah atau dirinya).
 *
 * Izin modul hanya menjawab "boleh membuka layar ini?"; cakupan menjawab
 * "baris mana yang boleh dilihat?". Dua hal berbeda: guru memang boleh membuka
 * laporan supervisi, tetapi hanya baris dirinya.
 */
class DataScopeService
{
    /**
     * Izin yang berarti pemegangnya mengurus supervisi seluruh sekolah.
     *
     * `supervisi.report.view` **tidak** termasuk: guru dan staf memegangnya
     * untuk membaca hasil sendiri.
     */
    public const SCHOOL_PERMISSIONS = [
        'supervisi.manage',
        'supervisi.audit.view',
        'supervisi.visit.view.all',
        'supervisi.analytics.view',
        'supervisi.report.export',
    ];

    public function __construct(
        private readonly VisitAccessService $visits,
        private readonly SupervisiSettings $settings,
    ) {
    }

    public function resolve(?User $actor): DataScope
    {
        if ($actor === null) {
            return DataScope::self(null);
        }

        foreach (self::SCHOOL_PERMISSIONS as $permission) {
            if ($actor->can($permission)) {
                return DataScope::school((int) $actor->getKey());
            }
        }

        // Kebijakan sekolah: rekan sejawat boleh membaca ringkasan agregat
        // (tanpa nama) — mati secara bawaan.
        return DataScope::self(
            $this->visits->employeeIdFor($actor),
            $this->settings->bool('peer_summary_enabled'),
            (int) $actor->getKey(),
        );
    }

    /**
     * Laporan agregat sekolah hanya untuk pemegang tata kelola, kecuali
     * sekolah mengizinkan ringkasan bagi rekan sejawat.
     */
    public function assertAllowsAggregate(DataScope $scope, string $what = 'Ringkasan sekolah'): void
    {
        if ($scope->allowsPeerSummary()) {
            return;
        }

        throw new AuthorizationException(
            $what.' hanya dapat dibuka oleh kepala sekolah, wakil kepala sekolah, pengawas, atau admin. '.
            'Sekolah dapat mengizinkan ringkasan agregat bagi rekan sejawat lewat pengaturan `peer_summary_enabled`.'
        );
    }
}
