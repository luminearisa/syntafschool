<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\CertificateGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Sertifikat (§57): penyelesaian kajian, program Ramadan, dan kompetisi.
 */
class ActivityCertificateGateway implements CertificateGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\CertificateProviderInterface');
    }

    public function issue(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'render')) {
                    $result = (array) $provider->render($payload);

                    return array_merge(['issued' => true], $result);
                }
            } catch (\Throwable $e) {
                return ['issued' => false, 'error' => $e->getMessage()];
            }
        }

        // Tanpa penyedia sertifikat, modul tetap mencatat niat penerbitan
        // pada `religious_certificates` dengan status DRAFT.
        return [
            'issued' => false,
            'pending_provider' => true,
            'reason' => $this->unavailableReason(),
        ];
    }

    public function status(string $reference): ?array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'status')) {
                    return (array) $provider->status($reference);
                }
            } catch (\Throwable) {
            }
        }

        $table = $this->firstTable(['religious_certificates']);
        if ($table === null) {
            return null;
        }

        $row = DB::table($table)->where('code', $reference)->first();

        return $row ? (array) $row : null;
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available()
            ? null
            : 'Penyedia sertifikat belum terpasang; penerbitan dicatat sebagai draf di modul ini.';
    }
}
