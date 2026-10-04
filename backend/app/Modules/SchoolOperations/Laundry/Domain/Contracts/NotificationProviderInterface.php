<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/** Notifikasi wali/santri (§45, §46, §74) melalui penyedia yang ada. */
interface NotificationProviderInterface
{
    /**
     * @param  list<int>  $recipientUserIds
     * @param  array{type:string, title:string, body:string, event?:string|null, payload?:array}  $payload
     * @return array{sent:int, provider?:string, skipped?:bool, reason?:string}
     */
    public function send(array $recipientUserIds, array $payload): array;

    public function available(): bool;
}
