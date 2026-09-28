/**
 * Tipe Phase 2 — aktivitas pembelajaran (Assignment, Quiz, Forum, Lesson,
 * Choice, Survey) beserta submission, attempt, dan catatan nilai.
 *
 * Dipisah dari `types.ts` (Phase 1) agar modul aktivitas dapat dibaca utuh
 * tanpa menggulir berkas besar; `types.ts` tetap menjadi sumber tipe inti.
 */

import type { ActivityTypeValue } from './types';

export type ActivityStatusValue = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';
export type SubmissionTypeValue = 'NONE' | 'ONLINE_TEXT' | 'FILE' | 'ONLINE_TEXT_AND_FILE';
export type SubmissionStatusValue = 'DRAFT' | 'SUBMITTED' | 'RESUBMITTED' | 'GRADED' | 'RETURNED' | 'REJECTED';
export type QuizAttemptStatusValue =
  | 'IN_PROGRESS'
  | 'SUBMITTED'
  | 'AUTO_GRADED'
  | 'MANUAL_GRADING'
  | 'GRADED'
  | 'ABANDONED'
  | 'EXPIRED';
export type QuestionTypeValue =
  | 'MULTIPLE_CHOICE_SINGLE'
  | 'MULTIPLE_CHOICE_MULTIPLE'
  | 'TRUE_FALSE'
  | 'SHORT_ANSWER'
  | 'NUMERICAL'
  | 'ESSAY'
  | 'MATCHING';
export type QuizGradeStrategyValue = 'HIGHEST' | 'LATEST' | 'AVERAGE' | 'FIRST';
export type QuizNavigationValue = 'FREE' | 'SEQUENTIAL';
export type ForumTypeValue = 'GENERAL' | 'COURSE_ANNOUNCEMENT' | 'Q_AND_A' | 'STANDARD';
export type ForumPostStatusValue = 'VISIBLE' | 'PENDING' | 'HIDDEN' | 'DELETED';
export type SurveyQuestionTypeValue = 'MULTIPLE_CHOICE' | 'RATING' | 'TEXT' | 'YES_NO';
export type ChoiceResultsVisibilityValue = 'AFTER_RESPONSE' | 'AFTER_CLOSE' | 'ALWAYS' | 'NEVER';
export type GradeSourceValue = 'ASSIGNMENT' | 'QUIZ' | 'FORUM' | 'LESSON' | 'CHOICE' | 'SURVEY' | 'MANUAL';

export interface ActivityState {
  locked: boolean;
  reason: string | null;
  reason_label: string | null;
  completed: boolean;
  past_due: boolean;
  closed: boolean;
  not_open_yet: boolean;
  opens_at: string | null;
  due_at: string | null;
  close_at: string | null;
}

export interface ActivityNeighbour {
  id: number;
  title: string;
  activity_type: ActivityTypeValue;
  type_label: string;
}

export interface LmsActivity {
  id: number;
  course_id: number;
  section_id: number | null;
  learning_item_id: number;
  activity_type: ActivityTypeValue;
  activity_type_label: string;
  title: string;
  description: string | null;
  instructions: string | null;
  status: ActivityStatusValue;
  status_label: string;
  visibility: string;
  start_at: string | null;
  due_at: string | null;
  close_at: string | null;
  completion_rule: string;
  completion_min_grade: number | null;
  availability: Record<string, unknown> | null;
  is_graded: boolean;
  published_at: string | null;
  created_by: number | null;
  created_at: string | null;
  updated_at: string | null;
  player_url: string;
  learning_item?: {
    id: number;
    title: string;
    item_type: string;
    is_required: boolean;
    sort_order: number;
    estimated_minutes: number | null;
    visibility: string;
  };
  detail: Record<string, unknown> | null;
}

export interface LmsActivityShowPayload {
  activity: LmsActivity;
  state: ActivityState;
  neighbours: { previous: ActivityNeighbour | null; next: ActivityNeighbour | null };
  can_manage: boolean;
  can_grade: boolean;
}

export interface SubmissionFile {
  id: number;
  name: string;
  size: number;
  size_label?: string;
  type: string | null;
  purpose: 'SUBMISSION' | 'FEEDBACK';
  url: string;
}

export interface AssignmentSubmission {
  id: number;
  attempt: number;
  status: SubmissionStatusValue;
  status_label: string;
  text_content: string | null;
  submitted_at: string | null;
  graded_at: string | null;
  grade: number | null;
  original_grade: number | null;
  late_penalty_percent: number | null;
  late: boolean;
  passed: boolean | null;
  feedback: string | null;
  grader_id: number | null;
  return_reason: string | null;
  files: SubmissionFile[];
  student?: { user_id: number; name: string | null; student_id?: number | null };
}

export interface AssignmentConfig {
  id: number;
  submission_type: SubmissionTypeValue;
  submission_type_label: string;
  grading_type: string;
  maximum_grade: number;
  passing_grade: number | null;
  maximum_attempts: number;
  allow_late_submission: boolean;
  late_penalty_percent: number;
  late_penalty_days_step: number | null;
  group_submission: boolean;
  require_file: boolean;
  require_text: boolean;
  allowed_file_types: string[];
  max_file_size_kb: number | null;
  settings: Record<string, unknown> | null;
}

export interface AssignmentStudentView {
  assignment: AssignmentConfig;
  status: SubmissionStatusValue | 'NOT_SUBMITTED';
  status_label: string;
  attempts_used: number;
  attempts_remaining: number;
  can_submit: boolean;
  submission: AssignmentSubmission | null;
  submissions: AssignmentSubmission[];
  allowed_file_types: string[];
  late_policy: { due_at: string | null; cutoff_at: string | null; allow_late: boolean; penalty_percent: number; is_past_due: boolean };
  grades: { maximum: number; passing: number | null; type: string };
  state?: ActivityState;
  locked?: boolean;
}

export interface AssignmentOverview {
  activity: { id: number; title: string; status: string; due_at: string | null; close_at: string | null };
  assignment: AssignmentConfig;
  statistics: {
    participants: number;
    submitted: number;
    not_submitted: number;
    graded: number;
    ungraded: number;
    late: number;
    average: number | null;
    highest: number | null;
    lowest: number | null;
    passed: number;
    pass_rate: number | null;
    submission_rate: number | null;
    maximum_grade: number;
  };
  submissions: {
    data: AssignmentSubmission[];
    current_page?: number;
    last_page?: number;
    total?: number;
  };
}

export interface QuizConfig {
  id: number;
  time_limit_minutes: number | null;
  attempts_allowed: number;
  grade_strategy: QuizGradeStrategyValue;
  grade_strategy_label: string;
  shuffle_questions: boolean;
  shuffle_answers: boolean;
  show_one_question_at_time: boolean;
  navigation_method: QuizNavigationValue;
  maximum_grade: number;
  passing_grade: number | null;
  questions_per_page: number | null;
  require_password: boolean;
  password?: string | null;
  open_at: string | null;
  close_at: string | null;
  review_options: Record<string, boolean>;
  settings: Record<string, unknown> | null;
}

export interface QuizStudentView {
  quiz: QuizConfig;
  total_marks: number;
  question_count: number;
  attempts: Array<{
    id: number;
    attempt_number: number;
    status: QuizAttemptStatusValue;
    status_label: string;
    final_grade: number | null;
    submitted_at: string | null;
    seconds_remaining: number | null;
  }>;
  attempts_used: number;
  attempts_allowed: number;
  attempts_remaining: number | null;
  open_attempt_id: number | null;
  final_grade: number | null;
  review_options: Record<string, boolean>;
  state?: ActivityState;
  locked?: boolean;
}

export interface QuizQuestionOption {
  id: number;
  option_key: string | null;
  text: string;
  is_correct?: boolean | null;
  match_target?: string | null;
}

export interface QuizAttemptQuestion {
  attempt_question_id: number;
  question_id: number;
  sort_order: number;
  marks: number;
  question_type: QuestionTypeValue;
  question_text: string | null;
  options: QuizQuestionOption[];
  answer: {
    answer: string | null;
    answer_data: Record<string, unknown> | null;
    marks: number | null;
    is_correct: boolean | null;
    feedback: string | null;
  } | null;
  correct_option_ids: number[] | null;
}

export interface QuizAttemptPayload {
  attempt: {
    id: number;
    quiz_id: number;
    attempt_number: number;
    status: QuizAttemptStatusValue;
    status_label: string;
    started_at: string | null;
    expires_at: string | null;
    submitted_at: string | null;
    server_time: string;
    seconds_remaining: number | null;
    raw_score: number | null;
    maximum_score: number;
    final_grade: number | null;
    passed: boolean | null;
    needs_manual_grading: boolean;
  };
  quiz: QuizConfig;
  activity: { id: number; title: string; instructions: string | null; course_id: number } | null;
  questions: QuizAttemptQuestion[];
  review: Record<string, boolean>;
  navigation: QuizNavigationValue;
  aggregate?: { strategy: string; final_grade: number | null; attempts: number };
}

export interface QuizBuilderPayload {
  quiz: QuizConfig;
  total_marks: number;
  slots: Array<{
    id: number;
    sort_order: number;
    marks: number;
    is_random: boolean;
    question_count: number | null;
    category: { id: number; name: string } | null;
    question: {
      id: number;
      name: string;
      question_type: QuestionTypeValue;
      question_type_label: string;
      difficulty: string;
      version: number;
      default_mark: number;
      options_count: number;
    } | null;
  }>;
  statistics?: QuizStatistics;
}

export interface QuizStatistics {
  participants: number;
  attempts: number;
  students_attempted: number;
  average: number | null;
  highest: number | null;
  lowest: number | null;
  pass_rate: number | null;
  completion_rate: number | null;
  pending_manual_grading: number;
  maximum_grade: number;
  questions: Array<{
    slot_id: number;
    question_id: number | null;
    title: string;
    is_random: boolean;
    question_type: QuestionTypeValue | null;
    marks: number;
    answers: number;
    correct: number;
    incorrect: number;
    correct_percent: number | null;
    incorrect_percent: number | null;
    average_marks: number | null;
  }>;
}

export interface QuestionOptionInput {
  id?: number;
  option_key?: string | null;
  text: string;
  is_correct?: boolean;
  feedback?: string | null;
  match_target?: string | null;
  sort_order?: number;
}

export interface QuestionPayload {
  id: number;
  bank_id: number | null;
  category_id: number | null;
  course_id: number | null;
  name: string;
  question_type: QuestionTypeValue;
  question_type_label: string;
  question_text: string;
  general_feedback: string | null;
  default_mark: number;
  difficulty: 'EASY' | 'MEDIUM' | 'HARD';
  status: string;
  version: number;
  settings: Record<string, unknown> | null;
  options: QuestionOptionInput[];
  versions?: Array<{ id: number; version: number; question_text: string; default_mark: number; created_at: string | null }>;
}

export interface QuestionBank {
  id: number;
  name: string;
  description: string | null;
  course_id: number | null;
  visibility: string;
  status: string;
  questions_count: number;
  categories_count: number;
}

export interface QuestionCategoryNode {
  id: number;
  name: string;
  description: string | null;
  parent_id: number | null;
  questions_count: number;
  children: QuestionCategoryNode[];
}

export interface QuestionBankTree {
  bank: { id: number; name: string; description: string | null; course_id: number | null; visibility: string; questions_count: number };
  categories: QuestionCategoryNode[];
}

export interface ForumDiscussionSummary {
  id: number;
  title: string;
  content: string;
  author: string | null;
  author_id: number;
  is_pinned: boolean;
  is_locked: boolean;
  status: ForumPostStatusValue;
  posts_count: number;
  last_post_at: string | null;
  created_at: string | null;
}

export interface ForumView {
  forum: {
    id: number;
    forum_type: ForumTypeValue;
    forum_type_label: string;
    subscription_enabled: boolean;
    moderation_enabled: boolean;
    grading_enabled: boolean;
    required_posts: number;
    max_depth: number;
    grading_max_posts: number | null;
    settings: Record<string, unknown> | null;
  };
  discussions: ForumDiscussionSummary[];
  subscription: { subscribed: boolean; notify_new_discussion: boolean; notify_new_post: boolean } | null;
  participation: { posts: number; discussions: number; contributions?: number; required_posts: number; meets_requirement: boolean } | null;
  can_start_discussion: boolean;
  state?: ActivityState;
  locked?: boolean;
  can_moderate?: boolean;
}

export interface ForumPostNode {
  id: number;
  parent_id: number | null;
  depth: number;
  content: string;
  author: string | null;
  author_id: number;
  is_teacher_post: boolean;
  status: ForumPostStatusValue;
  is_mine: boolean;
  can_edit: boolean;
  created_at: string | null;
  edited_at: string | null;
  replies: ForumPostNode[];
}

export interface ForumDiscussionView {
  discussion: {
    id: number;
    title: string;
    content: string;
    author: string | null;
    author_id: number;
    is_pinned: boolean;
    is_locked: boolean;
    status: ForumPostStatusValue;
    created_at: string | null;
    posts_count: number;
  };
  posts: ForumPostNode[];
}

export interface LessonPageSummary {
  id: number;
  title: string;
  page_type: 'CONTENT' | 'QUESTION';
  is_required: boolean;
  sort_order: number;
  visited: boolean;
}

export interface LessonPageDetail {
  id: number;
  title: string;
  content: string | null;
  page_type: 'CONTENT' | 'QUESTION';
  is_required: boolean;
  sort_order: number;
  question: {
    id: number;
    question_type: QuestionTypeValue;
    question_text: string;
    options: Array<{ id: number; option_key: string | null; text: string }>;
  } | null;
  branches: Array<{ id: number; label: string | null; answer_key: string | null; is_correct: boolean; next_page_id: number | null }>;
}

export interface LessonView {
  lesson: { id: number; require_all_pages: boolean; max_attempts: number; allow_review: boolean; pages_count: number; settings: Record<string, unknown> | null };
  pages: LessonPageSummary[];
  current: LessonPageDetail | null;
  progress: { visited_pages: number[]; completed_at: string | null; percent: number } | null;
  nav: { previous: { id: number; title: string } | null; next: { id: number; title: string } | null; total: number; position: number | null };
  state?: ActivityState;
  locked?: boolean;
}

export interface ChoiceView {
  choice: {
    id: number;
    allow_multiple: boolean;
    limit_responses: number;
    results_visibility: ChoiceResultsVisibilityValue;
    results_visibility_label: string;
    anonymous: boolean;
    show_available_choices: boolean;
    settings: Record<string, unknown> | null;
  };
  options: Array<{ id: number; text: string; limit: number | null; sort_order: number }>;
  my_response: number[];
  has_responded: boolean;
  responses_used: number;
  results: ChoiceResults | null;
  results_visible: boolean;
  state?: ActivityState;
  can_manage?: boolean;
}

export interface ChoiceResults {
  total_responses: number;
  participants: number;
  options: Array<{ id: number; text: string; count: number; percent: number }>;
  anonymous: boolean;
  participants_total?: number;
  response_rate?: number | null;
}

export interface SurveyView {
  survey: {
    id: number;
    anonymous: boolean;
    allow_multiple_submissions: boolean;
    show_results_to_students: boolean;
    questions_count: number;
    settings: Record<string, unknown> | null;
  };
  questions: Array<{
    id: number;
    question_type: SurveyQuestionTypeValue;
    question_type_label: string;
    text: string;
    options: string[] | null;
    rating_min: number;
    rating_max: number;
    is_required: boolean;
    sort_order: number;
  }>;
  has_submitted: boolean;
  results: SurveyResults | null;
  state?: ActivityState;
  can_manage?: boolean;
}

export interface SurveyResults {
  anonymous: boolean;
  responses: number;
  participants_total?: number;
  response_rate?: number | null;
  questions: Array<{
    id: number;
    text: string;
    question_type: SurveyQuestionTypeValue;
    answers: number;
    average?: number | null;
    distribution?: Record<string, number>;
    percentages?: Record<string, number>;
    comments?: string[];
  }>;
}

export interface ActivityGradeRecord {
  activity_id: number;
  title?: string | null;
  activity_type?: ActivityTypeValue | null;
  source: GradeSourceValue;
  grade: number | null;
  maximum_grade: number;
  percent: number | null;
  passed: boolean | null;
  feedback: string | null;
  graded_at: string | null;
  user_id?: number;
  student_id?: number | null;
}

export interface CourseGradeSummary {
  activities: number;
  graded: number;
  average: number | null;
  average_percent: number | null;
  passed: number;
  failed: number;
  records: ActivityGradeRecord[];
}

export interface ActivityTypeOption {
  value: ActivityTypeValue;
  label: string;
  icon: string;
  graded: boolean;
}

export interface ChildActivity {
  id: number;
  title: string;
  activity_type: ActivityTypeValue;
  activity_type_label: string;
  course_id: number;
  course_name: string | null;
  due_at: string | null;
  completed: boolean;
  grade: number | null;
  maximum_grade: number | null;
  percent: number | null;
  passed: boolean | null;
  feedback: string | null;
}

export interface DueSoonActivity {
  id: number;
  title: string;
  activity_type: ActivityTypeValue;
  activity_type_label: string;
  course_id: number;
  course_name: string | null;
  due_at: string | null;
  is_past_due: boolean;
  player_url: string;
}

export interface RecentlyGradedActivity {
  activity_id: number;
  title: string | null;
  activity_type: ActivityTypeValue | null;
  course_id: number;
  course_name: string | null;
  grade: number;
  maximum_grade: number;
  percent: number;
  passed: boolean | null;
  graded_at: string | null;
  player_url: string | null;
}

export interface PendingGradingRow {
  activity_id: number;
  title: string;
  activity_type: ActivityTypeValue;
  activity_type_label: string;
  course_id: number;
  course_name: string | null;
  pending: number;
  manage_url: string;
}
