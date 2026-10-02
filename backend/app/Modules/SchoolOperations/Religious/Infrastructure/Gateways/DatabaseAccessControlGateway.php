<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousAccessProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Otorisasi & cakupan (§94). Menyatukan RBAC + Policy + cakupan
 * kampus/organisasi/masjid dalam satu titik.
 */
class DatabaseAccessControlGateway implements ReligiousAccessProviderInterface
{
    use GatewaySupport;

    public function __construct(protected \App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext $context)
    {
    }

    public function can(string $ability, ?int $userId = null): bool
    {
        $user = $userId ? $this->userModel()::query()->find($userId) : auth()->user();
        if ($user === null) {
            return false;
        }

        return (bool) $user->can($ability);
    }

    public function scopeFor(?int $userId = null): array
    {
        $user = $userId ? $this->userModel()::query()->find($userId) : null;

        return $this->context->scopeFor($user);
    }

    public function campusIds(?int $userId = null): array
    {
        $user = $userId ? $this->userModel()::query()->find($userId) : null;

        return $this->context->campusIds($user);
    }

    public function mosqueIds(?int $userId = null): array
    {
        $user = $userId ? $this->userModel()::query()->find($userId) : null;

        return $this->context->mosqueIds($user);
    }

    public function available(): bool
    {
        return class_exists($this->userModel());
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Model pengguna tidak ditemukan.';
    }

    protected function userModel(): string
    {
        return (string) config('religious.integrations.user_model', 'App\\Models\\User');
    }
}
