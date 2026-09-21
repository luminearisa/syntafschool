import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import type { SelectOption } from '@/constants/masterData';
import { LOCATION_STATUS_LABELS, LOCATION_TYPE_LABELS, toOptions } from '../lib/labels';
import type {
  LibraryLocation,
  LibraryLocationPayload,
  LibraryLocationStatus,
  LibraryLocationType,
} from '@/types/library';

interface Props {
  open: boolean;
  editing: LibraryLocation | null;
  locations: LibraryLocation[];
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryLocationPayload) => void;
  /** Induk yang dipilih otomatis saat menambah anak lokasi. */
  defaultParentId?: number | '';
}

interface FormState {
  parent_id: number | '';
  code: string;
  name: string;
  type: LibraryLocationType | '';
  description: string;
  status: LibraryLocationStatus;
}

const emptyForm: FormState = {
  parent_id: '',
  code: '',
  name: '',
  type: '',
  description: '',
  status: 'active',
};

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Ratakan pohon lokasi sambil menyimpan kedalaman untuk indentasi. */
function flatten(
  nodes: LibraryLocation[],
  depth = 0,
  out: { node: LibraryLocation; depth: number }[] = [],
): { node: LibraryLocation; depth: number }[] {
  for (const node of nodes) {
    out.push({ node, depth });
    if (node.children?.length) flatten(node.children, depth + 1, out);
  }
  return out;
}

/** Kumpulkan id keturunan (lintas kedalaman) dari sebuah lokasi. */
function descendantIds(locations: LibraryLocation[], rootId: number): Set<number> {
  const flat = flatten(locations).map((entry) => entry.node);
  const result = new Set<number>();
  let frontier = [rootId];

  while (frontier.length) {
    const next = flat
      .filter((node) => node.parent_id !== null && frontier.includes(node.parent_id))
      .map((node) => node.id)
      .filter((id) => id !== rootId && !result.has(id));

    if (!next.length) break;
    next.forEach((id) => result.add(id));
    frontier = next;
  }

  return result;
}

/**
 * Form lokasi perpustakaan (hierarkis).
 *
 * `parent_id` boleh kosong (lokasi akar). Saat mengubah lokasi, pilihan induk
 * mengecualikan lokasi itu sendiri beserta seluruh keturunannya agar pohon
 * tidak bisa dibuat melingkar.
 */
export function LocationFormModal({
  open,
  editing,
  locations,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
  defaultParentId = '',
}: Props) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const parentOptions = useMemo<SelectOption[]>(() => {
    const excluded = new Set<number>();
    if (editing) {
      excluded.add(editing.id);
      descendantIds(locations, editing.id).forEach((id) => excluded.add(id));
    }

    return flatten(locations)
      .filter((entry) => !excluded.has(entry.node.id))
      .map((entry) => ({
        value: entry.node.id,
        label: `${'— '.repeat(entry.depth)}${entry.node.name} (${entry.node.code})`,
      }));
  }, [locations, editing]);

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setValues({
        parent_id: editing.parent_id ?? '',
        code: editing.code,
        name: editing.name,
        type: editing.type,
        description: editing.description ?? '',
        status: editing.status,
      });
    } else {
      setValues({ ...emptyForm, parent_id: defaultParentId });
    }
  }, [open, editing, defaultParentId]);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.code.trim()) local.code = 'Kode lokasi wajib diisi';
    if (!values.name.trim()) local.name = 'Nama lokasi wajib diisi';
    if (!values.type) local.type = 'Jenis lokasi wajib dipilih';

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      parent_id: values.parent_id,
      code: values.code.trim(),
      name: values.name.trim(),
      type: values.type as LibraryLocationType,
      description: values.description,
      status: values.status,
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Lokasi' : 'Tambah Lokasi'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-location-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="library-location-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label>Induk Lokasi</Label>
          <SearchSelect
            options={parentOptions}
            value={values.parent_id}
            error={err('parent_id')}
            onChange={(v) => set('parent_id', toId(v))}
            placeholder="-- Kosongkan = lokasi akar --"
          />
          <p className="mt-1 text-xs text-slate-500">
            Bangun hierarki gedung → lantai → ruang. Kosongkan untuk lokasi akar.
          </p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Kode</Label>
            <Input
              value={values.code}
              error={err('code')}
              placeholder="mis. GDG-A"
              onChange={(e) => set('code', e.target.value)}
            />
          </div>
          <div>
            <Label required>Nama</Label>
            <Input
              value={values.name}
              error={err('name')}
              placeholder="mis. Gedung A"
              onChange={(e) => set('name', e.target.value)}
            />
          </div>
          <div>
            <Label required>Jenis</Label>
            <Select
              value={values.type}
              error={err('type')}
              onChange={(e) => set('type', e.target.value as LibraryLocationType | '')}
            >
              <option value="">-- Pilih jenis --</option>
              {toOptions(LOCATION_TYPE_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as LibraryLocationStatus)}
            >
              {toOptions(LOCATION_STATUS_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div>
          <Label>Deskripsi</Label>
          <Textarea
            value={values.description}
            error={err('description')}
            rows={3}
            placeholder="Opsional"
            onChange={(e) => set('description', e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}