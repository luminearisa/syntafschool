import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowDown,
  ArrowUp,
  CircleAlert,
  Eye,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import { ppdbFormFieldsApi } from '../../api/ppdbApi';
import { getValidationErrors } from '../../api/http';
import { usePpdbPeriodOptions } from '../../hooks/usePpdbPeriodOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage } from '@/lib/axios';
import {
  CHOICE_FIELD_TYPES,
  FIELD_TYPE_LABELS,
  FIELD_TYPE_ORDER,
  PpdbErrorCard,
  PpdbField,
  PpdbLoadingCard,
  PpdbPageHeader,
  SECTION_LABELS,
  SECTION_ORDER,
  describeError,
  snakeCaseKey,
  toNumOrEmpty,
} from '../../components/config/PpdbConfigUi';
import { PpdbNoPeriodState, PpdbPeriodPicker } from '../../components/config/PpdbPeriodPicker';
import {
  PpdbOptionRows,
  fromOptionRows,
  toOptionRows,
  type OptionRow,
} from '../../components/config/PpdbOptionRows';
import type {
  PpdbFieldOption,
  PpdbFormField,
  PpdbFormFieldPayload,
  PpdbFieldSection,
  PpdbFieldType,
  PpdbFormMeta,
} from '@/types/ppdb';

const KEY_PATTERN = /^[a-z][a-z0-9_]*$/;
const PHONE_PATTERN = '/^(\\+62|62|0)8[0-9]{7,12}$/';

interface FieldForm {
  period_id: string;
  field_key: string;
  label: string;
  type: PpdbFieldType;
  section: PpdbFieldSection;
  placeholder: string;
  description: string;
  required: boolean;
  active: boolean;
  order: string;
}

interface ValidationForm {
  min: string;
  max: string;
  regex: string;
  after: string;
  before: string;
  extensions: string;
  sizeKb: string;
}

const EMPTY_VALIDATION: ValidationForm = {
  min: '',
  max: '',
  regex: '',
  after: '',
  before: '',
  extensions: '',
  sizeKb: '',
};

type FormErrors = Partial<Record<keyof FieldForm | 'options' | 'validation', string>>;

function fieldToForm(field: PpdbFormField | null, fallbackPeriodId: number | ''): FieldForm {
  return {
    period_id: String(field?.period_id ?? fallbackPeriodId ?? ''),
    field_key: field?.field_key ?? '',
    label: field?.label ?? '',
    type: field?.type ?? 'text',
    section: field?.section ?? 'data_calon',
    placeholder: field?.placeholder ?? '',
    description: field?.description ?? '',
    required: field?.required ?? false,
    active: field?.active ?? true,
    order: field ? String(field.order) : '',
  };
}

/** Pecah aturan tersimpan menjadi isian terstruktur + aturan bebas. */
function splitRules(rules: string[] | null): { validation: ValidationForm; custom: string[] } {
  const validation = { ...EMPTY_VALIDATION };
  const custom: string[] = [];

  for (const rule of rules ?? []) {
    const [name, ...rest] = rule.split(':');
    const arg = rest.join(':').trim();
    switch (name) {
      case 'min':
        if (validation.min === '') validation.min = arg;
        else custom.push(rule);
        break;
      case 'max':
        if (validation.max === '') validation.max = arg;
        else custom.push(rule);
        break;
      case 'regex':
        validation.regex = arg;
        break;
      case 'after_or_equal':
        validation.after = arg;
        break;
      case 'before_or_equal':
        validation.before = arg;
        break;
      case 'extensions':
        validation.extensions = arg.split(',').join(', ');
        break;
      case 'size':
        validation.sizeKb = arg;
        break;
      default:
        custom.push(rule);
    }
  }

  return { validation, custom };
}

/** Susun ulang aturan dari isian terstruktur; tipe menentukan aturan yang sah. */
function buildRules(type: PpdbFieldType, validation: ValidationForm, custom: string[]): string[] {
  const out: string[] = [];
  const push = (name: string, value: string) => {
    const trimmed = value.trim();
    if (trimmed) out.push(`${name}:${trimmed}`);
  };

  switch (type) {
    case 'text':
    case 'textarea':
    case 'phone':
      push('min', validation.min);
      push('max', validation.max);
      push('regex', validation.regex);
      break;
    case 'email':
      out.push('email');
      push('max', validation.max);
      break;
    case 'number':
      push('min', validation.min);
      push('max', validation.max);
      break;
    case 'date':
      push('after_or_equal', validation.after);
      push('before_or_equal', validation.before);
      break;
    case 'multi_select':
      push('min', validation.min);
      push('max', validation.max);
      break;
    case 'file':
      push('extensions', validation.extensions.replace(/\s+/g, ''));
      push('size', validation.sizeKb);
      break;
    default:
      push('max', validation.max);
  }

  return [...out, ...custom.map((rule) => rule.trim()).filter(Boolean)];
}

/* ============================ PRATINJAU FIELD ============================ */

function FieldPreview({
  label,
  type,
  placeholder,
  description,
  required,
  options,
}: {
  label: string;
  type: PpdbFieldType;
  placeholder?: string;
  description?: string;
  required: boolean;
  options: OptionRow[];
}) {
  const previewOptions = options.filter((option) => option.label.trim() !== '');
  const choiceList = previewOptions.length > 0 ? previewOptions : [{ label: 'Opsi contoh', value: '' }];

  const control = () => {
    switch (type) {
      case 'textarea':
        return <Textarea rows={3} disabled placeholder={placeholder || 'Tulis jawaban di sini...'} />;
      case 'number':
        return <Input type="number" disabled placeholder={placeholder || '0'} />;
      case 'date':
        return <Input type="date" disabled />;
      case 'email':
        return <Input type="email" disabled placeholder={placeholder || 'nama@email.com'} />;
      case 'phone':
        return <Input type="tel" disabled placeholder={placeholder || '0812xxxxxxx'} />;
      case 'select':
      case 'multi_select':
        return (
          <Select disabled value="">
            <option value="">
              {placeholder || (type === 'multi_select' ? 'Pilih satu atau lebih' : '-- Pilih --')}
            </option>
            {choiceList.map((option, index) => (
              <option key={index} value={String(index)}>
                {option.label}
              </option>
            ))}
          </Select>
        );
      case 'radio':
        return (
          <div className="space-y-1">
            {choiceList.map((option, index) => (
              <label key={index} className="flex items-center gap-2 text-sm text-slate-600">
                <input type="radio" disabled name="ppdb-preview-radio" />
                {option.label}
              </label>
            ))}
          </div>
        );
      case 'checkbox':
        return (
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" disabled />
            {choiceList[0]?.label ?? 'Saya menyetujui pernyataan ini'}
          </label>
        );
      case 'file':
        return (
          <div className="rounded-lg border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-400">
            Klik atau seret berkas ke sini (format &amp; ukuran mengikuti aturan validasi).
          </div>
        );
      default:
        return <Input disabled placeholder={placeholder || 'Isian teks'} />;
    }
  };

  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
      <p className="mb-1 flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
        <Eye className="h-3.5 w-3.5" /> Pratinjau portal
      </p>
      <div className="rounded-lg border border-slate-200 bg-white p-3">
        <label className="mb-1 block text-sm font-medium text-slate-700">
          {label.trim() || 'Label field'}
          {required && <span className="ml-0.5 text-red-500">*</span>}
        </label>
        {control()}
        {description?.trim() && <p className="mt-1 text-xs text-slate-400">{description}</p>}
      </div>
    </div>
  );
}

/* ============================ ATURAN VALIDASI ============================ */

const NUMBER_RULE_LABELS: Partial<Record<PpdbFieldType, [string, string]>> = {
  text: ['Panjang minimal', 'Panjang maksimal'],
  textarea: ['Panjang minimal', 'Panjang maksimal'],
  phone: ['Panjang minimal', 'Panjang maksimal'],
  number: ['Nilai minimal', 'Nilai maksimal'],
  multi_select: ['Minimal pilihan', 'Maksimal pilihan'],
  email: ['', 'Panjang maksimal'],
};

function ValidationExtras({
  type,
  value,
  error,
  onChange,
}: {
  type: PpdbFieldType;
  value: ValidationForm;
  error?: string;
  onChange: (key: keyof ValidationForm, next: string) => void;
}) {
  const numberLabels = NUMBER_RULE_LABELS[type];
  const isTextLike = type === 'text' || type === 'textarea' || type === 'phone';
  const isChoiceOnly = type === 'select' || type === 'radio' || type === 'checkbox';

  return (
    <div className="grid grid-cols-2 gap-2">
      {numberLabels && (
        <>
          {numberLabels[0] && (
            <PpdbField label={numberLabels[0]} error={error}>
              <Input type="number" min={0} value={value.min} onChange={(e) => onChange('min', e.target.value)} />
            </PpdbField>
          )}
          <PpdbField label={numberLabels[1]} className={isChoiceOnly || type === 'email' ? 'col-span-2' : undefined}>
            <Input type="number" min={0} value={value.max} onChange={(e) => onChange('max', e.target.value)} />
          </PpdbField>
        </>
      )}

      {isTextLike && (
        <PpdbField
          label="Pola regex"
          className="col-span-2"
          hint="Ditulis apa adanya sebagai aturan `regex:...`."
        >
          <div className="flex gap-2">
            <Input
              value={value.regex}
              placeholder="/^[A-Za-z ]+$/"
              onChange={(e) => onChange('regex', e.target.value)}
            />
            {type === 'phone' && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="shrink-0"
                onClick={() => onChange('regex', PHONE_PATTERN)}
              >
                Pola Indonesia
              </Button>
            )}
          </div>
        </PpdbField>
      )}

      {type === 'date' && (
        <>
          <PpdbField label="Tidak sebelum" error={error}>
            <Input type="date" value={value.after} onChange={(e) => onChange('after', e.target.value)} />
          </PpdbField>
          <PpdbField label="Tidak sesudah">
            <Input type="date" value={value.before} onChange={(e) => onChange('before', e.target.value)} />
          </PpdbField>
        </>
      )}

      {type === 'file' && (
        <>
          <PpdbField
            label="Ekstensi diizinkan"
            className="col-span-2"
            error={error}
            hint="Pisahkan dengan koma, mis. pdf, jpg, png."
          >
            <Input
              value={value.extensions}
              placeholder="pdf, jpg, png"
              onChange={(e) => onChange('extensions', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Ukuran maksimal (KB)">
            <Input type="number" min={0} value={value.sizeKb} onChange={(e) => onChange('sizeKb', e.target.value)} />
          </PpdbField>
        </>
      )}

      {isChoiceOnly && (
        <p className="col-span-2 text-xs text-slate-400">
          Jawaban sudah dibatasi daftar pilihan. Aturan bebas tetap bisa ditambahkan di bawah.
        </p>
      )}
    </div>
  );
}

/* ============================ FORM MODAL ============================ */

function FieldFormModal({
  open,
  editing,
  periodId,
  periodLabel,
  meta,
  takenKeys,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: PpdbFormField | null;
  periodId: number | '';
  periodLabel: string;
  meta: PpdbFormMeta | null;
  takenKeys: string[];
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PpdbFormFieldPayload) => void;
}) {
  const [form, setForm] = useState<FieldForm>(() => fieldToForm(editing, periodId));
  const [options, setOptions] = useState<OptionRow[]>(() => toOptionRows(editing?.options));
  const [validation, setValidation] = useState<ValidationForm>(() => EMPTY_VALIDATION);
  const [customRules, setCustomRules] = useState<string[]>([]);
  const [keyTouched, setKeyTouched] = useState(false);
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  const sections = meta?.sections?.length ? meta.sections : SECTION_ORDER;
  const types = meta?.types?.length ? meta.types : FIELD_TYPE_ORDER;

  useEffect(() => {
    if (!open) return;
    const parsed = splitRules(editing?.validation ?? null);
    setForm(fieldToForm(editing, periodId));
    setOptions(toOptionRows(editing?.options));
    setValidation(parsed.validation);
    setCustomRules(parsed.custom);
    setKeyTouched(Boolean(editing?.field_key));
    setLocalErrors({});
  }, [open, editing, periodId]);

  const set = <K extends keyof FieldForm>(key: K, value: FieldForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const setRule = <K extends keyof ValidationForm>(key: K, value: string) =>
    setValidation((prev) => ({ ...prev, [key]: value }));

  const errorOf = (key: keyof FormErrors) => localErrors[key] ?? serverErrors[key]?.[0];
  const needsOptions = CHOICE_FIELD_TYPES.includes(form.type);

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    const errors: FormErrors = {};

    if (form.period_id === '') errors.period_id = 'Periode wajib dipilih.';
    if (!form.label.trim()) errors.label = 'Label wajib diisi.';
    else if (form.label.length > 150) errors.label = 'Label maksimal 150 karakter.';

    if (form.placeholder.length > 150) errors.placeholder = 'Placeholder maksimal 150 karakter.';
    if (form.order !== '' && (Number(form.order) < 0 || Number(form.order) > 65535))
      errors.order = 'Urutan harus berada di rentang 0 sampai 65535.';

    const key = form.field_key.trim();
    if (!key) errors.field_key = 'Kunci field wajib diisi.';
    else if (!KEY_PATTERN.test(key))
      errors.field_key = 'Kunci field harus snake_case: diawali huruf, lalu huruf/angka/garis bawah.';
    else if (key !== editing?.field_key && takenKeys.includes(key))
      errors.field_key = 'Kunci field sudah dipakai pada periode ini.';

    const cleanOptions = fromOptionRows(options);
    if (needsOptions && form.type !== 'checkbox' && cleanOptions.length === 0)
      errors.options = 'Tipe pilihan membutuhkan minimal satu opsi.';

    const rules = buildRules(form.type, validation, customRules);
    if (rules.some((rule) => rule.length > 100))
      errors.validation = 'Satu aturan validasi maksimal 100 karakter.';

    setLocalErrors(errors);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali definisi field.');
      return;
    }

    onSubmit({
      period_id: Number(form.period_id),
      field_key: key,
      label: form.label.trim(),
      type: form.type,
      section: form.section,
      placeholder: form.placeholder.trim(),
      description: form.description.trim(),
      required: form.required,
      active: form.active,
      order: toNumOrEmpty(form.order),
      options:
        form.type === 'checkbox'
          ? cleanOptions.slice(0, 1).map<PpdbFieldOption>((row) => ({ label: row.label, value: row.value }))
          : cleanOptions.map<PpdbFieldOption>((row) => ({ label: row.label, value: row.value })),
      validation: rules,
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Field Formulir' : 'Tambah Field Formulir'}
      size="xl"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="ppdb-field-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="ppdb-field-form" onSubmit={handleSubmit} className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div className="space-y-3">
          <PpdbField
            label="Periode"
            required
            error={errorOf('period_id')}
            hint="Field mengikuti periode yang dipilih di daftar."
          >
            <Input disabled value={periodLabel} />
          </PpdbField>

          <PpdbField label="Label" required error={errorOf('label')}>
            <Input
              value={form.label}
              placeholder="Nama Lengkap"
              error={errorOf('label')}
              onChange={(e) => {
                set('label', e.target.value);
                if (!keyTouched) set('field_key', snakeCaseKey(e.target.value));
              }}
            />
          </PpdbField>

          <PpdbField
            label="Kunci Field"
            required
            error={errorOf('field_key')}
            hint={
              <span className="font-mono">
                {form.field_key || 'nama_lengkap'} — kunci jawaban pendaftar, jangan diganti setelah terisi
              </span>
            }
          >
            <Input
              value={form.field_key}
              error={errorOf('field_key')}
              onChange={(e) => {
                setKeyTouched(true);
                set('field_key', snakeCaseKey(e.target.value));
              }}
            />
          </PpdbField>

          <div className="grid grid-cols-2 gap-3">
            <PpdbField label="Jenis Field" required error={errorOf('type')}>
              <Select
                value={form.type}
                error={errorOf('type')}
                onChange={(e) => set('type', e.target.value as PpdbFieldType)}
              >
                {types.map((type) => (
                  <option key={type} value={type}>
                    {FIELD_TYPE_LABELS[type] ?? type}
                  </option>
                ))}
              </Select>
            </PpdbField>
            <PpdbField label="Section" required error={errorOf('section')}>
              <Select
                value={form.section}
                error={errorOf('section')}
                onChange={(e) => set('section', e.target.value as PpdbFieldSection)}
              >
                {sections.map((section) => (
                  <option key={section} value={section}>
                    {meta?.section_labels?.[section] ?? SECTION_LABELS[section] ?? section}
                  </option>
                ))}
              </Select>
            </PpdbField>
          </div>

          <PpdbField label="Placeholder" error={errorOf('placeholder')}>
            <Input
              value={form.placeholder}
              placeholder="Tampilan saat kosong"
              error={errorOf('placeholder')}
              onChange={(e) => set('placeholder', e.target.value)}
            />
          </PpdbField>

          <PpdbField label="Deskripsi / Bantuan" error={errorOf('description')}>
            <Textarea
              rows={2}
              value={form.description}
              placeholder="Diisi sesuai kolom KTP tanpa gelar."
              error={errorOf('description')}
              onChange={(e) => set('description', e.target.value)}
            />
          </PpdbField>

          <div className="grid grid-cols-3 gap-3">
            <PpdbField label="Wajib" error={errorOf('required')}>
              <input
                type="checkbox"
                checked={form.required}
                className="mt-2.5 h-4 w-4 rounded border-slate-300"
                onChange={(e) => set('required', e.target.checked)}
              />
            </PpdbField>
            <PpdbField label="Aktif" error={errorOf('active')}>
              <input
                type="checkbox"
                checked={form.active}
                className="mt-2.5 h-4 w-4 rounded border-slate-300"
                onChange={(e) => set('active', e.target.checked)}
              />
            </PpdbField>
            <PpdbField label="Urutan" error={errorOf('order')} hint="Urutan manual; panah di daftar lebih praktis.">
              <Input
                type="number"
                min={0}
                value={form.order}
                error={errorOf('order')}
                onChange={(e) => set('order', e.target.value)}
              />
            </PpdbField>
          </div>
        </div>

        <div className="space-y-3">
          <FieldPreview
            label={form.label}
            type={form.type}
            placeholder={form.placeholder}
            description={form.description}
            required={form.required}
            options={options}
          />

          {needsOptions && (
            <Card className="p-3">
              <PpdbOptionRows
                rows={options}
                error={errorOf('options')}
                label={form.type === 'checkbox' ? 'Teks persetujuhan' : 'Pilihan'}
                onChange={setOptions}
                emptyHint={
                  form.type === 'checkbox'
                    ? 'Checkbox cukup satu label, mis. "Saya menyetujui syarat dan ketentuan".'
                    : 'Belum ada pilihan.'
                }
              />
              {form.type === 'checkbox' && options.length > 1 && (
                <p className="mt-1 text-xs text-amber-600">
                  Hanya pilihan pertama yang dipakai untuk tipe centang.
                </p>
              )}
            </Card>
          )}

          <Card className="p-3">
            <p className="mb-2 text-sm font-medium text-slate-700">Aturan validasi</p>
            <ValidationExtras
              type={form.type}
              value={validation}
              error={errorOf('validation')}
              onChange={setRule}
            />

            <div className="mt-3">
              <div className="mb-1 flex items-center justify-between">
                <span className="text-xs font-medium text-slate-600">Aturan tambahan</span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setCustomRules((prev) => [...prev, ''])}
                >
                  <Plus className="h-3.5 w-3.5" /> Tambah aturan
                </Button>
              </div>
              {customRules.length === 0 ? (
                <p className="text-xs text-slate-400">
                  Potongan aturan Laravel yang tidak punya isian khusus, mis. `in:a,b` atau `not_in:x`.
                </p>
              ) : (
                <ul className="space-y-1">
                  {customRules.map((rule, index) => (
                    <li key={index} className="flex items-center gap-2">
                      <Input
                        value={rule}
                        placeholder="max_without:10"
                        className="font-mono text-xs"
                        onChange={(e) =>
                          setCustomRules((prev) => prev.map((item, i) => (i === index ? e.target.value : item)))
                        }
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        title="Hapus aturan"
                        onClick={() => setCustomRules((prev) => prev.filter((_, i) => i !== index))}
                      >
                        <Trash2 className="h-3.5 w-3.5 text-red-500" />
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

export function PpdbFormBuilderPage() {
  const queryClient = useQueryClient();
  const { options: periodOptions, periods, firstPeriodId, isInitialLoading } = usePpdbPeriodOptions();
  const [picked, setPicked] = useState<number | ''>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PpdbFormField | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<PpdbFormField | null>(null);

  const periodId = picked === '' ? firstPeriodId : picked;

  const listQuery = useQuery({
    queryKey: ['ppdb', 'form-fields', 'list', periodId],
    enabled: periodId !== '',
    queryFn: () => ppdbFormFieldsApi.list({ period_id: periodId, per_page: 100 }),
  });

  const metaQuery = useQuery({
    queryKey: ['ppdb', 'form-fields', 'meta', periodId],
    enabled: periodId !== '',
    queryFn: () => ppdbFormFieldsApi.meta(periodId as number),
  });

  const fields = useMemo(() => {
    const rows = listQuery.data?.data ?? [];
    return [...rows].sort((a, b) => a.order - b.order);
  }, [listQuery.data]);

  const meta = metaQuery.data ?? null;
  const sections = meta?.sections?.length ? meta.sections : SECTION_ORDER;
  const currentPeriod = periods.find((period) => String(period.id) === String(periodId));
  const sectionLabel = (section: PpdbFieldSection) =>
    meta?.section_labels?.[section] ?? SECTION_LABELS[section] ?? section;

  const groups = useMemo(
    () =>
      sections
        .map((section) => ({
          section,
          items: fields.filter((field) => field.section === section),
        }))
        .filter((group) => group.items.length > 0),
    [fields, sections],
  );

  const emptySections = sections.filter((section) => !groups.some((group) => group.section === section));

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'form-fields'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'periods'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PpdbFormFieldPayload) =>
      editing ? ppdbFormFieldsApi.update(editing.id, payload) : ppdbFormFieldsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Field diperbarui' : 'Field ditambahkan');
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

  const activeMutation = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) =>
      ppdbFormFieldsApi.update(id, { active }),
    onSuccess: (_result, variables) => {
      toast.success(variables.active ? 'Field diaktifkan' : 'Field dinonaktifkan');
      invalidate();
    },
    onError: (error) => toast.error(describeError(error)),
  });

  const reorderMutation = useMutation({
    mutationFn: (items: { id: number; order: number }[]) => ppdbFormFieldsApi.reorder(items),
    onSuccess: () => {
      toast.success('Urutan field disimpan');
      invalidate();
    },
    onError: (error) => toast.error(describeError(error)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => ppdbFormFieldsApi.remove(id),
    onSuccess: () => {
      toast.success('Field dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (error) => {
      toast.error(describeError(error));
      setDeleting(null);
    },
  });

  const move = (section: PpdbFieldSection, index: number, direction: -1 | 1) => {
    const current = groups.find((group) => group.section === section);
    if (!current) return;
    const target = index + direction;
    if (target < 0 || target >= current.items.length) return;

    const nextSectionItems = [...current.items];
    [nextSectionItems[index], nextSectionItems[target]] = [nextSectionItems[target], nextSectionItems[index]];

    // Urutan dinormalisasi ulang lintas section agar tidak ada tabrakan nilai `order`.
    const ordered: PpdbFormField[] = [];
    for (const group of groups) {
      ordered.push(...(group.section === section ? nextSectionItems : group.items));
    }

    const items = ordered
      .map((field, position) => ({ id: field.id, order: position + 1 }))
      .filter((item, position) => item.order !== ordered[position].order);

    if (items.length === 0) return;
    reorderMutation.mutate(items);
  };

  const takenKeys = fields.filter((field) => field.id !== editing?.id).map((field) => field.field_key);
  const activeFieldCount = fields.filter((field) => field.active).length;

  if (!isInitialLoading && periodOptions.length === 0) {
    return (
      <div className="space-y-4">
        <PpdbPageHeader
          title="Pembangun Formulir"
          description="Formulir pendaftaran dibuat per periode. Pilih atau buat periode lebih dulu."
        />
        <PpdbNoPeriodState />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <PpdbPageHeader
        title="Pembangun Formulir"
        description="Atur field yang harus diisi orang tua di portal. Field nonaktif tetap menyimpan jawaban lama."
        actions={
          <>
            <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="ppdb.form.manage">
              <Button onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }} disabled={periodId === ''}>
                <Plus className="h-4 w-4" /> Tambah Field
              </Button>
            </Can>
          </>
        }
      />

      <Card className="flex flex-col gap-3 p-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="w-full sm:max-w-xs">
          <PpdbPeriodPicker
            value={periodId}
            options={periodOptions}
            isLoading={isInitialLoading}
            onChange={setPicked}
          />
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <Badge tone="gray">{fields.length} field</Badge>
          <Badge tone={activeFieldCount > 0 ? 'green' : 'amber'}>{activeFieldCount} aktif</Badge>
          <Badge tone="blue">{groups.length} section terisi</Badge>
          {currentPeriod?.status === 'open' && (
            <Badge tone="amber">Periode sedang dibuka — perubahan langsung terlihat pendaftar</Badge>
          )}
        </div>
      </Card>

      {listQuery.isLoading ? (
        <PpdbLoadingCard label="Memuat field formulir..." />
      ) : listQuery.isError ? (
        <PpdbErrorCard error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : fields.length === 0 ? (
        <Card>
          <EmptyState
            title="Formulir masih kosong"
            description="Tanpa field, pendaftar hanya mengisi data calon siswa dari master. Tambahkan field sesuai kebutuhan sekolah."
            action={
              <Can perm="ppdb.form.manage">
                <Button
                  size="sm"
                  className="mt-2"
                  onClick={() => {
                    setEditing(null);
                    setServerErrors({});
                    setFormOpen(true);
                  }}
                  disabled={periodId === ''}
                >
                  <Plus className="h-4 w-4" /> Tambah Field
                </Button>
              </Can>
            }
          />
        </Card>
      ) : (
        <div className="space-y-4">
          {groups.map((group) => (
            <Card key={group.section}>
              <CardHeader className="flex items-center justify-between">
                <CardTitle>{sectionLabel(group.section)}</CardTitle>
                <span className="text-xs text-slate-400">{group.items.length} field</span>
              </CardHeader>
              <CardBody className="p-0">
                <ul className="divide-y divide-slate-50">
                  {group.items.map((field, index) => (
                    <li key={field.id} className="flex flex-wrap items-center gap-3 px-4 py-2.5">
                      <span className="w-6 text-center text-xs font-medium text-slate-400">{index + 1}</span>
                      <div className="flex flex-1 flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-medium text-slate-800">{field.label}</span>
                          {field.required && <Badge tone="red">Wajib</Badge>}
                          {!field.active && <Badge tone="gray">Nonaktif</Badge>}
                          <Badge tone="blue">{FIELD_TYPE_LABELS[field.type] ?? field.type}</Badge>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                          <span className="font-mono">{field.field_key}</span>
                          {field.options && field.options.length > 0 && (
                            <span>{field.options.length} pilihan</span>
                          )}
                          {field.validation && field.validation.length > 0 && (
                            <span className="font-mono">{field.validation.join(' | ')}</span>
                          )}
                        </div>
                      </div>
                      <Can perm="ppdb.form.manage">
                        <div className="flex items-center gap-1">
                          <label className="mr-2 flex items-center gap-1 text-xs text-slate-500">
                            <input
                              type="checkbox"
                              className="h-4 w-4 rounded border-slate-300"
                              checked={field.active}
                              disabled={activeMutation.isPending && activeMutation.variables?.id === field.id}
                              onChange={(e) => activeMutation.mutate({ id: field.id, active: e.target.checked })}
                            />
                            Aktif
                          </label>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Naikkan"
                            disabled={index === 0 || reorderMutation.isPending}
                            onClick={() => move(group.section, index, -1)}
                          >
                            <ArrowUp className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Turunkan"
                            disabled={index === group.items.length - 1 || reorderMutation.isPending}
                            onClick={() => move(group.section, index, 1)}
                          >
                            <ArrowDown className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Edit"
                            onClick={() => {
                              setEditing(field);
                              setServerErrors({});
                              setFormOpen(true);
                            }}
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(field)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </div>
                      </Can>
                    </li>
                  ))}
                </ul>
              </CardBody>
            </Card>
          ))}

          {emptySections.length > 0 && (
            <p className="flex items-center gap-1.5 px-1 text-xs text-slate-400">
              <CircleAlert className="h-3.5 w-3.5 shrink-0" />
              Section {emptySections.map(sectionLabel).join(', ')} belum dipakai field.
            </p>
          )}
        </div>
      )}

      <FieldFormModal
        open={formOpen}
        editing={editing}
        periodId={periodId}
        periodLabel={currentPeriod?.name ?? ''}
        meta={meta}
        takenKeys={takenKeys}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Field Formulir"
        message={`Hapus "${deleting?.label ?? ''}" (${deleting?.field_key ?? ''})? Field yang sudah dijawab pendaftar biasanya ditolak backend — cukup nonaktifkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
