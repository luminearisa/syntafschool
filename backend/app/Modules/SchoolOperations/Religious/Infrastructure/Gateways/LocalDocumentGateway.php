<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\DocumentGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen (§30). Berkas khutbah, sertifikat, dan lampiran kegiatan.
 */
class LocalDocumentGateway implements DocumentGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\DocumentProviderInterface')
            ?? $this->delegate('App\\Modules\\Asset\\Domain\\Contracts\\DocumentProviderInterface')
            ?? $this->delegate('App\\Modules\\Hrd\\Domain\\Contracts\\DocumentStorageProviderInterface');
    }

    protected function table(): ?string
    {
        return $this->firstTable(['documents', 'hr_documents']);
    }

    public function store(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'store')) {
                    $result = $provider->store((string) ($payload['path'] ?? ''), $payload);
                    $url = method_exists($provider, 'url')
                        ? $provider->url((string) ($payload['path'] ?? ''))
                        : null;

                    return array_merge(['stored' => true], (array) $result, ['url' => $url]);
                }
            } catch (\Throwable $e) {
                return ['stored' => false, 'error' => $e->getMessage()];
            }
        }

        $table = $this->table();
        if ($table === null) {
            return ['stored' => false, 'reason' => $this->unavailableReason()];
        }

        $columns = $this->columns($table);
        $data = array_intersect_key($payload, array_flip($columns));
        foreach (['created_at', 'updated_at'] as $stamp) {
            if (in_array($stamp, $columns, true)) {
                $data[$stamp] = now();
            }
        }
        $id = DB::table($table)->insertGetId($data);

        return ['stored' => true, 'id' => $id, 'source' => $table];
    }

    public function find(int $documentId): ?array
    {
        $table = $this->table();

        return $table ? $this->row($table, $documentId) : null;
    }

    public function versions(int $documentId): array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'versions')) {
                    return (array) $provider->versions($documentId);
                }
            } catch (\Throwable) {
            }
        }

        return [];
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->table() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul dokumen tidak tersedia pada instalasi ini.';
    }
}
