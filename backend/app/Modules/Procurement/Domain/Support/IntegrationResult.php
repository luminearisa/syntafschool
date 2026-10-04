<?php

namespace App\Modules\Procurement\Domain\Support;

/**
 * Hasil pemanggilan penyedia integrasi (Accounting, Inventory, Asset, Budget).
 *
 * Modul Procurement tidak pernah menebak apakah pemanggilan luar berhasil:
 * setiap provider mengembalikan objek ini sehingga status handoff, retry, dan
 * idempotensi dapat dicatat apa adanya di `proc_integrations`.
 */
final readonly class IntegrationResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public bool $ok,
        public ?string $reference = null,
        public ?string $externalId = null,
        public string $message = '',
        public array $data = [],
        public ?string $errorCode = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function success(?string $reference = null, ?string $externalId = null, string $message = 'OK', array $data = []): self
    {
        return new self(true, $reference, $externalId, $message, $data);
    }

    /** @param array<string, mixed> $data */
    public static function failure(string $message, ?string $errorCode = null, array $data = []): self
    {
        return new self(false, null, null, $message, $data, $errorCode);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'reference' => $this->reference,
            'external_id' => $this->externalId,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'data' => $this->data,
        ];
    }
}
