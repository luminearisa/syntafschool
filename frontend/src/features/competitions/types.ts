export type CompetitionStatus =
  | 'DRAFT' | 'PLANNING' | 'REGISTRATION_OPEN' | 'REGISTRATION_CLOSED'
  | 'SELECTION' | 'TECHNICAL_MEETING' | 'RUNNING' | 'FINAL' | 'COMPLETED'
  | 'CANCELLED' | 'ARCHIVED';

export interface CompetitionTypeOption {
  id: number;
  code: string;
  name: string;
  competition_domain_id?: number | null;
  default_format?: string;
  default_scoring_model?: string;
  participant_mode?: string;
  requires_team?: boolean;
  requires_judge?: boolean;
  supports_bracket?: boolean;
}

export interface CompetitionDomainOption { id: number; code: string; name: string; color?: string | null; icon?: string | null }
export interface CompetitionDivisionOption { id: number; code: string; name: string; division_type: string; gender?: string; min_age?: number | null; max_age?: number | null }

export interface CompetitionRow {
  id: number;
  competition_type_id: number;
  competition_domain_id?: number | null;
  code: string;
  name: string;
  slug: string;
  short_name?: string | null;
  description?: string | null;
  organizer_name?: string | null;
  status: string;
  default_visibility?: string;
  default_participant_mode?: string;
  competition_type?: { id: number; code: string; name: string } | null;
  competition_domain?: { id: number; code: string; name: string } | null;
  competition_editions_count?: number;
  created_at?: string;
}

export interface CompetitionEdition {
  id: number;
  competition_id: number;
  year: number;
  code: string;
  name: string;
  slug: string;
  theme?: string | null;
  description?: string | null;
  terms?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
  registration_opens?: string | null;
  registration_closes?: string | null;
  status: CompetitionStatus;
  max_participants?: number | null;
  max_teams?: number | null;
  allows_external: boolean;
  allows_internal: boolean;
  requires_parent_consent: boolean;
  requires_payment: boolean;
  requires_eligibility: boolean;
  requires_selection: boolean;
  requires_documents: boolean;
  public_visibility: string;
  categories?: CompetitionCategory[];
  competition?: { id: number; code: string; name: string; slug: string } | null;
}

export interface CompetitionCategory {
  id: number;
  edition_id: number;
  competition_division_id?: number | null;
  code: string;
  name: string;
  description?: string | null;
  gender_restriction: string;
  min_grade_level?: string | null;
  max_grade_level?: string | null;
  participant_mode: 'INDIVIDUAL' | 'TEAM' | 'MIXED' | string;
  min_team_size?: number | null;
  max_team_size?: number | null;
  capacity?: number | null;
  registration_fee?: string | number | null;
  scoring_model?: string;
  ranking_model?: string;
  aggregation_method?: string;
  requires_submission: boolean;
  requires_judge: boolean;
  requires_documents: boolean;
  requires_parent_consent: boolean;
  is_active: boolean;
}

export interface CompetitionRegistration {
  id: number;
  edition_id: number;
  category_id: number;
  registration_number: string;
  participant_type: string;
  student_id?: number | null;
  team_id?: number | null;
  external_participant_name?: string | null;
  institution_name?: string | null;
  status: string;
  eligibility_status?: string | null;
  document_status?: string | null;
  consent_status?: string | null;
  payment_status: string;
  waitlist_position?: number | null;
  submitted_at?: string | null;
  rejection_reason?: string | null;
  revision_notes?: string | null;
}

export interface CompetitionParticipant {
  id: number;
  edition_id: number;
  category_id: number;
  registration_id?: number | null;
  team_id?: number | null;
  participant_type: string;
  student_id?: number | null;
  display_name?: string | null;
  institution_name?: string | null;
  status: string;
  eligibility_status?: string | null;
  qualification_status?: string | null;
  seed_number?: number | null;
}

export interface CompetitionRound { id: number; edition_id: number; category_id: number; name: string; code: string; sequence: number; round_type: string; format: string; status: string; is_final: boolean; qualification_count?: number | null }
export interface CompetitionMatch { id: number; round_id: number; category_id: number; match_number: number; name?: string | null; home_participant_id?: number | null; away_participant_id?: number | null; home_team_id?: number | null; away_team_id?: number | null; scheduled_at?: string | null; scheduled_end_at?: string | null; venue_name?: string | null; status: string; score_a?: number | string | null; score_b?: number | string | null; winner_participant_id?: number | null }
export interface CompetitionScorecard { id: number; rubric_id: number; judge_id?: number | null; match_id?: number | null; participant_id?: number | null; status: string; final_score?: number | string | null; current_version: number; submitted_at?: string | null; locked_at?: string | null }
export interface CompetitionResult { id: number; edition_id: number; category_id: number; round_id?: number | null; participant_id?: number | null; team_id?: number | null; final_score?: number | string | null; position?: number | null; qualification_status?: string | null; status: string; is_published: boolean; score_breakdown?: Record<string, unknown> }
export interface CompetitionAward { id: number; edition_id: number; category_id?: number | null; code: string; name: string; award_type: string; criteria_type: string; position_from?: number | null; position_to?: number | null }
export interface CompetitionDashboard { competitions: { total: number; active: number; completed: number }; registrations: { total: number; pending: number; approved: number; waitlisted: number }; operations: { upcoming_matches: number; running_matches: number; pending_scores: number }; recent: CompetitionRow[] }
export interface CompetitionMeta { types: CompetitionTypeOption[]; domains: CompetitionDomainOption[]; divisions: CompetitionDivisionOption[]; formats: { id: number; code: string; name: string }[]; levels: { id: number; code: string; name: string }[]; edition_statuses: string[]; registration_statuses: string[]; aggregation_methods: string[] }
export interface CompetitionDiscoverEdition { id: number; year: number; name: string; description?: string | null; starts_on?: string | null; ends_on?: string | null; registration_closes?: string | null; status: string; requires_parent_consent?: boolean; competition?: { id: number; name: string; slug: string; code: string; description?: string | null; organizer_name?: string | null } | null; categories: CompetitionCategory[] }
export interface CompetitionBracketSlotView { slot_id: number; position: number; participant_id?: number | null; team_id?: number | null; seed?: number | null; status: string; bye: boolean }
export interface CompetitionBracketRoundView { round: number; name: string; slots: CompetitionBracketSlotView[] }
export interface CompetitionBracketView { id: number; render_data?: CompetitionBracketRoundView[] | null; status?: string }
export interface PageResult<T> { items: T[]; currentPage: number; lastPage: number; total: number }
