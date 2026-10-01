/**
 * Bentuk data modul Aset & Sarpras, dicerminkan dari Model/Resource backend.
 *
 * Catatan sengaja: tipe di sini **longgar pada kolom yang belum dibakukan**
 * (mis. `unknown` untuk `checklist`) dan **ketat pada kolom yang menentukan
 * keputusan** (status, izin, nominal). Tujuannya: layar tidak pernah menebak
 * status, tetapi tidak pula gagal-untuk-mengetik hanya karena satu ruas JSON
 * berubah bentuk di sisi server.
 */

export interface ListParams {
  page?: number;
  per_page?: number;
  search?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  [key: string]: string | number | boolean | undefined;
}

export interface SelectOption {
  value: string | number;
  label: string;
}

/* ── Katalog ─────────────────────────────────────────────────────────────── */

export interface AssetCategory {
  id: number;
  code: string;
  name: string;
  kind: 'ASSET' | 'INVENTORY' | 'EXPENSE' | string;
  parent_id?: number | null;
  depreciation_method?: string | null;
  useful_life_months?: number | null;
  capitalization_threshold?: string | null;
  inspection_frequency_days?: number | null;
  default_criticality?: string | null;
  is_consumable?: boolean;
  description?: string | null;
}

export interface AssetType {
  id: number;
  category_id: number;
  code: string;
  name: string;
  default_useful_life_months?: number | null;
  default_criticality?: string | null;
  description?: string | null;
}

export interface AssetLocation {
  id: number;
  code?: string | null;
  name: string;
  location_type: string;
  path?: string | null;
  level?: number | null;
  parent_location_id?: number | null;
  campus_id?: number | null;
  capacity?: number | null;
  area_m2?: string | null;
  floor_label?: string | null;
  function?: string | null;
  status: string;
  contact?: string | null;
  is_bookable?: boolean;
  academic_room_id?: number | null;
  description?: string | null;
}

export interface AssetLocationTreeNode extends AssetLocation {
  children: AssetLocationTreeNode[];
}

export interface AssetCustodian {
  id: number;
  name: string;
  custodian_type: string;
  user_id?: number | null;
  unit_id?: number | null;
  location_id?: number | null;
  position?: string | null;
  phone?: string | null;
  is_active?: boolean;
}

/* ── Aset ────────────────────────────────────────────────────────────────── */

export interface AssetFinancial {
  acquisition_cost: string;
  book_value: string;
  accumulated_depreciation: string;
  salvage_value?: string;
  acquisition_source?: string | null;
  capitalization_decision?: string | null;
  capitalization_decision_label?: string | null;
  purchase_date?: string | null;
  invoice_number?: string | null;
  vendor?: string | null;
  last_valuation_at?: string | null;
}

export interface Asset {
  id: number;
  asset_code: string;
  asset_number?: number | null;
  asset_tag?: string | null;
  barcode?: string | null;
  name: string;
  category?: AssetCategory | null;
  type?: AssetType | null;
  location?: AssetLocation | null;
  unit_id?: number | null;
  custodian_id?: number | null;
  custodian_label?: string | null;
  custodian_type?: string | null;
  status: string;
  status_label?: string;
  condition: string;
  condition_label?: string;
  criticality?: string | null;
  tag_status?: string | null;
  serial_number?: string | null;
  manufacturer?: string | null;
  brand?: string | null;
  model?: string | null;
  acquisition_source?: string | null;
  purchase_date?: string | null;
  received_date?: string | null;
  commission_date?: string | null;
  warranty?: { until?: string | null; months?: number | null } | null;
  life?: {
    useful_life_months?: number | null;
    expected_life_end?: string | null;
    last_inspection_at?: string | null;
    next_inspection_at?: string | null;
    last_maintenance_at?: string | null;
    next_maintenance_at?: string | null;
    loan_count?: number | null;
    maintenance_count?: number | null;
    downtime_days?: number | null;
  } | null;
  is_archived?: boolean;
  version?: number;
  description?: string | null;
  /** Hanya terkirim bila pemanggil memegang izin keuangan. */
  financial?: AssetFinancial | null;
  created_at?: string | null;
  updated_at?: string | null;
  /** Konteks pengakuan: mengapa barang ini aset, bukan beban/persediaan. */
  capitalization?: {
    decision?: string;
    decision_label?: string;
    threshold?: string | null;
    reason?: string | null;
  } | null;
}

export interface AssetTimelineEntry {
  at: string;
  action: string;
  label?: string;
  actor?: string | null;
  reason?: string | null;
  meta?: Record<string, unknown> | null;
}

export interface AssetLabel {
  asset_code: string;
  name: string;
  barcode?: string | null;
  qr_payload?: string;
  qr_url?: string | null;
  location?: string | null;
  condition?: string | null;
  status?: string | null;
  label_status?: string | null;
  print_count?: number | null;
}

export interface AssetImportPreview {
  id: number;
  type: string;
  status: string;
  total_rows: number;
  valid_rows: number;
  error_rows: number;
  errors: { row: number; column?: string; message: string }[];
  preview: Record<string, unknown>[];
}

/* ── Peminjaman & pemindahan ─────────────────────────────────────────────── */

export interface AssetLoanItem {
  id: number;
  asset_id: number;
  asset?: Asset | null;
  condition_out?: string | null;
  condition_in?: string | null;
  returned?: boolean;
  notes?: string | null;
}

export interface AssetLoan {
  id: number;
  loan_number: string;
  status: string;
  borrower_id?: number | null;
  borrower_type?: string | null;
  borrower?: { id: number; name: string } | null;
  requested_by?: { id: number; name: string } | null;
  purpose?: string | null;
  start_at?: string | null;
  due_at?: string | null;
  returned_at?: string | null;
  approved_at?: string | null;
  handed_over_at?: string | null;
  condition_out?: string | null;
  condition_in?: string | null;
  damage_report_id?: number | null;
  overdue_notified_at?: string | null;
  notes?: string | null;
  items?: AssetLoanItem[];
  items_count?: number;
  created_at?: string | null;
}

export interface AssetTransfer {
  id: number;
  transfer_number: string;
  type: string;
  status: string;
  reason?: string | null;
  from_location?: AssetLocation | null;
  to_location?: AssetLocation | null;
  from_custodian_id?: number | null;
  to_custodian_id?: number | null;
  requested_by?: { id: number; name: string } | null;
  approved_at?: string | null;
  handed_over_at?: string | null;
  received_at?: string | null;
  recipient_condition?: string | null;
  recipient_notes?: string | null;
  document_reference?: string | null;
  items?: { id: number; asset_id: number; asset?: Asset | null }[];
  items_count?: number;
  created_at?: string | null;
}

/* ── Pemeliharaan ────────────────────────────────────────────────────────── */

export interface MaintenanceRequest {
  id: number;
  request_number: string;
  source?: string | null;
  category?: string | null;
  priority: string;
  status: string;
  title: string;
  description?: string | null;
  asset?: Asset | null;
  facility?: { id: number; name: string } | null;
  requested_by?: { id: number; name: string } | null;
  reported_at?: string | null;
  sla_due_at?: string | null;
  triaged_at?: string | null;
  work_order_id?: number | null;
  damage_report_id?: number | null;
  cost_estimate?: string | null;
  completed_at?: string | null;
  verified_at?: string | null;
  notes?: string | null;
  work_order?: MaintenanceWorkOrder | null;
  created_at?: string | null;
}

export interface MaintenanceWorkOrder {
  id: number;
  work_order_number: string;
  request_id?: number | null;
  request?: MaintenanceRequest | null;
  asset?: Asset | null;
  type: string;
  status: string;
  priority?: string | null;
  title?: string | null;
  description?: string | null;
  diagnosis?: string | null;
  repair_plan?: string | null;
  assigned_to?: { id: number; name: string } | null;
  assigned_at?: string | null;
  started_at?: string | null;
  due_at?: string | null;
  completed_at?: string | null;
  verified_at?: string | null;
  labor_cost?: string | null;
  parts_cost?: string | null;
  external_cost?: string | null;
  transport_cost?: string | null;
  other_cost?: string | null;
  total_cost?: string | null;
  downtime_minutes?: number | null;
  finance_status?: string | null;
  notes?: string | null;
  tasks?: { id: number; title: string; status: string }[];
  created_at?: string | null;
}

export interface MaintenanceSchedule {
  id: number;
  title: string;
  frequency: string;
  interval_days?: number | null;
  checklist?: unknown;
  next_due_at?: string | null;
  last_run_at?: string | null;
  is_active?: boolean;
  asset?: Asset | null;
  facility?: { id: number; name: string } | null;
  last_work_order_id?: number | null;
  notes?: string | null;
}

export interface AssetInspection {
  id: number;
  inspection_number: string;
  inspection_type: string;
  status: string;
  asset?: Asset | null;
  facility?: { id: number; name: string } | null;
  scheduled_at?: string | null;
  performed_at?: string | null;
  performed_by?: { id: number; name: string } | null;
  result_summary?: string | null;
  score?: string | null;
  findings?: unknown;
  condition_found?: string | null;
  follow_up_required?: boolean;
  work_order_id?: number | null;
  results?: { id: number; code: string; label: string; result: string; value?: string | null; notes?: string | null }[];
  notes?: string | null;
}

export interface DamageReport {
  id: number;
  report_number: string;
  reported_at?: string | null;
  severity: string;
  status: string;
  damage_type?: string | null;
  description?: string | null;
  asset?: Asset | null;
  facility?: { id: number; name: string } | null;
  location?: AssetLocation | null;
  reported_by?: { id: number; name: string } | null;
  verified_at?: string | null;
  maintenance_request_id?: number | null;
  resolution?: string | null;
  notes?: string | null;
}

/* ── Fasilitas ───────────────────────────────────────────────────────────── */

export interface Facility {
  id: number;
  facility_code: string;
  name: string;
  type?: { id: number; name: string } | null;
  location?: AssetLocation | null;
  campus_id?: number | null;
  /** Backend mengirim objek terperinci; layar wajib memperlakukan keduanya. */
  capacity?: number | { total?: number | null; normal?: number | null; exam?: number | null; event?: number | null } | null;
  capacity_total?: number | null;
  capacity_normal?: number | null;
  capacity_exam?: number | null;
  capacity_event?: number | null;
  status: string;
  status_label?: string;
  availability?: string | null;
  condition?: string | null;
  safety_status?: string | null;
  maintenance_status?: string | null;
  manager_user_id?: number | null;
  responsible_unit_id?: number | null;
  booking?: { min_booking_minutes?: number | null; max_booking_minutes?: number | null; advance_booking_days?: number | null; requires_approval?: boolean } | null;
  min_booking_minutes?: number | null;
  max_booking_minutes?: number | null;
  advance_booking_days?: number | null;
  requires_approval?: boolean;
  allowed_roles?: string[] | null;
  operational_hours?: { day_of_week: number; open_time?: string | null; close_time?: string | null; is_closed?: boolean }[] | null;
  utilization_rate?: string | number | null;
  booking_count?: number | null;
  description?: string | null;
}

export interface FacilityAvailability {
  available: boolean;
  reasons: string[];
  facility?: Facility | null;
}

export interface FacilityBooking {
  id: number;
  booking_number: string;
  source?: string | null;
  status: string;
  title: string;
  purpose?: string | null;
  facility?: Facility | null;
  location?: AssetLocation | null;
  attendee_count?: number | null;
  start_at: string;
  end_at: string;
  requires_approval?: boolean;
  approved_at?: string | null;
  rejection_reason?: string | null;
  checked_in_at?: string | null;
  completed_at?: string | null;
  cancelled_at?: string | null;
  cancel_reason?: string | null;
  requested_by?: { id: number; name: string } | null;
  conflict_checked_at?: string | null;
  notes?: string | null;
}

/* ── Stok opname ─────────────────────────────────────────────────────────── */

export interface StockOpnameItem {
  id: number;
  opname_id: number;
  asset_id?: number | null;
  asset?: Asset | null;
  expected_location_id?: number | null;
  expected_custodian_id?: number | null;
  actual_location_id?: number | null;
  actual_custodian_id?: number | null;
  scanned_code?: string | null;
  scanned_at?: string | null;
  scanned_by?: number | null;
  scan_result: string;
  condition_found?: string | null;
  is_variance?: boolean;
  variance_decision?: string | null;
  adjustment_applied_at?: string | null;
  adjustment_notes?: string | null;
  notes?: string | null;
}

export interface StockOpnameVariance {
  id: number;
  opname_id: number;
  item_id?: number | null;
  asset_id?: number | null;
  asset?: Asset | null;
  item?: StockOpnameItem | null;
  variance_type: string;
  description?: string | null;
  decision: string;
  decided_at?: string | null;
  adjustment?: unknown;
  notes?: string | null;
}

export interface StockOpname {
  id: number;
  opname_number: string;
  title: string;
  status: string;
  scope?: Record<string, unknown> | null;
  method?: string | null;
  scheduled_for?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  verified_at?: string | null;
  approved_at?: string | null;
  expected_count?: number | null;
  found_count?: number | null;
  variance_count?: number | null;
  notes?: string | null;
  items?: StockOpnameItem[];
  variances?: StockOpnameVariance[];
}

export interface StockOpnameSummary {
  summary: {
    expected: number;
    found: number;
    missing: number;
    unidentified: number;
    extra: number;
    variance: number;
    pending_decisions: number;
    progress: number;
  };
  by_result: { scan_result: string; total: number }[];
}

/* ── Penghapusan & penggantian ───────────────────────────────────────────── */

export interface DisposalRequest {
  id: number;
  request_number: string;
  reason: string;
  method?: string | null;
  status: string;
  asset?: Asset | null;
  description?: string | null;
  condition_at_request?: string | null;
  book_value_at_request?: string | null;
  estimated_value?: string | null;
  expected_proceeds?: string | null;
  buyer_name?: string | null;
  donation_recipient?: string | null;
  approval_reference?: string | null;
  submitted_at?: string | null;
  approved_at?: string | null;
  executed_at?: string | null;
  retired_at?: string | null;
  finance_status?: string | null;
  rejection_reason?: string | null;
  requested_by?: { id: number; name: string } | null;
  notes?: string | null;
}

export interface AssetReplacement {
  id: number;
  asset?: Asset | null;
  recommendation: string;
  trigger_reasons?: string[] | null;
  age_ratio?: string | null;
  maintenance_cost_ratio?: string | null;
  downtime_days?: number | null;
  priority?: string | null;
  status: string;
  reviewed_at?: string | null;
  decision_notes?: string | null;
  procurement_reference?: string | null;
  generated_at?: string | null;
}

/* ── Tata kelola ─────────────────────────────────────────────────────────── */

export interface AssetAuditEntry {
  id: number;
  asset_id?: number | null;
  action: string;
  actor?: { id: number; name: string } | null;
  reason?: string | null;
  storable_type?: string | null;
  storable_id?: number | null;
  created_at: string;
}

export interface DashboardOverview {
  assets?: { total: number; by_status: { status: string; total: number }[]; by_condition: { condition: string; total: number }[] };
  value?: { acquisition_cost: string; book_value: string; accumulated_depreciation?: string };
  maintenance?: { open_requests: number; overdue_work_orders: number; cost_this_month?: string };
  loans?: { active: number; overdue: number };
  facilities?: { total: number; in_use?: number; bookings_this_week?: number };
  opname?: { open_sessions: number; pending_decisions: number };
  alerts?: { type: string; message: string; severity?: string }[];
  generated_at?: string;
}
