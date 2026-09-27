<?php

namespace App\Http\Resources;

use App\Models\FinanceTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transfer internal (§42, §43). source/destination direpresentasikan sebagai
 * type + id; nama akun diambil dari relasi kondisional sesuai tipenya.
 */
class FinanceTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'transfer_number' => $this->transfer_number,
            'type' => $this->type,
            'source_type' => $this->source_type,
            'source_id' => (int) $this->source_id,
            'source_name' => $this->sideName($this->source_type, $this->sourceCashAccount, $this->sourceBankAccount),
            'destination_type' => $this->destination_type,
            'destination_id' => (int) $this->destination_id,
            'destination_name' => $this->sideName($this->destination_type, $this->destinationCashAccount, $this->destinationBankAccount),
            'amount' => (float) $this->amount,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            // Matriks approval §16: aturan yang berlaku saat transfer ini diajukan,
            // plus siapa saja yang sudah menyetujuinya.
            'requires_secondary_approval' => (bool) $this->requires_secondary_approval,
            'awaits_secondary_approval' => $this->resource->awaitsSecondaryApproval(),
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'secondary_approved_by' => $this->secondary_approved_by !== null ? (int) $this->secondary_approved_by : null,
            'secondary_approved_at' => $this->secondary_approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->cancelled_by !== null ? (int) $this->cancelled_by : null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'finance_transaction_id' => $this->finance_transaction_id !== null ? (int) $this->finance_transaction_id : null,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function sideName(?string $type, mixed $cash, mixed $bank): ?string
    {
        if ($type === FinanceTransfer::SIDE_BANK) {
            return $bank?->name;
        }

        return $cash?->name;
    }
}
