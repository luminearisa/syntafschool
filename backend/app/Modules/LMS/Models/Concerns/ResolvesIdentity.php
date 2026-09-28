<?php

namespace App\Modules\LMS\Models\Concerns;

use App\Models\User;
use App\Modules\LMS\Models\LmsUser;

/**
 * Model mana yang menyimpan identitas pelaku pada mode yang sedang aktif.
 *
 * Dikumpulkan di satu tempat supaya tidak ada relasi yang diam-diam menunjuk
 * `users` ketika LMS berjalan mandiri (dan sebaliknya).
 */
trait ResolvesIdentity
{
    /** @return class-string<\Illuminate\Foundation\Auth\User>|class-string<LmsUser> */
    public static function identityModel(): string
    {
        return config('lms.standalone', false) ? LmsUser::class : User::class;
    }

    public static function identityTable(): string
    {
        return config('lms.standalone', false) ? 'lms_users' : 'users';
    }
}
