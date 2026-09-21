/**
 * Tipe sirkulasi, denda, reservasi, pengadaan, inventaris, laporan,
 * dan notifikasi Library (Fase 4 & Phase 5).
 */

import type { LibraryListParams } from './library';

export type LoanStatus = 'active' | 'returned' | 'overdue' | 'lost';

export interface LoanRenewal {
  id: number;
  previous_due_at: string | null;
  new_due_at: string | null;
}

export interface LibraryLoan {
  id: number;
  library_id: number | null;
  loan_code: string;
  member_id: number;
  item_id: number;
  bibliography_id: number | null;

  borrowed_at: string | null;
  due_at: string | null;
  returned_on: string | null;
  returned_at: string | null;
  renewed_count: number;
  status: LoanStatus;
  is_open: boolean;
  overdue_days: number;
  is_overdue: boolean;
  notes: string | null;

  member?: { id: number; member_number: string; name: string | null } | null;
  item?: { id: number; item_code: string; barcode: string | null; accession_number: string | null } | null;
  bibliography?: { id: number; title: string; slug: string; control_number: string } | null;
  renewals?: LoanRenewal[];
  fines?: { id: number; type: string; amount: number; outstanding: number; status: string }[];
  created_at?: string | null;
}

export interface LoanCheckoutPayload {
  member_id?: number | '';
  member_code?: string;
  item_id?: number | '';
  item_code?: string;
  notes?: string;
}

export interface CirculationLookupItem {
  id: number;
  item_code: string;
  accession_number: string | null;
  barcode: string | null;
  status: string;
  is_available: boolean;
  title: string | null;
  bibliography_id: number | null;
  location: string | null;
  open_loan: string | null;
}

export interface MemberQuickSummary {
  id: number;
  member_number: string;
  display_name: string | null;
  membership_type: string | null;
  status: string;
  valid_until: string | null;
  is_within_validity: boolean;
  open_loans: number;
  max_loans: number | null;
  overdue_loans: number;
  outstanding_fine: number;
  active_reservations: number;
  loans: {
    id: number;
    loan_code: string;
    item_code: string | null;
    title: string | null;
    due_at: string | null;
    overdue_days: number;
  }[];
}

export type CirculationLookupResult =
  | { type: 'item'; item: CirculationLookupItem }
  | { type: 'member'; member: MemberQuickSummary }
  | { type: 'none'; message?: string }
  | { type: null; message?: string };

export interface CirculationSummary {
  active_loans: number;
  overdue_loans: number;
  due_today: number;
  returned_this_month: number;
  borrowed_this_month: number;
  on_loan_items: number;
  reserved_items: number;
  new_items_this_month: number;
}

export interface CirculationStats {
  summary: CirculationSummary;
  trend: { month: string; borrowed: number; returned: number }[];
  popular: { bibliography_id: number | null; title: string | null; slug: string | null; loans: number }[];
}

/* ============================ DENDA ============================ */

export type FineType = 'overdue' | 'damage' | 'lost' | 'other';
export type FineStatus = 'unpaid' | 'partial' | 'paid' | 'waived';

export interface LibraryFine {
  id: number;
  library_id: number | null;
  member_id: number;
  loan_id: number | null;
  item_id: number | null;
  type: FineType;
  amount: number;
  paid_amount: number;
  waived_amount: number;
  outstanding_amount: number;
  status: FineStatus;
  assessed_at: string | null;
  overdue_days: number | null;
  waived_reason: string | null;
  waived_at: string | null;
  notes: string | null;
  member?: { id: number; member_number: string; name: string | null } | null;
  loan?: { id: number; loan_code: string; due_at: string | null; returned_on: string | null } | null;
  item?: { id: number; item_code: string; barcode: string | null } | null;
  payments?: {
    id: number;
    amount: number;
    paid_at: string | null;
    payment_method: string | null;
    reference: string | null;
    notes: string | null;
  }[];
  created_at?: string | null;
}

export interface FineSummary {
  total: number;
  unpaid: number;
  partial: number;
  paid: number;
  waived: number;
  outstanding_amount: number;
  collected_amount: number;
}

/* ============================ RESERVASI ============================ */

export type ReservationStatus = 'waiting' | 'available' | 'picked_up' | 'completed' | 'expired' | 'cancelled';

export interface LibraryReservation {
  id: number;
  library_id: number | null;
  reservation_code: string;
  member_id: number;
  bibliography_id: number;
  item_id: number | null;
  status: ReservationStatus;
  is_open: boolean;
  queue_position: number | null;
  reserved_at: string | null;
  available_at: string | null;
  expires_at: string | null;
  picked_up_at: string | null;
  cancelled_at: string | null;
  notes: string | null;
  member?: { id: number; member_number: string; name: string | null } | null;
  bibliography?: { id: number; title: string; slug: string; control_number: string } | null;
  item?: { id: number; item_code: string; barcode: string | null } | null;
  created_at?: string | null;
}

/* ============================ PENGADAAN ============================ */

export type AcquisitionStatus = 'draft' | 'submitted' | 'approved' | 'ordered' | 'received' | 'cancelled';

export interface AcquisitionItem {
  id: number;
  title: string;
  isbn: string | null;
  quantity: number;
  received_quantity: number;
  unit_price: number;
  subtotal: number;
  bibliography_id: number | null;
  collection_type_id: number | null;
  notes: string | null;
}

export interface LibraryAcquisition {
  id: number;
  library_id: number | null;
  code: string;
  supplier_id: number | null;
  supplier_name: string | null;
  status: AcquisitionStatus;
  allowed_transitions: AcquisitionStatus[];
  requested_at: string | null;
  expected_at: string | null;
  approved_at: string | null;
  received_at: string | null;
  total_amount: number;
  notes: string | null;
  items_count: number;
  supplier?: { id: number; name: string } | null;
  items?: AcquisitionItem[];
  created_at?: string | null;
}

export interface AcquisitionPayload {
  library_id?: number | '';
  supplier_id?: number | '';
  supplier_name?: string;
  requested_at?: string;
  expected_at?: string;
  notes?: string;
  items: {
    title: string;
    isbn?: string;
    quantity: number | '';
    unit_price?: number | '';
  }[];
}

/* ============================ INVENTARIS ============================ */

export type InventoryStatus = 'draft' | 'ongoing' | 'finalized' | 'cancelled';
export type InventoryResult = 'found' | 'missing' | 'unexpected' | 'damaged';

export interface LibraryInventorySession {
  id: number;
  library_id: number | null;
  code: string;
  name: string;
  status: InventoryStatus;
  location_id: number | null;
  started_at: string | null;
  finalized_at: string | null;
  expected_count: number;
  found_count: number;
  missing_count: number;
  unexpected_count: number;
  damaged_count: number;
  notes: string | null;
  location?: { id: number; name: string } | null;
  lines_count?: number;
}

export interface InventorySummary {
  session: {
    id: number;
    code: string;
    name: string;
    status: InventoryStatus;
    started_at: string | null;
    finalized_at: string | null;
  };
  expected: number;
  found: number;
  missing: number;
  unexpected: number;
  damaged: number;
  missing_items: { item_code: string | null; barcode: string | null; location: string | null }[];
  damaged_items: { item_code: string | null; barcode: string | null; location: string | null }[];
  unexpected_codes: { scanned_code: string | null; notes: string | null }[];
}

export interface InventoryReport {
  sessions: {
    id: number;
    code: string;
    name: string;
    started_at: string | null;
    finalized_at: string | null;
    expected: number;
    found: number;
    missing: number;
    unexpected: number;
    damaged: number;
  }[];
  totals: {
    sessions: number;
    expected: number;
    found: number;
    missing: number;
    unexpected: number;
    damaged: number;
  };
}

/* ============================ LAPORAN ============================ */

export interface GroupedCount {
  label: string;
  total: number;
}

export interface CollectionReport {
  totals: { titles: number; titles_active: number; copies: number; copies_available: number };
  by_category: GroupedCount[];
  by_collection_type: GroupedCount[];
  by_location: GroupedCount[];
  by_year: { year: number; total: number }[];
  by_status: Record<string, number>;
}

export interface CirculationReport {
  borrowed: number;
  returned: number;
  active: number;
  overdue: number;
  renewals: number;
  by_month: { month: string; borrowed: number; returned: number }[];
  popular: { title: string | null; loans: number }[];
  active_members: { member_number: string | null; name: string | null; loans: number }[];
}

export interface MemberReport {
  total: number;
  active: number;
  expired: number;
  pending: number;
  suspended: number;
  by_type: GroupedCount[];
  by_linked_type: Record<string, number>;
  summary: {
    total: number;
    active: number;
    expired: number;
    pending: number;
    suspended: number;
    due_expired: number;
  };
}

export interface FineReport {
  summary: FineSummary;
  assessed: number;
  assessed_amount: number;
  paid_amount: number;
  waived_amount: number;
  outstanding_amount: number;
  by_type: { type: string; total: number; amount: number }[];
}

export interface AcquisitionReport {
  total_requests: number;
  by_status: Record<string, number>;
  total_amount: number;
  received_items: number;
  by_supplier: { label: string; total: number; amount: number }[];
}

export interface InventoryReportBundle {
  sessions: InventoryReport;
  current: { lost: number; damaged: number; withdrawn: number; available: number; on_loan: number };
}

/** Jenis ekspor CSV yang didukung backend. */
export type ExportType =
  | 'catalog'
  | 'items'
  | 'members'
  | 'loans'
  | 'overdue'
  | 'fines'
  | 'reservations'
  | 'acquisitions';

export type ImportType = 'catalog' | 'items' | 'members';

export interface ImportRow {
  row: number;
  data: Record<string, string>;
  errors: string[];
  is_duplicate: boolean;
}

export interface ImportPreview {
  total: number;
  valid: number;
  invalid: number;
  duplicate: number;
  rows: ImportRow[];
}

/* ============================ NOTIFIKASI ============================ */

export interface LibraryNotification {
  id: string;
  type: string;
  data: {
    type: string;
    title: string;
    body: string;
    loan_code?: string;
    reservation_code?: string;
    member_number?: string;
    fine_id?: number;
    due_at?: string;
    expires_at?: string;
    overdue_days?: number;
    amount?: number;
  };
  read_at: string | null;
  created_at: string | null;
}

export type { LibraryListParams };