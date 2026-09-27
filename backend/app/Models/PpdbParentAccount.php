<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Profil PPDB sebuah akun orang tua.
 *
 * Identitas (nama/email/telepon) TIDAK disalin — dibaca dari `users` dan
 * `guardians` existing. Tabel ini hanya menyimpan status akun PPDB, sehingga
 * satu akun dapat mendaftarkan banyak anak tanpa duplikasi data pribadi.
 */
class PpdbParentAccount extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const STATUS_PENDING = 'pending_verification';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_BLOCKED,
    ];

    protected $casts = ['verified_at' => 'datetime', 'last_activity_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(PpdbApplicant::class, 'parent_account_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(PpdbRegistration::class, 'parent_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('account_status', self::STATUS_ACTIVE);
    }

    /** Hanya akun aktif yang boleh bertransaksi; status lain read-only. */
    public function canTransact(): bool
    {
        return $this->account_status === self::STATUS_ACTIVE;
    }

    /**
     * Id santri yang merupakan anak pemilik akun orang tua ini.
     *
     * Rantai kepemilikan portal: `ppdb_parent_accounts.user_id` → `guardian_id`
     * → `student_guardians`. Rantai yang sama dipakai setiap portal modul (uang
     * sekolah, asrama) dan pengirim notifikasi ke arah sebaliknya, supaya
     * "anak saya" berarti sama di semua layar.
     *
     * @return list<int>
     */
    public static function childStudentIds(User $user): array
    {
        $guardianIds = static::query()
            ->where('user_id', $user->id)
            ->whereNotNull('guardian_id')
            ->pluck('guardian_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($guardianIds === []) {
            return [];
        }

        return DB::table('student_guardians')
            ->whereIn('guardian_id', $guardianIds)
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_ACCOUNT';
    }
}
