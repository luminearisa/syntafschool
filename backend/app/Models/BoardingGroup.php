<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok pengasuhan lintas kamar (§8): halaqah, piket, atau kelompok
 * binaan yang anggotanya bisa berasal dari beberapa kamar.
 *
 * Kelompok punya pengasuh sendiri (`musyrif_employee_id`) yang belum tentu
 * sama dengan wali kamar anggotanya — karena itu `BoardingScopeService`
 * menggabungkan area penugasan musyrif DAN keanggotaan kelompoknya.
 */
class BoardingGroup extends BaseModel
{
    use LogsBoardingActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Tidak Aktif',
    ];

    public function musyrifEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'musyrif_employee_id');
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(BoardingDormitory::class, 'dormitory_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BoardingGroupMember::class, 'group_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'boarding_group_members', 'group_id', 'student_id')
            ->withPivot(['joined_date', 'left_date'])
            ->withTimestamps();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BoardingActivitySchedule::class, 'group_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForEmployee(Builder $query, ?int $employeeId): Builder
    {
        return $employeeId === null ? $query : $query->where('musyrif_employee_id', $employeeId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * ID santri yang masih menjadi anggota (anggota yang sudah keluar tidak
     * dihitung, tetapi barisnya tetap ada sebagai riwayat).
     *
     * @return list<int>
     */
    public function currentMemberIds(): array
    {
        return $this->members()
            ->whereNull('left_date')
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_GROUP';
    }
}
