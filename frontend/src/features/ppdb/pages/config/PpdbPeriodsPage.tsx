import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowUpDown, Copy, Globe, Pencil, Plus, RefreshCw, Send, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbPeriodsApi } from '../../api/ppdbApi';
import { getValidationErrors } from '../../api/http';
import { usePpdbReferenceOptions } from '../../hooks/usePpdbReferenceOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage } from '@/lib/axios';
import { slugify } from '@/lib/utils';
import {
  PERIOD_STATUSES,
  PERIOD_STATUS_LABELS,
  PpdbErrorCard,
  PpdbField,
  PpdbFilterCard,
  PpdbLoadingCard,
  PpdbPageHeader,
  PpdbPeriodStatusBadge,
  describeError,
  formatRange,
  toDateInput,
  toOptions,
} from '../../components/config/PpdbConfigUi';
import type { PpdbFaqItem, PpdbPeriod, PpdbPeriodPayload, PpdbPeriodStatus } from '@/types/ppdb';

const PER_PAGE = 10;
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/**
 * `banner_path` & `logo_path` diterima backend (`PpdbPeriodRequest`) tetapi belum
 * ada di `PpdbPeriodPayload`, jadi tipe lokal ini melengkapi kontrak beku.
 */
type PeriodPayload = PpdbPeriodPayload & { banner_path?: string; logo_path?: string };

interface PeriodForm {
  name: string;
  code: string;
  slug: string;
  academic_year_id: string;
  public_title: string;
  public_description: string;
  banner_path: string;
  logo_path: string;
  contact_name: string;
  contact_phone: string;
  contact_email: string;
  contact_address: string;
  registration_start: string;
  registration_end: string;
  selection_announcement_date: string;
  re_registration_start: string;
  re_registration_end: string;
  status: PpdbPeriodStatus;
  description: string;
}

type FormErrors = Partial<Record<keyof PeriodForm | 'faq', string>>;

const EMPTY_FORM: PeriodForm = {
  name: '',
  code: '',
  slug: '',
  academic_year_id: '',
  public_title: '',
  public_description: '',
  banner_path: '',
  logo_path: '',
  contact_name: '',
  contact_phone: '',
  contact_email: '',
  contact_address: '',
  registration_start: '',
  registration_end: '',
  selection_announcement_date: '',
  re_registration_start: '',
  re_registration_end: '',
  status: 'draft',
  description: '',
};

function periodToForm(period: PpdbPeriod | null): PeriodForm {
  if (!period) return { ...EMPTY_FORM };
  return {
    name: period.name,
    code: period.code ?? '',
    slug: period.slug ?? '',
    academic_year_id: period.academic_year_id === null ? '' : String(period.academic_year_id),
    public_title: period.public_title ?? '',
    public_description: period.public_description ?? '',
    banner_path: period.banner_path ?? '',
    logo_path: period.logo_path ?? '',
    contact_name: period.contact_name ?? '',
    contact_phone: period.contact_phone ?? '',
    contact_email: period.contact_email ?? '',
    contact_address: period.contact_address ?? '',
    registration_start: toDateInput(period.registration_start),
    registration_end: toDateInput(period.registration_end),
    selection_announcement_date: toDateInput(period.selection_announcement_date),
    re_registration_start: toDateInput(period.re_registration_start),
    re_registration_end: toDateInput(period.re_registration_end),
    status: period.status,
    description: period.description ?? '',
  };
}

/** Jaga karakter yang sedang diketik (termasuk tanda hubung di akhir). */
function sanitizeSlug(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9-]+/g, '-');
}

function validate(form: PeriodForm, faq: PpdbFaqItem[]): FormErrors {
  const errors: FormErrors = {};

  if (!form.name.trim()) errors.name = 'Nama periode wajib diisi.';
  else if (form.name.length > 150) errors.name = 'Nama periode maksimal 150 karakter.';

  if (form.code.length > 30) errors.code = 'Kode maksimal 30 karakter.';

  if (form.slug.length > 100) errors.slug = 'Slug maksimal 100 karakter.';
  else if (form.slug && !SLUG_PATTERN.test(form.slug))
    errors.slug = 'Slug hanya boleh huruf kecil, angka, dan tanda hubung di tengah.';

  if (form.contact_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.contact_email))
    errors.contact_email = 'Format email tidak valid.';
  if (form.contact_phone.length > 20) errors.contact_phone = 'Telepon maksimal 20 karakter.';

  if (form.registration_start && form.registration_end && form.registration_end < form.registration_start)
    errors.registration_end = 'Akhir pendaftaran tidak boleh mendahului awal pendaftaran.';

  if (
    form.re_registration_start &&
    form.re_registration_end &&
    form.re_registration_end < form.re_registration_start
  )
    errors.re_registration_end = 'Akhir daftar ulang tidak boleh mendahului awal daftar ulang.';

  if (faq.some((item) => !item.question.trim() || !item.answer.trim()))
    errors.faq = 'Tiap baris FAQ membutuhkan pertanyaan dan jawaban.';

  return errors;
}

/* ============================ EDITOR FAQ ============================ */

function FaqRows({
  rows,
  error,
  onChange,
}: {
  rows: PpdbFaqItem[];
  error?: string;
  onChange: (rows: PpdbFaqItem[]) => void;
}) {
  const patch = (index: number, next: Partial<PpdbFaqItem>) =>
    onChange(rows.map((row, i) => (i === index ? { ...row, ...next } : row)));

  return (
    <div>
      <div className="mb-1 flex items-center justify-between">
        <span className="text-sm font-medium text-slate-700">FAQ halaman publik</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => onChange([...rows, { question: '', answer: '' }])}
        >
          <Plus className="h-3.5 w-3.5" /> Tambah pertanyaan
        </Button>
      </div>

      {rows.length === 0 ? (
        <p className="rounded-lg border border-dashed border-slate-200 px-3 py-2 text-xs text-slate-400">
          Belum ada FAQ. Pertanyaan di sini tampil di halaman publik periode.
        </p>
      ) : (
        <ul className="space-y-2">
          {rows.map((row, index) => (
            <li key={index} className="space-y-1 rounded-lg border border-slate-200 p-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-slate-500">Pertanyaan {index + 1}</span>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  title="Hapus FAQ"
                  onClick={() => onChange(rows.filter((_, i) => i !== index))}
                >
                  <Trash2 className="h-3.5 w-3.5 text-red-500" />
                </Button>
              </div>
              <Input
                value={row.question}
                placeholder="Contoh: Apakah pendaftaran bisa dilakukan dari HP?"
                onChange={(e) => patch(index, { question: e.target.value })}
              />
              <Textarea
                rows={2}
                value={row.answer}
                placeholder="Jawaban yang dilihat calon pendaftar."
                onChange={(e) => patch(index, { answer: e.target.value })}
              />
            </li>
          ))}
        </ul>
      )}
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}

/* ============================ FORM MODAL ============================ */

function PeriodFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: PpdbPeriod | null;
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PeriodPayload) => void;
}) {
  const { options: academicYears } = usePpdbReferenceOptions('academic_years');
  const [form, setForm] = useState<PeriodForm>(() => periodToForm(editing));
  const [faq, setFaq] = useState<PpdbFaqItem[]>(() => editing?.faq ?? []);
  const [slugTouched, setSlugTouched] = useState(false);
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  useEffect(() => {
    if (!open) return;
    setForm(periodToForm(editing));
    setFaq(editing?.faq ?? []);
    setSlugTouched(Boolean(editing?.slug));
    setLocalErrors({});
  }, [open, editing]);

  const set = <K extends keyof PeriodForm>(key: K, value: PeriodForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const errorOf = (key: keyof PeriodForm | 'faq') =>
    localErrors[key] ?? serverErrors[key]?.[0];

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    const cleaned = faq
      .filter((item) => item.question.trim() || item.answer.trim())
      .map((item) => ({ question: item.question.trim(), answer: item.answer.trim() }));

    const errors = validate(form, cleaned);
    setLocalErrors(errors);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali isian periode.');
      return;
    }

    onSubmit({
      name: form.name.trim(),
      code: form.code.trim(),
      slug: form.slug.trim(),
      academic_year_id: form.academic_year_id === '' ? '' : Number(form.academic_year_id),
      public_title: form.public_title.trim(),
      public_description: form.public_description.trim(),
      banner_path: form.banner_path.trim(),
      logo_path: form.logo_path.trim(),
      contact_name: form.contact_name.trim(),
      contact_phone: form.contact_phone.trim(),
      contact_email: form.contact_email.trim(),
      contact_address: form.contact_address.trim(),
      registration_start: form.registration_start,
      registration_end: form.registration_end,
      selection_announcement_date: form.selection_announcement_date,
      re_registration_start: form.re_registration_start,
      re_registration_end: form.re_registration_end,
      status: form.status,
      description: form.description.trim(),
      faq: cleaned,
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Periode PPDB' : 'Tambah Periode PPDB'}
      size="xl"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="ppdb-period-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="ppdb-period-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbField label="Nama Periode" required error={errorOf('name')}>
            <Input
              value={form.name}
              placeholder="PPDB 2026/2027"
              error={errorOf('name')}
              onChange={(e) => {
                set('name', e.target.value);
                if (!slugTouched) set('slug', slugify(e.target.value));
              }}
            />
          </PpdbField>

          <PpdbField
            label="Slug"
            error={errorOf('slug')}
            hint={
              form.slug ? (
                <span className="font-mono">/ppdb/{form.slug}</span>
              ) : (
                'Alamat halaman publik; kosongkan agar backend membangkitkannya.'
              )
            }
          >
            <Input
              value={form.slug}
              placeholder="ppdb-2026-2027"
              error={errorOf('slug')}
              onChange={(e) => {
                setSlugTouched(true);
                set('slug', sanitizeSlug(e.target.value));
              }}
            />
          </PpdbField>

          <PpdbField label="Kode" error={errorOf('code')} hint="Kosongkan agar kode dibuat otomatis.">
            <Input
              value={form.code}
              placeholder="PPDB-2026"
              error={errorOf('code')}
              onChange={(e) => set('code', e.target.value.toUpperCase())}
            />
          </PpdbField>

          <PpdbField label="Tahun Ajaran" error={errorOf('academic_year_id')}>
            <Select
              value={form.academic_year_id}
              error={errorOf('academic_year_id')}
              onChange={(e) => set('academic_year_id', e.target.value)}
            >
              <option value="">-- Belum ditentukan --</option>
              {academicYears.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </Select>
          </PpdbField>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <PpdbField label="Awal Pendaftaran" error={errorOf('registration_start')}>
            <Input
              type="date"
              value={form.registration_start}
              error={errorOf('registration_start')}
              onChange={(e) => set('registration_start', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Akhir Pendaftaran" error={errorOf('registration_end')}>
            <Input
              type="date"
              value={form.registration_end}
              error={errorOf('registration_end')}
              onChange={(e) => set('registration_end', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Tanggal Pengumuman" error={errorOf('selection_announcement_date')}>
            <Input
              type="date"
              value={form.selection_announcement_date}
              error={errorOf('selection_announcement_date')}
              onChange={(e) => set('selection_announcement_date', e.target.value)}
            />
          </PpdbField>
          <PpdbField
            label="Awal Daftar Ulang"
            error={errorOf('re_registration_start')}
            hint={
              form.re_registration_start &&
              form.registration_end &&
              form.re_registration_start < form.registration_end
                ? 'Biasanya dimulai setelah pendaftaran ditutup.'
                : undefined
            }
          >
            <Input
              type="date"
              value={form.re_registration_start}
              error={errorOf('re_registration_start')}
              onChange={(e) => set('re_registration_start', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Akhir Daftar Ulang" error={errorOf('re_registration_end')}>
            <Input
              type="date"
              value={form.re_registration_end}
              error={errorOf('re_registration_end')}
              onChange={(e) => set('re_registration_end', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Status" error={errorOf('status')} hint="Status menggerakkan alur pendaftaran.">
            <Select
              value={form.status}
              error={errorOf('status')}
              onChange={(e) => set('status', e.target.value as PpdbPeriodStatus)}
            >
              {toOptions(PERIOD_STATUS_LABELS).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </PpdbField>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbField label="Judul Halaman Publik" error={errorOf('public_title')}>
            <Input
              value={form.public_title}
              placeholder="Penerimaan Peserta Didik Baru"
              error={errorOf('public_title')}
              onChange={(e) => set('public_title', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Deskripsi Halaman Publik" error={errorOf('public_description')}>
            <Textarea
              rows={2}
              value={form.public_description}
              error={errorOf('public_description')}
              onChange={(e) => set('public_description', e.target.value)}
            />
          </PpdbField>
          <PpdbField
            label="Path Banner"
            error={errorOf('banner_path')}
            hint="Jalur berkas hasil unggah, mis. ppdb/2026/banner.jpg."
          >
            <Input
              value={form.banner_path}
              error={errorOf('banner_path')}
              onChange={(e) => set('banner_path', e.target.value)}
            />
          </PpdbField>
          <PpdbField
            label="Path Logo"
            error={errorOf('logo_path')}
            hint="Jalur berkas hasil unggah, mis. ppdb/2026/logo.png."
          >
            <Input
              value={form.logo_path}
              error={errorOf('logo_path')}
              onChange={(e) => set('logo_path', e.target.value)}
            />
          </PpdbField>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbField label="Nama Panitia" error={errorOf('contact_name')}>
            <Input
              value={form.contact_name}
              error={errorOf('contact_name')}
              onChange={(e) => set('contact_name', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Telepon / WhatsApp" error={errorOf('contact_phone')}>
            <Input
              value={form.contact_phone}
              placeholder="0812xxxxxxx"
              error={errorOf('contact_phone')}
              onChange={(e) => set('contact_phone', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Email Panitia" error={errorOf('contact_email')}>
            <Input
              type="email"
              value={form.contact_email}
              error={errorOf('contact_email')}
              onChange={(e) => set('contact_email', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Alamat Sekretariat" error={errorOf('contact_address')}>
            <Input
              value={form.contact_address}
              error={errorOf('contact_address')}
              onChange={(e) => set('contact_address', e.target.value)}
            />
          </PpdbField>
        </div>

        <PpdbField label="Catatan Internal" error={errorOf('description')}>
          <Textarea
            rows={2}
            value={form.description}
            error={errorOf('description')}
            onChange={(e) => set('description', e.target.value)}
          />
        </PpdbField>

        <FaqRows rows={faq} error={errorOf('faq')} onChange={setFaq} />
      </form>
    </Modal>
  );
}

/** Cermin `PpdbPeriod::TRANSITIONS`; dipakai bila respons tidak membawa `allowed_transitions`. */
const FALLBACK_TRANSITIONS: Record<PpdbPeriodStatus, PpdbPeriodStatus[]> = {
  draft: ['scheduled', 'open', 'archived'],
  scheduled: ['open', 'archived'],
  open: ['closed'],
  closed: ['open', 'archived'],
  archived: [],
};

/* ============================ MODAL STATUS ============================ */

function PeriodStatusModal({
  period,
  submitting,
  onClose,
  onSubmit,
}: {
  period: PpdbPeriod | null;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (status: PpdbPeriodStatus) => void;
}) {
  const [status, setStatus] = useState<PpdbPeriodStatus>('draft');

  useEffect(() => {
    if (period) setStatus(period.status);
  }, [period]);

  const targets = useMemo(() => {
    if (!period) return [];
    const base = period.allowed_transitions?.length
      ? period.allowed_transitions
      : FALLBACK_TRANSITIONS[period.status] ?? [];
    return base.filter((value) => value !== period.status);
  }, [period]);

  const allowed = period ? [period.status, ...targets] : PERIOD_STATUSES;

  return (
    <Modal
      open={!!period}
      onClose={onClose}
      title={`Ubah Status — ${period?.name ?? ''}`}
      size="sm"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button
            onClick={() => onSubmit(status)}
            loading={submitting}
            disabled={status === period?.status || targets.length === 0}
          >
            Simpan Status
          </Button>
        </>
      }
    >
      <PpdbField
        label="Status Baru"
        hint={
          targets.length === 0
            ? `Status ${period ? PERIOD_STATUS_LABELS[period.status] : ''} tidak bisa dipindahkan lagi.`
            : 'Hanya perpindahan yang diizinkan backend yang tersedia.'
        }
      >
        <Select value={status} onChange={(e) => setStatus(e.target.value as PpdbPeriodStatus)}>
          {allowed.map((value) => (
            <option key={value} value={value}>
              {PERIOD_STATUS_LABELS[value]}
            </option>
          ))}
        </Select>
      </PpdbField>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

export function PpdbPeriodsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'' | PpdbPeriodStatus>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PpdbPeriod | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<PpdbPeriod | null>(null);
  const [statusTarget, setStatusTarget] = useState<PpdbPeriod | null>(null);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'periods', 'list', page, search, statusFilter],
    queryFn: () =>
      ppdbPeriodsApi.list({
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        status: statusFilter || undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'periods'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'waves'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'programs'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PeriodPayload) =>
      editing ? ppdbPeriodsApi.update(editing.id, payload) : ppdbPeriodsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Periode diperbarui' : 'Periode dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (error) => {
      setServerErrors(getValidationErrors(error) ?? {});
      toast.error(getErrorMessage(error));
    },
  });

  const publishMutation = useMutation({
    mutationFn: (id: number) => ppdbPeriodsApi.publish(id),
    onSuccess: (period) => {
      toast.success(`Periode "${period.name}" dipublikasikan`);
      invalidate();
    },
    onError: (error) => toast.error(describeError(error)),
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: PpdbPeriodStatus }) =>
      ppdbPeriodsApi.update(id, { status }),
    onSuccess: () => {
      toast.success('Status periode diperbarui');
      setStatusTarget(null);
      invalidate();
    },
    onError: (error) => toast.error(describeError(error)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => ppdbPeriodsApi.remove(id),
    onSuccess: () => {
      toast.success('Periode dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (error) => {
      toast.error(describeError(error));
      setDeleting(null);
    },
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const openCreate = () => {
    setEditing(null);
    setServerErrors({});
    setFormOpen(true);
  };

  const openEdit = (period: PpdbPeriod) => {
    setEditing(period);
    setServerErrors({});
    setFormOpen(true);
  };

  const copyPublicPath = async (slug: string) => {
    const url = `${window.location.origin}/ppdb/${slug}`;
    try {
      await navigator.clipboard.writeText(url);
      toast.success('Alamat halaman publik disalin');
    } catch {
      toast.error(`Salin manual: ${url}`);
    }
  };

  return (
    <div className="space-y-4">
      <PpdbPageHeader
        title="Periode PPDB"
        description="Satu periode mewakili satu tahun ajaran penerimaan. Status dan jendela tanggal menentukan apakah halaman publik menerima pendaftaran."
        actions={
          <>
            <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="ppdb.period.manage">
              <Button onClick={openCreate}>
                <Plus className="h-4 w-4" /> Tambah Periode
              </Button>
            </Can>
          </>
        }
      />

      <PpdbFilterCard>
        <PpdbField label="Cari">
          <Input
            value={search}
            placeholder="Cari nama, kode, atau slug..."
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </PpdbField>
        <PpdbField label="Status">
          <Select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value as '' | PpdbPeriodStatus);
              setPage(1);
            }}
          >
            <option value="">Semua status</option>
            {toOptions(PERIOD_STATUS_LABELS).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </PpdbField>
      </PpdbFilterCard>

      {listQuery.isLoading ? (
        <PpdbLoadingCard label="Memuat periode..." />
      ) : listQuery.isError ? (
        <PpdbErrorCard error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada periode"
            description="Periode adalah pintu masuk seluruh konfigurasi PPDB."
            action={
              <Can perm="ppdb.period.manage">
                <Button size="sm" className="mt-2" onClick={openCreate}>
                  <Plus className="h-4 w-4" /> Tambah Periode
                </Button>
              </Can>
            }
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Periode</th>
                  <th className="px-4 py-2 font-medium">Tahun Ajaran</th>
                  <th className="px-4 py-2 font-medium">Pendaftaran</th>
                  <th className="px-4 py-2 font-medium">Pengumuman</th>
                  <th className="px-4 py-2 font-medium">Daftar Ulang</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Cakupan</th>
                  <th className="w-60 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((period) => (
                  <tr key={period.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">{period.name}</div>
                      <div className="mt-0.5 font-mono text-xs text-slate-500">{period.code}</div>
                      <div className="mt-1 flex items-center gap-1">
                        <Badge tone="gray">
                          <Globe className="mr-1 h-3 w-3" />
                          /ppdb/{period.slug}
                        </Badge>
                        <button
                          type="button"
                          title="Salin alamat publik"
                          className="text-slate-400 hover:text-slate-600"
                          onClick={() => copyPublicPath(period.slug)}
                        >
                          <Copy className="h-3.5 w-3.5" />
                        </button>
                      </div>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {period.academic_year?.name ?? <span className="text-slate-400">—</span>}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {formatRange(period.registration_start, period.registration_end)}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {formatRange(period.selection_announcement_date)}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {formatRange(period.re_registration_start, period.re_registration_end)}
                    </td>
                    <td className="px-4 py-2">
                      <PpdbPeriodStatusBadge status={period.status} />
                      {period.registration_open !== undefined && (
                        <div className="mt-1">
                          <Badge tone={period.registration_open ? 'green' : 'amber'}>
                            {period.registration_open ? 'Formulir terbuka' : 'Formulir tertutup'}
                          </Badge>
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      <div>{period.waves_count ?? 0} gelombang</div>
                      <div>{period.programs_count ?? 0} program</div>
                      <div>{period.registrations_count ?? 0} pendaftar</div>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap items-center gap-1">
                        <Can perm="ppdb.period.manage">
                          {period.status !== 'open' && (
                            <Button
                              variant="secondary"
                              size="sm"
                              title="Publikasikan halaman publik periode ini"
                              loading={publishMutation.isPending && publishMutation.variables === period.id}
                              onClick={() => publishMutation.mutate(period.id)}
                            >
                              <Send className="h-3.5 w-3.5" /> Publikasi
                            </Button>
                          )}
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Ubah status"
                            onClick={() => setStatusTarget(period)}
                          >
                            <ArrowUpDown className="h-3.5 w-3.5" /> Status
                          </Button>
                          <Button variant="ghost" size="icon" title="Edit" onClick={() => openEdit(period)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(period)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <PeriodFormModal
        open={formOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <PeriodStatusModal
        period={statusTarget}
        submitting={statusMutation.isPending}
        onClose={() => setStatusTarget(null)}
        onSubmit={(status) => {
          if (statusTarget) statusMutation.mutate({ id: statusTarget.id, status });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Periode PPDB"
        message={`Hapus "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Gelombang, program, formulir, pertanyaan, dokumen, dan biaya milik periode ini ikut terpengaruh.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
