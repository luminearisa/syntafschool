import { useId } from 'react';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { cn } from '@/lib/utils';
import { toInputDate } from './format';
import type { PpdbAnswerValue, PpdbSchemaField, PpdbSchemaQuestion } from '@/types/ppdbPortal';

/** Bentuk seragam antara field form builder dan pertanyaan tambahan. */
export interface FieldSpec {
  key: string;
  label: string;
  type: string;
  required: boolean;
  placeholder: string | null;
  description: string | null;
  options: { label: string; value: string }[];
  validation: string[];
  order: number;
}

type Widget =
  | 'text'
  | 'textarea'
  | 'number'
  | 'date'
  | 'email'
  | 'phone'
  | 'select'
  | 'radio'
  | 'boolean'
  | 'multi'
  | 'yes_no'
  | 'file';

const WIDGETS: Record<string, Widget> = {
  text: 'text',
  textarea: 'textarea',
  number: 'number',
  date: 'date',
  email: 'email',
  phone: 'phone',
  select: 'select',
  dropdown: 'select',
  radio: 'radio',
  single_choice: 'radio',
  checkbox: 'boolean',
  multi_select: 'multi',
  multiple_choice: 'multi',
  yes_no: 'yes_no',
  file: 'file',
};

export function widgetOf(type: string): Widget {
  return WIDGETS[type] ?? 'text';
}

function normalizeOptions(
  options: { label: string; value?: string | null }[] | null | undefined,
): { label: string; value: string }[] {
  return (options ?? []).map((option) => ({
    label: option.label,
    value: (option.value ?? option.label ?? '').toString(),
  }));
}

export function toFieldSpec(field: PpdbSchemaField): FieldSpec {
  return {
    key: field.key,
    label: field.label,
    type: field.type,
    required: field.required,
    placeholder: field.placeholder,
    description: field.description,
    options: normalizeOptions(field.options),
    validation: field.validation ?? [],
    order: field.order,
  };
}

export function toQuestionSpec(question: PpdbSchemaQuestion): FieldSpec {
  return {
    key: String(question.id),
    label: question.question,
    type: question.type,
    required: question.required,
    placeholder: null,
    description: question.description,
    options: normalizeOptions(question.options),
    validation: [],
    order: question.order,
  };
}

export function isEmptyAnswer(value: PpdbAnswerValue | undefined): boolean {
  if (value === null || value === undefined) return true;
  if (typeof value === 'string') return value.trim() === '';
  if (Array.isArray(value)) return value.length === 0;
  return false;
}

interface RuleShape {
  min?: number;
  max?: number;
  numeric: boolean;
  integer: boolean;
  email: boolean;
  in: string[];
}

function parseRules(rules: string[]): RuleShape {
  const out: RuleShape = { numeric: false, integer: false, email: false, in: [] };
  for (const raw of rules) {
    const [name, arg] = raw.split(':');
    const num = Number(arg);
    if (name === 'min' && Number.isFinite(num)) out.min = num;
    else if (name === 'max' && Number.isFinite(num)) out.max = num;
    else if (name === 'numeric') out.numeric = true;
    else if (name === 'integer') out.integer = true;
    else if (name === 'email') out.email = true;
    else if (name === 'in' && arg) out.in = arg.split(',').map((v) => v.trim());
  }
  return out;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** Validasi sisi klien atas aturan Laravel yang bisa diperiksa tanpa server. */
export function validateSpec(spec: FieldSpec, value: PpdbAnswerValue | undefined): string | undefined {
  const rules = parseRules(spec.validation);
  const widget = widgetOf(spec.type);

  if (spec.required && isEmptyAnswer(value)) return `${spec.label} wajib diisi.`;
  if (isEmptyAnswer(value)) return undefined;

  if (widget === 'number' || rules.numeric || rules.integer) {
    const num = Number(value);
    if (!Number.isFinite(num)) return `${spec.label} harus berupa angka.`;
    if (rules.integer && !Number.isInteger(num)) return `${spec.label} harus bilangan bulat.`;
    if (rules.min !== undefined && num < rules.min) return `${spec.label} minimal ${rules.min}.`;
    if (rules.max !== undefined && num > rules.max) return `${spec.label} maksimal ${rules.max}.`;
  }

  if (widget === 'date') {
    const text = String(value);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(text) || Number.isNaN(new Date(text).getTime())) {
      return `${spec.label} harus tanggal yang valid (mis. 2026-01-31).`;
    }
  }

  if (widget === 'email' || rules.email) {
    if (!EMAIL_RE.test(String(value))) return `${spec.label} bukan format email yang benar.`;
  }

  if (widget === 'yes_no' && !['yes', 'no'].includes(String(value))) {
    return `${spec.label} pilih Ya atau Tidak.`;
  }

  if (widget === 'boolean' && typeof value !== 'boolean') {
    return `${spec.label} harus dicentang atau dikosongkan.`;
  }

  if (rules.in.length) {
    const list = Array.isArray(value) ? value : [String(value)];
    if (list.some((item) => !rules.in.includes(String(item)))) {
      return `${spec.label} berisi pilihan yang tidak tersedia.`;
    }
  }

  if (widget === 'text' || widget === 'textarea' || widget === 'phone' || widget === 'email') {
    const length = String(value).length;
    if (rules.max !== undefined && length > rules.max) return `${spec.label} maksimal ${rules.max} karakter.`;
    if (rules.min !== undefined && length < rules.min) return `${spec.label} minimal ${rules.min} karakter.`;
  }

  return undefined;
}

interface Props {
  spec: FieldSpec;
  value: PpdbAnswerValue | undefined;
  onChange: (value: PpdbAnswerValue) => void;
  error?: string;
  disabled?: boolean;
  className?: string;
}

/**
 * Satu widget untuk satu field/pertanyaan dinamis.
 *
 * Tipe tak dikenal jatuh ke input teks agar formulir tetap dapat diisi bila
 * backend menambah tipe baru sebelum frontend menyusul.
 */
export function DynamicFieldInput({ spec, value, onChange, error, disabled, className }: Props) {
  const id = useId();
  const widget = widgetOf(spec.type);
  const helpId = `${id}-help`;
  const showError = error ?? undefined;

  const labelNode = (
    <Label htmlFor={id} required={spec.required} className="mb-1">
      {spec.label}
    </Label>
  );

  const helpNode =
    spec.description || showError ? (
      <p id={helpId} className={cn('mt-1 text-xs', showError ? 'text-red-500' : 'text-slate-500')}>
        {showError ?? spec.description}
      </p>
    ) : null;

  if (widget === 'file') {
    return (
      <div className={className}>
        {labelNode}
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
          Berkas untuk {spec.label} dikumpulkan pada langkah Dokumen, bukan di sini.
        </div>
        {helpNode}
      </div>
    );
  }

  if (widget === 'textarea') {
    return (
      <div className={className}>
        {labelNode}
        <Textarea
          id={id}
          value={String(value ?? '')}
          placeholder={spec.placeholder ?? undefined}
          disabled={disabled}
          error={showError}
          aria-describedby={spec.description ? helpId : undefined}
          onChange={(e) => onChange(e.target.value)}
        />
        {helpNode}
      </div>
    );
  }

  if (widget === 'select') {
    return (
      <div className={className}>
        {labelNode}
        <Select
          id={id}
          value={String(value ?? '')}
          disabled={disabled}
          error={showError}
          aria-describedby={spec.description ? helpId : undefined}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">{spec.placeholder || '-- Pilih --'}</option>
          {spec.options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
        {helpNode}
      </div>
    );
  }

  if (widget === 'radio' || widget === 'yes_no') {
    const options =
      widget === 'yes_no'
        ? [
            { label: 'Ya', value: 'yes' },
            { label: 'Tidak', value: 'no' },
          ]
        : spec.options;
    return (
      <fieldset className={className} disabled={disabled}>
        <legend className="mb-1 block text-sm font-medium text-slate-700">
          {spec.label}
          {spec.required && <span className="ml-0.5 text-red-500">*</span>}
        </legend>
        <div className="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap">
          {options.map((option, index) => (
            <label
              key={`${option.value}-${index}`}
              className={cn(
                'flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm',
                String(value) === option.value
                  ? 'border-brand-500 bg-brand-50 text-brand-700'
                  : 'border-slate-200 bg-white text-slate-700',
                disabled && 'opacity-60',
              )}
            >
              <input
                type="radio"
                name={id}
                value={option.value}
                checked={String(value ?? '') === option.value}
                onChange={() => onChange(option.value)}
                className="h-4 w-4 accent-brand-600"
              />
              {option.label}
            </label>
          ))}
        </div>
        {helpNode}
      </fieldset>
    );
  }

  if (widget === 'multi') {
    const list = Array.isArray(value) ? value.map(String) : [];
    const toggle = (optionValue: string, checked: boolean) => {
      const next = checked
        ? Array.from(new Set([...list, optionValue]))
        : list.filter((item) => item !== optionValue);
      onChange(next);
    };
    return (
      <fieldset className={className} disabled={disabled}>
        <legend className="mb-1 block text-sm font-medium text-slate-700">
          {spec.label}
          {spec.required && <span className="ml-0.5 text-red-500">*</span>}
        </legend>
        <div className="flex flex-col gap-1.5">
          {spec.options.map((option, index) => (
            <label
              key={`${option.value}-${index}`}
              className={cn(
                'flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm',
                list.includes(option.value)
                  ? 'border-brand-500 bg-brand-50 text-brand-700'
                  : 'border-slate-200 bg-white text-slate-700',
                disabled && 'opacity-60',
              )}
            >
              <input
                type="checkbox"
                checked={list.includes(option.value)}
                onChange={(e) => toggle(option.value, e.target.checked)}
                className="h-4 w-4 accent-brand-600"
              />
              {option.label}
            </label>
          ))}
        </div>
        {helpNode}
      </fieldset>
    );
  }

  if (widget === 'boolean') {
    const checked = value === true || value === '1' || value === 1 || value === 'true';
    return (
      <div className={className}>
        {labelNode}
        <label
          className={cn(
            'flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm',
            checked ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-700',
            disabled && 'opacity-60',
          )}
        >
          <input
            id={id}
            type="checkbox"
            checked={checked}
            disabled={disabled}
            onChange={(e) => onChange(e.target.checked)}
            className="h-4 w-4 accent-brand-600"
          />
          {spec.placeholder || spec.description || 'Centang bila sesuai'}
        </label>
        {helpNode}
      </div>
    );
  }

  const inputType = widget === 'number' ? 'number' : widget === 'date' ? 'date' : widget === 'email' ? 'email' : 'text';
  return (
    <div className={className}>
      {labelNode}
      <Input
        id={id}
        type={inputType}
        inputMode={widget === 'number' ? 'numeric' : widget === 'phone' ? 'tel' : undefined}
        step={widget === 'number' ? 'any' : undefined}
        value={widget === 'date' ? toInputDate(String(value ?? '')) : String(value ?? '')}
        placeholder={spec.placeholder ?? undefined}
        disabled={disabled}
        error={showError}
        aria-describedby={spec.description ? helpId : undefined}
        onChange={(e) => onChange(e.target.value)}
      />
      {helpNode}
    </div>
  );
}

/** Nilai jawaban siap-baca untuk langkah tinjauan. */
export function answerDisplayText(spec: FieldSpec, value: PpdbAnswerValue | undefined): string {
  if (isEmptyAnswer(value)) return 'Belum diisi';
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
  if (Array.isArray(value)) {
    return value
      .map((item) => spec.options.find((option) => option.value === String(item))?.label ?? String(item))
      .join(', ');
  }
  const label = spec.options.find((option) => option.value === String(value))?.label;
  if (widgetOf(spec.type) === 'yes_no') return String(value) === 'no' ? 'Tidak' : 'Ya';
  return label ?? String(value);
}
