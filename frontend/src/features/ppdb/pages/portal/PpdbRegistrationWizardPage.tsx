import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  BadgeCheck,
  CheckCircle2,
  CloudUpload,
  Info,
  Lock,
  Save,
  Send,
  ShieldAlert,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { ApplicantForm, applicantToForm, emptyApplicantForm, formToApplicant, validateApplicantForm, type ApplicantFormValues } from '../../components/portal/ApplicantForm';
import { DataState } from '../../components/portal/DataState';
import { DocumentSlotCard } from '../../components/portal/DocumentSlotCard';
import { DynamicFieldInput, toFieldSpec, toQuestionSpec, validateSpec, type FieldSpec } from '../../components/portal/DynamicFieldInput';
import { PaymentPanel } from '../../components/portal/PaymentPanel';
import { RegistrationStepper, type StepDescriptor, type StepKey } from '../../components/portal/RegistrationStepper';
import { ReviewSummary } from '../../components/portal/ReviewSummary';
import { StatusBadges } from '../../components/portal/StatusBadges';
import { formatClock, formatDate, formatDateTime, formatRupiah, quotaLine } from '../../components/portal/format';
import { FEE_TYPE_LABELS, SECTION_LABELS } from '../../components/portal/labels';
import { ppdbPortalPaymentApi, ppdbPortalRegistrationsApi, ppdbPublicApi } from '../../api/ppdbApi';
import { getErrorMessage } from '@/lib/axios';
import { cn } from '@/lib/utils';
import type {
  PpdbAnswerValue,
  PpdbPortalRegistrationDetail,
  PpdbPublicProgram,
  PpdbPublicWave,
  PpdbRegistrationProgressStep,
  PpdbRegistrationUpdatePayload,
} from '@/types/ppdbPortal';
import type { PpdbFieldSection } from '@/types/ppdb';

const AUTOSAVE_DELAY = 800;

/**
 * Label cadangan — server sudah mengirim `label` untuk tiap langkah
 * (`PpdbRegistrationQueryService::progress()`), jadi nilai ini hanya dipakai
 * sebelum respons itu tiba atau bila labelnya kosong.
 */
const STEP_LABELS: Record<StepKey, string> = {
  data_calon: 'Calon siswa',
  pilihan: 'Program & gelombang',
  formulir: 'Formulir',
  pertanyaan: 'Pertanyaan',
  dokumen: 'Dokumen',
  review: 'Tinjauan',
  bayar: 'Pembayaran',
  kirim: 'Kirim',
};

/** Susunan langkah sebelum respons detail tiba (urutan sama dengan `progress()`). */
const FALLBACK_STEPS: StepDescriptor[] = [
  { key: 'data_calon', label: STEP_LABELS.data_calon, done: false, required: true },
  { key: 'pilihan', label: STEP_LABELS.pilihan, done: true, required: true },
  { key: 'formulir', label: STEP_LABELS.formulir, done: false, required: true },
  { key: 'pertanyaan', label: STEP_LABELS.pertanyaan, done: false, required: false },
  { key: 'dokumen', label: STEP_LABELS.dokumen, done: false, required: true },
  { key: 'review', label: STEP_LABELS.review, done: false, required: true },
  { key: 'bayar', label: STEP_LABELS.bayar, done: false, required: false },
  { key: 'kirim', label: STEP_LABELS.kirim, done: false, required: true },
];

const STEP_HINTS: Record<StepKey, string> = {
  data_calon: 'Identitas anak Anda. Bagian ini tersimpan otomatis sebagai draf.',
  pilihan: 'Program dan gelombang yang sedang Anda tuju beserta konsekuensi biayanya.',
  formulir: 'Pertanyaan wajib yang ditetapkan sekolah untuk periode ini.',
  pertanyaan: 'Pertanyaan tambahan dari panitia, dapat berbeda menurut program atau gelombang.',
  dokumen: 'Unggah berkas sesuai persyaratan. Berkas yang ditolak dapat Anda ganti.',
  review: 'Periksa seluruh isian sebelum dikirim. Setelah dikirim, data dikunci.',
  bayar: 'Tagihan resmi dan pelaporan pembayaran Anda.',
  kirim: 'Pengiriman akhir berkas pendaftaran.',
};

/**
 * Tautan `?langkah=` pada notifikasi & halaman seleksi masih memakai nama kunci
 * lama (`calon`, `pembayaran`), sedangkan kunci progres dari server adalah
 * `data_calon` dan `bayar`.
 */
const STEP_KEY_ALIASES: Record<string, StepKey> = {
  calon: 'data_calon',
  pembayaran: 'bayar',
};

function resolveStepKey(raw: string): StepKey | null {
  const key = STEP_KEY_ALIASES[raw] ?? raw;
  return Object.prototype.hasOwnProperty.call(STEP_HINTS, key) ? (key as StepKey) : null;
}

/**
 * `required` tidak dikirim `progress()` — diturunkan dari angka langkah itu
 * sendiri: biaya ada hanya bila `applicable`, dan sebuah langkah isian wajib
 * hanya bila jumlah isian wajibnya lebih dari nol.
 */
function stepIsRequired(step: PpdbRegistrationProgressStep): boolean {
  switch (step.key) {
    case 'bayar':
      return step.applicable;
    case 'formulir':
    case 'pertanyaan':
    case 'dokumen':
      return step.required_total > 0;
    default:
      return true;
  }
}

interface AnswerMaps {
  fields: Record<string, PpdbAnswerValue>;
  questions: Record<string, PpdbAnswerValue>;
}

const EMPTY_ANSWERS: AnswerMaps = { fields: {}, questions: {} };

/** Kunci `answers` (`PpdbAnswerService::keyed()`): `fields.{field_key}` & `questions.{id}`. */
function detailToAnswers(detail: PpdbPortalRegistrationDetail): AnswerMaps {
  return {
    fields: { ...(detail.answers.fields ?? {}) },
    questions: { ...(detail.answers.questions ?? {}) },
  };
}

/** Satu baris label+nilai untuk ringkasan singkat di luar langkah tinjauan. */
function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-1 border-b border-slate-100 py-1.5 text-sm last:border-0">
      <span className="text-xs text-slate-500">{label}</span>
      <span className="text-slate-800">{value}</span>
    </div>
  );
}

/** Wizard pendaftaran: draf tersimpan otomatis, langkah disusun oleh server. */
export function PpdbRegistrationWizardPage() {
  const { id: idParam = '' } = useParams();
  const registrationId = Number(idParam);
  const [searchParams, setSearchParams] = useSearchParams();
  const queryClient = useQueryClient();

  const [stepIndex, setStepIndex] = useState(0);
  const [applicant, setApplicant] = useState<ApplicantFormValues>(emptyApplicantForm);
  const [answers, setAnswers] = useState<AnswerMaps>(EMPTY_ANSWERS);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [savedAt, setSavedAt] = useState<Date | null>(null);
  const [saveState, setSaveState] = useState<'idle' | 'pending' | 'saving' | 'error'>('idle');
  const [confirmOpen, setConfirmOpen] = useState(false);

  const dirty = useRef<{ fields: Set<string>; questions: Set<string>; applicant: Set<string> }>({
    fields: new Set(),
    questions: new Set(),
    applicant: new Set(),
  });
  const latest = useRef<{ applicant: ApplicantFormValues; answers: AnswerMaps }>({ applicant, answers });
  const timer = useRef<number | undefined>(undefined);
  const hydrated = useRef<number | null>(null);

  const detailQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'registration', registrationId],
    queryFn: () => ppdbPortalRegistrationsApi.show(registrationId),
    enabled: Number.isFinite(registrationId) && registrationId > 0,
  });

  const detail = detailQuery.data;

  useEffect(() => {
    if (!detail || hydrated.current === detail.id) return;
    hydrated.current = detail.id;
    setApplicant(applicantToForm(detail.applicant));
    setAnswers(detailToAnswers(detail));
    dirty.current = { fields: new Set(), questions: new Set(), applicant: new Set() };
    setSaveState('idle');
    setStepIndex(0);
  }, [detail]);

  useEffect(() => () => window.clearTimeout(timer.current), []);
  useEffect(() => {
    latest.current = { applicant, answers };
  }, [applicant, answers]);

  // `editable` sudah dihitung server lewat `PpdbRegistration::isEditable()`
  // (draft & need_revision saja), jadi tidak disaring ulang di sini.
  const editable = Boolean(detail?.editable);
  const needsRevision = detail?.status === 'need_revision';
  // Pelaporan pembayaran tidak dikunci status pendaftaran di backend
  // (`PpdbPaymentController::store()` cuma memeriksa kepemilikan tagihan), jadi
  // tetap bisa dilakukan sesudah berkas dikirim — kecuali berkas sudah selesai
  // atau dibatalkan.
  const canReportPayment = !['cancelled', 'completed'].includes(detail?.status ?? '');

  const buildPayload = useCallback((): PpdbRegistrationUpdatePayload | null => {
    const { fields, questions, applicant: dirtyApplicant } = dirty.current;
    const snapshot = latest.current;
    if (!fields.size && !questions.size && !dirtyApplicant.size) return null;

    const payload: PpdbRegistrationUpdatePayload = {};
    if (fields.size) {
      payload.fields = {};
      for (const key of fields) payload.fields[key] = snapshot.answers.fields[key] ?? null;
    }
    if (questions.size) {
      payload.questions = {};
      for (const key of questions) payload.questions[key] = snapshot.answers.questions[key] ?? null;
    }
    if (dirtyApplicant.size) payload.applicant = formToApplicant(snapshot.applicant);
    return payload;
  }, []);

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = buildPayload();
      if (!payload) return Promise.resolve(undefined as never);
      return ppdbPortalRegistrationsApi.update(registrationId, payload);
    },
    onMutate: () => setSaveState('saving'),
    onSuccess: () => {
      dirty.current = { fields: new Set(), questions: new Set(), applicant: new Set() };
      setSaveState('idle');
      setSavedAt(new Date());
    },
    onError: (error) => {
      setSaveState('error');
      toast.error(getErrorMessage(error));
    },
  });

  const flushSave = useCallback(() => {
    window.clearTimeout(timer.current);
    if (editable && !saveMutation.isPending) saveMutation.mutate();
  }, [editable, saveMutation]);

  const scheduleSave = useCallback(() => {
    if (!editable) return;
    setSaveState('pending');
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(flushSave, AUTOSAVE_DELAY);
  }, [editable, flushSave]);

  const markApplicant = (key: keyof ApplicantFormValues, value: string) => {
    setApplicant((prev) => ({ ...prev, [key]: value }));
    dirty.current.applicant.add(key);
    setErrors((prev) => {
      if (!prev[`applicant.${key}`]) return prev;
      const copy = { ...prev };
      delete copy[`applicant.${key}`];
      return copy;
    });
    scheduleSave();
  };

  const setFieldAnswer = (key: string, value: PpdbAnswerValue) => {
    setAnswers((prev) => ({ ...prev, fields: { ...prev.fields, [key]: value } }));
    dirty.current.fields.add(key);
    setErrors((prev) => {
      if (!prev[`fields.${key}`]) return prev;
      const copy = { ...prev };
      delete copy[`fields.${key}`];
      return copy;
    });
    scheduleSave();
  };

  const setQuestionAnswer = (key: string, value: PpdbAnswerValue) => {
    setAnswers((prev) => ({ ...prev, questions: { ...prev.questions, [key]: value } }));
    dirty.current.questions.add(key);
    setErrors((prev) => {
      if (!prev[`questions.${key}`]) return prev;
      const copy = { ...prev };
      delete copy[`questions.${key}`];
      return copy;
    });
    scheduleSave();
  };

  /** Validasi lengkap sisi klien: dipakai untuk menandai langkah & menahan kirim. */
  const collectErrors = useCallback((): Record<string, string> => {
    if (!detail) return {};
    const next: Record<string, string> = {};
    const applicantErrors = validateApplicantForm(latest.current.applicant);
    for (const [key, message] of Object.entries(applicantErrors)) next[`applicant.${key}`] = message;
    // Field bertipe `file` sengaja dilewati: backend juga tidak pernah memintanya
    // di jalur jawaban (`PpdbScopeService::validationRules()` tidak membuat aturan
    // untuknya, `missingRequiredFields(skipFile: true)` dan langkah `formulir` pada
    // `progress()` tidak menghitungnya). Berkasnya dikumpulkan lewat modul dokumen,
    // sehingga menandai isian itu kosong akan mengunci tombol kirim selamanya.
    for (const spec of detail.schema.fields.map(toFieldSpec)) {
      if (spec.type === 'file') continue;
      const message = validateSpec(spec, latest.current.answers.fields[spec.key]);
      if (message) next[`fields.${spec.key}`] = message;
    }
    for (const spec of detail.schema.questions.map(toQuestionSpec)) {
      const message = validateSpec(spec, latest.current.answers.questions[spec.key]);
      if (message) next[`questions.${spec.key}`] = message;
    }
    return next;
  }, [detail]);

  const liveErrors = useMemo(() => collectErrors(), [collectErrors]);
  const stepHasError = (key: StepKey) => {
    const prefix =
      key === 'data_calon'
        ? 'applicant.'
        : key === 'formulir'
          ? 'fields.'
          : key === 'pertanyaan'
            ? 'questions.'
            : null;
    if (!prefix) return false;
    return Object.keys(liveErrors).some((entry) => entry.startsWith(prefix));
  };

  // `summary.missing` = syarat wajib yang belum punya berkas aktif.
  const documentsIncomplete = detail ? detail.documents.summary.missing.length > 0 : false;

  /**
   * Langkah disusun dari `detail.progress` (kunci, label, dan `done` dari server),
   * lalu langkah `pilihan` disisipkan di sini karena `progress()` tidak
   * mengirimkannya — program & gelombang terkunci saat pendaftaran dibuat.
   */
  const steps: StepDescriptor[] = useMemo(() => {
    if (!detail) return FALLBACK_STEPS;
    const described: StepDescriptor[] = [];
    for (const step of detail.progress) {
      const liveDone =
        step.key === 'data_calon'
          ? Boolean(detail.applicant?.full_name) && !stepHasError('data_calon')
          : step.key === 'formulir' || step.key === 'pertanyaan'
            ? !stepHasError(step.key)
            : false;
      described.push({
        key: step.key,
        label: step.label || STEP_LABELS[step.key],
        required: stepIsRequired(step),
        done: step.done || liveDone,
      });
      if (step.key === 'data_calon') {
        described.push({
          key: 'pilihan',
          label: STEP_LABELS.pilihan,
          required: true,
          done: Boolean(detail.program_id),
        });
      }
    }
    return described.length ? described : FALLBACK_STEPS;
  }, [detail, liveErrors]);

  // Tautan dalam dari notifikasi/halaman seleksi: ?langkah=<key> membuka langkah terkait sekali saja.
  const deepLink = useRef<string | null>(searchParams.get('langkah'));
  useEffect(() => {
    const raw = deepLink.current;
    if (!raw || !steps.length) return;
    const key = resolveStepKey(raw);
    const index = key ? steps.findIndex((step) => step.key === key) : -1;
    if (index >= 0) setStepIndex(index);
    else toast.error('Langkah yang diminta tidak tersedia pada pendaftaran ini.');
    deepLink.current = null;
    if (searchParams.get('langkah')) {
      const next = new URLSearchParams(searchParams);
      next.delete('langkah');
      setSearchParams(next, { replace: true });
    }
  }, [searchParams, setSearchParams, steps]);

  const currentStep = steps[Math.min(stepIndex, steps.length - 1)];
  const currentKey = currentStep?.key ?? 'data_calon';

  // Jadwal periode tidak ikut respons detail (`PpdbRegistrationResource::ref()`
  // hanya `{id, code, name, slug}`), jadi dibaca dari endpoint publik.
  const periodSlug = detail?.period?.slug ?? null;
  const publicPeriodQuery = useQuery({
    queryKey: ['ppdb', 'public', 'period', periodSlug ?? ''],
    queryFn: () => ppdbPublicApi.period(periodSlug ?? ''),
    enabled: Boolean(periodSlug) && (currentKey === 'pilihan' || needsRevision),
    staleTime: 120_000,
  });

  const paymentQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'payment', registrationId],
    queryFn: () => ppdbPortalPaymentApi.forRegistration(registrationId),
    enabled: (currentKey === 'bayar' || confirmOpen) && Number.isFinite(registrationId),
  });

  // Tagihan tidak ikut respons detail (`invoices` hanya untuk admin), jadi nomor
  // tagihan pada konfirmasi kirim dibaca dari `GET .../payment` (`invoices[]`).
  const invoices = paymentQuery.data?.invoices ?? [];
  const invoiceLabel = invoices.length
    ? invoices.length > 1
      ? `${invoices[0]?.number ?? '-'} (+${invoices.length - 1} tagihan lain)`
      : (invoices[0]?.number ?? '-')
    : paymentQuery.isFetching
      ? 'memuat tagihan...'
      : 'belum diterbitkan';

  const goToStep = (index: number) => {
    if (index === stepIndex) return;
    flushSave();
    setStepIndex(index);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const goNext = () => {
    const allowed = currentKey === 'data_calon' ? validateApplicantForm(applicant) : {};
    if (Object.keys(allowed).length) {
      const mapped: Record<string, string> = {};
      for (const [key, message] of Object.entries(allowed)) mapped[`applicant.${key}`] = message;
      setErrors((prev) => ({ ...prev, ...mapped }));
      toast.error('Lengkapi identitas anak sebelum melanjutkan.');
      return;
    }
    goToStep(Math.min(stepIndex + 1, steps.length - 1));
  };

  const stepIndexOf = (key: StepKey) => steps.findIndex((step) => step.key === key);

  const submitMutation = useMutation({
    mutationFn: () => ppdbPortalRegistrationsApi.submit(registrationId),
    onSuccess: () => {
      setConfirmOpen(false);
      toast.success('Pendaftaran terkirim. Panitia akan memeriksa berkas Anda.');
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'registration'] });
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'dashboard'] });
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'notifications'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const attemptSubmit = () => {
    const found = collectErrors();
    if (Object.keys(found).length) {
      setErrors(found);
      const firstKey = Object.keys(found)[0];
      const target = firstKey.startsWith('fields.') ? stepIndexOf('formulir') : firstKey.startsWith('questions.') ? stepIndexOf('pertanyaan') : stepIndexOf('data_calon');
      goToStep(target >= 0 ? target : 0);
      toast.error(`${Object.keys(found).length} isian belum benar. Perhatikan tanda merah.`);
      return;
    }
    if (documentsIncomplete) {
      goToStep(Math.max(stepIndexOf('dokumen'), 0));
      toast.error('Lengkapi dokumen wajib sebelum mengirim.');
      return;
    }
    flushSave();
    setConfirmOpen(true);
  };

  const saveStatusText = () => {
    if (!editable) return null;
    if (saveState === 'saving' || saveState === 'pending') return 'Menyimpan draf...';
    if (saveState === 'error') return 'Draf gagal disimpan — coba simpan manual';
    if (savedAt) return `Tersimpan otomatis ${formatClock(savedAt)}`;
    return 'Draf belum ada perubahan';
  };

  // Penanda revisi diambil dari `blockers()`: `key` field = `field_key`,
  // `key` pertanyaan = id pertanyaan sebagai string (sama dengan kunci jawaban).
  const flaggedFieldKeys = useMemo(() => {
    if (!needsRevision || !detail) return new Set<string>();
    return new Set<string>(detail.blockers.fields.map((item) => item.key));
  }, [needsRevision, detail]);

  const flaggedQuestionIds = useMemo(() => {
    if (!needsRevision || !detail) return new Set<string>();
    return new Set<string>(detail.blockers.questions.map((item) => item.key));
  }, [needsRevision, detail]);

  const missingDocumentIds = useMemo(() => {
    if (!detail) return new Set<number>();
    return new Set<number>(detail.blockers.documents.map((item) => item.id));
  }, [detail]);

  if (!Number.isFinite(registrationId) || registrationId <= 0) {
    return (
      <Card className="p-6 text-center">
        <p className="text-sm font-medium text-slate-800">Tautan pendaftaran tidak dikenali</p>
        <p className="mt-1 text-xs text-slate-500">Buka daftar pendaftaran Anda untuk memilih berkas yang benar.</p>
        <Link to="/ppdb/portal/pendaftaran" className="mt-3 inline-block">
          <Button size="sm">Ke daftar pendaftaran</Button>
        </Link>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <DataState
        isLoading={detailQuery.isLoading}
        isError={detailQuery.isError}
        error={detailQuery.error}
        onRetry={() => detailQuery.refetch()}
        isEmpty={!detail}
        loadingLabel="Memuat berkas pendaftaran..."
        errorTitle="Berkas pendaftaran tidak dapat dimuat"
        emptyTitle="Pendaftaran tidak ditemukan"
      >
        {detail && (
          <>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <Link
                  to="/ppdb/portal/pendaftaran"
                  className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline"
                >
                  <ArrowLeft className="h-3 w-3" /> Semua pendaftaran
                </Link>
                <h1 className="mt-1 truncate text-lg font-semibold text-slate-900">
                  {detail.applicant?.full_name || 'Calon siswa'}
                </h1>
                <p className="text-xs text-slate-500">
                  {detail.registration_number} ·{' '}
                  {detail.program?.name ?? 'program belum dipilih'} ·{' '}
                  {detail.wave?.name ?? 'tanpa gelombang'}
                </p>
              </div>
              <div className="shrink-0 text-right">
                <StatusBadges
                  status={detail.status}
                  paymentStatus={detail.payment_status}
                  documentStatus={detail.document_status}
                />
                <p className="mt-1 flex items-center justify-end gap-1 text-[11px] text-slate-500">
                  {saveState === 'saving' || saveState === 'pending' ? (
                    <Spinner className="h-3 w-3" />
                  ) : saveState === 'error' ? (
                    <AlertCircle className="h-3 w-3 text-red-500" />
                  ) : (
                    <CloudUpload className="h-3 w-3 text-green-600" />
                  )}
                  {saveStatusText()}
                </p>
              </div>
            </div>

            {!editable && (
              <Card className="border-slate-300 bg-slate-50 p-4">
                <p className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                  <Lock className="h-4 w-4" /> Berkas ini sudah terkirim dan dalam keadaan baca-saja
                </p>
                <p className="mt-1 text-xs text-slate-600">
                  Status saat ini: {detail.status_label}. Data tidak dapat diubah bebas. Bila ada
                  kekeliruan, hubungi panitia — hanya panitia yang dapat meminta revisi.
                </p>
              </Card>
            )}

            {needsRevision && (
              <Card className="border-amber-300 bg-amber-50/70 p-4">
                <p className="flex items-center gap-1.5 text-sm font-semibold text-amber-900">
                  <ShieldAlert className="h-4 w-4" /> Panitia meminta revisi
                </p>
                {detail.revision_note ? (
                  <p className="mt-1 whitespace-pre-line text-xs text-amber-900">{detail.revision_note}</p>
                ) : (
                  <p className="mt-1 text-xs text-amber-900">
                    Perbaiki bagian yang ditandai merah di bawah, lalu kirim ulang berkas Anda.
                  </p>
                )}
                <div className="mt-2 flex flex-wrap gap-1 text-[11px]">
                  {detail.blockers.fields.length > 0 && (
                    <Badge tone="amber">{detail.blockers.fields.length} isian perlu diperbaiki</Badge>
                  )}
                  {detail.blockers.questions.length > 0 && (
                    <Badge tone="amber">{detail.blockers.questions.length} pertanyaan perlu dijawab</Badge>
                  )}
                  {detail.blockers.documents.length > 0 && (
                    <Badge tone="amber">{detail.blockers.documents.length} dokumen perlu diunggah ulang</Badge>
                  )}
                </div>
                <p className="mt-2 text-[11px] text-amber-800">
                  Batas waktu revisi mengikuti jadwal periode (
                  {formatDate(publicPeriodQuery.data?.registration_end)}).
                </p>
              </Card>
            )}

            <RegistrationStepper
              steps={steps}
              current={stepIndex}
              onSelect={goToStep}
              canVisit={() => true}
            />

            <section>
              <div className="mb-2 flex items-start gap-1.5 rounded-lg bg-white px-3 py-2 text-xs text-slate-600 ring-1 ring-slate-200">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" />
                <span>{STEP_HINTS[currentKey]}</span>
              </div>

              {currentKey === 'data_calon' && (
                <Card className="p-4">
                  <ApplicantForm values={applicant} errors={prefixErrors(errors, 'applicant.')} onChange={markApplicant} disabled={!editable} />
                  <p className="mt-3 text-xs text-slate-500">
                    Jenis kelamin dan agama anak dicatat pada formulir dinamis periode ini, jadi tidak
                    diminta di sini.
                  </p>
                </Card>
              )}

              {currentKey === 'pilihan' && (
                <PilihanStep
                  detail={detail}
                  periodPrograms={publicPeriodQuery.data?.programs ?? []}
                  periodWaves={publicPeriodQuery.data?.waves ?? []}
                  periodStart={publicPeriodQuery.data?.registration_start ?? null}
                  periodEnd={publicPeriodQuery.data?.registration_end ?? null}
                  loading={publicPeriodQuery.isLoading}
                />
              )}

              {currentKey === 'formulir' && (
                <FormSchemaStep
                  detail={detail}
                  answers={answers}
                  errors={prefixErrors(errors, 'fields.')}
                  flagged={flaggedFieldKeys}
                  disabled={!editable}
                  onChange={setFieldAnswer}
                />
              )}

              {currentKey === 'pertanyaan' && (
                <QuestionsStep
                  detail={detail}
                  answers={answers}
                  errors={prefixErrors(errors, 'questions.')}
                  flagged={flaggedQuestionIds}
                  disabled={!editable}
                  onChange={setQuestionAnswer}
                />
              )}

              {currentKey === 'dokumen' && (
                <div className="space-y-3">
                  {detail.documents.requirements.length === 0 ? (
                    <Card className="p-4 text-sm text-slate-500">
                      Periode ini tidak meminta berkas unggahan. Anda dapat langsung melanjutkan ke
                      langkah berikutnya.
                    </Card>
                  ) : (
                    detail.documents.requirements.map((slot) => (
                      <DocumentSlotCard
                        key={slot.id}
                        slot={slot}
                        registrationId={detail.id}
                        disabled={!detail.document_upload_allowed}
                        flagged={needsRevision && (slot.status === 'rejected' || missingDocumentIds.has(slot.id))}
                        flagNote={slot.document?.review_note ?? detail.revision_note}
                        onUploaded={() => {
                          queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'registration', registrationId] });
                        }}
                      />
                    ))
                  )}
                  <DocumentTally detail={detail} />
                </div>
              )}

              {currentKey === 'review' && (
                <div className="space-y-3">
                  <ReviewSummary detail={detail} applicant={applicant} answers={answers} />
                  <SubmitPanel
                    detail={detail}
                    editable={editable}
                    blocked={Object.keys(liveErrors).length > 0 || documentsIncomplete || !detail.can_submit}
                    submitting={submitMutation.isPending}
                    onOpenConfirm={attemptSubmit}
                  />
                </div>
              )}

              {currentKey === 'bayar' && (
                <div className="space-y-3">
                  {paymentQuery.isLoading ? (
                    <Card>
                      <div className="flex items-center justify-center gap-2 py-12 text-sm text-slate-500">
                        <Spinner /> Memuat tagihan...
                      </div>
                    </Card>
                  ) : paymentQuery.isError ? (
                    <Card>
                      <div className="p-4 text-center">
                        <p className="text-sm font-medium text-slate-800">Tagihan belum dapat dimuat</p>
                        <p className="mt-1 text-xs text-slate-500">{getErrorMessage(paymentQuery.error)}</p>
                        <Button size="sm" variant="outline" className="mt-2" onClick={() => paymentQuery.refetch()}>
                          Coba lagi
                        </Button>
                      </div>
                    </Card>
                  ) : paymentQuery.data ? (
                    <PaymentPanel payment={paymentQuery.data} disabled={!canReportPayment} />
                  ) : (
                    <Card className="p-4">
                      <p className="text-sm font-medium text-slate-800">
                        {detail.schema.fee_total > 0
                          ? 'Tagihan Anda belum diterbitkan'
                          : 'Tidak ada biaya pada pilihan ini'}
                      </p>
                      <p className="mt-1 text-xs text-slate-500">
                        {detail.schema.fee_total > 0
                          ? 'Panitia membuat tagihan setelah pendaftaran dikirim. Halaman ini terisi otomatis begitu tagihan terbit.'
                          : 'Pendaftaran Anda tidak memerlukan pembayaran, jadi langkah ini dapat dilewati.'}
                      </p>
                    </Card>
                  )}
                </div>
              )}

              {currentKey === 'kirim' && (
                <div className="space-y-3">
                  <ChecklistCard detail={detail} liveErrorCount={Object.keys(liveErrors).length} documentsIncomplete={documentsIncomplete} />
                  <SubmitPanel
                    detail={detail}
                    editable={editable}
                    blocked={Object.keys(liveErrors).length > 0 || documentsIncomplete || !detail.can_submit}
                    submitting={submitMutation.isPending}
                    onOpenConfirm={attemptSubmit}
                  />
                </div>
              )}
            </section>

            <div className="sticky bottom-0 -mx-4 flex items-center gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
              <Button
                variant="outline"
                onClick={() => goToStep(Math.max(stepIndex - 1, 0))}
                disabled={stepIndex === 0}
                className="flex-1 sm:flex-none"
              >
                <ArrowLeft className="h-4 w-4" />
                Kembali
              </Button>
              <Button variant="ghost" onClick={flushSave} disabled={!editable || saveMutation.isPending} className="hidden sm:inline-flex">
                <Save className="h-4 w-4" />
                Simpan draf
              </Button>
              {stepIndex < steps.length - 1 ? (
                <Button onClick={goNext} className="flex-1 sm:flex-none">
                  Lanjut
                  <ArrowRight className="h-4 w-4" />
                </Button>
              ) : (
                <Button onClick={attemptSubmit} disabled={!editable || !detail.can_submit} className="flex-1 sm:flex-none">
                  <Send className="h-4 w-4" />
                  Kirim pendaftaran
                </Button>
              )}
            </div>
          </>
        )}
      </DataState>

      <Modal
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        title="Kirim pendaftaran?"
        footer={
          <>
            <Button variant="outline" onClick={() => setConfirmOpen(false)}>
              Periksa lagi
            </Button>
            <Button loading={submitMutation.isPending} onClick={() => submitMutation.mutate()}>
              Ya, kirim
            </Button>
          </>
        }
      >
        <p className="text-sm text-slate-600">
          Setelah dikirim, isian Anda dikunci dan tidak dapat diubah bebas. Panitia akan memeriksa
          berkas, lalu menghubungi Anda melalui portal bila ada yang perlu direvisi.
        </p>
        {detail && (
          <ul className="mt-3 space-y-1 text-sm text-slate-700">
            <li>- Anak: {detail.applicant?.full_name ?? 'belum diisi'}</li>
            <li>
              - Program: {detail.program?.name ?? '-'} ({detail.wave?.name ?? 'tanpa gelombang'})
            </li>
            <li>- Nomor tagihan: {invoiceLabel}</li>
            <li>- Total biaya: {formatRupiah(detail.schema.fee_total)}</li>
          </ul>
        )}
      </Modal>
    </div>
  );
}

function prefixErrors(errors: Record<string, string>, prefix: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(errors)) {
    if (key.startsWith(prefix)) out[key.slice(prefix.length)] = value;
  }
  return out;
}

/** Langkah "pilihan": program & gelombang terkunci pada pendaftaran ini. */
function PilihanStep({
  detail,
  periodPrograms,
  periodWaves,
  periodStart,
  periodEnd,
  loading,
}: {
  detail: PpdbPortalRegistrationDetail;
  periodPrograms: PpdbPublicProgram[];
  periodWaves: PpdbPublicWave[];
  periodStart: string | null;
  periodEnd: string | null;
  loading: boolean;
}) {
  const { schema } = detail;

  return (
    <div className="space-y-3">
      <Card className="p-4">
        <p className="text-sm font-semibold text-slate-900">Pilihan yang sedang berjalan</p>
        <div className="mt-2">
          <InfoRow label="Periode" value={detail.period?.name ?? '-'} />
          <InfoRow label="Program" value={detail.program?.name ?? 'Belum dipilih'} />
          <InfoRow label="Gelombang" value={detail.wave?.name ?? 'Tanpa gelombang'} />
          <InfoRow label="Batas pendaftaran" value={`${formatDate(periodStart)} - ${formatDate(periodEnd)}`} />
        </div>
        <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
          Pilihan program dan gelombang terkunci saat pendaftaran dibuat. Untuk mencoba program lain,
          buat pendaftaran baru pada akun yang sama melalui menu Pendaftaran. Berkas isian Anda tidak
          hilang.
        </p>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Biaya yang berlaku untuk pilihan ini</CardTitle>
        </CardHeader>
        <CardBody>
          {schema.fees.length === 0 ? (
            <p className="text-sm text-slate-500">Belum ada biaya yang dipublikasikan untuk pilihan ini.</p>
          ) : (
            <ul className="divide-y divide-slate-100 text-sm">
              {schema.fees.map((fee) => (
                <li key={fee.id} className="flex items-center justify-between gap-2 py-2">
                  <span className="min-w-0 text-slate-700">
                    {fee.name}
                    <span className="ml-1 text-xs text-slate-400">{FEE_TYPE_LABELS[fee.type] ?? fee.type}</span>
                  </span>
                  <span className="shrink-0 font-medium text-slate-800">{formatRupiah(fee.amount)}</span>
                </li>
              ))}
              <li className="flex items-center justify-between gap-2 py-2 font-semibold text-slate-900">
                <span>Total</span>
                <span>{formatRupiah(schema.fee_total)}</span>
              </li>
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Pilihan lain pada periode ini</CardTitle>
        </CardHeader>
        <CardBody>
          {loading ? (
            <div className="flex items-center gap-2 text-sm text-slate-500">
              <Spinner className="h-4 w-4" /> Memuat daftar program...
            </div>
          ) : periodPrograms.length === 0 && periodWaves.length === 0 ? (
            <p className="text-sm text-slate-500">Daftar program publik belum tersedia untuk periode ini.</p>
          ) : (
            <div className="space-y-3">
              {periodWaves.length > 0 && (
                <div>
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Gelombang</p>
                  <ul className="space-y-1">
                    {periodWaves.map((wave) => (
                      <li key={wave.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <span className="text-slate-700">
                          {wave.name}
                          {detail.wave_id === wave.id && <Badge tone="brand" className="ml-2">dipilih</Badge>}
                        </span>
                        <span className="text-xs text-slate-500">
                          {quotaLine(wave.quota, wave.registered)}
                          {wave.registration_end ? ` · tutup ${formatDate(wave.registration_end)}` : ''}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {periodPrograms.length > 0 && (
                <div>
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Program</p>
                  <ul className="space-y-1">
                    {periodPrograms.map((program) => (
                      <li key={program.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <span className="text-slate-700">
                          {program.name}
                          {detail.program_id === program.id && <Badge tone="brand" className="ml-2">dipilih</Badge>}
                        </span>
                        <span className="text-xs text-slate-500">
                          {quotaLine(program.quota, program.registered)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

/** Render field dinamis per section, terurut, tanpa satu pun label keras. */
function FormSchemaStep({
  detail,
  answers,
  errors,
  flagged,
  disabled,
  onChange,
}: {
  detail: PpdbPortalRegistrationDetail;
  answers: AnswerMaps;
  errors: Record<string, string | undefined>;
  flagged: Set<string>;
  disabled: boolean;
  onChange: (key: string, value: PpdbAnswerValue) => void;
}) {
  const sections: PpdbFieldSection[] = detail.schema.sections_ordered.length
    ? detail.schema.sections_ordered.map((section) => section.key)
    : Array.from(new Set(detail.schema.fields.map((field) => field.section)));

  if (!detail.schema.fields.length) {
    return (
      <Card className="p-4 text-sm text-slate-500">
        Periode ini tidak menambahkan isian khusus. Lanjutkan ke langkah berikutnya.
      </Card>
    );
  }

  return (
    <div className="space-y-3">
      {sections.map((section) => {
        const fields = detail.schema.fields
          .filter((field) => field.section === section)
          .sort((a, b) => a.order - b.order);
        if (!fields.length) return null;
        const first = fields[0];
        return (
          <Card key={section}>
            <CardHeader>
              <CardTitle>{first.section_label || SECTION_LABELS[section] || section}</CardTitle>
            </CardHeader>
            <CardBody className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {fields.map((field) => {
                const spec = toFieldSpec(field);
                const isFlagged = flagged.has(spec.key);
                return (
                  <div
                    key={field.id}
                    className={cn(
                      'rounded-lg p-0.5',
                      isFlagged && 'ring-1 ring-amber-300 ring-offset-2',
                      (spec.type === 'textarea' || spec.type === 'multi_select' || spec.type === 'file' || widgetNeedsFullWidth(spec)) && 'sm:col-span-2',
                    )}
                  >
                    {isFlagged && (
                      <p className="mb-1 text-[11px] font-medium text-amber-700">
                        Bagian ini ditandai panitia untuk diperbaiki.
                      </p>
                    )}
                    <DynamicFieldInput
                      spec={spec}
                      value={answers.fields[spec.key]}
                      disabled={disabled}
                      error={errors[spec.key]}
                      onChange={(value) => onChange(spec.key, value)}
                    />
                  </div>
                );
              })}
            </CardBody>
          </Card>
        );
      })}
    </div>
  );
}

function widgetNeedsFullWidth(spec: FieldSpec): boolean {
  const widest = spec.label.length > 28 || spec.options.some((option) => option.label.length > 28);
  return spec.type === 'radio' && widest;
}

/** Pertanyaan tambahan — scope program/gelombang sudah diselesaikan server. */
function QuestionsStep({
  detail,
  answers,
  errors,
  flagged,
  disabled,
  onChange,
}: {
  detail: PpdbPortalRegistrationDetail;
  answers: AnswerMaps;
  errors: Record<string, string | undefined>;
  flagged: Set<string>;
  disabled: boolean;
  onChange: (key: string, value: PpdbAnswerValue) => void;
}) {
  const questions = detail.schema.questions.slice().sort((a, b) => a.order - b.order);

  if (!questions.length) {
    return (
      <Card className="p-4 text-sm text-slate-500">
        Tidak ada pertanyaan tambahan untuk program dan gelombang Anda.
      </Card>
    );
  }

  return (
    <div className="space-y-3">
      {questions.map((question, index) => {
        const spec = toQuestionSpec(question);
        const isFlagged = flagged.has(spec.key);
        return (
          <Card key={question.id} className={cn('p-4', isFlagged && 'border-amber-300 bg-amber-50/40')}>
            <p className="mb-2 text-xs text-slate-500">
              Pertanyaan {index + 1} dari {questions.length}
              {question.program_id ? ' · khusus program tertentu' : ''}
              {question.wave_id ? ' · khusus gelombang tertentu' : ''}
            </p>
            {isFlagged && (
              <p className="mb-1 text-[11px] font-medium text-amber-700">
                Jawabannya diminta panitia untuk diperbaiki.
              </p>
            )}
            <DynamicFieldInput
              spec={spec}
              value={answers.questions[spec.key]}
              disabled={disabled}
              error={errors[spec.key]}
              onChange={(value) => onChange(spec.key, value)}
            />
          </Card>
        );
      })}
    </div>
  );
}

function DocumentTally({ detail }: { detail: PpdbPortalRegistrationDetail }) {
  const { summary, unlinked_documents, can_upload } = detail.documents;

  return (
    <Card className="p-4">
      <p className="text-sm font-medium text-slate-800">
        Kelengkapan dokumen {summary.uploaded}/{summary.total}
        {summary.approved > 0 && (
          <span className="ml-2 text-xs text-green-700">{summary.approved} disetujui</span>
        )}
      </p>
      {summary.missing.length > 0 && (
        <p className="mt-1 text-xs text-red-600">
          Berkas wajib belum terunggah: {summary.missing.map((item) => item.name).join(', ')}.
        </p>
      )}
      {summary.rejected > 0 && (
        <p className="mt-1 text-xs text-red-600">
          {summary.rejected} berkas perlu Anda ganti. Ganti berkas akan membuat versi baru; versi lama
          tetap tersimpan sebagai arsip.
        </p>
      )}
      {unlinked_documents.length > 0 && (
        <p className="mt-1 text-xs text-slate-500">
          {unlinked_documents.length} berkas terunggah tetapi tidak terkait syarat apa pun — hubungi
          panitia bila berkas itu seharusnya dihitung.
        </p>
      )}
      {!can_upload && (
        <p className="mt-1 text-xs text-slate-500">
          Status berkas ini sudah tidak menerima unggahan baru.
        </p>
      )}
    </Card>
  );
}

function ChecklistCard({
  detail,
  liveErrorCount,
  documentsIncomplete,
}: {
  detail: PpdbPortalRegistrationDetail;
  liveErrorCount: number;
  documentsIncomplete: boolean;
}) {
  // Riwayat lengkap (`histories`) hanya dikirim ke admin, jadi jejak proses di
  // portal disusun dari stempel waktu pada baris pendaftaran itu sendiri.
  const trail = [
    { label: 'Berkas dibuat', at: detail.created_at },
    { label: 'Dikirim ke panitia', at: detail.submitted_at },
    { label: 'Diverifikasi', at: detail.verified_at },
    { label: 'Hasil diumumkan', at: detail.announced_at },
    { label: 'Proses selesai', at: detail.completed_at },
  ].filter((entry): entry is { label: string; at: string } => Boolean(entry.at));

  const items = [
    {
      label: 'Identitas calon siswa',
      ok: liveErrorCount === 0 && detail.blockers.applicant.length === 0,
    },
    {
      label: 'Formulir & pertanyaan wajib',
      ok: liveErrorCount === 0 && detail.blockers.fields.length === 0 && detail.blockers.questions.length === 0,
    },
    { label: 'Dokumen wajib terunggah', ok: !documentsIncomplete && detail.blockers.documents.length === 0 },
    {
      label: 'Pembayaran terverifikasi',
      ok: detail.payment_status === 'paid',
      hint: 'Boleh menyusul sesuai ketentuan panitia',
    },
  ];

  return (
    <Card className="p-4">
      <p className="text-sm font-semibold text-slate-900">Daftar periksa sebelum kirim</p>
      <ul className="mt-2 space-y-1.5 text-sm">
        {items.map((item) => (
          <li key={item.label} className="flex items-start gap-2">
            {item.ok ? (
              <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
            ) : (
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
            )}
            <span className={item.ok ? 'text-slate-700' : 'text-slate-800'}>
              {item.label}
              {!item.ok && item.hint && <span className="ml-1 text-xs text-slate-500">({item.hint})</span>}
            </span>
          </li>
        ))}
      </ul>
      {trail.length > 0 && (
        <details className="mt-3 border-t border-slate-100 pt-2">
          <summary className="cursor-pointer text-xs font-medium text-slate-600">
            Riwayat proses ({trail.length})
          </summary>
          <ul className="mt-1 space-y-1 text-xs text-slate-500">
            {trail.map((entry) => (
              <li key={entry.label}>
                {formatDateTime(entry.at)} · {entry.label}
              </li>
            ))}
          </ul>
          <p className="mt-1 text-[11px] text-slate-400">
            Catatan pemeriksaan tiap tahapan hanya dapat dilihat panitia.
          </p>
        </details>
      )}
    </Card>
  );
}

function SubmitPanel({
  detail,
  editable,
  blocked,
  submitting,
  onOpenConfirm,
}: {
  detail: PpdbPortalRegistrationDetail;
  editable: boolean;
  blocked: boolean;
  submitting: boolean;
  onOpenConfirm: () => void;
}) {
  if (!editable) {
    return (
      <Card className="flex items-start gap-2 p-4">
        <BadgeCheck className="mt-0.5 h-5 w-5 shrink-0 text-green-600" />
        <div>
          <p className="text-sm font-semibold text-slate-900">
            Pendaftaran sudah dikirim{' '}
            {detail.submitted_at ? formatDateTime(detail.submitted_at) : ''}
          </p>
          <p className="mt-0.5 text-xs text-slate-500">
            Pantau status dari portal. Anda tetap dapat mengunggah dokumen tambahan bila panitia
            memintanya melalui fitur revisi.
          </p>
          <div className="mt-2 flex flex-col gap-2 sm:flex-row">
            <Link to={`/ppdb/portal/registrations/${detail.id}/seleksi`}>
              <Button size="sm" variant="outline" className="w-full sm:w-auto">
                Lihat tahapan seleksi
              </Button>
            </Link>
            <Link to="/ppdb/portal/notifikasi">
              <Button size="sm" variant="ghost" className="w-full sm:w-auto">
                Cek notifikasi
              </Button>
            </Link>
          </div>
        </div>
      </Card>
    );
  }

  return (
    <Card className="p-4">
      <p className="text-sm font-semibold text-slate-900">Kirim berkas pendaftaran</p>
      <p className="mt-1 text-xs text-slate-500">
        {blocked
          ? 'Tombol kirim aktif setelah seluruh isian wajib benar dan dokumen wajib terunggah.'
          : 'Pastikan nomor kontak Anda aktif karena panitia menghubungi melalui notifikasi portal dan WhatsApp.'}
      </p>
      <Button size="lg" className="mt-3 w-full sm:w-auto" disabled={blocked} loading={submitting} onClick={onOpenConfirm}>
        <Send className="h-4 w-4" />
        Kirim pendaftaran
      </Button>
      <p className="mt-2 text-[11px] text-slate-400">
        {detail.parent ? `Status akun Anda: ${detail.parent.account_status_label}. ` : ''}
        Kirim hanya dapat dilakukan satu kali per pendaftaran.
      </p>
    </Card>
  );
}

export default PpdbRegistrationWizardPage;
