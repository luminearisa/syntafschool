import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, ChevronDown, ChevronRight, Plus } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { locationsApi } from '../api/assetApi';
import { FormField } from '../components/AssetUi';
import { locationType } from '../lib/labels';
import type { AssetLocationTreeNode } from '../types';

/**
 * Struktur lokasi: kampus → gedung → lantai → ruang.
 *
 * Lokasi adalah tulang punggung modul ini: opname, pemindahan, dan booking
 * fasilitas semuanya bersandar pada satu pohon ruang yang rapi.
 */
export default function AssetLocationPage() {
  const [bukaBuat, setBukaBuat] = useState(false);
  const [induk, setInduk] = useState<AssetLocationTreeNode | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'location-tree'],
    queryFn: () => locationsApi.tree(),
  });

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Struktur Lokasi</h1>
          <p className="text-xs text-slate-500">
            Ruang yang tertata membuat “barang ini di mana” selalu punya jawaban satu makna — bukan “di gedung A” bagi semua orang.
          </p>
        </div>
        <Can perm="location.manage">
          <Button size="sm" onClick={() => setBukaBuat(true)}>
            <Plus className="mr-1 h-4 w-4" /> Tambah lokasi
          </Button>
        </Can>
      </header>

      <Card className="p-4">
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : (data ?? []).length === 0 ? (
          <EmptyState
            title="Belum ada lokasi"
            description="Mulai dari kampus, lalu gedung, lantai, dan ruang."
            action={
              <Can perm="location.manage">
                <Button size="sm" onClick={() => setBukaBuat(true)}>
                  Tambah lokasi
                </Button>
              </Can>
            }
          />
        ) : (
          <ul className="space-y-1">
            {(data ?? []).map((node) => (
              <LocationNode key={node.id} node={node} onAdd={setInduk} />
            ))}
          </ul>
        )}
      </Card>

      {bukaBuat && <LocationFormModal parent={null} onClose={() => setBukaBuat(false)} />}
      {induk && <LocationFormModal parent={induk} onClose={() => setInduk(null)} />}
    </div>
  );
}

function LocationNode({ node, onAdd }: { node: AssetLocationTreeNode; onAdd: (node: AssetLocationTreeNode) => void }) {
  const [terbuka, setTerbuka] = useState(true);
  const adaAnak = (node.children ?? []).length > 0;

  return (
    <li>
      <div className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
        <button
          type="button"
          className="flex h-5 w-5 items-center justify-center text-slate-400"
          onClick={() => setTerbuka((v) => !v)}
          aria-label={terbuka ? 'Tutup' : 'Buka'}
          disabled={!adaAnak}
        >
          {adaAnak ? terbuka ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" /> : <span className="h-1 w-1 rounded-full bg-slate-300" />}
        </button>
        <Building2 className="h-4 w-4 text-slate-400" />
        <span className="text-sm text-slate-800">{node.name}</span>
        <Badge tone="gray">{locationType.label(node.location_type)}</Badge>
        {node.is_bookable && <Badge tone="blue">dapat dipesan</Badge>}
        {node.capacity && <span className="text-[11px] text-slate-500">kapasitas {node.capacity}</span>}
        <Can perm="location.manage">
          <button type="button" className="ml-auto text-[11px] text-brand-700 hover:underline" onClick={() => onAdd(node)}>
            tambah sub-lokasi
          </button>
        </Can>
      </div>

      {adaAnak && terbuka && (
        <ul className="ml-6 space-y-1 border-l border-slate-100 pl-2">
          {(node.children ?? []).map((child) => (
            <LocationNode key={child.id} node={child} onAdd={onAdd} />
          ))}
        </ul>
      )}
    </li>
  );
}

function LocationFormModal({ parent, onClose }: { parent: AssetLocationTreeNode | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    name: '',
    location_type: parent ? (parent.location_type === 'CAMPUS' ? 'BUILDING' : parent.location_type === 'BUILDING' ? 'FLOOR' : 'ROOM') : 'CAMPUS',
    capacity: '',
    area_m2: '',
    is_bookable: false,
    description: '',
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data: pohon } = useQuery({
    queryKey: ['asset', 'location-tree'],
    queryFn: () => locationsApi.tree(),
    enabled: false,
  });

  const simpan = useMutation({
    mutationFn: () =>
      locationsApi.create({
        ...form,
        parent_location_id: parent?.id,
        capacity: form.capacity === '' ? undefined : Number(form.capacity),
      }),
    onSuccess: () => {
      toast.success('Lokasi ditambahkan');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={parent ? `Sub-lokasi dari ${parent.name}` : 'Tambah lokasi'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !form.name}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Nama" required error={errors.name?.[0]}>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Misal: Ruang 101" />
        </FormField>
        <FormField label="Jenis" required error={errors.location_type?.[0]}>
          <Select value={form.location_type} onChange={(e) => setForm({ ...form, location_type: e.target.value })}>
            {locationType.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Kapasitas">
          <Input type="number" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
        </FormField>
        <FormField label="Luas (m²)">
          <Input type="number" value={form.area_m2} onChange={(e) => setForm({ ...form, area_m2: e.target.value })} />
        </FormField>
        <FormField label="Dapat dipesan" hint="Ruang yang dapat dipesan akan muncul pada papan jadwal fasilitas.">
          <Select value={form.is_bookable ? '1' : '0'} onChange={(e) => setForm({ ...form, is_bookable: e.target.value === '1' })}>
            <option value="0">Tidak</option>
            <option value="1">Ya</option>
          </Select>
        </FormField>
        {pohon && (
          <FormField label="Induk" hint="Pindahkan lokasi dengan mengubah induknya.">
            <SearchSelect options={[]} value={parent?.id ?? ''} onChange={() => undefined} disabled />
          </FormField>
        )}
        <FormField label="Keterangan" className="sm:col-span-2">
          <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>
      </div>
      {parent && <p className="mt-3 text-[11px] text-slate-500">Induk: {parent.path ?? parent.name}</p>}
    </Modal>
  );
}
