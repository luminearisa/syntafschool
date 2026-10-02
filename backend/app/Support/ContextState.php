<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;

/**
 * Peran (konteks) aktif pada request berjalan — hanya terisi untuk token mobile
 * ber-ability `ctx:*`. Dibaca oleh `User` (permission/role per konteks) dan
 * modul-modul yang menentukan "siapa pengguna ini" lewat `users.userable`.
 *
 * Dengan menimpa `userable` PADA OBJEK di memori (tidak pernah disimpan),
 * seluruh modul yang sudah memakai `users.userable` otomatis melihat identitas
 * sesuai peran aktif — tanpa memodifikasi tiap modul satu per satu.
 */
final class ContextState
{
    private static ?int $userId = null;

    private static ?string $type = null;

    private static ?int $entityId = null;

    public static function set(int $userId, string $type, ?int $entityId): void
    {
        self::$userId = $userId;
        self::$type = $type;
        self::$entityId = $entityId;
    }

    public static function clear(): void
    {
        self::$userId = self::$type = self::$entityId = null;
    }

    /** Konteks aktif untuk akun ini, atau null (token web / tanpa konteks). */
    public static function typeFor(?int $userId): ?string
    {
        return $userId !== null && self::$userId === $userId ? self::$type : null;
    }

    /** Samakan `userable` objek User dengan peran aktif (hanya di memori). */
    public static function apply(User $user): void
    {
        if (self::$userId === null || (int) $user->getKey() !== self::$userId) {
            return;
        }

        $class = match (self::$type) {
            'employee' => Employee::class,
            'guardian' => Guardian::class,
            'student' => Student::class,
            default => null,
        };

        if ($class === null || self::$entityId === null) {
            return;
        }

        $user->setAttribute('userable_type', (new $class)->getMorphClass());
        $user->setAttribute('userable_id', self::$entityId);
        $user->syncOriginalAttributes(['userable_type', 'userable_id']);
        $user->unsetRelation('userable');
    }
}
