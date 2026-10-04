import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Layers, Plus, Trash2 } from 'lucide-react';
import { Link } from 'react-router-dom';
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
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { formatDate, formatRupiah } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { purchaseRequestsApi } from '../api/procurementApi';
import { FormField, StatusBadge, useCategoryOptions, useEnumMetadata, useItemOptions } from '../components/ProcurementUi';
import { prPriority, prStatus, procurementMethod } from '../lib/labels';

interface DraftItem {
  description: string;
  item_type: string;
  quantity: string;
  unit: string;
  estimated_unit_price: string;
  category_id: number | '';
  is_asset: boolean;
}

const emptyItem = (): DraftItem => ({
  description: '',
  item_type: 'GOODS',
  quantity: '1',
  unit: 'unit',
  estimated_unit_price: '',
  category_id: '',
  is_asset: false,
});

/** Purchase Request — dokumen kebutuhan internal (§9–§11). */
export default function PurchaseRequestListPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [buka, setBuka] = useState(false);
  const [gabung, setGabung] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'purchase-requests', page, search, status],
    queryFn: () => purchaseRequestsApi.list({ page, per_page: 20, search, status }),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Permintaan Pembelian (PR)</h1>
          <p className="text-xs text-slate-500">
            PR mencatat kebutuhan unit. Validasi hanya memberi informasi — keputusan tetap di alur pengadaan.
          </p>
        </div>
        <Can perm="procurement.create_pr">
          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={() => setGabung(true)}>
              <Layers className="mr-1 h-4 w-4" /> Konsolidasi
            </Button>
            <Button size="sm" onClick={() => setBuka(true)}>
              <Plus className="mr-1 h-4 w-4" /> Buat PR
            </Button>
          </div>
        </Can>
      </header>

      <Card>
        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
          <Input
            placeholder="Cari nomor / tujuan…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="max-w-xs"
          />
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="max-w-[200px]"
          >
            <option value="">Semua status</option>
            {prStatus.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada permintaan" description="Buat PR pertama untuk memulai pengadaan." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-2 font-medium">Nomor</th>
                  <th className="px-3 py-2 font-medium">Pemohon</th>
                  <th className="px-3 py-2 font-medium">Perlu tanggal</th>
                  <th className="px-3 py-2 font-medium">Prioritas</th>
                  <th className="px-3 py-2 font-medium">Metode</th>
                  <th className="px-3 py-2 text-right font-medium">Estimasi</th>
                  <th className="px-5 py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((pr) => (
                  <tr key={pr.id} className="hover:bg-slate-50">
                    <td className="px-5 py-2">
                      <Link to={`/procurement/purchase-requests/${pr.id}`} className="font-mono text-xs text-brand-600 hover:underline">
                        {pr.number}
                      </Link>
                    </td>
                    <td className="px-3 py-2 text-slate-700">{pr.requester?.name ?? '-'}</td>
                    <td className="px-3 py-2 text-slate-600">{formatDate(pr.required_date)}</td>
                    <td className="px-3 py-2">
                      <StatusBadge value={pr.priority} map={prPriority} />
                    </td>
                    <td className="px-3 py-2">
                      <StatusBadge value={pr.method} map={procurementMethod} />
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums text-slate-700">
                      {formatRupiah(pr.estimated_total ?? 0)}
                    </td>
                    <td className="px-5 py-2">
                      <StatusBadge value={pr.status} map={prStatus} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      {buka && <PrFormModal onClose={() => setBuka(false)} />}
      {gabung && <ConsolidateModal onClose={() => setGabung(false)} />}
    </div>
  );
}

function PrFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const { optionsOf } = useEnumMetadata();
  const { options: categoryOptions } = useCategoryOptions();
  const { options: itemOptions } = useItemOptions();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    required_date: '',
    priority: 'NORMAL',
    purpose: '',
    currency: 'IDR',
  });
  const [items, setItems] = useState<DraftItem[]>([emptyItem()]);

  const total = items.reduce(
    (acc, it) => acc + Number(it.quantity || 0) * Number(it.estimated_unit_price || 0),
    0,
  );

  const simpan = useMutation({
    mutationFn: () =>
      purchaseRequestsApi.create({
        ...form,
        items: items.map((it) => ({
          description: it.description,
          item_type: it.item_type,
          quantity: Number(it.quantity),
          unit: it.unit,
          estimated_unit_price: Number(it.estimated_unit_price || 0),
          category_id: it.category_id === '' ? null : it.category_id,
          is_asset: it.is_asset,
        })),
      }),
    onSuccess: () => {
      toast.success('PR dibuat');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const setItem = (i: number, patch: Partial<DraftItem>) =>
    setItems((prev) => prev.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));

  const priorityList = optionsOf('pr_priority');
  const priorities = priorityList.length ? priorityList : prPriority.options();
  const typeList = optionsOf('item_type');

  return (
    <Modal
      open
      onClose={onClose}
      title="Buat Purchase Request"
      size="xl"
      footer={
        <div className="flex items-center justify-between gap-2">
          <span className="text-sm text-slate-600">
            Estimasi total: <strong className="tabular-nums">{formatRupiah(total)}</strong>
          </span>
          <div className="flex gap-2">
            <Button variant="ghost" onClick={onClose}>
              Batal
            </Button>
            <Button
              onClick={() => simpan.mutate()}
              loading={simpan.isPending}
              disabled={items.some((it) => !it.description || !it.quantity)}
            >
              Simpan
            </Button>
          </div>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <FormField label="Perlu tanggal" required error={errors.required_date?.[0]}>
          <Input
            type="date"
            value={form.required_date}
            onChange={(e) => setForm({ ...form, required_date: e.target.value })}
          />
        </FormField>
        <FormField label="Prioritas" error={errors.priority?.[0]}>
          <Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
            {priorities.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Mata uang">
          <Input value={form.currency} maxLength={3} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} />
        </FormField>
        <FormField label="Tujuan / keperluan" required error={errors.purpose?.[0]} className="sm:col-span-3">
          <Textarea rows={2} value={form.purpose} onChange={(e) => setForm({ ...form, purpose: e.target.value })} />
        </FormField>
      </div>

      <div className="mt-4">
        <div className="mb-2 flex items-center justify-between">
          <h3 className="text-sm font-semibold text-slate-700">Rincian kebutuhan</h3>
          <Button size="sm" variant="outline" onClick={() => setItems((p) => [...p, emptyItem()])}>
            <Plus className="mr-1 h-3.5 w-3.5" /> Baris
          </Button>
        </div>
        {errors['items'] && <p className="mb-2 text-[11px] text-red-600">{errors['items'][0]}</p>}

        <div className="space-y-2">
          {items.map((it, i) => (
            <div key={i} className="grid grid-cols-12 items-end gap-2 rounded-lg border border-slate-200 p-2">
              <div className="col-span-12 sm:col-span-3">
                <FormField label={i === 0 ? 'Deskripsi' : ''}>
                  <Input
                    list="proc-item-options"
                    value={it.description}
                    onChange={(e) => setItem(i, { description: e.target.value })}
                  />
                </FormField>
              </div>
              <div className="col-span-6 sm:col-span-2">
                <FormField label={i === 0 ? 'Jenis' : ''}>
                  <Select value={it.item_type} onChange={(e) => setItem(i, { item_type: e.target.value })}>
                    {(typeList.length ? typeList : []).map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                    {typeList.length === 0 && <option value="GOODS">Barang</option>}
                  </Select>
                </FormField>
              </div>
              <div className="col-span-3 sm:col-span-1">
                <FormField label={i === 0 ? 'Jumlah' : ''}>
                  <Input
                    type="number"
                    value={it.quantity}
                    onChange={(e) => setItem(i, { quantity: e.target.value })}
                  />
                </FormField>
              </div>
              <div className="col-span-3 sm:col-span-1">
                <FormField label={i === 0 ? 'Satuan' : ''}>
                  <Input value={it.unit} onChange={(e) => setItem(i, { unit: e.target.value })} />
                </FormField>
              </div>
              <div className="col-span-6 sm:col-span-2">
                <FormField label={i === 0 ? 'Harga satuan' : ''}>
                  <Input
                    type="number"
                    value={it.estimated_unit_price}
                    onChange={(e) => setItem(i, { estimated_unit_price: e.target.value })}
                  />
                </FormField>
              </div>
              <div className="col-span-5 sm:col-span-2">
                <FormField label={i === 0 ? 'Kategori' : ''}>
                  <SearchSelect
                    options={categoryOptions}
                    value={it.category_id}
                    onChange={(v) => setItem(i, { category_id: v === '' ? '' : Number(v) })}
                  />
                </FormField>
              </div>
              <div className="col-span-1 flex justify-end pb-1.5">
                <Button
                  size="icon"
                  variant="ghost"
                  onClick={() => setItems((p) => p.filter((_, idx) => idx !== i))}
                  disabled={items.length === 1}
                >
                  <Trash2 className="h-4 w-4 text-red-500" />
                </Button>
              </div>
            </div>
          ))}
        </div>
        <datalist id="proc-item-options">
          {itemOptions.map((o) => (
            <option key={o.value} value={o.label} />
          ))}
        </datalist>
      </div>
    </Modal>
  );
}

function ConsolidateModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [title, setTitle] = useState('');
  const [ids, setIds] = useState<number[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data } = useQuery({
    queryKey: ['procurement', 'purchase-requests', 'approved'],
    queryFn: () => purchaseRequestsApi.list({ status: 'APPROVED', per_page: 50 }),
  });

  const simpan = useMutation({
    mutationFn: () => purchaseRequestsApi.consolidate({ purchase_request_ids: ids, title }),
    onSuccess: () => {
      toast.success('Peristiwa pengadaan dibuat');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const rows = data?.data ?? [];

  return (
    <Modal
      open
      onClose={onClose}
      title="Konsolidasi PR menjadi peristiwa pengadaan"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!title || ids.length === 0}>
            Gabungkan
          </Button>
        </div>
      }
    >
      <FormField label="Nama peristiwa" required error={errors.title?.[0]}>
        <Input value={title} onChange={(e) => setTitle(e.target.value)} />
      </FormField>
      <FormField label="PR yang disetujui" required error={errors.purchase_request_ids?.[0]} className="mt-3">
        <div className="max-h-64 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
          {rows.length === 0 ? (
            <p className="p-2 text-xs text-slate-500">Tidak ada PR berstatus disetujui.</p>
          ) : (
            rows.map((pr) => (
              <label key={pr.id} className="flex items-center justify-between rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                <span className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={ids.includes(pr.id)}
                    onChange={(e) => setIds((prev) => (e.target.checked ? [...prev, pr.id] : prev.filter((v) => v !== pr.id)))}
                  />
                  <span className="font-mono text-xs text-slate-500">{pr.number}</span>
                  <span className="text-slate-700">{pr.purpose ?? '-'}</span>
                </span>
                <span className="flex items-center gap-2">
                  <Badge tone="gray">{formatRupiah(pr.estimated_total ?? 0)}</Badge>
                </span>
              </label>
            ))
          )}
        </div>
      </FormField>
    </Modal>
  );
}
