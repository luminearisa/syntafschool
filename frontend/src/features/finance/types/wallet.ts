export interface WalletAccount {
  id: number;
  number: string;
  status: 'active' | 'blocked' | 'closed' | string;
  status_label: string;
  is_active: boolean;
  can_transact: boolean;
  owner_type: string | null;
  owner_type_label: string | null;
  owner_id: number | null;
  owner_name: string;
  owner_identifier: string | null;
  balance: number;
  held_balance: number;
  available_balance: number;
  rfid_count?: number;
}

export interface WalletTopUp {
  id: number;
  number: string;
  wallet_id: number;
  wallet_number: string | null;
  owner_name: string;
  owner_type: string | null;
  owner_identifier: string | null;
  amount: number;
  method: string;
  method_label: string;
  status: string;
  payment_status: string;
  can_verify: boolean;
  can_reject: boolean;
  bank_name: string | null;
  transferred_on: string | null;
  rejection_reason: string | null;
}

export interface WalletLedgerEntry {
  id: number;
  wallet_id: number;
  transaction_number: string;
  type: string;
  type_label: string;
  direction: 'credit' | 'debit' | string;
  amount: number;
  signed_amount: number;
  balance_before: number;
  balance_after: number;
  status: string;
  description: string | null;
  posted_at: string | null;
}
