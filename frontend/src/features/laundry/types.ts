/** Tipe data modul Laundry Management (School Operations). */

export interface ApiEnvelope<T> {
  data: T;
}

export interface PagedMeta {
  total: number;
  page: number;
  per_page?: number;
}

export interface PagedResponse<T> {
  data: T[];
  meta: PagedMeta;
}

/* ── Katalog ─────────────────────────────────────────────────────────── */

export interface LaundryService {
  id: number;
  code: string;
  name: string;
  description?: string | null;
  service_type?: string | null;
  pricing_model: 'WEIGHT' | 'PIECE' | 'SERVICE' | string;
  unit?: string | null;
  processing_hours?: number | null;
  is_active: boolean;
  sort_order?: number;
}

export interface LaundryPricingRule {
  id: number;
  laundry_service_id: number | null;
  pricing_model: string;
  item_type?: string | null;
  unit_price?: number | null;
  minimum_quantity?: number | null;
  minimum_charge?: number | null;
  fee_type?: string | null;
  fee_amount?: number | null;
  fee_percent?: number | null;
  effective_from?: string | null;
  effective_until?: string | null;
  priority?: number;
  is_active: boolean;
  service?: { id: number; code: string; name: string } | null;
}

export interface LaundryMachine {
  id: number;
  code: string;
  name: string;
  machine_type: string;
  capacity_kg?: number | null;
  location?: string | null;
  status: string;
  asset_id?: number | null;
  maintenance_state?: string | null;
  notes?: string | null;
}

/* ── Policy & jatah ──────────────────────────────────────────────────── */

export interface LaundryPolicy {
  id: number;
  code: string;
  name: string;
  description?: string | null;
  is_active: boolean;
  versions?: LaundryPolicyVersion[];
}

export interface LaundryPolicyVersion {
  id: number;
  laundry_policy_id: number;
  version: number;
  quota_type: string;
  quota_amount: number;
  period_type: string;
  custom_period_start?: string | null;
  custom_period_end?: string | null;
  carry_over_mode: string;
  carry_over_limit?: number | null;
  max_free_weight_per_order?: number | null;
  school_id?: number | null;
  dormitory_id?: number | null;
  education_level_id?: number | null;
  student_category?: string | null;
  academic_year_id?: number | null;
  effective_from: string;
  effective_until?: string | null;
  priority?: number;
  status: string;
  published_at?: string | null;
}

export interface EntitlementState {
  entitlement_id: number | null;
  has_policy: boolean;
  quota_type: string | null;
  period: { type: string; key: string; start: string; end: string } | null;
  allocated: number;
  carry_in: number;
  adjusted: number;
  used: number;
  remaining: number;
  status?: string;
}

export interface EntitlementRow extends EntitlementState {
  student_id?: number;
  student_name?: string | null;
  nis?: string | null;
}

export interface EntitlementTransaction {
  id: number;
  type: string;
  amount: number;
  balance_after: number;
  period_key?: string | null;
  reference_type?: string | null;
  reference_id?: number | null;
  reason?: string | null;
  occurred_at?: string | null;
}

export interface StudentStatement {
  student: { id: number; name?: string | null; nis?: string | null };
  period: string;
  entitlement: {
    quota_type: string;
    allocated: number;
    carry_in: number;
    adjusted: number;
    used: number;
    remaining: number;
  } | null;
  transactions: Array<{
    date?: string | null;
    order_number: string;
    net_weight?: number | null;
    free: number;
    paid: number;
    charge: number;
    wallet_ref?: string | null;
    wallet_before?: number | null;
    wallet_after?: number | null;
    status: string;
    payment_status: string;
  }>;
  totals: { free_quantity: number; paid_quantity: number; paid_amount: number; remaining_quota: number };
  wallet?: WalletAccount | null;
}

/* ── Order ───────────────────────────────────────────────────────────── */

export interface OrderSummary {
  id: number;
  order_number: string;
  student_id: number;
  service?: string | null;
  status: string;
  status_group: string;
  order_date?: string | null;
  net_weight?: number | null;
  item_count: number;
  free_quantity: number;
  paid_quantity: number;
  total_amount: number;
  payment_status: string;
  due_at?: string | null;
  student_name?: string | null;
  nis?: string | null;
}

export interface OrderItem {
  id: number;
  item_type: string;
  quantity: number;
  color?: string | null;
  condition?: string | null;
  unit_price?: number | null;
  tag_code?: string | null;
  notes?: string | null;
}

export interface OrderAllocation {
  type: string;
  quantity: number;
  amount: number;
  entitlement_transaction_id?: number | null;
}

export interface OrderDetail extends OrderSummary {
  student_nis?: string | null;
  dormitory_id?: number | null;
  bag_code?: string | null;
  gross_weight?: number | null;
  bag_weight?: number | null;
  quota_type?: string | null;
  discount_amount: number;
  wallet_transaction_number?: string | null;
  pickup_method?: string | null;
  notes?: string | null;
  received_at?: string | null;
  weighed_at?: string | null;
  completed_at?: string | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  pricing_snapshot?: Record<string, unknown> | null;
  items: OrderItem[];
  allocations: OrderAllocation[];
  labels: Array<{ label_number: string; qr_payload: string }>;
}

export interface OrderTimeline {
  order: OrderDetail;
  status_logs: Array<{ from?: string | null; to: string; actor?: string | null; reason?: string | null; reference?: string | null; at?: string | null }>;
  processing_steps: Array<{ step: string; status: string; machine_id?: number | null; operator_id?: number | null; started_at?: string | null; ended_at?: string | null; notes?: string | null }>;
  payments: Array<{ number: string; amount: number; method: string; status: string; wallet_transaction?: string | null; balance_before?: number | null; balance_after?: number | null; paid_at?: string | null }>;
  refunds: Array<{ number: string; amount: number; status: string; reason?: string | null; wallet_transaction?: string | null }>;
  quality_checks: Array<{ result: string; checklist?: Record<string, boolean> | null; notes?: string | null; at?: string | null }>;
  incidents: Array<{ number: string; type: string; status: string; description?: string | null }>;
  handover: {
    pickups: Array<{ receiver: string; method: string; at?: string | null }>;
    deliveries: Array<{ receiver: string; method: string; dormitory_id?: number | null; at?: string | null }>;
  };
}

export interface QuotePreview {
  quote: {
    pricing_model: string;
    currency: string;
    base_gross: number;
    extras_total: number;
    total_gross: number;
    minimum_applied: boolean;
    quota_type?: string | null;
    free_quantity: number;
    paid_quantity: number;
    paid_amount: number;
    lines: Array<{ kind: string; description?: string; quantity?: number; unit_price?: number; amount?: number; rule_id?: number | null; item_type?: string | null; fee_type?: string | null }>;
    item_unit_prices?: Record<string, number>;
    snapshot?: Record<string, unknown>;
  };
  entitlement: EntitlementState;
  wallet?: { balance: number; after_payment: number; sufficient: boolean } | null;
}

export interface PaymentPreview {
  order_number: string;
  net_weight?: number | null;
  quota_type?: string | null;
  free_quantity: number;
  paid_quantity: number;
  total_gross: number;
  discount: number;
  payable: number;
  wallet?: { number: string; balance: number; status: string; after_payment?: number | null; sufficient: boolean } | null;
  payment_status: string;
}

export interface WalletAccount {
  wallet_id: number;
  number: string;
  balance: number;
  status: string;
}

export interface IdentifyResult {
  identity: Record<string, unknown> & { type?: string; student_id?: number | null; bag_id?: number | null };
  student?: Record<string, unknown> | null;
  quota?: EntitlementState | null;
  wallet?: WalletAccount | null;
  open_orders: OrderSummary[];
  bag?: LaundryBag | null;
}

/* ── Pemrosesan ──────────────────────────────────────────────────────── */

export type KanbanColumn = 'WAITING' | 'WASHING' | 'DRYING' | 'IRONING' | 'FOLDING' | 'QUALITY_CHECK' | 'PACKAGING' | 'READY';

export interface KanbanCard {
  id: number;
  order_number: string;
  student_name?: string | null;
  nis?: string | null;
  service?: string | null;
  net_weight?: number | null;
  payment_status: string;
  due_at?: string | null;
  delayed: boolean;
}

export type KanbanBoard = Record<KanbanColumn, KanbanCard[]>;

export interface LaundryBatch {
  id: number;
  batch_number: string;
  stage: string;
  laundry_machine_id?: number | null;
  operator_id?: number | null;
  status: string;
  started_at?: string | null;
  completed_at?: string | null;
  total_weight?: number;
  total_orders?: number;
  program?: string | null;
  temperature_c?: number | null;
  detergent?: string | null;
  drying_method?: string | null;
  notes?: string | null;
  machine?: { id: number; code: string; name: string } | null;
  orders?: Array<{ id: number; order_number: string; net_weight?: number | null; status?: string }> | null;
}

/* ── Insiden & tas ───────────────────────────────────────────────────── */

export interface LaundryIncident {
  id: number;
  incident_number: string;
  laundry_order_id: number;
  laundry_order_item_id?: number | null;
  incident_type: string;
  description: string;
  evidence_path?: string | null;
  responsible_operator_id?: number | null;
  resolution?: string | null;
  compensation_type?: string | null;
  compensation_amount?: number | null;
  compensation_reference?: string | null;
  status: string;
  approved_by?: number | null;
  approved_at?: string | null;
  created_at?: string | null;
  order?: { id: number; order_number: string; student_id?: number } | null;
  item?: { id: number; item_type: string; quantity: number } | null;
}

export interface LaundryBag {
  id: number;
  bag_code: string;
  rfid_uid?: string | null;
  qr_code?: string | null;
  bag_type?: string | null;
  capacity_kg?: number | null;
  tare_weight?: number | null;
  status: string;
  notes?: string | null;
  assignments?: Array<{ id: number; student_id: number; status: string; assigned_at?: string | null }> | null;
}

/* ── Dashboard & laporan ─────────────────────────────────────────────── */

export interface LaundryDashboard {
  date: string;
  today: { orders: number; received: number; weight_kg: number; payment_required: number; completed: number; cancelled: number };
  pipeline: { processing: number; washing: number; drying: number; ironing: number; folding: number; quality_check: number; packaged: number; ready: number; queued: number; delivered: number };
  incidents_open: number;
  delayed: number;
}

export interface DailyOperations {
  date: string;
  total_orders: number;
  total_kg: number;
  free_kg: number;
  paid_kg: number;
  paid_revenue: number;
  wallet_transactions: number;
  completed: number;
  pending: number;
  incidents: number;
}

export interface FreeVsPaid {
  period: { from?: string | null; to?: string | null };
  free: { total_orders: number; total_kg: number; estimated_value: number };
  paid: { total_orders: number; total_kg: number; revenue: number };
}

export interface QuotaReportRow {
  student_id: number;
  student_name?: string | null;
  nis?: string | null;
  class_name?: string | null;
  level_name?: string | null;
  period_key: string;
  quota_type: string;
  allocated: number;
  carry_in: number;
  adjusted: number;
  used: number;
  remaining: number;
  expired: boolean;
  status: string;
}

export interface WalletReportRow {
  payment_number: string;
  order_number?: string | null;
  student_id?: number | null;
  amount: number;
  method: string;
  status: string;
  wallet_transaction?: string | null;
  balance_before?: number | null;
  balance_after?: number | null;
  paid_at?: string | null;
}

export interface OperationalReport {
  period: { from?: string | null; to?: string | null };
  average_processing_hours?: number | null;
  orders_per_operator: Record<string, number>;
  kg_per_machine: Record<string, number>;
  delayed_orders: number;
  qc_failures: number;
  lost_items: number;
  damaged_items: number;
}

export interface AuditLogRow {
  id: number;
  actor_id?: number | null;
  action: string;
  subject_type: string;
  subject_id?: number | null;
  before?: Record<string, unknown> | null;
  after?: Record<string, unknown> | null;
  reason?: string | null;
  reference?: string | null;
  created_at?: string | null;
  actor?: { id: number; name: string } | null;
}

/* ── Portal ──────────────────────────────────────────────────────────── */

export interface PortalContext {
  student?: Record<string, unknown> | null;
  quota: EntitlementState;
  wallet?: WalletAccount | null;
  paid_total: number;
  recent_orders: OrderSummary[];
}
