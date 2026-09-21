import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import type { SelectOption } from '@/constants/masterData';
import type { LibraryLocation } from '@/types/library';

interface Props {
  open: boolean;
  location: LibraryLocation | null;
  locations: LibraryLocation[];
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (parentId: number | '') => void;
}

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
 * Dialog untuk memindahkan lokasi ke induk lain (atau ke akar).
 *
 * Pilihan induk mengecualikan lokasi itu sendiri beserta keturunannya supaya
 * pohon tidak bisa dibuat melingkar.
 */
export function LocationMoveModal({
  open,
  location,
  locations,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [parentId, setParentId] = useState<number | ''>('');

  const parentOptions = useMemo<SelectOption[]>(() => {
    const excluded = new Set<number>();
    if (location) {
      excluded.add(location.id);
      descendantIds(locations, location.id).forEach((id) => excluded.add(id));
    }

    return flatten(locations)
      .filter((entry) => !excluded.has(entry.node.id))
      .map((entry) => ({
        value: entry.node.id,
        label: `${'— '.repeat(entry.depth)}${entry.node.name} (${entry.node.code})`,
      }));
  }, [locations, location]);

  useEffect(() => {
    if (!open) return;
    setParentId(location?.parent_id ?? '');
  }, [open, location]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    onSubmit(parentId);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Pindahkan Lokasi"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-location-move-form" loading={submitting}>
            Pindahkan
          </Button>
        </>
      }
    >
      <form id="library-location-move-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
          Memindahkan <span className="font-medium text-slate-800">{location?.name ?? '—'}</span>{' '}
          <span className="font-mono text-xs">({location?.code ?? '—'})</span>
        </div>

        <div>
          <Label>Induk Baru</Label>
          <SearchSelect
            options={parentOptions}
            value={parentId}
            error={serverErrors?.parent_id?.[0]}
            onChange={(v) => setParentId(toId(v))}
            placeholder="-- Kosongkan = jadikan lokasi akar --"
          />
          <p className="mt-1 text-xs text-slate-500">
            Lokasi tidak dapat dipindahkan ke dalam keturunannya sendiri.
          </p>
        </div>
      </form>
    </Modal>
  );
}