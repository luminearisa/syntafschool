<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\VendorGatewayInterface;

class DatabaseVendorGateway implements VendorGatewayInterface
{
    use GatewaySupport;

    public function find(int $vendorId): ?array
    {
        foreach (['finance_vendors', 'vendors'] as $table) {
            if (! $this->has($table)) {
                continue;
            }
            $row = $this->row($table, $vendorId);
            if ($row) {
                return [
                    'id' => (int) $row['id'],
                    'name' => $row['name'] ?? null,
                    'code' => $row['code'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'status' => $row['status'] ?? null,
                    'source' => $table,
                ];
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->has('finance_vendors') || $this->has('vendors');
    }
}
