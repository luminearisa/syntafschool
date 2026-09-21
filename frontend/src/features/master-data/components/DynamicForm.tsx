import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { FieldRenderer } from './FieldRenderer';
import type { FieldDef } from '@/constants/masterData';

function buildDefaults(fields: FieldDef[], initial?: Record<string, unknown>) {
  const init: Record<string, unknown> = {};
  fields.forEach((f) => {
    if (initial && initial[f.name] !== undefined && initial[f.name] !== null) {
      init[f.name] = initial[f.name];
    } else if (f.type === 'checkbox') {
      init[f.name] = false;
    } else {
      init[f.name] = '';
    }
  });
  return init;
}

/** Normalisasi nilai sebelum dikirim ke API */
function normalize(fields: FieldDef[], values: Record<string, unknown>) {
  const out: Record<string, unknown> = {};
  fields.forEach((f) => {
    let v = values[f.name];
    if (f.type === 'checkbox') {
      out[f.name] = Boolean(v);
      return;
    }
    if (v === '' || v === undefined) {
      if (f.required) out[f.name] = v;
      else return; // buang field kosong opsional
    }
    out[f.name] = v;
  });
  return out;
}

export function DynamicForm({
  fields,
  initial,
  submitting,
  serverErrors,
  submitLabel = 'Simpan',
  onSubmit,
  onCancel,
}: {
  fields: FieldDef[];
  initial?: Record<string, unknown>;
  submitting?: boolean;
  serverErrors?: Record<string, string[]>;
  submitLabel?: string;
  onSubmit: (data: Record<string, unknown>) => void;
  onCancel: () => void;
}) {
  const [values, setValues] = useState(() => buildDefaults(fields, initial));
  const [errors, setErrors] = useState<Record<string, string>>({});

  const handleChange = (name: string, value: unknown) => {
    setValues((prev) => ({ ...prev, [name]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[name];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const localErrors: Record<string, string> = {};
    fields.forEach((f) => {
      const v = values[f.name];
      if (f.required && (v === '' || v === null || v === undefined)) {
        localErrors[f.name] = `${f.label} wajib diisi`;
      }
    });
    if (Object.keys(localErrors).length > 0) {
      setErrors(localErrors);
      return;
    }
    onSubmit(normalize(fields, values));
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {fields.map((field) => (
          <div
            key={field.name}
            className={field.type === 'textarea' ? 'sm:col-span-2' : ''}
          >
            <FieldRenderer
              field={field}
              value={values[field.name]}
              error={errors[field.name] || serverErrors?.[field.name]?.[0]}
              onChange={handleChange}
            />
          </div>
        ))}
      </div>
      <div className="flex justify-end gap-2 pt-2">
        <Button type="button" variant="outline" onClick={onCancel} disabled={submitting}>
          Batal
        </Button>
        <Button type="submit" loading={submitting}>
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}
