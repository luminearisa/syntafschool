import { useMemo } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, BellRing, ExternalLink, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import {
  ppdbAnnouncementsApi,
  ppdbInvoicesApi,
  ppdbPaymentsApi,
  ppdbRegistrationsApi,
  ppdbSelectionApi,
} from '../../api/ppdbApi';
import { DocumentReviewPanel, type DocumentSlot } from '../../components/ops/PpdbDocumentReview';
import {
  RegistrationWorkflowBar,
  type AnnouncementOption,
  type RevisionTargetOption,
} from '../../components/ops/PpdbRegistrationWorkflow';
import {
  AccountStatusBadge,
  AxisBadge,
  ErrorLine,
  FieldList,
  FieldRow,
  LoadingLine,
  Notice,
  PaymentStatusBadge,
  DocumentStatusBadge,
  PageHeader,
  RegistrationStatusBadge,
  ReloadButton,
  SectionCard,
  StatCard,
  StatusTriplet,
  TabsNav,
} from '../../components/ops/PpdbOpsUi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Can } from '@/components/common/Can';
import { cn, formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import {
  FIELD_SECTION_LABELS,
  INVOICE_TYPE_LABELS,
  PARTICIPANT_STATUS_LABELS,
  REGISTRATION_STATUS_LABELS,
  SELECTION_RESULT_LABELS,
  STAGE_TYPE_LABELS,
  allowedTransitions,
  fileUrl,
  formatDateTime,
  historyActionLabel,
  money,
  prettifyKey,
  statusLabelOf,
  toNumber,
  yesNoLabel,
} from '../../components/ops/labels';
import type {
  PpdbApplicantDocument,
  PpdbDocumentStatus,
  PpdbFieldSection,
  PpdbInvoiceType,
  PpdbParticipantStatus,
  PpdbPayment,
  PpdbPaymentStatus,
  PpdbRegistration,
  PpdbRegistrationHistory,
  PpdbRegistrationStatus,
  PpdbSelectionListResponse,
  PpdbSelectionResult,
  PpdbSelectionStage,
} from '@/types/ppdb';
import type {
  PpdbDocumentSummary,
  PpdbKeyedAnswers,
  PpdbNextAction,
  PpdbOptionValue,
  PpdbPortalApplicant,
  PpdbPortalDocument,
  PpdbPortalParentRow,
  PpdbRef,
  PpdbRegistrationBlockers,
  PpdbRegistrationProgressStep,
  PpdbRegistrationSchema,
} from '@/types/ppdbPortal';

/* ============================ BENTUK RESPONS ============================ */

/** Item tagihan pada `paymentSummary()` — hanya `name`, `amount`, `fee_id`; tidak ada `id`. */
interface DetailInvoiceItemRow {
  name: string;
  amount: number;
  fee_id: number | null;
}

/** Pembayaran pada `paymentSummary()` — tidak mengirim `invoice_id` maupun `created_at`. */
interface DetailPaymentRow {
  id: number;
  method: 'manual' | 'midtrans';
  status: 'pending' | 'paid' | 'failed' | 'expired' | 'refunded';
  amount: number;
  provider: string | null;
  provider_reference: string | null;
  proof_path: string | null;
  proof_url: string | null;
  paid_at: string | null;
  verified_at: string | null;
  note: string | null;
}

/** Satu tagihan di dalam `detail.invoices.invoices`. */
interface DetailInvoiceRow {
  id: number;
  number: string;
  type: PpdbInvoiceType;
  status: PpdbPaymentStatus;
  amount: number;
  discount_amount: number;
  total_amount: number;
  paid_amount: number;
  outstanding_amount: number;
  due_date: string | null;
  issued_at: string | null;
  paid_at: string | null;
  notes: string | null;
  items: DetailInvoiceItemRow[];
  payments: DetailPaymentRow[];
}

/**
 * `PpdbRegistrationQueryService::paymentSummary()` — objek ringkasan yang
 * MEMUAT daftar tagihan di dalam kunci `invoices`. Jadi kunci `invoices` pada
 * detail admin bukan daftar, dan `invoice` tunggal tidak pernah ada.
 */
interface DetailPaymentSummary {
  invoice_count: number;
  total_amount: number;
  paid_amount: number;
  outstanding_amount: number;
  last_due_date: string | null;
  payment_status: PpdbPaymentStatus;
  payment_status_label: string;
  invoices: DetailInvoiceRow[];
}

/** Baris `selectionSummary().stages[]` — identitas tahap direnggut ke baris peserta. */
interface DetailSelectionStageRow {
  id: number;
  stage_id: number;
  stage_name: string | null;
  stage_type: string | null;
  scheduled_at: string | null;
  location: string | null;
  status: PpdbParticipantStatus | null;
  score: number | null;
  passing_score: number | null;
  result: PpdbSelectionResult | null;
  attended_at: string | null;
}

/** `PpdbRegistrationQueryService::selectionSummary()`. */
interface DetailSelectionSummary {
  result: PpdbSelectionResult | null;
  announced_at: string | null;
  stage_count: number;
  total_score: number;
  stages: DetailSelectionStageRow[];
}

/**
 * `PpdbRegistrationHistoryResource::row()`.
 *
 * `PpdbRegistrationHistory` di `types/ppdb.ts` belum mengenal tiga label yang
 * dihitung server, jadi dilengkapi di sini tanpa menyentuh berkas tipe.
 */
type DetailHistoryRow = PpdbRegistrationHistory & {
  action_label?: string | null;
  from_status_label?: string | null;
  to_status_label?: string | null;
};

/**
 * `GET /ppdb/registrations/{id}` — `PpdbRegistrationDetailResource`.
 *
 * Bentuknya DUA lapisan yang digabung rata oleh
 * `array_merge(parent::toArray($request), $this->detail)`: baris
 * `PpdbRegistrationResource::row()` + `PpdbRegistrationQueryService::detail($r, true)`.
 * Tidak ada pembungkus `registration`, tidak ada `answer_map`/`missing`/`steps`,
 * dan `documents` adalah objek `PpdbDocumentService::annotated()` (bukan array).
 *
 * `applicant` memakai `PpdbApplicantResource::row()` sehingga `gender`, `religion`,
 * dan `target_program` berupa NAMA (string), sementara `parent` memakai
 * `PpdbParentAccountResource::row()` penuh — kolom `parent` pada baris daftar
 * ditimpa oleh versi lengkap ini.
 */
type AdminRegistrationDetail = {
  id: number;
  registration_id: number;
  registration_number: string;
  status: PpdbRegistrationStatus;
  status_label: string;
  payment_status: PpdbPaymentStatus;
  payment_status_label: string;
  document_status: PpdbDocumentStatus;
  document_status_label: string;
  is_editable: boolean;
  revision_note: string | null;
  notes: string | null;
  submitted_at: string | null;
  verified_at: string | null;
  selection_result: PpdbSelectionResult | null;
  announced_at: string | null;
  completed_at: string | null;
  student_id: number | null;
  applicant_id: number;
  parent_account_id: number;
  period_id: number;
  program_id: number;
  wave_id: number | null;
  created_at: string | null;
  updated_at: string | null;
  links: Record<string, string>;
  next_action: PpdbNextAction;
  applicant: PpdbPortalApplicant | null;
  parent: PpdbPortalParentRow | null;
  period: (PpdbRef & { slug: string | null }) | null;
  program: PpdbRef | null;
  wave: PpdbRef | null;
  allowed_transitions: PpdbOptionValue<PpdbRegistrationStatus>[];
  schema: PpdbRegistrationSchema;
  answers: PpdbKeyedAnswers;
  documents: PpdbDocumentSummary;
  progress: PpdbRegistrationProgressStep[];
  blockers: PpdbRegistrationBlockers;
  can_submit: boolean;
  editable: boolean;
  document_upload_allowed: boolean;
  validation_rules: Record<string, string[]>;
  quota_override: { applied: boolean; reason: string | null };
  histories: DetailHistoryRow[];
  invoices: DetailPaymentSummary;
  selection_participants: DetailSelectionSummary;
};

/**
 * `ppdbRegistrationsApi.show()` sengaja longgar (`Record<string, unknown>`)
 * karena isi detail dinamis. Penyempitan ke bentuk nyata dilakukan satu kali di
 * sini, dikunci oleh kolom yang selalu ada pada baris pendaftaran.
 */
function readAdminDetail(
  payload: Record<string, unknown> | undefined | null,
): AdminRegistrationDetail | null {
  if (!payload) return null;
  const number = payload.registration_number;
  const status = payload.status;
  const id = payload.id;
  if (typeof number !== 'string' || typeof status !== 'string' || typeof id !== 'number') {
    return null;
  }
  return payload as AdminRegistrationDetail;
}

/**
 * `PpdbRegistration` utuh untuk komponen ops (`RegistrationWorkflowBar`,
 * `StatusTriplet`).
 *
 * `period`/`wave`/`program` tidak dipetakan: `PpdbRef` mengirim
 * `id: number | null` sedangkan tipe baris menuntut `id: number`, dan tidak ada
 * komponen ops yang membacanya — nama periode, gelombang, dan program dirender
 * langsung dari `detail`. `next_action` juga tidak dipetakan karena tipe
 * `PpdbRegistration.next_action` masih string sementara server mengirim objek
 * `{key,label,status,status_label}`.
 */
function toRegistrationRow(detail: AdminRegistrationDetail): PpdbRegistration {
  return {
    id: detail.id,
    registration_number: detail.registration_number,
    period_id: detail.period_id,
    wave_id: detail.wave_id,
    program_id: detail.program_id,
    applicant_id: detail.applicant_id,
    parent_account_id: detail.parent_account_id,
    status: detail.status,
    status_label: detail.status_label,
    payment_status: detail.payment_status,
    payment_status_label: detail.payment_status_label,
    document_status: detail.document_status,
    document_status_label: detail.document_status_label,
    is_editable: detail.is_editable,
    submitted_at: detail.submitted_at,
    verified_at: detail.verified_at,
    selection_result: detail.selection_result,
    announced_at: detail.announced_at,
    completed_at: detail.completed_at,
    student_id: detail.student_id,
    revision_note: detail.revision_note,
    notes: detail.notes,
    created_at: detail.created_at,
    updated_at: detail.updated_at,
    applicant: detail.applicant,
    parent: detail.parent,
    allowed_transitions: detail.allowed_transitions.map((option) => option.value),
  };
}

/** `PpdbApplicantDocumentResource::row()` -> bentuk yang dibaca panel ulasan. */
function toOpsDocument(document: PpdbPortalDocument): PpdbApplicantDocument {
  const row: PpdbApplicantDocument = { ...document, file_path: document.file_path ?? '' };
  return row;
}

/**
 * Slot dokumen untuk `DocumentReviewPanel`, dibangun dari
 * `PpdbDocumentService::annotated()`: `requirements[]` (tiap syarat + berkas
 * aktif + versinya) lalu `unlinked_documents[]` (berkas yang syaratnya sudah
 * hilang dari cakupan periode/program/gelombang).
 */
function documentSlotsOf(documents: PpdbDocumentSummary): DocumentSlot[] {
  const slots: DocumentSlot[] = documents.requirements.map((requirement) => ({
    key: `req-${requirement.id}`,
    requirementId: requirement.id,
    name: requirement.name,
    required: requirement.required,
    active: requirement.document ? toOpsDocument(requirement.document) : null,
    versions: requirement.versions.map(toOpsDocument),
  }));

  for (const orphan of documents.unlinked_documents) {
    slots.push({
      key: `unlinked-${orphan.id}`,
      requirementId: orphan.requirement_id,
      name: orphan.requirement_name,
      required: false,
      active: toOpsDocument(orphan),
      versions: [toOpsDocument(orphan)],
    });
  }

  return slots;
}

/** Item pembayaran pada tagihan dari `GET /ppdb/invoices` -> baris seragam. */
function paymentRowFromApi(payment: PpdbPayment): DetailPaymentRow {
  return {
    id: payment.id,
    method: payment.method,
    status: payment.status,
    amount: toNumber(payment.amount),
    provider: payment.provider ?? null,
    provider_reference: payment.provider_reference ?? null,
    proof_path: payment.proof_path ?? null,
    proof_url: payment.proof_url ?? null,
    paid_at: payment.paid_at ?? null,
    verified_at: payment.verified_at ?? null,
    note: payment.note ?? null,
  };
}

/**
 * `GET /ppdb/selection-stages` tidak mengirim daftar datar: isinya objek
 * `{stages, weight_groups, pagination, required_weight_total}`
 * (PpdbSelectionController.php:74-84), sementara `crud().listAll` di lapisan API
 * masih mendeklarasikannya sebagai array. Kedua bentuk ditangani di sini.
 */
function readStageList(payload: unknown): PpdbSelectionStage[] {
  if (Array.isArray(payload)) return payload;
  if (payload && typeof payload === 'object') {
    const wrapped = payload as PpdbSelectionListResponse;
    return Array.isArray(wrapped.stages) ? wrapped.stages : [];
  }
  return [];
}

const TABS = [
  { key: 'ringkasan', label: 'Ringkasan' },
  { key: 'formulir', label: 'Formulir' },
  { key: 'dokumen', label: 'Dokumen' },
  { key: 'pembayaran', label: 'Pembayaran' },
  { key: 'seleksi', label: 'Seleksi' },
  { key: 'riwayat', label: 'Riwayat' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

/* ============================ KECIL ============================ */

const MILESTONES: { key: string; label: string; statuses: PpdbRegistrationStatus[] }[] = [
  { key: 'submitted', label: 'Dikirim', statuses: ['submitted', 'under_review', 'need_revision'] },
  { key: 'verified', label: 'Terverifikasi', statuses: ['verified', 'selection', 'passed', 'failed', 'waitlisted', 're_registration', 'completed'] },
  { key: 'selection', label: 'Seleksi', statuses: ['selection', 'passed', 'failed', 'waitlisted', 're_registration', 'completed'] },
  { key: 'result', label: 'Hasil', statuses: ['passed', 'failed', 'waitlisted', 're_registration', 'completed'] },
  { key: 're_registration', label: 'Daftar ulang', statuses: ['re_registration', 'completed'] },
  { key: 'completed', label: 'Selesai', statuses: ['completed'] },
];

function WorkflowTrail({ status }: { status: PpdbRegistrationStatus }) {
  if (status === 'draft') {
    return (
      <Notice tone="gray">
        Pendaftaran masih draf — belum dikirim orang tua, jadi belum masuk antrean petugas.
      </Notice>
    );
  }
  if (status === 'cancelled') {
    return <Notice tone="red">Pendaftaran dibatalkan. Alur berhenti di sini.</Notice>;
  }

  const reached = MILESTONES.filter((milestone) => milestone.statuses.includes(status));
  const furthest = reached.length ? MILESTONES.indexOf(reached[reached.length - 1]) : -1;

  return (
    <ol className="flex flex-wrap items-center gap-1.5 text-xs">
      {MILESTONES.map((milestone, index) => {
        const done = index <= furthest;
        const current = index === furthest;
        return (
          <li key={milestone.key} className="flex items-center gap-1.5">
            <span
              className={cn(
                'rounded-full px-2.5 py-1 font-medium',
                current
                  ? 'bg-brand-600 text-white'
                  : done
                    ? 'bg-brand-100 text-brand-700'
                    : 'bg-slate-100 text-slate-400',
              )}
            >
              {milestone.label}
            </span>
            {index < MILESTONES.length - 1 && <span className="text-slate-300">›</span>}
          </li>
        );
      })}
    </ol>
  );
}

function answerDisplay(
  value: unknown,
  options?: { label: string; value?: string }[] | null,
  type?: string,
): string {
  if (value === null || value === undefined || value === '') return '—';
  if (Array.isArray(value)) {
    return value.map((item) => answerDisplay(item, options, type)).join(', ');
  }
  if (typeof value === 'boolean') return yesNoLabel(value);
  const asString = String(value);
  const match = options?.find((option) => String(option.value ?? option.label) === asString);
  if (match) return match.label;
  if (type === 'date' || /^\d{4}-\d{2}-\d{2}$/.test(asString)) return formatDate(asString);
  return asString;
}

function InvoiceCard({ invoice }: { invoice: DetailInvoiceRow }) {
  /**
   * `paymentSummary()` sudah melampirkan pembayaran tiap tagihan. Query ini
   * hanya menarik ulang bila ringkasan datang tanpa pembayaran — misalnya
   * transfer yang dilaporkan setelah halaman dibuka.
   */
  const paymentsQuery = useQuery({
    queryKey: ['ppdb', 'payments', { invoice_id: invoice.id }],
    enabled: invoice.payments.length === 0,
    queryFn: async () => {
      const rows = await ppdbPaymentsApi.list({ invoice_id: invoice.id, per_page: 50 });
      return (rows.data ?? []).map(paymentRowFromApi);
    },
  });

  const reminderMutation = useReminderMutation();

  const payments = invoice.payments.length ? invoice.payments : paymentsQuery.data ?? [];
  const fileLink = (payment: DetailPaymentRow) => fileUrl(payment.proof_url, payment.proof_path);

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="font-mono text-xs text-slate-500">{invoice.number}</p>
          <p className="text-sm font-semibold text-slate-900">{money(invoice.total_amount)}</p>
          <p className="text-xs text-slate-500">
            Jatuh tempo {invoice.due_date ? formatDate(invoice.due_date) : '—'} · diterbitkan{' '}
            {invoice.issued_at ? formatDate(invoice.issued_at) : '—'}
          </p>
        </div>
        <div className="flex flex-col items-end gap-1.5">
          <div className="flex items-center gap-2">
            <AxisBadge axis="Tipe" tone="gray">
              {statusLabelOf(INVOICE_TYPE_LABELS, invoice.type)}
            </AxisBadge>
            <PaymentStatusBadge status={invoice.status} />
          </div>
          <Can perm="ppdb.payment.verify">
            <Button
              size="sm"
              variant="outline"
              loading={reminderMutation.isPending && reminderMutation.variables === invoice.id}
              disabled={invoice.outstanding_amount <= 0}
              onClick={() =>
                reminderMutation.mutate(invoice.id, {
                  onSuccess: (result) =>
                    toast.success(result.sent ? 'Pengingat dikirim ke orang tua.' : 'Pengingat dicatat, tidak dikirim.'),
                  onError: (error: unknown) => toast.error(getErrorMessage(error)),
                })
              }
            >
              <BellRing className="h-3.5 w-3.5" /> Kirim pengingat
            </Button>
          </Can>
        </div>
      </div>

      {invoice.notes && <p className="mt-2 text-xs text-slate-500">{invoice.notes}</p>}

      {invoice.items.length > 0 && (
        <ul className="mt-3 divide-y divide-slate-50 text-sm">
          {invoice.items.map((item, index) => (
            <li key={`${item.name}-${index}`} className="flex items-center justify-between gap-2 py-1">
              <span className="text-slate-600">{item.name}</span>
              <span className="text-slate-700">{money(item.amount)}</span>
            </li>
          ))}
          {invoice.discount_amount > 0 && (
            <li className="flex items-center justify-between gap-2 py-1 text-green-700">
              <span>Diskon</span>
              <span>-{money(invoice.discount_amount)}</span>
            </li>
          )}
          <li className="flex items-center justify-between gap-2 py-1 font-semibold text-slate-800">
            <span>Sisa tagihan</span>
            <span className={invoice.outstanding_amount > 0 ? 'text-red-600' : 'text-green-600'}>
              {money(invoice.outstanding_amount)}
            </span>
          </li>
        </ul>
      )}

      <div className="mt-3 border-t border-slate-100 pt-3">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Pembayaran</p>
        {paymentsQuery.isLoading ? (
          <LoadingLine label="Memuat pembayaran..." />
        ) : payments.length === 0 ? (
          <p className="text-sm text-slate-400">Belum ada pembayaran dilaporkan.</p>
        ) : (
          <ul className="divide-y divide-slate-50">
            {payments.map((payment) => {
              const proof = fileLink(payment);
              return (
                <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5 text-sm">
                  <span className="text-slate-600">
                    {money(payment.amount)} · {payment.provider ?? payment.method}
                    {payment.provider_reference ? ` · ref ${payment.provider_reference}` : ''} ·{' '}
                    {formatDateTime(payment.paid_at ?? payment.verified_at)}
                  </span>
                  <span className="flex items-center gap-2">
                    <Badge tone={payment.status === 'paid' ? 'green' : payment.status === 'pending' ? 'amber' : 'red'}>
                      {statusLabelOf(
                        { pending: 'Menunggu', paid: 'Lunas', failed: 'Gagal', expired: 'Kedaluwarsa', refunded: 'Dikembalikan' },
                        payment.status,
                      )}
                    </Badge>
                    {proof && (
                      <a href={proof} target="_blank" rel="noreferrer" className="text-xs text-brand-700 hover:underline">
                        bukti
                      </a>
                    )}
                  </span>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </Card>
  );
}

function useReminderMutation() {
  return useMutation({
    mutationFn: (id: number) => ppdbInvoicesApi.remind(id),
  });
}

/* ============================ HALAMAN ============================ */

export function PpdbRegistrationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const registrationId = Number(id);
  const [searchParams, setSearchParams] = useSearchParams();
  const tab: TabKey = (TABS.some((item) => item.key === searchParams.get('tab'))
    ? searchParams.get('tab')
    : 'ringkasan') as TabKey;

  const detailQuery = useQuery({
    queryKey: ['ppdb', 'registration', registrationId],
    enabled: Number.isFinite(registrationId) && registrationId > 0,
    queryFn: () => ppdbRegistrationsApi.show(registrationId),
  });

  /**
   * Satu respons datar sudah memuat riwayat (`histories`), ringkasan pembayaran
   * lengkap dengan tagihannya (`invoices`), dan tahap seleksi calon ini
   * (`selection_participants`) — semuanya khusus endpoint admin
   * (`PpdbRegistrationQueryService::detail($r, true)`), jadi tab Riwayat dan
   * Pembayaran tidak butuh query tambahan.
   */
  const detail = readAdminDetail(detailQuery.data);
  const registration = useMemo(() => (detail ? toRegistrationRow(detail) : null), [detail]);

  const announcementsQuery = useQuery({
    queryKey: ['ppdb', 'announcements', 'options', detail?.period_id],
    enabled: Boolean(detail?.period_id),
    queryFn: () => ppdbAnnouncementsApi.listAll({ period_id: detail?.period_id }),
  });

  /** Tahap pada periode ini — hanya dicari bila calon belum tercatat di tahap mana pun. */
  const periodStagesQuery = useQuery({
    queryKey: ['ppdb', 'selection-stages', 'for-registration', detail?.period_id],
    enabled: Boolean(detail) && (detail?.selection_participants.stage_count ?? 0) === 0,
    queryFn: async () => readStageList(await ppdbSelectionApi.listAll({ period_id: detail?.period_id })),
  });

  const documentSlots = useMemo<DocumentSlot[] | null>(() => {
    if (!detail) return null;
    const slots = documentSlotsOf(detail.documents);
    // Kosong berarti tidak ada syarat pada cakupan periode/program/gelombang ini;
    // serahkan panel ke sumber cadangannya sendiri daripada menampilkan daftar kosong.
    return slots.length > 0 ? slots : null;
  }, [detail]);

  const revisionTargets = useMemo<RevisionTargetOption[]>(() => {
    if (!detail) return [];
    const targets: RevisionTargetOption[] = [];
    for (const field of detail.schema.fields) {
      targets.push({ type: 'field', key: field.key, label: field.label });
    }
    for (const question of detail.schema.questions) {
      targets.push({ type: 'question', id: question.id, label: question.question });
    }
    for (const requirement of detail.documents.requirements) {
      targets.push({ type: 'document', id: requirement.id, label: requirement.name });
    }
    return targets;
  }, [detail]);

  if (!Number.isFinite(registrationId) || registrationId <= 0) {
    return (
      <Card>
        <ErrorLine message="ID pendaftaran tidak valid pada alamat halaman." />
      </Card>
    );
  }

  if (detailQuery.isLoading) {
    return (
      <div className="space-y-4">
        <PageHeader title="Berkas pendaftaran" description="Memuat data..." />
        <Card>
          <LoadingLine label="Memuat berkas pendaftaran..." />
        </Card>
      </div>
    );
  }

  if (detailQuery.isError || !detail || !registration) {
    return (
      <div className="space-y-4">
        <Link
          to="/ppdb/registrations"
          className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700"
        >
          <ArrowLeft className="h-4 w-4" /> Kembali ke register
        </Link>
        <Card>
          <ErrorLine
            message={
              detailQuery.isError
                ? getErrorMessage(detailQuery.error)
                : 'Respons server tidak memuat data pendaftaran ini.'
            }
            onRetry={() => detailQuery.refetch()}
          />
        </Card>
      </div>
    );
  }

  const applicant = detail.applicant;
  const parent = detail.parent;
  const serverTransitions = detail.allowed_transitions.map((option) => option.value);
  const allowed = allowedTransitions(detail.status, detail.allowed_transitions);
  const announcements: AnnouncementOption[] = (announcementsQuery.data ?? []).map((item) => ({
    id: item.id,
    title: item.title,
  }));
  const documentSummary = detail.documents.summary;
  const paymentSummary = detail.invoices;
  const invoices = detail.invoices.invoices;
  const selectionStages = detail.selection_participants.stages;
  const histories = detail.histories;
  const quotaOverride = detail.quota_override;

  /* ---- jawaban dinamis per section ---- */
  const fieldGroups = buildFieldGroups(detail);
  const orphanAnswers = fieldGroups.orphan;
  const sections = fieldGroups.groups;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link
          to="/ppdb/registrations"
          className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700"
        >
          <ArrowLeft className="h-4 w-4" /> Register pendaftaran
        </Link>
        <div className="flex items-center gap-2">
          <ReloadButton onClick={() => detailQuery.refetch()} busy={detailQuery.isFetching} />
        </div>
      </div>

      <PageHeader
        title={applicant?.full_name ?? 'Calon tanpa nama'}
        description={
          <span className="font-mono text-xs">{registration.registration_number}</span>
        }
        badge={<StatusTriplet registration={registration} />}
      />

      <Card className="p-4">
        <div className="space-y-3">
          <WorkflowTrail status={registration.status} />
          <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <span>Periode: {detail.period?.name ?? '—'}</span>
            <span>·</span>
            <span>Gelombang: {detail.wave?.name ?? '—'}</span>
            <span>·</span>
            <span>Program: {detail.program?.name ?? '—'}</span>
            <span>·</span>
            <span>Dikirim: {detail.submitted_at ? formatDateTime(detail.submitted_at) : 'belum'}</span>
            <span>·</span>
            <span>
              Verifikasi: {detail.verified_at ? formatDateTime(detail.verified_at) : 'belum'}
            </span>
            <span>·</span>
            <span>Langkah server: {detail.next_action.label}</span>
          </div>
          <RegistrationWorkflowBar
            registration={registration}
            serverTransitions={serverTransitions}
            revisionTargets={revisionTargets}
            announcements={announcements}
            onChanged={() => detailQuery.refetch()}
          />
          <Can
            perm="ppdb.registration.override_quota"
            fallback={
              <Notice tone="gray">
                Menetapkan lulus di atas kuota butuh hak{' '}
                <code className="rounded bg-white/70 px-1 font-mono text-[11px]">
                  ppdb.registration.override_quota
                </code>{' '}
                dan alasan tertulis minimal 10 karakter. Akun Anda tidak punya hak
                tersebut, jadi server menolak nilai lulus bila kuota sudah penuh.
              </Notice>
            }
          >
            <Notice tone="amber">
              Kuota penuh? Lulus di atas kuota tetap wajib memakai alasan tertulis
              (minimal 10 karakter) yang direkam sebagai{' '}
              <code className="rounded bg-white/70 px-1 font-mono text-[11px]">
                quota_override_reason
              </code>
              ; alasan yang sudah dipakai tampil pada kartu Hasil &amp; pengumuman.
            </Notice>
          </Can>
        </div>
      </Card>

      <TabsNav
        tabs={[...TABS]}
        active={tab}
        onChange={(key) => {
          const next = new URLSearchParams(searchParams);
          next.set('tab', key);
          setSearchParams(next, { replace: true });
        }}
      />

      {registration.status === 'need_revision' && registration.revision_note && (
        <Notice tone="amber">
          <span className="font-medium">Menunggu revisi orang tua:</span> {registration.revision_note}
        </Notice>
      )}

      {tab === 'ringkasan' && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <SectionCard title="Identitas calon" description="Data calon saat pendaftaran dikirim.">
            <FieldList>
              <FieldRow label="Nama calon">{applicant?.full_name ?? '—'}</FieldRow>
              {applicant?.nickname && <FieldRow label="Nama panggilan">{applicant.nickname}</FieldRow>}
              {/*
                `gender`, `religion`, dan `target_program` pada
                `PpdbApplicantResource::row()` adalah Nama master (string) hasil
                `?->name`, bukan objek relasi.
              */}
              <FieldRow label="Jenis kelamin">{applicant?.gender ?? '—'}</FieldRow>
              <FieldRow label="NISN / NIK">
                {applicant?.nisn ?? '—'} / {applicant?.nik ?? '—'}
              </FieldRow>
              <FieldRow label="Tempat, tanggal lahir">
                {applicant?.birth_place ?? '—'}
                {applicant?.birth_date ? `, ${formatDate(applicant.birth_date)}` : ''}
              </FieldRow>
              <FieldRow label="Umur">{applicant?.age_label ?? '—'}</FieldRow>
              <FieldRow label="Agama">{applicant?.religion ?? '—'}</FieldRow>
              <FieldRow label="Alamat">{applicant?.address ?? '—'}</FieldRow>
              <FieldRow label="Sekolah asal">
                {applicant?.previous_school ?? '—'}
                {applicant?.previous_school_npsn ? ` (NPSN ${applicant.previous_school_npsn})` : ''}
              </FieldRow>
              {applicant?.photo_path && (
                <FieldRow label="Pas foto">
                  <a
                    href={fileUrl(null, applicant.photo_path) ?? undefined}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 text-brand-700 hover:underline"
                  >
                    <ExternalLink className="h-3.5 w-3.5" /> Lihat berkas
                  </a>
                </FieldRow>
              )}
              {applicant?.target_program && (
                <FieldRow label="Program sasaran">{applicant.target_program}</FieldRow>
              )}
            </FieldList>
          </SectionCard>

          <SectionCard
            title="Akun orang tua / wali"
            description="Baris akun penuh (`PpdbParentAccountResource::row()`); satu akun dapat memegang beberapa anak."
            action={
              <Link
                to={`/ppdb/parent-accounts?search=${encodeURIComponent(parent?.email ?? parent?.name ?? '')}`}
                className="text-xs font-medium text-brand-700 hover:underline"
              >
                Buka akun
              </Link>
            }
          >
            {parent ? (
              <FieldList>
                <FieldRow label="Nama">{parent.name ?? '—'}</FieldRow>
                <FieldRow label="Email">{parent.email ?? '—'}</FieldRow>
                <FieldRow label="Telepon">{parent.phone ?? '—'}</FieldRow>
                <FieldRow label="Alamat">{parent.address ?? '—'}</FieldRow>
                <FieldRow label="Status akun">
                  <AccountStatusBadge status={parent.account_status} />
                </FieldRow>
                <FieldRow label="Boleh bertransaksi">{yesNoLabel(parent.can_transact)}</FieldRow>
                <FieldRow label="Terverifikasi">
                  {parent.verified_at ? formatDateTime(parent.verified_at) : 'belum'}
                </FieldRow>
                <FieldRow label="Aktivitas terakhir">
                  {parent.last_activity_at ? formatDateTime(parent.last_activity_at) : '—'}
                </FieldRow>
                <FieldRow label="Jumlah anak / pendaftaran">
                  {parent.applicant_count} calon · {parent.registration_count} pendaftaran
                </FieldRow>
                {detail.student_id && (
                  <FieldRow label="Siswa hasil konversi">
                    {/* Baris pendaftaran hanya mengirim `student_id`; nama/NIS siswa tidak ikut. */}
                    <Badge tone="green">Siswa #{detail.student_id}</Badge>
                  </FieldRow>
                )}
              </FieldList>
            ) : (
              <Notice tone="gray">
                Relasi akun orang tua tidak ikut termuat pada respons ini, jadi data wali
                tidak dapat ditampilkan.
              </Notice>
            )}
          </SectionCard>

          <SectionCard
            title="Kelengkapan berkas"
            description="Progres dan penghambat dihitung server — halaman ini hanya membacanya."
          >
            <ol className="flex flex-wrap gap-1.5">
              {detail.progress.map((step) => (
                <li key={step.key}>
                  <Badge tone={step.done ? 'green' : 'gray'}>{step.label}</Badge>
                </li>
              ))}
            </ol>
            <FieldList className="mt-2">
              <FieldRow label="Dokumen">
                {documentSummary.uploaded}/{documentSummary.total} terunggah ·{' '}
                {documentSummary.approved} disetujui · {documentSummary.rejected} ditolak ·{' '}
                {documentSummary.under_review} ditinjau
              </FieldRow>
              {documentSummary.missing.length > 0 && (
                <FieldRow label="Syarat tanpa berkas">
                  {documentSummary.missing.map((item) => item.name).join(', ')}
                </FieldRow>
              )}
              <FieldRow label="Unggah dokumen">
                {detail.document_upload_allowed ? 'dibuka untuk orang tua' : 'ditutup server'}
              </FieldRow>
            </FieldList>
            {!detail.blockers.ready && (
              <Notice tone="amber" className="mt-2">
                <span className="font-medium">{detail.blockers.total} penghambat kirim:</span>{' '}
                {[
                  ...detail.blockers.fields.map((item) => item.label),
                  ...detail.blockers.questions.map((item) => item.label),
                  ...detail.blockers.documents.map((item) => item.name),
                  ...detail.blockers.applicant.map((column) => `data calon (${column})`),
                ].join(' · ')}
              </Notice>
            )}
          </SectionCard>

          <SectionCard title="Catatan petugas" description="Bersumber dari field catatan pendaftaran.">
            <p className="text-sm text-slate-600">{detail.notes || 'Belum ada catatan.'}</p>
            <p className="mt-2 text-xs text-slate-400">
              Dapat diubah lewat permintaan revisi atau penetapan hasil — setiap perubahan tercatat di
              tab Riwayat.
            </p>
          </SectionCard>

          <SectionCard title="Hasil & pengumuman">
            <FieldList>
              <FieldRow label="Hasil seleksi">
                {detail.selection_result
                  ? SELECTION_RESULT_LABELS[detail.selection_result]
                  : 'belum ditetapkan'}
              </FieldRow>
              <FieldRow label="Diumumkan">
                {detail.announced_at ? formatDateTime(detail.announced_at) : '—'}
              </FieldRow>
              <FieldRow label="Selesai daftar ulang">
                {detail.completed_at ? formatDateTime(detail.completed_at) : '—'}
              </FieldRow>
              {quotaOverride.applied && (
                <FieldRow label="Override kuota">
                  <span className="text-amber-700">{quotaOverride.reason ?? 'ditetapkan'}</span>
                </FieldRow>
              )}
              <FieldRow label="Transisi tersedia">
                {allowed.length ? allowed.map((key) => REGISTRATION_STATUS_LABELS[key]).join(' · ') : 'tidak ada'}
              </FieldRow>
            </FieldList>
          </SectionCard>
        </div>
      )}

      {tab === 'formulir' && (
        <div className="space-y-4">
          {sections.map((section) => (
            <SectionCard key={section.key} title={section.label} description={`${section.rows.length} isian`}>
              <FieldList>
                {section.rows.map((row) => (
                  <FieldRow key={row.key} label={row.label}>
                    <span className={cn(!row.value && 'text-slate-400')}>{row.value || '—'}</span>
                  </FieldRow>
                ))}
              </FieldList>
            </SectionCard>
          ))}

          {orphanAnswers.length > 0 && (
            <SectionCard
              title="Isian tanpa skema"
              description="Ditampilkan sebagai kunci / nilai karena tidak ikut terkirim pada skema formulir."
            >
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-3 py-2 font-medium">Kunci</th>
                      <th className="px-3 py-2 font-medium">Nilai</th>
                    </tr>
                  </thead>
                  <tbody>
                    {orphanAnswers.map((row) => (
                      <tr key={row.key} className="border-b border-slate-50 last:border-0">
                        <td className="px-3 py-2 font-mono text-xs text-slate-500">{row.key}</td>
                        <td className="px-3 py-2 text-slate-700">{row.value || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </SectionCard>
          )}

          {sections.length === 0 && orphanAnswers.length === 0 && (
            <Card>
              <Notice tone="gray" className="border-0 bg-transparent">
                Tidak ada jawaban formulir pada respons ini. Bila pendaftaran masih draf, isian memang
                belum dikirim.
              </Notice>
            </Card>
          )}
        </div>
      )}

      {tab === 'dokumen' && (
        <DocumentReviewPanel
          registrationId={registration.id}
          slots={documentSlots}
          title="Dokumen per syarat"
          description="Versi aktif yang dinilai; versi lama tetap tersimpan dan dapat dibuka."
          onChanged={() => detailQuery.refetch()}
        />
      )}

      {tab === 'pembayaran' && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <PaymentStatusBadge status={registration.payment_status} />
            <span className="text-xs text-slate-500">
              Label server: {paymentSummary.payment_status_label} · status pembayaran dihitung
              terpisah dari status berkas.
            </span>
            <Link
              to="/ppdb/payments"
              className="text-xs font-medium text-brand-700 hover:underline"
            >
              Kelola tagihan &amp; verifikasi pembayaran
            </Link>
          </div>

          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard
              label="Tagihan"
              value={paymentSummary.invoice_count}
              hint={
                paymentSummary.last_due_date
                  ? `Jatuh tempo terakhir ${formatDate(paymentSummary.last_due_date)}`
                  : 'belum ada jatuh tempo'
              }
            />
            <StatCard label="Total tagihan" value={money(paymentSummary.total_amount)} />
            <StatCard label="Terbayar" value={money(paymentSummary.paid_amount)} tone="green" />
            <StatCard
              label="Sisa tagihan"
              value={money(paymentSummary.outstanding_amount)}
              tone={paymentSummary.outstanding_amount > 0 ? 'red' : 'green'}
            />
          </div>

          {invoices.length === 0 ? (
            <Card>
              <Notice tone="gray" className="border-0 bg-transparent">
                Ringkasan pembayaran server mengirim `invoice_count = 0` — belum ada
                tagihan untuk pendaftaran ini. Terbitkan manual pada halaman Pembayaran.
              </Notice>
            </Card>
          ) : (
            invoices.map((invoice) => <InvoiceCard key={invoice.id} invoice={invoice} />)
          )}
        </div>
      )}

      {tab === 'seleksi' && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <DocumentStatusBadge status={registration.document_status} />
            <span>
              Hasil akhir:{' '}
              {detail.selection_result ? SELECTION_RESULT_LABELS[detail.selection_result] : 'belum ditetapkan'}
            </span>
            <Link to="/ppdb/selection" className="font-medium text-brand-700 hover:underline">
              Input nilai pada halaman Seleksi
            </Link>
          </div>

          {selectionStages.length > 0 ? (
            <SectionCard
              title="Nilai per tahap"
              description={`selectionSummary(): ${detail.selection_participants.stage_count} tahap · total nilai ${detail.selection_participants.total_score}.`}
            >
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-3 py-2 font-medium">Tahap</th>
                      <th className="px-3 py-2 font-medium">Status peserta</th>
                      <th className="px-3 py-2 text-right font-medium">Nilai</th>
                      <th className="px-3 py-2 text-right font-medium">KKM</th>
                      <th className="px-3 py-2 font-medium">Hasil</th>
                      <th className="px-3 py-2 font-medium">Kehadiran</th>
                    </tr>
                  </thead>
                  <tbody>
                    {selectionStages.map((row) => {
                      const below =
                        row.score !== null && row.passing_score !== null && row.score < row.passing_score;
                      return (
                        <tr key={row.id} className="border-b border-slate-50 last:border-0">
                          <td className="px-3 py-2 text-slate-700">
                            {row.stage_name ?? `Tahap #${row.stage_id}`}
                            <span className="ml-1 text-xs text-slate-400">
                              {statusLabelOf(STAGE_TYPE_LABELS, row.stage_type)}
                              {row.scheduled_at ? ` · ${formatDateTime(row.scheduled_at)}` : ''}
                              {row.location ? ` · ${row.location}` : ''}
                            </span>
                          </td>
                          <td className="px-3 py-2 text-xs text-slate-500">
                            {statusLabelOf(PARTICIPANT_STATUS_LABELS, row.status)}
                          </td>
                          <td className={cn('px-3 py-2 text-right', below ? 'text-red-600' : 'text-slate-700')}>
                            {row.score ?? '—'}
                          </td>
                          <td className="px-3 py-2 text-right text-slate-500">{row.passing_score ?? '—'}</td>
                          <td className="px-3 py-2 text-slate-600">
                            {row.result ? SELECTION_RESULT_LABELS[row.result] : '—'}
                          </td>
                          <td className="px-3 py-2 text-xs text-slate-500">
                            {row.attended_at ? formatDateTime(row.attended_at) : '—'}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <Notice tone="gray" className="mt-3">
                Skor gabungan dihitung server sebagai Σ(nilai tahap × bobot tahap) /
                Σ(bobot tahap) dan total bobot satu grup harus tepat 100%. Nilai tidak
                pernah dinormalisasi di sisi aplikasi — bila bobot belum pas, error
                validasi server yang akan tampil.
              </Notice>
            </SectionCard>
          ) : (
            <SectionCard
              title="Tahap seleksi pada periode ini"
              description="Calon ini belum tercatat sebagai peserta tahap mana pun; daftar di bawah adalah tahap yang tersedia."
            >
              {periodStagesQuery.isLoading ? (
                <LoadingLine label="Memuat tahap..." />
              ) : (periodStagesQuery.data ?? []).length === 0 ? (
                <p className="text-sm text-slate-400">Belum ada tahap seleksi dibuat pada periode ini.</p>
              ) : (
                <ul className="divide-y divide-slate-50 text-sm">
                  {(periodStagesQuery.data ?? []).map((stage) => (
                    <li key={stage.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                      <span className="text-slate-700">
                        {stage.name}
                        <span className="ml-1 text-xs text-slate-400">
                          {stage.scheduled_at ? formatDateTime(stage.scheduled_at) : 'tanpa jadwal'} ·{' '}
                          {stage.program?.name ?? 'semua program'}
                        </span>
                      </span>
                      <span className="text-xs text-slate-500">
                        bobot {stage.weight ?? 0} · KKM {stage.passing_score ?? '—'} ·{' '}
                        {stage.scored_participants_count ?? 0}/{stage.participants_count ?? 0} dinilai
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </SectionCard>
          )}
        </div>
      )}

      {tab === 'riwayat' && (
        <SectionCard
          title="Riwayat perubahan"
          description="Dikirim pada `histories` respons berkas — setiap aksi petugas maupun orang tua tercatat."
          action={
            <Button
              size="sm"
              variant="ghost"
              onClick={() => detailQuery.refetch()}
              loading={detailQuery.isFetching}
            >
              <RefreshCw className="h-3.5 w-3.5" /> Muat ulang
            </Button>
          }
          bodyClassName="rounded-lg border border-slate-100"
        >
          {histories.length === 0 ? (
            <p className="py-6 text-center text-sm text-slate-400">Belum ada riwayat tercatat.</p>
          ) : (
            <ol className="divide-y divide-slate-50">
              {histories.map((entry) => (
                <HistoryRow key={entry.id} entry={entry} />
              ))}
            </ol>
          )}
        </SectionCard>
      )}
    </div>
  );
}

/* ============================ RIWAYAT & FORMULIR (murni) ============================ */

function HistoryRow({ entry }: { entry: DetailHistoryRow }) {
  return (
    <li className="flex flex-wrap items-start justify-between gap-2 px-4 py-2.5">
      <div className="min-w-0">
        <p className="text-sm font-medium text-slate-700">
          {entry.action_label ?? historyActionLabel(entry.action)}
        </p>
        <p className="text-xs text-slate-500">
          {entry.changed_by_name ?? 'sistem'} · {formatDateTime(entry.created_at)}
        </p>
        {entry.note && <p className="mt-1 text-xs text-slate-600">{entry.note}</p>}
      </div>
      <div className="flex shrink-0 items-center gap-1.5">
        {entry.from_status && <RegistrationStatusBadge status={entry.from_status} />}
        {entry.to_status && entry.to_status !== entry.from_status && (
          <>
            <span className="text-xs text-slate-400">→</span>
            <RegistrationStatusBadge status={entry.to_status} />
          </>
        )}
      </div>
    </li>
  );
}

interface AnswerRow {
  key: string;
  label: string;
  value: string;
}

interface AnswerGroup {
  key: string;
  label: string;
  rows: AnswerRow[];
}

/**
 * Skema formulir (`PpdbScopeService::schemaFor()`) digabung dengan jawaban
 * `PpdbAnswerService::keyed()`.
 *
 * `keyed()` mengirim DUA peta bernilai — `fields: {field_key: nilai}` dan
 * `questions: {"<id>": nilai}` — bukan daftar jawaban dan bukan `answer_map`.
 * Skema tetap jadi acuan urutan dan label; kunci yang nilainya ada di luar
 * skema (kolom kandidat, pertanyaan yang sudah dinonaktifkan) ditampilkan
 * terpisah supaya tidak hilang.
 */
function buildFieldGroups(detail: AdminRegistrationDetail): {
  groups: AnswerGroup[];
  orphan: AnswerRow[];
} {
  const fieldAnswers = detail.answers.fields;
  const questionAnswers = detail.answers.questions;
  const groups = new Map<string, AnswerGroup>();
  const usedFieldKeys = new Set<string>();
  const usedQuestionIds = new Set<string>();

  const push = (sectionKey: string, sectionLabel: string, row: AnswerRow) => {
    const group = groups.get(sectionKey) ?? { key: sectionKey, label: sectionLabel, rows: [] };
    group.rows.push(row);
    groups.set(sectionKey, group);
  };

  const sectionLabelOf = (section: PpdbFieldSection) => {
    const fromOrdered = detail.schema.sections_ordered.find((item) => item.key === section)?.label;
    return fromOrdered ?? detail.schema.sections[section] ?? FIELD_SECTION_LABELS[section] ?? prettifyKey(section);
  };

  for (const field of detail.schema.fields) {
    usedFieldKeys.add(field.key);
    push(`section:${field.section}`, sectionLabelOf(field.section), {
      key: `field:${field.key}`,
      label: field.label,
      value: answerDisplay(fieldAnswers[field.key], field.options, field.type),
    });
  }

  for (const question of detail.schema.questions) {
    const mapKey = String(question.id);
    usedQuestionIds.add(mapKey);
    push('section:questions', 'Pertanyaan khusus', {
      key: `question:${mapKey}`,
      label: question.question,
      value: answerDisplay(
        questionAnswers[mapKey],
        question.options.map((option) => ({ label: option.label, value: option.value ?? undefined })),
        question.type,
      ),
    });
  }

  const orphan: AnswerRow[] = [];
  for (const [key, value] of Object.entries(fieldAnswers)) {
    if (usedFieldKeys.has(key)) continue;
    orphan.push({ key: `field:${key}`, label: prettifyKey(key), value: answerDisplay(value) });
  }
  for (const [id, value] of Object.entries(questionAnswers)) {
    if (usedQuestionIds.has(id)) continue;
    orphan.push({ key: `question:${id}`, label: `Pertanyaan #${id}`, value: answerDisplay(value) });
  }

  const orderedKeys = detail.schema.sections_ordered.map((item) => `section:${item.key}`);
  const sorted = [...groups.values()].sort((a, b) => {
    const aIndex = orderedKeys.indexOf(a.key);
    const bIndex = orderedKeys.indexOf(b.key);
    if (aIndex === -1 && bIndex === -1) return a.label.localeCompare(b.label);
    if (aIndex === -1) return 1;
    if (bIndex === -1) return -1;
    return aIndex - bIndex;
  });

  return { groups: sorted, orphan };
}
