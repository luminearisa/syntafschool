<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\CateringGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Catering (§46, §47, §80).
 *
 * Belum ada modul Catering di repositori ini, sehingga gateway dibuat
 * configurable: bila penyedia catering dipasang (lewat config atau tabel
 * catering yang benar-benar ada), permintaan diteruskan ke sana. Bila tidak,
 * modul menyimpan kebutuhan makan sebagai referensi dan menyatakan bahwa
 * produksi makanan belum tertaut — tidak mengarang transaksi makanan.
 */
class ConfigurableCateringGateway implements CateringGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        $configured = (string) config('religious.integrations.catering_provider', '');
        if ($configured !== '' && interface_exists($configured)) {
            return $this->delegate($configured);
        }

        return null;
    }

    protected function table(): ?string
    {
        return $this->firstTable(['catering_meal_plans', 'catering_requests', 'catering_orders']);
    }

    public function requestMealPlan(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['requestMealPlan', 'request', 'createPlan'] as $method) {
                    if (method_exists($provider, $method)) {
                        return (array) $provider->{$method}($payload);
                    }
                }
            } catch (\Throwable $e) {
                return ['requested' => false, 'error' => $e->getMessage()];
            }
        }

        $table = $this->table();
        if ($table !== null) {
            $columns = $this->columns($table);
            $data = array_intersect_key($payload, array_flip($columns));
            foreach (['created_at', 'updated_at'] as $stamp) {
                if (in_array($stamp, $columns, true)) {
                    $data[$stamp] = now();
                }
            }
            $id = DB::table($table)->insertGetId($data);

            return ['requested' => true, 'reference' => 'CAT-'.$id, 'source' => $table];
        }

        // Belum ada modul catering: simpan sebagai kebutuhan yang menunggu penyedia.
        return [
            'requested' => false,
            'pending_provider' => true,
            'reference' => $payload['reference'] ?? null,
            'reason' => $this->unavailableReason(),
        ];
    }

    public function updateHeadcount(string $reference, int $expectedParticipants): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'updateHeadcount')) {
                    return (array) $provider->updateHeadcount($reference, $expectedParticipants);
                }
            } catch (\Throwable $e) {
                return ['updated' => false, 'error' => $e->getMessage()];
            }
        }

        return ['updated' => false, 'pending_provider' => true, 'expected_participants' => $expectedParticipants];
    }

    public function mealPlanStatus(string $reference): ?array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'mealPlanStatus')) {
                    return (array) $provider->mealPlanStatus($reference);
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->table() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available()
            ? null
            : 'Modul Catering belum terpasang; kebutuhan makan dicatat sebagai referensi yang menunggu penyedia.';
    }
}
