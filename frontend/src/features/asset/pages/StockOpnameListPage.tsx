import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardCheck, Plus } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { useCategoryOptions, useLocationOptions, FormField } from '../components/AssetUi';
import { opnamesApi } from '../api/assetGovernanceApi';
import { fmtDateTime } from '../lib/format';
import { opnameStatus } from '../lib/labels';

/**
 * Stok opname: sesi penghitungan fisik.
 *
 * Sesi TIDAK mengubah data aset. Selisih hidup di barisnya sendiri sampai ada
 * manusia yang memutuskan — inilah bedanya dari "hitung lalu timpa".
 */
export default function StockOpnameListPage() {
  const [bukaBuat, setBukaBuat] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'opnames'],
    queryFn: () => opnamesApi.list({ per_page: 30 }),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Stok Opname</h1>
          <p className="text-xs text-slate-500">
            Penghitungan fisik tidak menimpa data aset: setiap selisih menunggu keputusan manusia sebelum disesuaikan.
          </p>
        </div>
        <Can perm="asset.stockopname.create">
          <Button size="sm" onClick={() => setBukaBuat(true)}>
            <Plus className="mr-1 h-4 w-4" /> Sesi baru
          </Button>
        </Can>
      </header>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada sesi opname" description="Mulai sesi untuk satu ruang atau satu gedung — tidak perlu seluruh sekolah sekaligus." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((opname) => (
              <li key={opname.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-500">{opname.opname_number}</span>
                    <Badge tone={opnameStatus.tone(opname.status)}>{opnameStatus.label(opname.status)}</Badge>
                    {(opname.variance_count ?? 0) > 0 && <Badge tone="amber">{opname.variance_count} selisih</Badge>}
                  </div>
                  <p className="mt-1 text-sm text-slate-800">{opname.title}</p>
                  <p className="text-[11px] text-slate-500">
                    Diharapkan {opname.expected_count ?? 0} · ditemukan {opname.found_count ?? 0}
                    {opname.started_at && <> · mulai {fmtDateTime(opname.started_at)}</>}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Link to={`/assets/stock-opnames/${opname.id}`}>
                    <Button size="sm" variant="outline">
                      <ClipboardCheck className="mr-1 h-3.5 w-3.5" />
                      {['PLANNED'].includes(opname.status) ? 'Buka' : 'Lanjutkan penghitungan'}
                    </Button>
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {bukaBuat && <OpnameFormModal onClose={() => setBukaBuat(false)} />}
    </div>
  );
}

function OpnameFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const { options: locationOptions } = useLocationOptions();
  const { options: categoryOptions } = useCategoryOptions();
  const [form, setForm] = useState({
    title: '',
    location_id: '' as number | '',
    category_id: '' as number | '',
    scheduled_for: '',
    method: 'SCAN',
    notes: '',
  });
  const [galat, setGalat] = useState<string | null>(null);

  // Lingkup kosong berarti "seluruh sekolah" bagi backend, dan itu ditolak:
  // menghitung seluruh sekolah tanpa sengaja adalah cara tercepat membuat
  // opname yang tidak selesai-selesai. Jadi lingkup diminta sejak awal.
  const lingkupTerisi = form.location_id !== '' || form.category_id !== '';

  const simpan = useMutation({
    mutationFn: () =>
      opnamesApi.create({
        title: form.title,
        scheduled_for: form.scheduled_for || undefined,
        method: form.method,
        notes: form.notes || undefined,
        scope: {
          location_id: form.location_id === '' ? undefined : form.location_id,
          category_id: form.category_id === '' ? undefined : form.category_id,
        },
      }),
    onSuccess: () => {
      toast.success('Sesi opname dibuat — daftar harapan disusun dari lingkup yang dipilih');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Sesi stok opname baru"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button
            onClick={() => {
              if (!lingkupTerisi) {
                setGalat('Pilih minimal satu lingkup: ruang, gedung, atau kategori.');
                return;
              }
              setGalat(null);
              simpan.mutate();
            }}
            disabled={simpan.isPending || !form.title}
          >
            Buat sesi
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Judul sesi" required className="sm:col-span-2">
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Misal: Opname Ruang 101–105" />
        </FormField>
        <FormField label="Lingkup lokasi" required hint="Pilih ruang atau kategori — opname tanpa lingkup akan ditolak, karena menghitung seluruh sekolah sekaligus jarang selesai.">
          <SearchSelect options={locationOptions} value={form.location_id} onChange={(v) => setForm({ ...form, location_id: v === '' ? '' : Number(v) })} />
        </FormField>
        <FormField label="Lingkup kategori">
          <SearchSelect options={categoryOptions} value={form.category_id} onChange={(v) => setForm({ ...form, category_id: v === '' ? '' : Number(v) })} />
        </FormField>
        <FormField label="Jadwal">
          <Input type="date" value={form.scheduled_for} onChange={(e) => setForm({ ...form, scheduled_for: e.target.value })} />
        </FormField>
        <FormField label="Metode">
          <SearchSelect
            options={[
              { value: 'SCAN', label: 'Pemindaian QR/barcode' },
              { value: 'MANUAL', label: 'Pencatatan manual' },
            ]}
            value={form.method}
            onChange={(v) => setForm({ ...form, method: String(v) })}
          />
        </FormField>
        <FormField label="Catatan" className="sm:col-span-2">
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </div>
      {galat && <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">{galat}</p>}
    </Modal>
  );
}
