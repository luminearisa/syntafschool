/**
 * Bentuk data modul Procurement & Vendor, dicerminkan dari Model/Controller
 * backend (`app/Modules/Procurement`).
 *
 * Sengaja **ketat pada kolom yang menentukan keputusan** (status, nominal,
 * kuantitas, kunci idempotensi) dan **longgar pada ruas yang masih berevolusi**
 * (mis. `unknown` untuk payload integrasi). Layar tidak boleh menebak status,
 * tetapi juga tidak boleh gagal-ketik hanya karena satu ruas JSON berubah.
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

/** Opsi enum dari backend (`/procurement/metadata`). */
export type EnumOptions = Record<string, SelectOption[]>;

export interface MetaRecord {
  id: number;
  code?: string | null;
  name: string;
  [key: string]: unknown;
}

/* ── Vendor (§4–§7) ──────────────────────────────────────────────────────── */

export interface VendorContact {
  id: number;
  vendor_id: number;
  name: string;
  position?: string | null;
  email?: string | null;
  phone?: string | null;
  is_primary?: boolean;
}

export interface VendorAddress {
  id: number;
  vendor_id: number;
  label?: string | null;
  address: string;
  city?: string | null;
  province?: string | null;
  postal_code?: string | null;
  is_primary?: boolean;
}

export interface VendorBankAccount {
  id: number;
  vendor_id: number;
  bank_name: string;
  account_number: string;
  account_name: string;
  is_primary?: boolean;
}

export interface VendorDocument {
  id: number;
  vendor_id: number;
  type: string;
  name: string;
  number?: string | null;
  issued_date?: string | null;
  expiry_date?: string | null;
  status: string;
  file_path?: string | null;
  is_mandatory?: boolean;
  verified_at?: string | null;
  notes?: string | null;
}

export interface VendorPerformanceRecord {
  id: number;
  vendor_id: number;
  period: string;
  on_time_delivery?: number | string | null;
  quality_acceptance?: number | string | null;
  order_fulfillment?: number | string | null;
  invoice_accuracy?: number | string | null;
  responsiveness?: number | string | null;
  overall_score?: number | string | null;
  grade?: string | null;
}

export interface Vendor {
  id: number;
  code: string;
  legal_name: string;
  trading_name?: string | null;
  vendor_type?: string | null;
  entity_type?: string | null;
  status: string;
  classification?: string | null;
  tier?: string | null;
  risk_level?: string | null;
  npwp?: string | null;
  nib?: string | null;
  address?: string | null;
  city?: string | null;
  province?: string | null;
  country?: string | null;
  postal_code?: string | null;
  contact_person?: string | null;
  email?: string | null;
  phone?: string | null;
  website?: string | null;
  currency?: string | null;
  payment_terms?: string | null;
  tax_name?: string | null;
  tax_number?: string | null;
  tax_rate?: string | number | null;
  notes?: string | null;
  contacts?: VendorContact[];
  addresses?: VendorAddress[];
  bankAccounts?: VendorBankAccount[];
  documents?: VendorDocument[];
  categories?: MetaRecord[];
  performanceRecords?: VendorPerformanceRecord[];
  created_at?: string | null;
  updated_at?: string | null;
}

export interface VendorPerformanceScorecard {
  vendor_id?: number;
  period: string;
  components?: Record<string, number | string>;
  overall_score?: number | string;
  grade?: string;
  [key: string]: unknown;
}

/* ── Katalog (§8) ────────────────────────────────────────────────────────── */

export interface ProcurementCategory {
  id: number;
  code: string;
  name: string;
  item_type?: string | null;
  parent_id?: number | null;
  is_active?: boolean;
  description?: string | null;
}

export interface ProcurementItem {
  id: number;
  sku?: string | null;
  name: string;
  category_id?: number | null;
  item_type?: string | null;
  unit?: string | null;
  estimated_price?: string | number | null;
  is_asset?: boolean;
  is_active?: boolean;
  description?: string | null;
}

/* ── Purchase Request (§9–§11) ───────────────────────────────────────────── */

export interface PurchaseRequestItem {
  id: number;
  purchase_request_id: number;
  description: string;
  item_type?: string | null;
  quantity: string | number;
  unit?: string | null;
  estimated_unit_price?: string | number | null;
  estimated_total?: string | number | null;
  category_id?: number | null;
  item_id?: number | null;
  is_asset?: boolean;
  notes?: string | null;
}

export interface PurchaseRequestApproval {
  id: number;
  purchase_request_id: number;
  step?: number | null;
  role?: string | null;
  status: string;
  approver_id?: number | null;
  approver?: { id: number; name: string } | null;
  notes?: string | null;
  acted_at?: string | null;
}

export interface PurchaseRequest {
  id: number;
  number: string;
  requester_id: number;
  requester?: { id: number; name: string } | null;
  department_id?: number | null;
  required_date?: string | null;
  priority: string;
  status: string;
  method?: string | null;
  currency?: string | null;
  purpose?: string | null;
  estimated_total?: string | number | null;
  items?: PurchaseRequestItem[];
  approvals?: PurchaseRequestApproval[];
  created_at?: string | null;
}

export interface ProcurementEvent {
  id: number;
  number?: string | null;
  title: string;
  method?: string | null;
  status?: string | null;
  estimated_total?: string | number | null;
  purchase_request_ids?: number[];
  created_at?: string | null;
}

export interface TimelineEntry {
  at?: string | null;
  action: string;
  actor?: string | null;
  notes?: string | null;
  [key: string]: unknown;
}

/* ── RFQ, penawaran, evaluasi (§12–§20) ─────────────────────────────────── */

export interface RfqVendor {
  id: number;
  rfq_id: number;
  vendor_id: number;
  vendor?: { id: number; code: string; legal_name: string } | null;
  status?: string | null;
  invited_at?: string | null;
}

export interface RfqItem {
  id: number;
  rfq_id: number;
  purchase_request_item_id?: number | null;
  description: string;
  quantity: string | number;
  unit?: string | null;
}

export interface Rfq {
  id: number;
  number: string;
  title: string;
  event_id?: number | null;
  status: string;
  currency?: string | null;
  due_date?: string | null;
  issued_at?: string | null;
  closed_at?: string | null;
  items?: RfqItem[];
  vendors?: RfqVendor[];
  quotations?: Quotation[];
  created_at?: string | null;
}

export interface QuotationItem {
  id: number;
  quotation_id: number;
  rfq_item_id?: number | null;
  description: string;
  quantity: string | number;
  unit?: string | null;
  unit_price: string | number;
  total?: string | number | null;
}

export interface Quotation {
  id: number;
  number: string;
  rfq_id?: number | null;
  vendor_id: number;
  vendor?: { id: number; code: string; legal_name: string } | null;
  status: string;
  currency?: string | null;
  quotation_date?: string | null;
  valid_until?: string | null;
  delivery_days?: number | null;
  payment_terms?: string | null;
  subtotal?: string | number | null;
  tax_amount?: string | number | null;
  total?: string | number | null;
  items?: QuotationItem[];
  evaluations?: QuotationEvaluation[];
}

export interface EvaluationCriteria {
  id: number;
  rfq_id: number;
  code: string;
  name: string;
  weight: string | number;
}

export interface QuotationEvaluation {
  id: number;
  quotation_id: number;
  evaluator_id?: number | null;
  evaluator?: { id: number; name: string } | null;
  weighted_score?: string | number | null;
  recommendation?: string | null;
  status?: string | null;
  items?: { id: number; criteria_id: number; score: string | number; notes?: string | null }[];
}

export interface ComparisonRow {
  rfq_item_id: number;
  description: string;
  quantity: string | number;
  unit?: string | null;
  [key: string]: unknown;
}

export interface ComparisonMatrix {
  rfq_id: number;
  vendors: { vendor_id: number; vendor?: string | null; quotation_id?: number | null; total?: string | number | null }[];
  items?: ComparisonRow[];
  lowest_total?: string | number | null;
}

export interface VendorSelection {
  id: number;
  number: string;
  rfq_id?: number | null;
  rfq?: { id: number; number: string; title?: string | null } | null;
  selected_quotation_id: number;
  selected_vendor_id: number;
  selectedVendor?: { id: number; code: string; legal_name: string } | null;
  selectedQuotation?: Quotation | null;
  alternativeVendor?: { id: number; code: string; legal_name: string } | null;
  approvedBy?: { id: number; name: string } | null;
  rationale: string;
  recommendation?: string | null;
  status: string;
  approved_by?: number | null;
  approved_at?: string | null;
  created_at?: string | null;
}

/* ── Purchase Order (§21–§30) ────────────────────────────────────────────── */

export interface PurchaseOrderItem {
  id: number;
  purchase_order_id: number;
  description: string;
  item_type?: string | null;
  quantity: string | number;
  unit?: string | null;
  unit_price: string | number;
  total?: string | number | null;
  received_quantity?: string | number | null;
  is_asset?: boolean;
  category_id?: number | null;
}

export interface PurchaseOrderApproval {
  id: number;
  purchase_order_id: number;
  step?: number | null;
  role?: string | null;
  status: string;
  approver_id?: number | null;
  approver?: { id: number; name: string } | null;
  notes?: string | null;
  acted_at?: string | null;
}

export interface PurchaseOrderVersion {
  id: number;
  purchase_order_id: number;
  version: number;
  snapshot?: Record<string, unknown>;
  reason?: string | null;
  is_material?: boolean;
  created_by?: number | null;
  created_at?: string | null;
}

export interface PurchaseOrder {
  id: number;
  number: string;
  vendor_id: number;
  vendor?: { id: number; code: string; legal_name: string } | null;
  selection_id?: number | null;
  rfq_id?: number | null;
  status: string;
  po_date?: string | null;
  expected_delivery_date?: string | null;
  currency?: string | null;
  subtotal?: string | number | null;
  tax_amount?: string | number | null;
  discount_amount?: string | number | null;
  grand_total?: string | number | null;
  payment_terms?: string | null;
  notes?: string | null;
  requester_id?: number | null;
  items?: PurchaseOrderItem[];
  approvals?: PurchaseOrderApproval[];
  versions?: PurchaseOrderVersion[];
  confirmations?: VendorConfirmation[];
  goodsReceipts?: { id: number; number: string; receipt_date?: string | null; status: string }[];
  invoices?: { id: number; number: string; vendor_invoice_number: string; status: string; total?: string | number | null }[];
  created_at?: string | null;
}

export interface VendorConfirmation {
  id: number;
  purchase_order_id: number;
  status: string;
  notes?: string | null;
  confirmed_at?: string | null;
}

/* ── Penerimaan (§31–§36) ────────────────────────────────────────────────── */

export interface GoodsReceiptItem {
  id: number;
  goods_receipt_id: number;
  purchase_order_item_id?: number | null;
  description?: string | null;
  delivered_quantity: string | number;
  accepted_quantity: string | number;
  rejected_quantity?: string | number | null;
  notes?: string | null;
}

export interface GoodsReceipt {
  id: number;
  number: string;
  purchase_order_id: number;
  vendor_id: number;
  status: string;
  receipt_date?: string | null;
  received_by?: number | null;
  notes?: string | null;
  items?: GoodsReceiptItem[];
  inspections?: { id: number; status: string; notes?: string | null; inspected_at?: string | null }[];
}

export interface ServiceReceipt {
  id: number;
  number: string;
  purchase_order_id: number;
  vendor_id: number;
  status: string;
  receipt_date?: string | null;
  notes?: string | null;
  items?: { id: number; description: string; quantity: string | number; accepted_quantity?: string | number | null }[];
}

/* ── Invoice & matching (§37–§44) ────────────────────────────────────────── */

export interface VendorInvoiceItem {
  id: number;
  vendor_invoice_id: number;
  purchase_order_item_id?: number | null;
  description: string;
  quantity: string | number;
  unit?: string | null;
  unit_price: string | number;
  total?: string | number | null;
}

export interface InvoiceMatchResult {
  id: number;
  vendor_invoice_id: number;
  match_status: string;
  quantity_ok?: boolean;
  price_ok?: boolean;
  amount_ok?: boolean;
  differences?: Record<string, unknown>;
  checked_at?: string | null;
}

export interface VendorInvoice {
  id: number;
  number: string;
  vendor_invoice_number: string;
  vendor_id: number;
  vendor?: { id: number; code: string; legal_name: string } | null;
  purchase_order_id?: number | null;
  purchase_order?: { id: number; number: string } | null;
  status: string;
  invoice_date?: string | null;
  due_date?: string | null;
  currency?: string | null;
  subtotal?: string | number | null;
  tax_amount?: string | number | null;
  total?: string | number | null;
  items?: VendorInvoiceItem[];
  matchResults?: InvoiceMatchResult[];
  integrations?: ProcurementIntegration[];
  exceptions?: InvoiceException[];
  duplicateOf?: { id: number; number: string } | null;
  created_at?: string | null;
}

export interface ProcurementIntegration {
  id: number;
  subject_type: string;
  subject_id: number;
  provider: string;
  direction?: string | null;
  reference?: string | null;
  idempotency_key?: string | null;
  external_id?: string | null;
  status: string;
  payload?: unknown;
  response?: unknown;
  error_message?: string | null;
  retry_count?: number | null;
  sent_at?: string | null;
  responded_at?: string | null;
  created_at?: string | null;
}

export interface InvoiceException {
  id: number;
  vendor_invoice_id: number;
  invoice?: { id: number; number: string; vendor_invoice_number: string } | null;
  type: string;
  status: string;
  detail?: string | null;
  resolution?: string | null;
  detected_at?: string | null;
  resolved_at?: string | null;
}

export interface PaymentStatus {
  status: string;
  paid_amount?: string | number | null;
  outstanding?: string | number | null;
  due_date?: string | null;
  [key: string]: unknown;
}

/* ── Kontrak, anggaran, pengaturan ──────────────────────────────────────── */

export interface Contract {
  id: number;
  number: string;
  vendor_id: number;
  vendor?: { id: number; code: string; legal_name: string } | null;
  title?: string | null;
  status: string;
  start_date?: string | null;
  end_date?: string | null;
  value?: string | number | null;
  currency?: string | null;
  notes?: string | null;
}

export interface BudgetAvailability {
  budget_id?: number | null;
  code?: string | null;
  name?: string | null;
  allocated?: string | number | null;
  committed?: string | number | null;
  available?: string | number | null;
  requested?: string | number | null;
  is_sufficient?: boolean;
}

export interface ProcurementSetting {
  id: number;
  key: string;
  value: unknown;
  group?: string | null;
  description?: string | null;
}

/* ── Dasbor, laporan, kesehatan integrasi ───────────────────────────────── */

export interface ProcurementDashboard {
  [key: string]: unknown;
}

export interface IntegrationHealth {
  providers: Record<string, boolean>;
}

export interface ReportResult {
  type?: string;
  rows: Record<string, unknown>[];
  summary?: Record<string, unknown>;
  [key: string]: unknown;
}
