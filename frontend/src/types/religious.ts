export interface ReligiousPrayerType {
  code: string;
  name: string;
  arabic_name?: string | null;
  category?: string;
  default_rakaat?: number | null;
  congregation_required?: boolean;
  ramadan_only?: boolean;
}

export interface ReligiousMeta {
  module?: { code?: string; name?: string; version?: number };
  domains?: string[];
  prayer_types: ReligiousPrayerType[];
  activity_types: string[];
  registration_modes?: string[];
  attendance?: {
    statuses?: string[] | Record<string, string>;
    methods?: string[] | Record<string, string>;
  };
  scope?: Record<string, unknown>;
}

export interface PrayerScheduleRow {
  prayer_type: string;
  name?: string;
  adhan_time?: string | null;
  iqamah_time?: string | null;
  start_time?: string | null;
  end_time?: string | null;
  status?: string;
  source?: string;
  version?: number;
  schedule_id?: number;
}

export interface PrayerScheduleDay {
  date: string;
  mosque_id?: number | null;
  campus_id?: number | null;
  prayers: PrayerScheduleRow[];
  next?: PrayerScheduleRow | null;
  source?: string;
}

export interface ReligiousAttendanceSummary {
  date?: string;
  total?: number;
  present?: number;
  late?: number;
  absent?: number;
  excused?: number;
  authorized_absence?: number;
  unknown?: number;
  attendance_rate?: number | null;
  late_rate?: number | null;
  absence_rate?: number | null;
  by_prayer?: Record<string, {
    total?: number;
    present?: number;
    late?: number;
    absent?: number;
    excused?: number;
    authorized_absence?: number;
    unknown?: number;
  }>;
  [key: string]: unknown;
}

export interface ReligiousDashboard {
  date: string;
  mosques: number;
  prayer_types: number;
  prayer_attendance: ReligiousAttendanceSummary;
  by_prayer?: ReligiousAttendanceSummary['by_prayer'];
  activities_today?: number;
  friday_upcoming?: number;
  ramadan?: Record<string, unknown> | null;
  providers?: { prayer_time?: Record<string, unknown> };
}

export interface ReligiousMosque {
  id: number;
  code?: string | null;
  name: string;
  campus_id?: number | null;
  description?: string | null;
  location?: string | null;
  capacity?: number | null;
  gender_access?: string;
  operational_status?: string;
  active?: boolean;
  area_count?: number;
  pending_bookings?: number;
}

export interface ReligiousMosqueArea {
  id: number;
  code?: string | null;
  name: string;
  area_type?: string;
  capacity?: number | null;
  bookable?: boolean;
  gender?: string;
  floor?: string | null;
}

export interface ReligiousMosqueOverview {
  mosque: ReligiousMosque;
  areas: ReligiousMosqueArea[];
  equipment_total?: number;
  equipment_attention?: number;
  asset_assignments?: number;
  open_maintenance?: number;
  display?: Record<string, unknown> | null;
}

export interface ReligiousPrayerWindow {
  allowed: boolean;
  status_hint?: string | null;
  reason?: string | null;
  policy_id?: number | null;
  overrideable?: boolean;
}

export interface ReligiousActivity {
  id: number;
  code?: string;
  title: string;
  activity_type: string;
  status: string;
  capacity?: number | null;
  registration_mode?: string;
  target_population?: string;
  starts_on?: string | null;
  ends_on?: string | null;
  description?: string | null;
  location?: string | null;
  mosque_id?: number | null;
}

export interface ReligiousActivitySession {
  id: number;
  activity_id: number;
  session_number?: number;
  title?: string | null;
  topic?: string | null;
  session_date: string;
  start_time?: string | null;
  end_time?: string | null;
  location?: string | null;
  status?: string;
  capacity?: number | null;
  attended_count?: number | null;
}

export interface ReligiousRamadanProgram {
  id: number;
  code?: string;
  name?: string;
  hijri_year?: string | null;
  gregorian_start?: string | null;
  gregorian_end?: string | null;
  mosque_id?: number | null;
  expected_participants?: number | null;
  budget?: number | string | null;
  status?: string;
  [key: string]: unknown;
}

export interface ReligiousAnalytics {
  period?: { from: string; to: string };
  prayer_attendance?: ReligiousAttendanceSummary & { total_records?: number };
  activity_participation?: {
    activities_created?: number;
    participants?: number;
    attendance_records?: number;
    present?: number;
    late?: number;
    by_type?: Array<{ type: string; total: number }>;
    kajian_sessions?: number;
  };
  mosque_utilization?: {
    entries?: number;
    total_attendance?: number;
    average_attendance?: number;
    average_utilization_pct?: number;
    peak?: number | null;
    by_mosque?: Array<{ mosque_id: number; entries: number; attendance: number }>;
  };
  personnel?: Record<string, unknown>;
  ramadan?: Record<string, unknown>;
  disclaimer?: string;
}

export interface ReligiousBooking {
  id: number;
  code?: string;
  title: string;
  mosque_id: number;
  area_id?: number | null;
  starts_at?: string | null;
  ends_at?: string | null;
  expected_attendees?: number | null;
  status: string;
  approval_status?: string | null;
}

export interface ReligiousFridayPrayer {
  id: number;
  date: string;
  mosque_id: number;
  khatib?: string | null;
  topic?: string | null;
  theme?: string | null;
  start_time?: string | null;
  estimated_attendance?: number | null;
  actual_attendance?: number | null;
  status?: string;
}

export interface ReligiousKhutbah {
  id: number;
  code?: string;
  title: string;
  theme?: string | null;
  category_code?: string | null;
  language?: string | null;
  prayer_date?: string | null;
  status?: string;
  khatib_name?: string | null;
  version?: number;
  has_document?: boolean;
}
