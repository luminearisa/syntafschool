/**
 * Tipe Modul Clinic / UKS.
 *
 * Bentuk ini mengikuti respons backend apa adanya — termasuk penegasan bahwa
 * identitas siswa (nama, NIS, kelas) datang dari master ERP, bukan dari tabel
 * Clinic. Karena itu `patient_header.identity` selalu berbentuk snapshot, dan
 * tidak ada duplikasi nama di tabel pasien.
 */

export interface ClinicOption {
  value: string;
  label: string;
}

export interface ClinicLocationRow {
  id: number;
  code: string;
  name: string;
  type: string;
  location: string | null;
  bed_capacity: number;
  supports_emergency: boolean;
  supports_observation: boolean;
  is_default: boolean;
}

export interface ClinicMetadata {
  clinics: ClinicLocationRow[];
  complaints: { id: number; code: string; name: string; category: string | null; is_infectious: boolean }[];
  procedure_types: { id: number; code: string; name: string; category: string; requires_consent: boolean; is_billable: boolean }[];
  diagnosis_coding_system: string;
  diagnosis_common: { code: string; name: string; category: string | null; is_infectious: boolean }[];
  screening_programs: { id: number; code: string; name: string; type: string; status: string }[];
  staff: { id: number; user_id: number; name: string | null; role: string; role_label: string; clinic_id: number }[];
  equipment: { id: number; sku: string | null; name: string; serial_number: string | null; condition: string | null; location: string | null }[];
  options: Record<string, ClinicOption[]>;
}

export interface ClinicMe {
  staff: null | {
    id: number;
    clinic: { id: number; code: string; name: string; type: string } | null;
    role: string;
    role_label: string;
    license_number: string | null;
    specialization: string | null;
  };
  capabilities: {
    view_all: boolean;
    record_clinical: boolean;
    diagnose: boolean;
    prescribe: boolean;
    dispense: boolean;
    export: boolean;
    parent_portal: boolean;
  };
  scope: { visible_patients: number; children: number[] };
}

export interface ClinicPatientRow {
  patient_id: number;
  medical_record_number: string;
  patient_type: string;
  status: string;
  student: { id: number; nis: string | null; name: string; birth_date: string | null } | null;
  alerts: { allergy: boolean; special_care: boolean };
  last_visit_at: string | null;
}

export interface ClinicIdentitySnapshot {
  id: number;
  nis: string | null;
  nisn: string | null;
  name: string;
  nick_name: string | null;
  gender: string | null;
  birth_date: string | null;
  age_years: number | null;
  blood_type: string | null;
  class_id: number | null;
  class_name: string | null;
  program: string | null;
  is_active: boolean;
}

export interface ClinicAllergy {
  id: number;
  category: string;
  category_label: string;
  allergen: string;
  reaction: string | null;
  severity: string;
  is_critical: boolean;
  identified_at: string | null;
}

export interface ClinicSpecialCondition {
  id: number;
  category: string;
  category_label: string;
  condition: string;
  care_instruction: string | null;
  start_date: string | null;
  end_date: string | null;
  visible_to_kitchen: boolean;
  visible_to_boarding: boolean;
}

export interface ClinicPatientProfile {
  patient: {
    id: number;
    medical_record_number: string;
    patient_type: string;
    status: string;
    blood_type: string | null;
    emergency_contact: { name: string | null; phone: string | null; relation: string | null };
    insurance: { provider: string | null; number: string | null };
    alerts: { allergy: boolean; special_care: boolean };
  };
  identity: ClinicIdentitySnapshot | null;
  guardians: { guardian_id: number; name: string; phone: string | null; email: string | null; relation: string | null; is_primary?: boolean; has_account: boolean }[];
  allergies: ClinicAllergy[];
  special_conditions: ClinicSpecialCondition[];
  vitals_latest: ClinicVitalSign | null;
  visit_summary: { total: number; last_30_days: number; last_visit_at: string | null; open_visits: number };
  medication_history: ClinicMedicationAdministration[];
  follow_ups: ClinicFollowUp[];
  medical_history?: { id: number; category: string; title: string; year: string | null; disclosure: string; status: string }[];
}

export interface ClinicVitalSign {
  id: number;
  measured_at: string;
  temperature: number | null;
  systolic: number | null;
  diastolic: number | null;
  heart_rate: number | null;
  respiratory_rate: number | null;
  oxygen_saturation: number | null;
  weight_kg: number | null;
  height_cm: number | null;
  bmi: number | null;
  bmi_category: string | null;
  pain_score?: number | null;
}

export interface ClinicQueueEntry {
  id: number;
  visit_id: number;
  clinic_id: number;
  queue_number: string;
  stage: string;
  sequence: number;
  queue_date: string;
}

export interface ClinicTriage {
  id: number;
  priority: string;
  chief_complaint: string;
  pain_score: number | null;
  consciousness: string;
  general_condition: string;
  duration: string | null;
  onset: string | null;
  severity_self: string | null;
  associated_symptoms: string[] | null;
  requires_isolation: boolean;
  triaged_at: string;
}

export interface ClinicVisitRow {
  id: number;
  visit_number: string;
  visit_at: string;
  visit_type: string;
  visit_type_label: string;
  priority: string;
  priority_label: string;
  status: string;
  status_label: string;
  is_emergency: boolean;
  chief_complaint: string | null;
  patient: { patient_id: number; mrn: string | null; name: string | null; nis: string | null };
  discharge_status: string | null;
}

export interface ClinicVisit {
  id: number;
  visit_number: string;
  visit_at: string;
  visit_type: string;
  visit_type_label: string;
  source: string;
  source_label: string;
  priority: string;
  priority_label: string;
  status: string;
  status_label: string;
  is_emergency: boolean;
  requires_observation: boolean;
  parent_notified: boolean;
  clinic: { id: number; code: string; name: string; location: string | null } | null;
  provider: { id: number; name: string } | null;
  registrar: { id: number; name: string } | null;
  complaint: { id: number; name: string; category: string | null } | null;
  chief_complaint: string | null;
  notes: string | null;
  closed_at: string | null;
  can_be_written: boolean;
}

export interface ClinicAssessment {
  id: number;
  version: number;
  is_current: boolean;
  method: string;
  subjective: string | null;
  objective: string | null;
  assessment: string | null;
  plan: string | null;
  notes: string | null;
  assessment_at: string;
}

export interface ClinicDiagnosis {
  id: number;
  diagnosis_code: string | null;
  diagnosis_name: string;
  type: string;
  notes: string | null;
  version: number;
  is_current: boolean;
  diagnosed_at: string;
}

export interface ClinicProcedure {
  id: number;
  procedure: string;
  notes: string | null;
  supplies: { name: string; quantity?: number }[] | null;
  performed_at: string;
}

export interface ClinicObservation {
  id: number;
  location: string | null;
  bed: string | null;
  reason: string;
  started_at: string;
  ended_at: string | null;
  status: string;
  records?: ClinicObservationRecord[];
}

export interface ClinicObservationRecord {
  id: number;
  recorded_at: string;
  condition: string;
  notes: string | null;
  action: string | null;
}

export interface ClinicMedicationRow {
  id: number;
  code: string;
  name: string;
  generic_name: string | null;
  dosage_form: string;
  strength: string | null;
  unit: string;
  route: string;
  category: string | null;
  requires_prescription: boolean;
  is_controlled: boolean;
  status: string;
  inventory_item_id: number | null;
  stock_available: number | null;
  stock_source: 'inventory' | 'unlinked';
}

export interface ClinicPrescriptionItem {
  id: number;
  medication_id: number | null;
  medication_name: string;
  dose: string | null;
  frequency: string | null;
  duration: string | null;
  route: string;
  quantity: number | string;
  dispensed_quantity: number | string;
  unit: string | null;
  instruction: string | null;
}

export interface ClinicPrescription {
  id: number;
  prescription_number: string;
  status: string;
  prescribed_at: string;
  notes: string | null;
  allergy_override: boolean;
  override_reason: string | null;
  allergy_alerts: ClinicAllergyWarning[] | null;
  items: ClinicPrescriptionItem[];
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
  visit?: { id: number; visit_number: string };
}

export interface ClinicAllergyWarning {
  allergy_id: number;
  allergen: string;
  severity: string;
  reaction: string | null;
  medication_id: number | null;
  medication_name: string | null;
  is_critical: boolean;
}

export interface ClinicDispensingResultRow {
  id: number;
  medication_name: string | null;
  quantity: number;
  unit: string | null;
  batch_number: string | null;
  expiry_date: string | null;
  inventory_result: 'PENDING' | 'SUCCESS' | 'INSUFFICIENT_STOCK' | 'EXPIRED' | 'NOT_AVAILABLE' | 'FAILED';
  inventory_movement_id: number | null;
  notes: string | null;
}

export interface ClinicMedicationAdministration {
  id: number;
  medication_name: string;
  dose: string | null;
  route: string;
  status: string;
  administered_at: string | null;
  version: number;
  is_current: boolean;
}

export interface ClinicReferral {
  id: number;
  referral_number: string;
  facility: string;
  facility_type: string | null;
  reason: string;
  urgency: string;
  status: string;
  referral_date: string;
  transport: string | null;
  outcome: string | null;
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
}

export interface ClinicFollowUp {
  id: number;
  due_date: string;
  type: string;
  status: string;
  instruction: string | null;
  is_overdue: boolean;
  completed_at: string | null;
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
}

export interface ClinicEmergency {
  id: number;
  incident_number: string;
  severity: string;
  incident_at: string;
  location: string | null;
  description: string;
  action_taken: string | null;
  stabilization: string | null;
  parent_contacted: boolean;
  parent_contacted_at: string | null;
  referral_required: boolean;
  ambulance_required: boolean;
  hospital: string | null;
  transport_method: string | null;
  status: string;
  outcome: string | null;
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
}

export interface ClinicInjury {
  id: number;
  incident_number: string;
  incident_at: string;
  location: string | null;
  activity: string | null;
  injury_type: string | null;
  body_part: string | null;
  mechanism: string | null;
  severity: string;
  treatment: string | null;
  referral_required: boolean;
  parent_notified: boolean;
  status: string;
  follow_up: string | null;
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
}

export interface ClinicScreening {
  id: number;
  type: string;
  screened_at: string;
  height_cm: number | string | null;
  weight_kg: number | string | null;
  bmi: number | string | null;
  bmi_category: string | null;
  vision_left: string | null;
  vision_right: string | null;
  dental_status: string | null;
  result: string;
  findings: string | null;
  recommendation: string | null;
  follow_up_required: boolean;
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
}

export interface ClinicVaccination {
  id: number;
  vaccine: string;
  dose: number;
  given_at: string;
  batch_number: string | null;
  provider: string | null;
  next_due_at: string | null;
  consent_obtained: boolean;
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
}

export interface ClinicCertificate {
  id: number;
  certificate_number: string;
  type: string;
  status: string;
  valid_from: string | null;
  valid_until: string | null;
  purpose: string | null;
  clinical_summary: string | null;
  restrictions: string | null;
  recommendation: string | null;
  rejection_reason: string | null;
  verification_code: string;
  issued_at: string | null;
  version: number;
  /** Dihitung server (status disetujui + belum lewat masa berlaku). */
  is_valid?: boolean;
  patient?: { id: number; student: { full_name?: string; nis?: string | null } | null };
}

export interface ClinicDocument {
  id: number;
  document_number: string;
  type: string;
  title: string;
  version: number;
  is_current: boolean;
  visibility: string;
  mime_type: string | null;
  size_bytes: number;
  created_at: string | null;
}

export interface ClinicQueueRow {
  visit_id: number;
  visit_number: string;
  patient_name: string | null;
  nis: string | null;
  priority: string;
  priority_label: string;
  status: string;
  status_label: string;
  stage: string | null;
  queue_number: string | null;
  waiting_minutes: number | null;
  is_emergency: boolean;
  chief_complaint: string | null;
}

export interface ClinicDashboard {
  date: string;
  visits_today: number;
  visits_this_month: number;
  queue_waiting: number;
  pending_triage: number;
  in_treatment: number;
  in_observation: number;
  emergencies_open: number;
  referrals_active: number;
  follow_ups_due: number;
  follow_ups_overdue: number;
  medications_dispensed_today: number;
  visits_by_day: { date: string; total: number }[];
  visits_by_month: { month: string; total: number }[];
  top_complaints: { complaint: string; total: number }[];
  top_diagnoses: { diagnosis: string; total: number }[];
  queue_board: ClinicQueueRow[];
  low_stock: { id: number; sku: string; name: string; quantity: number; minimum_quantity: number }[];
  expiring_medications: {
    batch_id: number;
    batch_number: string | null;
    medication_name: string | null;
    expiry_date: string | null;
    expired: boolean;
    days_left: number | null;
  }[];
}

/** Workspace kunjungan: header pasien + seluruh bagian klinis. */
export interface ClinicWorkspace {
  visit: ClinicVisit;
  patient_header: {
    patient_id: number;
    medical_record_number: string;
    patient_type: string;
    identity: ClinicIdentitySnapshot | null;
    blood_type: string | null;
    alerts: { allergy: boolean; special_care: boolean };
    allergies: { id: number; allergen: string; category: string; severity: string; reaction: string | null; is_critical: boolean }[];
    special_conditions: { id: number; category: string; condition: string; care_instruction: string | null }[];
    emergency_contact: { name: string | null; phone: string | null; relation: string | null };
    guardians: { guardian_id: number; name: string; phone: string | null; relation: string | null; has_account: boolean }[];
    boarding?: { dormitory: string | null; room: string | null; bed: string | null; is_boarder: boolean } | null;
  };
  queue: ClinicQueueEntry | null;
  triage: ClinicTriage | null;
  vitals: ClinicVitalSign[];
  assessments: ClinicAssessment[];
  current_assessment: ClinicAssessment | null;
  diagnoses: ClinicDiagnosis[];
  procedures: ClinicProcedure[];
  observations: ClinicObservation[];
  prescriptions: ClinicPrescription[];
  administrations: ClinicMedicationAdministration[];
  referrals: ClinicReferral[];
  follow_ups: ClinicFollowUp[];
  emergencies: ClinicEmergency[];
  injuries: ClinicInjury[];
  discharge: {
    id: number;
    status: string;
    condition: string;
    instructions: string | null;
    follow_up_required: boolean;
    follow_up_date: string | null;
    discharge_time: string;
    version: number;
  } | null;
  certificates: ClinicCertificate[];
  documents: ClinicDocument[];
  timeline: { at: string; type: string; label: string }[];
}

export interface ClinicPortalChild {
  patient_id: number;
  medical_record_number: string;
  student_id: number | null;
  name: string | null;
  nis: string | null;
  birth_date: string | null;
  blood_type: string | null;
  alerts: { allergy: boolean; special_care: boolean };
  last_visit_at: string | null;
}

export interface ClinicPortalVisit {
  visit_number: string;
  visit_at: string;
  visit_type: string;
  visit_type_label: string;
  status: string;
  status_label: string;
  chief_complaint: string | null;
  priority: string;
  diagnosis: string | null;
  notes: string | null;
  assessment_summary: string | null;
  medications: { medication_name: string; dose: string | null; status: string; administered_at: string | null }[];
  discharge: {
    status: string;
    status_label: string;
    condition: string;
    instructions: string | null;
    follow_up_required: boolean;
    follow_up_date: string | null;
  } | null;
  referrals: { referral_number: string; facility: string; urgency: string; status: string; referral_date: string }[];
}


/**
 * Bentuk respons bersarang yang dipakai klien API.
 *
 * Diberi nama (bukan generic bertingkat di tempat pemanggilan) agar kontrak
 * dengan backend terbaca jelas dan tidak ada tipe objek anonim yang panjang.
 */
export interface ClinicVitalsResult {
  vital: ClinicVitalSign;
  warnings: string[];
  note: string;
}

export interface ClinicPrescriptionCreated {
  prescription: ClinicPrescription;
  allergy_warnings: ClinicAllergyWarning[];
}

export interface ClinicDispensingOutcome {
  dispensing: { id: number; dispensing_number: string; status: string };
  results: ClinicDispensingResultRow[];
  prescription_status: string;
  note: string;
}

export interface ClinicDischargeOutcome {
  discharge: unknown;
  attendance: { status: string; message: string };
  boarding: { allowed: boolean; requires_supervision?: boolean; note: string } | null;
  note: string;
}

export interface ClinicEmergencyCreated {
  emergency: ClinicEmergency;
  visit_id: number;
  visit_number: string;
}

export interface ClinicQueueBoard {
  date: string;
  warning_minutes: number;
  rows: ClinicQueueRow[];
}

export interface ClinicScreeningProgress {
  screened: number;
  needs_follow_up: number;
  referrals: number;
}

export interface ClinicScreeningTargets {
  total: number;
  progress: ClinicScreeningProgress;
  students: { id: number; name: string; nis: string | null; class_name: string | null }[];
}

export interface ClinicPatientDirectory {
  rows: ClinicPatientRow[];
  total_visible: number;
}

export interface ClinicStudentSearchResult {
  id: number;
  nis: string | null;
  name: string;
  class_name: string | null;
  has_medical_record: boolean;
  medical_record_number: string | null;
}

export interface ClinicPortalChildren {
  registered: ClinicPortalChild[];
  unregistered: { id: number; name: string; nis: string | null; class_name: string | null }[];
  privacy: Record<string, boolean>;
}

export interface ClinicMedicationBatchRow {
  id: number;
  batch_number: string;
  expiry_date: string | null;
  status: string;
  is_expired: boolean;
  is_expiring_soon: boolean;
  quantity_received: number;
}

export interface ClinicAllergyCheck {
  warnings: ClinicAllergyWarning[];
  has_critical: boolean;
  note: string;
}

export interface ClinicEmergencyDetail {
  emergency: ClinicEmergency;
  contacts: { guardian_id: number; name: string; phone: string | null; relation: string | null }[];
}

export interface ClinicBatchCreated {
  id: number;
  batch_number: string;
  expiry_date: string | null;
  status: string;
}

export interface ClinicReportRow {
  [key: string]: unknown;
}
