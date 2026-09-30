export interface UksOption {
  value: string;
  label: string;
}

export interface UksMetadata {
  visit_types: UksOption[];
  severities: UksOption[];
  visit_statuses: UksOption[];
  screening_types: UksOption[];
  screening_results: UksOption[];
  incident_statuses: UksOption[];
  letter_types: UksOption[];
  officer_roles: UksOption[];
  referral_statuses: UksOption[];
  stock_reasons: UksOption[];
}

export interface UksMe {
  is_officer: boolean;
  officer_role: string | null;
  is_student: boolean;
  is_guardian: boolean;
  is_employee: boolean;
  can_view_all: boolean;
  can_operate: boolean;
  student_id: number | null;
  guardian_id: number | null;
}

export interface UksVisitRow {
  id: number;
  code: string;
  student_id: number;
  student?: { id: number; full_name: string; nis: string | null } | null;
  school_class_id: number | null;
  class_name?: string | null;
  visit_type: string;
  visit_type_label: string;
  complaint: string;
  symptoms: string | null;
  severity: string;
  severity_label: string;
  status: string;
  status_label: string;
  arrived_at: string | null;
  left_at: string | null;
  treatment: string | null;
  advice: string | null;
  needs_rest: boolean;
  sent_home: boolean;
  parent_notified: boolean;
  parent_notified_at: string | null;
  notes: string | null;
  reported_by?: string | null;
  handled_by?: string | null;
  medications_count?: number;
  medications?: UksMedication[] | null;
  consents?: UksConsent[] | null;
  referral?: UksReferral | null;
  letters?: UksLetter[] | null;
}

export interface UksMedication {
  id: number;
  medicine_name: string;
  dosage: string | null;
  quantity: string;
  unit: string | null;
  given_at: string | null;
}

export interface UksConsent {
  id: number;
  decision: string;
  note: string | null;
  channel: string;
  decided_at: string;
}

export interface UksReferral {
  id: number;
  facility_name: string;
  facility_type: string | null;
  reason: string;
  status: string;
  outcome: string | null;
  referred_at: string;
}

export interface UksMedicine {
  id: number;
  code: string;
  name: string;
  category: string | null;
  unit: string;
  stock: string;
  min_stock: string;
  expiry_date: string | null;
  storage_location: string | null;
  is_active: boolean;
  notes: string | null;
}

export interface UksStudentOption {
  id: number;
  full_name: string;
  nis: string | null;
}

export interface UksDashboard {
  window: { from: string; to: string };
  scope: { level: string; label: string; student_count?: number };
  visits: {
    total: number;
    open_now: number;
    sent_home: number;
    referred: number;
    parent_notified: number;
    by_status: Record<string, number>;
    by_severity: Record<string, number>;
    top_complaints: { complaint: string; total: number }[];
  };
  incidents: { total: number };
  screenings: { total: number; by_result: Record<string, number> };
  stock: { medicines: number; low_stock: number; expiring_soon: number; expired: number } | null;
}

export interface UksScreening {
  id: number;
  student_id: number;
  student?: { full_name: string; nis: string | null } | null;
  screening_type: string;
  screened_at: string;
  height_cm: string | null;
  weight_kg: string | null;
  bmi: string | null;
  result: string;
  findings: string | null;
}

export interface UksImmunization {
  id: number;
  student_id: number;
  student?: { full_name: string; nis: string | null } | null;
  vaccine_name: string;
  dose_number: number;
  given_at: string;
  next_due_at: string | null;
  provider: string | null;
}

export interface UksIncident {
  id: number;
  code: string;
  student_id: number;
  student?: { full_name: string; nis: string | null } | null;
  occurred_at: string;
  location: string | null;
  severity: string;
  description: string;
  status: string;
  follow_up: string | null;
  parent_notified: boolean;
}

export interface UksLetter {
  id: number;
  number: string;
  student_id: number;
  student?: { full_name: string; nis: string | null } | null;
  letter_type: string;
  date_from: string;
  date_to: string;
  verification_code: string;
}

export interface UksHealthProfile {
  blood_type: string | null;
  allergies: string | null;
  chronic_conditions: string | null;
  regular_medications: string | null;
  special_needs: string | null;
  insurance_provider: string | null;
  insurance_number: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  emergency_contact_relation: string | null;
  notes: string | null;
}

export interface UksStudentRecord {
  profile: UksHealthProfile | null;
  screenings: UksScreening[];
  immunizations: UksImmunization[];
  visits_count: number;
  incidents_count: number;
}

export interface UksOfficer {
  id: number;
  employee_id: number;
  role: string;
  license_no: string | null;
  is_active: boolean;
  appointed_at: string | null;
  employee?: { id: number; full_name: string; nip: string | null; position: string | null } | null;
}

export const SEVERITY_TONE: Record<string, 'gray' | 'blue' | 'amber' | 'red'> = {
  RINGAN: 'gray',
  SEDANG: 'blue',
  BERAT: 'amber',
  DARURAT: 'red',
};

export const STATUS_TONE: Record<string, 'gray' | 'blue' | 'amber' | 'green' | 'red' | 'brand'> = {
  MENUNGGU: 'amber',
  DITANGANI: 'blue',
  ISTIRAHAT: 'brand',
  PULANG: 'gray',
  DIRUJUK: 'red',
  SELESAI: 'green',
  TERBUKA: 'amber',
  DITINDAKLANJUTI: 'blue',
  NORMAL: 'green',
  PERLU_PERHATIAN: 'amber',
  RUJUK: 'red',
};
