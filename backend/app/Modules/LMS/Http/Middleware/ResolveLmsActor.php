<?php

namespace App\Modules\LMS\Http\Middleware;

use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menyiapkan `LmsActor` untuk seluruh endpoint LMS (§20 langkah pertama).
 *
 * Middleware ini yang membuat Integrated & Standalone Mode berbagi controller
 * yang sama: apa pun sumber identitasnya, controller selalu menerima objek
 * `LmsActor` yang sudah dinormalisasi.
 */
class ResolveLmsActor
{
    public const ATTRIBUTE = 'lms_actor';

    public function __construct(private readonly UserProviderInterface $users) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $this->users->fromRequest($request);

        $request->attributes->set(self::ATTRIBUTE, $actor);

        if ($actor === null) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terautentikasi pada LMS.',
            ], 401);
        }

        return $next($request);
    }

    /** Pelaku pada request saat ini (null untuk guest). */
    public static function actor(Request $request): ?LmsActor
    {
        $actor = $request->attributes->get(self::ATTRIBUTE);

        return $actor instanceof LmsActor ? $actor : null;
    }
}
