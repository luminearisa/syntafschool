<?php

namespace App\Modules\Promotion\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris persetujuan berjenjang untuk satu keputusan kenaikan.
 *
 * Satu keputusan memiliki tepat satu baris per peran penyetuju (`REQUIRED_ROLES`).
 * Keputusan baru berstatus `approved` setelah seluruh peran menyetujui.
 */
class PromotionApproval extends PromotionModel
{
    public const ROLE_WALI_KELAS = 'wali_kelas';
    public const ROLE_KEPALA_SEKOLAH = 'kepala_sekolah';

    /**
     * Peran yang wajib menyetujui sebelum keputusan boleh difinalisasi.
     *
     * @var list<string>
     */
    public const REQUIRED_ROLES = [
        self::ROLE_WALI_KELAS,
        self::ROLE_KEPALA_SEKOLAH,
    ];

    /** @var array<string, string> */
    public const ROLE_LABELS = [
        self::ROLE_WALI_KELAS => 'Wali kelas',
        self::ROLE_KEPALA_SEKOLAH => 'Kepala sekolah',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $table = 'promotion_approvals';

    protected $casts = [
        'approved_at' => 'datetime',
        'status' => 'string',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(PromotionDecision::class, 'promotion_decision_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
