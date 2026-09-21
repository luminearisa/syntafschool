import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Label } from '@/components/ui/Label';
import { useOptions } from '@/hooks/useOptions';
import type { FieldDef } from '@/constants/masterData';

export function FieldRenderer({
  field,
  value,
  error,
  onChange,
}: {
  field: FieldDef;
  value: unknown;
  error?: string;
  onChange: (name: string, value: unknown) => void;
}) {
  const { options: remoteOptions } = useOptions(field.optionSource?.resource);
  const options = field.options ?? remoteOptions;

  const renderControl = () => {
    switch (field.type) {
      case 'textarea':
        return (
          <Textarea
            value={(value as string) ?? ''}
            error={error}
            placeholder={field.placeholder}
            onChange={(e) => onChange(field.name, e.target.value)}
          />
        );
      case 'select':
        return (
          <Select
            value={(value as string) ?? ''}
            error={error}
            onChange={(e) => onChange(field.name, e.target.value)}
          >
            <option value="">-- Pilih --</option>
            {options.map((o) => (
              <option key={String(o.value)} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        );
      case 'checkbox':
        return (
          <label className="flex h-9 cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={Boolean(value)}
              onChange={(e) => onChange(field.name, e.target.checked)}
            />
            {field.label}
          </label>
        );
      case 'number':
        return (
          <Input
            type="number"
            value={(value as string | number) ?? ''}
            error={error}
            placeholder={field.placeholder}
            onChange={(e) =>
              onChange(field.name, e.target.value === '' ? '' : Number(e.target.value))
            }
          />
        );
      case 'date':
        return (
          <Input
            type="date"
            value={(value as string) ?? ''}
            error={error}
            onChange={(e) => onChange(field.name, e.target.value)}
          />
        );
      default:
        return (
          <Input
            value={(value as string) ?? ''}
            error={error}
            placeholder={field.placeholder}
            onChange={(e) => onChange(field.name, e.target.value)}
          />
        );
    }
  };

  return (
    <div>
      {field.type !== 'checkbox' && (
        <Label required={field.required}>{field.label}</Label>
      )}
      {renderControl()}
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}
