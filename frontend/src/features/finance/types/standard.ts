/**
 * Tipe akuntansi standar: unit bisnis, dana, saldo awal, permohonan, aset tetap,
 * akrual, dan laporan.
 */

export interface BusinessUnit {
  code: string;
  name: string;
  parent_code: string | null;
  level: number;
  is_active: boolean;
  description?: string | null;
}

export interface ModuleOption {
  value: string;
  label: string;
}

export interface Fund {
  id: number;
  kode_dana: string;
  nama_dana: string;
  jenis: string;
  peruntukan: string | null;
  status: string;
  keterangan?: string | null;
  accounts_count?: number;
  contra_accounts_count?: number;
}

export interface OpeningBalance {
  id: number;
  account_id: number;
  jenis_saldo: 'debet' | 'kredit';
  saldo: string;
  posted: boolean;
  keterangan?: string | null;
  account?: { id: number; code: string; name: string; type: string } | null;
}

export interface OpeningSummary {
  total_debit: string;
  total_credit: string;
  difference: string;
  balanced: boolean;
  posted: boolean;
  accounts: number;
  by_root: { root: string; label: string; total_debit: string; total_credit: string }[];
}

export interface ReopenRequest {
  id: number;
  period_id: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  requested_by: number | null;
  decided_by: number | null;
  decided_at?: string | null;
  decision_note?: string | null;
  period?: { id: number; code: string; name: string; status: string } | null;
  requested_by_user?: { id: number; name: string } | null;
  decided_by_user?: { id: number; name: string } | null;
}

export interface VoidRequest {
  id: number;
  transaction_id: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  decided_at?: string | null;
  decision_note?: string | null;
  reversal_transaction_id?: number | null;
  transaction?: { id: number; transaction_number: string; transaction_date?: string; description?: string; total_debit?: string; status?: string } | null;
}

export interface TrialBalanceRow {
  account_id: number;
  code: string;
  name: string;
  root: string | null;
  root_label: string;
  opening: string;
  debit: string;
  credit: string;
  balance: string;
}

export interface TrialBalance {
  from: string;
  to: string;
  rows: TrialBalanceRow[];
  totals: { debit: string; credit: string };
  balanced: boolean;
}

export interface ProfitLossUnitRow {
  unit: string;
  revenue: string;
  expense: string;
  result: string;
}

export interface ProfitLossByUnit {
  from: string;
  to: string;
  units: ProfitLossUnitRow[];
  totals: { revenue: string; expense: string; result: string };
}

export interface FundStatementRow {
  kode_dana: string | null;
  nama_dana: string;
  jenis: string | null;
  jenis_label: string;
  peruntukan: string | null;
  status: string | null;
  opening: string;
  received: string;
  used: string;
  balance: string;
  cash_movement: string;
}

export interface FundStatement {
  from: string;
  to: string;
  funds: FundStatementRow[];
  totals: { received: string; used: string; balance: string };
}

export interface FixedAsset {
  id: number;
  kode_aset: string;
  nama: string;
  kategori: string | null;
  tanggal_perolehan: string;
  harga_perolehan: string;
  nilai_residu: string;
  umur_bulan: number;
  metode: 'garis_lurus' | 'saldo_menurun';
  akun_aset_id: number;
  akun_akumulasi_id: number;
  akun_beban_depresiasi_id: number;
  kode_unit: string | null;
  kode_dana: string | null;
  penyusutan_bulanan: string;
  akumulasi_penyusutan: string;
  nilai_buku: string;
  status: 'aktif' | 'dilepas' | 'dihapusbukukan';
}

export interface DepreciationRow {
  asset_id: number;
  kode_aset: string;
  nama?: string;
  metode?: string;
  nilai_buku?: string;
  nilai: string;
  transaction_id?: number;
}

export interface DepreciationPreview {
  periode: string;
  tanggal: string;
  rows: DepreciationRow[];
  total: string;
}

export interface Accrual {
  id: number;
  kode: string;
  tanggal: string;
  tanggal_balik: string;
  jenis: 'pendapatan' | 'beban';
  akun_akrual_id: number;
  akun_lawan_id: number;
  jumlah: string;
  deskripsi: string | null;
  status: 'draft' | 'posted' | 'reversed';
  kode_unit: string | null;
  kode_dana: string | null;
  akun_akrual?: { id: number; code: string; name: string } | null;
  akun_lawan?: { id: number; code: string; name: string } | null;
}

export interface AccountOption {
  id: number;
  code: string;
  name: string;
  type: string;
  is_active: boolean;
  children_count: number;
}
