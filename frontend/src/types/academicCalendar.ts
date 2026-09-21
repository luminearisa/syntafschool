/** Tipe modul Kalender Akademik & Hari Efektif (A4). */

export interface EventType {
  id: number;
  code: string;
  name: string;
  color: string | null;
  is_holiday: boolean;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface EventTypePayload {
  code: string;
  name: string;
  color: string;
  is_holiday: boolean;
  is_active: boolean;
}

export interface AcademicEvent {
  id: number;
  semester_id: number;
  academic_year_id: number | null;
  event_type_id: number;
  title: string;
  start_date: string;
  end_date: string;
  description: string | null;
  location: string | null;
  is_holiday: boolean;
  is_active: boolean;
  semester?: {
    id: number;
    name: string;
    academic_year_id: number;
  } | null;
  event_type?: {
    id: number;
    code: string;
    name: string;
    color: string | null;
    is_holiday: boolean;
  } | null;
  academic_year?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface AcademicEventPayload {
  semester_id: number | '';
  academic_year_id: number | '';
  event_type_id: number | '';
  title: string;
  start_date: string;
  end_date: string;
  description: string;
  location: string;
  is_holiday: boolean;
  is_active: boolean;
}

export interface CalendarMonth {
  month: string;
  label: string;
  events: AcademicEvent[];
}

export interface AcademicCalendarResult {
  months: CalendarMonth[];
}

export interface EffectiveDay {
  id: number;
  semester_id: number;
  education_level_id: number | null;
  date: string;
  is_school_day: boolean;
  note: string | null;
  semester?: { id: number; name: string } | null;
  education_level?: {
    id: number;
    name: string;
    jenjang: string;
    level_number: number;
  } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface EffectiveDayPayload {
  semester_id: number | '';
  education_level_id: number | '';
  date: string;
  is_school_day: boolean;
  note: string;
}

export interface EffectiveDaysGenerateResult {
  created: number;
  updated: number;
  skipped: number;
  school_days: number;
  holidays: number;
}

export interface EffectiveDaysSummaryMonth {
  month: string;
  label: string;
  school_days: number;
  holidays: number;
  effective_days: number;
}

export interface EffectiveDaysSummary {
  total_school_days: number;
  total_holidays: number;
  total_effective_days: number;
  months: EffectiveDaysSummaryMonth[];
}
