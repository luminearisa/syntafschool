/**
 * Tipe kontrak API modul Library / Perpustakaan (Step 1 — Fondasi).
 *
 * Nilai status/tipe mencerminkan konstanta model di backend.
 */

export interface LibraryListParams {
  page?: number;
  per_page?: number;
  search?: string;
  all?: boolean;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  filter?: Record<string, string | number | boolean>;
}

/* ============================ PERPUSTAKAAN ============================ */

export type LibraryStatus = 'draft' | 'active' | 'inactive' | 'archived';

export interface Library {
  id: number;
  school_id: number | null;
  code: string;
  name: string;
  description: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  timezone: string;
  logo_path: string | null;
  status: LibraryStatus;
  is_active: boolean;
  allowed_transitions: LibraryStatus[];

  locations_count: number;
  operating_hours_count: number;
  closures_count: number;
  numbering_configs_count: number;

  school?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
  deleted_at?: string | null;
}

export interface LibraryPayload {
  school_id?: number | '';
  code: string;
  name: string;
  description?: string;
  phone?: string;
  email?: string;
  address?: string;
  timezone: string;
  logo_path?: string;
  status?: LibraryStatus;
}

export interface LibraryTransitionPayload {
  status: LibraryStatus;
  reason?: string;
}

/* ============================ KONFIGURASI ============================ */

export type LibrarySettingType = 'string' | 'integer' | 'boolean' | 'decimal' | 'json';

export interface LibrarySettingEntry {
  key: string;
  value: string | number | boolean | unknown[] | Record<string, unknown> | null;
  raw_value: string | null;
  type: LibrarySettingType;
  group: string;
  description: string | null;
}

/** Konfigurasi dikelompokkan: `{ general: [...], loan: [...], … }` */
export type LibrarySettingsGrouped = Record<string, LibrarySettingEntry[]>;

/* ============================ LOKASI ============================ */

export type LibraryLocationType =
  | 'building'
  | 'floor'
  | 'room'
  | 'reading_area'
  | 'storage'
  | 'service_desk'
  | 'other';

export type LibraryLocationStatus = 'active' | 'inactive';

export interface LibraryLocation {
  id: number;
  library_id: number;
  parent_id: number | null;
  code: string;
  name: string;
  type: LibraryLocationType;
  description: string | null;
  status: LibraryLocationStatus;
  is_active: boolean;
  children_count: number;
  parent?: { id: number; name: string; code: string } | null;
  children?: LibraryLocation[];
  created_at?: string | null;
  updated_at?: string | null;
}

export interface LibraryLocationPayload {
  parent_id?: number | '';
  code: string;
  name: string;
  type: LibraryLocationType;
  description?: string;
  status?: LibraryLocationStatus;
}

/* ============================ JAM OPERASIONAL ============================ */

export interface LibraryOperatingHour {
  id: number;
  library_id: number;
  day_of_week: number;
  day_label: string;
  is_open: boolean;
  open_time: string | null;
  close_time: string | null;
  interval_label: string;
}

export interface LibraryOperatingHourRow {
  day_of_week: number | '';
  is_open: boolean;
  open_time: string;
  close_time: string;
}

/* ============================ PENUTUPAN ============================ */

export type LibraryClosureType =
  | 'national_holiday'
  | 'school_holiday'
  | 'maintenance'
  | 'school_event'
  | 'other';

export type LibraryClosureStatus = 'active' | 'cancelled';

export interface LibraryClosure {
  id: number;
  library_id: number;
  date: string | null;
  start_time: string | null;
  end_time: string | null;
  reason: string;
  type: LibraryClosureType;
  status: LibraryClosureStatus;
  is_active: boolean;
  is_full_day: boolean;
  period_label: string;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface LibraryClosurePayload {
  date: string;
  start_time?: string;
  end_time?: string;
  reason: string;
  type: LibraryClosureType;
  status?: LibraryClosureStatus;
}

/* ============================ PENOMORAN ============================ */

export type LibraryNumberingResetPeriod = 'never' | 'year' | 'month';

export interface LibraryNumberingConfig {
  id: number;
  library_id: number;
  key: string;
  label: string;
  format: string;
  prefix: string | null;
  padding: number;
  reset_period: LibraryNumberingResetPeriod;
  start_number: number;
  last_number: number;
  last_reset_at: string | null;
  preview: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface LibraryNumberingConfigInput {
  key: string;
  format: string;
  prefix?: string;
  padding: number | '';
  reset_period: LibraryNumberingResetPeriod;
  start_number: number | '';
}

/* ============================ DASHBOARD & SETUP ============================ */

export type LibraryCheckStatus = 'complete' | 'incomplete' | 'warning';
export type LibrarySetupStatus = 'complete' | 'incomplete' | 'warning';

export interface LibrarySetupCheck {
  key: string;
  label: string;
  status: LibraryCheckStatus;
  detail: string;
  weight: number;
}

export interface LibrarySetupSummary {
  status: LibrarySetupStatus;
  percent: number;
  checks: LibrarySetupCheck[];
}

export type LibraryOperatingStatus = 'open' | 'closed' | 'unset';

export interface LibraryDashboard {
  library: {
    id: number;
    code: string;
    name: string;
    status: LibraryStatus;
    timezone: string;
  };
  operating: {
    status: LibraryOperatingStatus;
    today_label: string | null;
    today_hours: string | null;
    closures_today: {
      reason: string;
      start_time: string | null;
      end_time: string | null;
    }[];
  };
  location: { count: number };
  configuration: { percent: number; status: LibrarySetupStatus };
  setup: LibrarySetupCheck[];
  system: { is_active: boolean; last_update: string | null };
  /** `null` = sumber data belum ada (sirkulasi = Fase 4) → tampil "—". */
  statistics: {
    books: number | null;
    items: number | null;
    members: number | null;
    active_loans: number | null;
    overdue_loans: number | null;
  };
  /** Fase 3: angka katalog & keanggotaan yang sudah punya sumber data. */
  catalog: {
    bibliographies: number;
    bibliographies_active: number;
    items: number;
    items_available: number;
    recent: {
      id: number;
      title: string;
      slug: string;
      status: string;
      created_at: string | null;
    }[];
  };
  members: {
    total: number;
    active: number;
    expired: number;
    pending: number;
    suspended: number;
    due_expired: number;
  };
}

/* ============================ JEJAK AUDIT ============================ */

export interface LibraryActivity {
  id: number;
  log_name: string | null;
  event: string | null;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  causer: { id: number; name: string } | null;
  causer_id: number | null;
  old_values: Record<string, unknown>;
  new_values: Record<string, unknown>;
  properties: Record<string, unknown>;
  created_at: string | null;
}