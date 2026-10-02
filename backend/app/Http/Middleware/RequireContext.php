<?php

namespace App\Http\Middleware;

use App\Services\Mobile\ContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ctx:guardian,student` — endpoint hanya untuk token yang diterbitkan untuk
 * salah satu konteks itu (lewat /auth/switch-context).
 */
class RequireContext
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $token = $request->user()?->currentAccessToken();

        foreach ($types as $type) {
            if ($token && method_exists($token, 'can') && $token->can(ContextService::ability($type))) {
                $request->attributes->set('mobile_context', $type);

                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Endpoint ini tidak tersedia pada peran Anda saat ini.',
        ], 403);
    }
}
