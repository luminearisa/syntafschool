import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownToLine, History, Pencil, Plus, RefreshCw, Search, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { inventoryApi } from '../api/inventoryApi';
import { InventoryAdjustmentModal } from '../components/InventoryAdjustmentModal';
import { InventoryItemFormModal } from '../components/InventoryItemFormModal';
import type { InventoryItem, InventoryItemPayload } from '../types';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage } from '@/lib/axios';

const CONDITION_LABEL: Record<string, string> = {
  good: 'Baik', needs_repair: 'Perlu perbaikan', damaged: 'Rusak', unusable: 'Tidak dapat digunakan',
};
const movementLabel: Record<string, string> = { opening: 'Saldo awal', in: 'Barang masuk', out: 'Barang keluar' };
const currency = (value: number | null) => value === null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);

export function InventoryPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [categoryId, setCategoryId] = useState<number | ''>('');
  const [locationId, setLocationId] = useState<number | ''>('');
  const [lowStock, setLowStock] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<InventoryItem | null>(null);
  const [adjusting, setAdjusting] = useState<InventoryItem | null>(null);
  const [archiving, setArchiving] = useState<InventoryItem | null>(null);
  const [historyId, setHistoryId] = useState<number | null>(null);

  const lookupsQuery = useQuery({ queryKey: ['inventory', 'lookups'], queryFn: () => inventoryApi.lookups(), staleTime: 5 * 60_000 });
  const itemsQuery = useQuery({
    queryKey: ['inventory', 'items', page, search, categoryId, locationId, lowStock],
    queryFn: () => inventoryApi.list({ page, per_page: 15, search, category_id: categoryId, location_id: locationId, low_stock: lowStock }),
  });
  const historyQuery = useQuery({
    queryKey: ['inventory', 'item', historyId],
    enabled: historyId !== null,
    queryFn: () => inventoryApi.show(historyId!),
  });
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['inventory', 'items'] });
    queryClient.invalidateQueries({ queryKey: ['inventory', 'item'] });
  };
  const createMutation = useMutation({ mutationFn: (payload: InventoryItemPayload) => inventoryApi.create(payload), onSuccess: () => { toast.success('Barang inventaris ditambahkan'); setFormOpen(false); invalidate(); }, onError: (error) => toast.error(getErrorMessage(error)) });
  const updateMutation = useMutation({ mutationFn: ({ id, payload }: { id: number; payload: InventoryItemPayload }) => inventoryApi.update(id, payload), onSuccess: () => { toast.success('Data barang diperbarui'); setFormOpen(false); setEditing(null); invalidate(); }, onError: (error) => toast.error(getErrorMessage(error)) });
  const adjustMutation = useMutation({ mutationFn: ({ id, payload }: { id: number; payload: { direction: 'in' | 'out'; quantity: number; reason: string; reference?: string } }) => inventoryApi.adjust(id, payload), onSuccess: () => { toast.success('Mutasi stok berhasil dicatat'); setAdjusting(null); invalidate(); }, onError: (error) => toast.error(getErrorMessage(error)) });
  const archiveMutation = useMutation({ mutationFn: (id: number) => inventoryApi.archive(id), onSuccess: () => { toast.success('Barang inventaris diarsipkan'); setArchiving(null); invalidate(); }, onError: (error) => toast.error(getErrorMessage(error)) });

  const applySearch = (event: FormEvent) => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()); };
  const rows = itemsQuery.data?.data ?? [];
  const lookups = lookupsQuery.data ?? { categories: [], units: [], locations: [] };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h1 className="text-lg font-semibold text-slate-900">Inventaris Sekolah</h1><p className="text-sm text-slate-500">Kelola aset dan persediaan dengan riwayat barang masuk/keluar yang dapat diaudit.</p></div>
        <div className="flex items-center gap-2"><Button size="icon" variant="ghost" title="Muat ulang" onClick={() => itemsQuery.refetch()}><RefreshCw className={itemsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button><Can perm="inventory.create"><Button onClick={() => { setEditing(null); setFormOpen(true); }}><Plus className="h-4 w-4" /> Tambah Barang</Button></Can></div>
      </div>

      {lookupsQuery.isError && <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Referensi kategori/lokasi tidak berhasil dimuat: {getErrorMessage(lookupsQuery.error)}</div>}
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(240px,1fr)_200px_200px_auto]">
          <form onSubmit={applySearch} className="flex items-center gap-2"><div className="relative w-full"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari kode, nama, nomor seri..." className="pl-9" /></div><Button type="submit" variant="outline">Cari</Button></form>
          <Select value={categoryId} onChange={(event) => { setCategoryId(event.target.value ? Number(event.target.value) : ''); setPage(1); }}><option value="">Semua kategori</option>{lookups.categories.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select>
          <Select value={locationId} onChange={(event) => { setLocationId(event.target.value ? Number(event.target.value) : ''); setPage(1); }}><option value="">Semua lokasi</option>{lookups.locations.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select>
          <label className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm text-slate-700"><input type="checkbox" checked={lowStock} onChange={(event) => { setLowStock(event.target.checked); setPage(1); }} /> Stok menipis</label>
        </div>
      </Card>

      <Card>
        {itemsQuery.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat inventaris...</div>
          : itemsQuery.isError ? <EmptyState title="Gagal memuat inventaris" description={getErrorMessage(itemsQuery.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => itemsQuery.refetch()}>Coba lagi</Button>} />
            : rows.length === 0 ? <EmptyState title="Barang belum tersedia" description={search || lowStock ? 'Coba ubah kata kunci atau filter.' : 'Tambahkan barang untuk mulai mengelola inventaris.'} />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Barang</th><th className="px-4 py-3">Kategori / Lokasi</th><th className="px-4 py-3 text-right">Stok</th><th className="px-4 py-3">Kondisi</th><th className="px-4 py-3 text-right">Nilai Satuan</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{rows.map((item) => <tr key={item.id} className="border-b border-slate-50 hover:bg-slate-50/60"><td className="px-4 py-3"><div className="font-medium text-slate-800">{item.name}</div><div className="font-mono text-xs text-slate-500">{item.sku}{item.serial_number ? ` · SN ${item.serial_number}` : ''}</div></td><td className="px-4 py-3"><div className="text-slate-700">{item.category_name ?? 'Tanpa kategori'}</div><div className="text-xs text-slate-400">{item.location_name ?? 'Lokasi belum ditentukan'}</div></td><td className="px-4 py-3 text-right"><div className={`font-semibold ${item.is_low_stock ? 'text-amber-700' : 'text-slate-800'}`}>{item.quantity} {item.unit_symbol ?? item.unit_name ?? ''}</div><div className="text-xs text-slate-400">Minimum {item.minimum_quantity}</div>{item.is_low_stock && <Badge className="mt-1" tone="amber">Stok menipis</Badge>}</td><td className="px-4 py-3"><Badge tone={item.condition === 'good' ? 'green' : item.condition === 'needs_repair' ? 'amber' : 'red'}>{CONDITION_LABEL[item.condition] ?? item.condition}</Badge>{!item.is_active && <div className="mt-1 text-xs text-slate-400">Nonaktif</div>}</td><td className="px-4 py-3 text-right text-slate-600">{currency(item.unit_cost)}</td><td className="px-4 py-3"><div className="flex justify-end gap-1"><Button size="icon" variant="ghost" title="Riwayat stok" onClick={() => setHistoryId(item.id)}><History className="h-4 w-4" /></Button><Can perm="inventory.update"><Button size="icon" variant="ghost" title="Penyesuaian stok" onClick={() => setAdjusting(item)}><ArrowDownToLine className="h-4 w-4" /></Button><Button size="icon" variant="ghost" title="Edit barang" onClick={() => { setEditing(item); setFormOpen(true); }}><Pencil className="h-4 w-4" /></Button></Can><Can perm="inventory.delete">{item.quantity <= 0 && <Button size="icon" variant="ghost" title="Arsipkan barang" onClick={() => setArchiving(item)}><Trash2 className="h-4 w-4 text-red-500" /></Button>}</Can></div></td></tr>)}</tbody>
              </table></div>}
        {itemsQuery.data?.meta && <Pagination meta={itemsQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <InventoryItemFormModal open={formOpen} editing={editing} lookups={lookups} submitting={createMutation.isPending || updateMutation.isPending} onClose={() => { setFormOpen(false); setEditing(null); }} onSubmit={(payload) => editing ? updateMutation.mutate({ id: editing.id, payload }) : createMutation.mutate(payload)} />
      <InventoryAdjustmentModal item={adjusting} open={!!adjusting} submitting={adjustMutation.isPending} onClose={() => setAdjusting(null)} onSubmit={(payload) => { if (adjusting) adjustMutation.mutate({ id: adjusting.id, payload }); }} />
      <ConfirmDialog open={!!archiving} title="Arsipkan Barang" message={`Arsipkan ${archiving?.name ?? ''}? Riwayat mutasi tetap tersimpan.`} loading={archiveMutation.isPending} onCancel={() => setArchiving(null)} onConfirm={() => { if (archiving) archiveMutation.mutate(archiving.id); }} />

      <Modal open={historyId !== null} onClose={() => setHistoryId(null)} title={`Riwayat Stok · ${historyQuery.data?.name ?? ''}`} size="xl">
        {historyQuery.isLoading ? <div className="flex justify-center py-10"><Spinner /></div> : historyQuery.isError ? <EmptyState title="Gagal memuat riwayat" description={getErrorMessage(historyQuery.error)} /> : historyQuery.data ? <div className="space-y-3"><div className="rounded-lg bg-slate-50 p-3 text-sm">Saldo saat ini: <strong>{historyQuery.data.quantity} {historyQuery.data.unit_symbol ?? historyQuery.data.unit_name ?? ''}</strong></div>{(historyQuery.data.movements ?? []).length === 0 ? <EmptyState title="Belum ada mutasi stok" /> : <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b text-left text-xs uppercase text-slate-500"><th className="px-3 py-2">Tanggal</th><th className="px-3 py-2">Jenis</th><th className="px-3 py-2 text-right">Jumlah</th><th className="px-3 py-2 text-right">Saldo Setelah</th><th className="px-3 py-2">Alasan / Referensi</th><th className="px-3 py-2">Petugas</th></tr></thead><tbody>{historyQuery.data.movements?.map((movement) => <tr key={movement.id} className="border-b border-slate-50"><td className="px-3 py-2 text-xs">{movement.created_at ? new Date(movement.created_at).toLocaleString('id-ID') : '—'}</td><td className="px-3 py-2">{movementLabel[movement.movement_type] ?? movement.movement_type}</td><td className={`px-3 py-2 text-right font-medium ${movement.movement_type === 'out' ? 'text-red-600' : 'text-green-700'}`}>{movement.movement_type === 'out' ? '−' : '+'}{movement.quantity}</td><td className="px-3 py-2 text-right">{movement.quantity_after}</td><td className="px-3 py-2">{movement.reason}{movement.reference ? <div className="text-xs text-slate-400">Ref: {movement.reference}</div> : null}</td><td className="px-3 py-2 text-slate-500">{movement.created_by_name ?? '—'}</td></tr>)}</tbody></table></div>}</div> : null}
      </Modal>
    </div>
  );
}
