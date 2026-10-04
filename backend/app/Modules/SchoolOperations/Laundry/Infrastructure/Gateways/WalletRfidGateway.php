<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Models\WalletRfidCard;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\RfidProviderInterface;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBag;
use App\Modules\SchoolOperations\Laundry\Models\LaundryLabel;
use Illuminate\Support\Facades\DB;

/**
 * Resolusi identitas RFID/QR (§17, §70).
 *
 * Urutan resolusi: (1) kartu RFID wallet → santri + wallet; (2) laundry bag
 * (rfid_uid/qr_code/bag_code) → santri pemegang aktif; (3) label order
 * (label_number/qr payload) → order. RFID hanya credential — saldo tetap di
 * Wallet module.
 */
class WalletRfidGateway implements RfidProviderInterface
{
    use GatewaySupport;

    public function resolve(string $uid): ?array
    {
        $uid = trim($uid);
        if ($uid === '') {
            return null;
        }

        if ($card = $this->resolveWalletCard($uid)) {
            return $card;
        }

        if ($bag = $this->resolveBag($uid)) {
            return $bag;
        }

        return $this->resolveLabel($uid);
    }

    public function available(): bool
    {
        return $this->has('wallet_rfid_cards') || $this->has('laundry_bags');
    }

    private function resolveWalletCard(string $uid): ?array
    {
        if (! $this->has('wallet_rfid_cards')) {
            return null;
        }

        $card = WalletRfidCard::query()
            ->where('uid', $uid)
            ->whereIn('status', [WalletRfidCard::STATUS_ACTIVE])
            ->first();

        if (! $card) {
            return null;
        }

        $wallet = DB::table('wallets')->where('id', $card->wallet_id)->first();
        $studentId = $wallet?->student_id ? (int) $wallet->student_id : null;

        return [
            'type' => 'student',
            'student_id' => $studentId,
            'wallet_id' => (int) $card->wallet_id,
            'rfid_uid' => (string) $card->uid,
            'source' => 'wallet_rfid_cards',
        ];
    }

    private function resolveBag(string $uid): ?array
    {
        if (! $this->has('laundry_bags')) {
            return null;
        }

        $bag = LaundryBag::query()
            ->where(fn ($q) => $q->where('rfid_uid', $uid)
                ->orWhere('qr_code', $uid)
                ->orWhere('bag_code', $uid))
            ->first();

        if (! $bag) {
            return null;
        }

        $assignment = DB::table('laundry_bag_assignments')
            ->where('laundry_bag_id', $bag->id)
            ->where('status', 'ACTIVE')
            ->orderByDesc('id')
            ->first();

        return [
            'type' => 'bag',
            'bag_id' => (int) $bag->id,
            'bag_code' => (string) $bag->bag_code,
            'student_id' => $assignment ? (int) $assignment->student_id : null,
            'source' => 'laundry_bags',
        ];
    }

    private function resolveLabel(string $uid): ?array
    {
        if (! $this->has('laundry_labels')) {
            return null;
        }

        $label = LaundryLabel::query()
            ->where('label_number', $uid)
            ->orWhere('qr_payload', $uid)
            ->first();

        if (! $label) {
            return null;
        }

        $order = DB::table('laundry_orders')->where('id', $label->laundry_order_id)->first();

        return [
            'type' => 'label',
            'order_id' => (int) $label->laundry_order_id,
            'order_number' => $order->order_number ?? null,
            'student_id' => $order ? (int) $order->student_id : null,
            'source' => 'laundry_labels',
        ];
    }
}
