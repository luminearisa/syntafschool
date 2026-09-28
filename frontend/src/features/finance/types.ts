import type { PaginatedResponse } from '@/types';

export interface FinanceBillingItem {
  id?: number;
  fee_type_id?: number | null;
  description: string;
  amount: number;
  quantity: number;
  total?: number;
}

export interface FinanceBilling {
  id: number;
  billing_number: string;
  customer_type: string;
  customer_id: number;
  student_id: number | null;
  student_name: string | null;
  source: string;
  due_date: string | null;
  subtotal: number;
  total: number;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  status_label: string;
  invoice_id: number | null;
  invoice_number: string | null;
  items_count?: number;
  notes: string | null;
  items?: FinanceBillingItem[];
}

export interface FinanceInvoiceItem {
  id: number;
  description: string;
  quantity: number;
  unit_price: number;
  total: number;
  account_code?: string | null;
  account_name?: string | null;
}

export interface FinanceInvoice {
  id: number;
  invoice_number: string;
  billing_id: number | null;
  billing_number: string | null;
  student_id: number | null;
  student_name: string | null;
  issue_date: string | null;
  due_date: string | null;
  subtotal: number;
  discount_amount: number;
  tax: number;
  total: number;
  paid_amount: number;
  written_off_amount: number;
  remaining_amount: number;
  status: string;
  status_label: string;
  receivable_status: string;
  is_overdue: boolean;
  notes: string | null;
  items?: FinanceInvoiceItem[];
}

export interface FinancePayment {
  id: number;
  payment_number: string;
  invoice_id: number | null;
  invoice_number: string | null;
  billing_id: number | null;
  student_id: number | null;
  student_name: string | null;
  amount: number;
  method: string;
  method_label: string;
  status: string;
  status_label: string;
  paid_at: string | null;
  reference_number: string | null;
  cash_account_id: number | null;
  cash_account_name: string | null;
  bank_account_id: number | null;
  bank_account_name: string | null;
  verified_by_name: string | null;
  reject_reason: string | null;
  notes: string | null;
}

export interface FinanceCashAccountOption {
  id: number;
  code: string;
  name: string;
  current_balance: number;
  is_active: boolean;
}

export interface FinanceBankAccountOption {
  id: number;
  code: string;
  name: string;
  account_number: string;
  bank_name: string | null;
  current_balance: number;
  is_active: boolean;
}

export interface FinancePage<T> {
  data: T[];
  meta?: PaginatedResponse<T>['meta'];
}
