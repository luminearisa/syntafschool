import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { formatRupiah } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { catalogApi } from '../api/procurementApi';
import { FormField, StatusBadge, useEnumMetadata } from '../components/ProcurementUi';
import { itemType } from '../lib/labels';
import type { ProcurementCategory, ProcurementItem } from '../types';

type Tab = 'items' | 'categories';

/** Katalog pengadaan: barang/jasa yang sering dibeli + kategori. */
export default function CatalogPage() {
  const [tab, setTab] = useState<Tab>('items');

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Katalog Pengadaan</h1>
          <p className="text-xs text-slate-500">
            Katalog mempercepat pembuatan PR dan menyatukan satuan — bukan pengganti master barang gudang.
          </p>
        </div>
      </header>

      <div className="flex gap-1 border-b border-slate-200">
        <TabButton active={tab === 'items'} onClick={() => setTab('items')}>
          Barang & Jasa
        </TabButton>
        <TabButton active={tab === 'categories'} onClick={() => setTab('categories')}>
          Kategori
        </TabButton>
      </div>

      {tab === 'items' ? <ItemsTab /> : <CategoriesTab />}
    </div>
  );
}

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'border-b-2 border-brand-600 px-4 py-2 text-sm font-medium text-brand-600'
          : 'border-b-2 border-transparent px-4 py-2 text-sm text-slate-500 hover:text-slate-700'
      }
    >
      {children}
    </button>
  );
}

function ItemsTab() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<ProcurementItem | null>(null);
  const [buka, setBuka] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'items', page, search],
    queryFn: () => catalogApi.items({ page, per_page: 20, search }),
  });

  const rows = data?.data ?? [];

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
        <Input
          placeholder="Cari SKU / nama…"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          className="max-w-xs"
        />
        <Can perm="procurement.manage_settings">
          <Button
            size="sm"
            onClick={() => {
              setEditing(null);
              setBuka(true);
            }}
          >
            <Plus className="mr-1 h-4 w-4" /> Tambah item
          </Button>
        </Can>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState title="Belum ada item katalog" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-5 py-2 font-medium">SKU</th>
                <th className="px-3 py-2 font-medium">Nama</th>
                <th className="px-3 py-2 font-medium">Jenis</th>
                <th className="px-3 py-2 font-medium">Satuan</th>
                <th className="px-3 py-2 text-right font-medium">Harga acuan</th>
                <th className="px-3 py-2 font-medium">Status</th>
                <th className="px-5 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((item) => (
                <tr key={item.id}>
                  <td className="px-5 py-2 font-mono text-xs text-slate-500">{item.sku ?? '-'}</td>
                  <td className="px-3 py-2 text-slate-800">{item.name}</td>
                  <td className="px-3 py-2">
                    <StatusBadge value={item.item_type} map={itemType} />
                  </td>
                  <td className="px-3 py-2 text-slate-600">{item.unit ?? '-'}</td>
                  <td className="px-3 py-2 text-right tabular-nums text-slate-700">
                    {formatRupiah(item.estimated_price ?? 0)}
                  </td>
                  <td className="px-3 py-2">
                    {item.is_active === false ? <Badge tone="gray">Nonaktif</Badge> : <Badge tone="green">Aktif</Badge>}
                  </td>
                  <td className="px-5 py-2 text-right">
                    <Can perm="procurement.manage_settings">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                          setEditing(item);
                          setBuka(true);
                        }}
                      >
                        <Pencil className="h-3.5 w-3.5" />
                      </Button>
                    </Can>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}

      {buka && <ItemModal item={editing} onClose={() => setBuka(false)} />}
    </Card>
  );
}

function CategoriesTab() {
  const [buka, setBuka] = useState(false);
  const [editing, setEditing] = useState<ProcurementCategory | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'categories'],
    queryFn: () => catalogApi.categories(),
  });

  const rows = data ?? [];

  return (
    <Card>
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
        <span className="text-xs text-slate-500">{rows.length} kategori</span>
        <Can perm="procurement.manage_settings">
          <Button
            size="sm"
            onClick={() => {
              setEditing(null);
              setBuka(true);
            }}
          >
            <Plus className="mr-1 h-4 w-4" /> Tambah kategori
          </Button>
        </Can>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState title="Belum ada kategori" />
      ) : (
        <ul className="divide-y divide-slate-100">
          {rows.map((c) => (
            <li key={c.id} className="flex items-center justify-between px-5 py-3">
              <div>
                <div className="flex items-center gap-2">
                  <span className="font-mono text-xs text-slate-500">{c.code}</span>
                  <span className="text-sm text-slate-800">{c.name}</span>
                  {c.item_type && <StatusBadge value={c.item_type} map={itemType} />}
                  {c.is_active === false && <Badge tone="gray">Nonaktif</Badge>}
                </div>
                {c.description && <p className="text-[11px] text-slate-500">{c.description}</p>}
              </div>
              <Can perm="procurement.manage_settings">
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setEditing(c);
                    setBuka(true);
                  }}
                >
                  <Pencil className="h-3.5 w-3.5" />
                </Button>
              </Can>
            </li>
          ))}
        </ul>
      )}

      {buka && <CategoryModal category={editing} onClose={() => setBuka(false)} />}
    </Card>
  );
}

function ItemModal({ item, onClose }: { item: ProcurementItem | null; onClose: () => void }) {
  const qc = useQueryClient();
  const { optionsOf } = useEnumMetadata();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    sku: item?.sku ?? '',
    name: item?.name ?? '',
    category_id: item?.category_id ? String(item.category_id) : '',
    item_type: item?.item_type ?? 'GOODS',
    unit: item?.unit ?? 'unit',
    estimated_price: item?.estimated_price != null ? String(item.estimated_price) : '',
    is_asset: item?.is_asset ?? false,
    is_active: item?.is_active ?? true,
    description: item?.description ?? '',
  });

  const { data: categories } = useQuery({
    queryKey: ['procurement', 'categories'],
    queryFn: () => catalogApi.categories(),
  });

  const simpan = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        category_id: form.category_id === '' ? null : Number(form.category_id),
        estimated_price: form.estimated_price === '' ? null : Number(form.estimated_price),
      };
      return item ? catalogApi.updateItem(item.id, payload) : catalogApi.createItem(payload);
    },
    onSuccess: () => {
      toast.success(item ? 'Item diperbarui' : 'Item ditambahkan');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const typeList = optionsOf('item_type');
  const types = typeList.length ? typeList : itemType.options();

  return (
    <Modal
      open
      onClose={onClose}
      title={item ? 'Sunting item' : 'Tambah item'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!form.name}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="SKU" error={errors.sku?.[0]}>
          <Input value={form.sku} onChange={(e) => setForm({ ...form, sku: e.target.value })} />
        </FormField>
        <FormField label="Nama" required error={errors.name?.[0]}>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </FormField>
        <FormField label="Kategori" error={errors.category_id?.[0]}>
          <Select value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })}>
            <option value="">—</option>
            {(categories ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.code} — {c.name}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Jenis" error={errors.item_type?.[0]}>
          <Select value={form.item_type} onChange={(e) => setForm({ ...form, item_type: e.target.value })}>
            {types.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Satuan" error={errors.unit?.[0]}>
          <Input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} />
        </FormField>
        <FormField label="Harga acuan" error={errors.estimated_price?.[0]}>
          <Input
            type="number"
            value={form.estimated_price}
            onChange={(e) => setForm({ ...form, estimated_price: e.target.value })}
          />
        </FormField>
        <FormField label="Menjadi aset?" hint="Item aset akan didaftarkan ke modul Aset saat diterima.">
          <Select value={form.is_asset ? '1' : '0'} onChange={(e) => setForm({ ...form, is_asset: e.target.value === '1' })}>
            <option value="0">Bukan aset</option>
            <option value="1">Aset</option>
          </Select>
        </FormField>
        <FormField label="Status">
          <Select value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
            <option value="1">Aktif</option>
            <option value="0">Nonaktif</option>
          </Select>
        </FormField>
        <FormField label="Deskripsi" error={errors.description?.[0]} className="sm:col-span-2">
          <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  );
}

function CategoryModal({ category, onClose }: { category: ProcurementCategory | null; onClose: () => void }) {
  const qc = useQueryClient();
  const { optionsOf } = useEnumMetadata();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    code: category?.code ?? '',
    name: category?.name ?? '',
    item_type: category?.item_type ?? 'GOODS',
    is_active: category?.is_active ?? true,
    description: category?.description ?? '',
  });

  const simpan = useMutation({
    mutationFn: () => (category ? catalogApi.updateCategory(category.id, form) : catalogApi.createCategory(form)),
    onSuccess: () => {
      toast.success(category ? 'Kategori diperbarui' : 'Kategori ditambahkan');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const typeList = optionsOf('item_type');
  const types = typeList.length ? typeList : itemType.options();

  return (
    <Modal
      open
      onClose={onClose}
      title={category ? 'Sunting kategori' : 'Tambah kategori'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!form.code || !form.name}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Kode" required error={errors.code?.[0]}>
          <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
        </FormField>
        <FormField label="Nama" required error={errors.name?.[0]}>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </FormField>
        <FormField label="Jenis bawaan" error={errors.item_type?.[0]}>
          <Select value={form.item_type} onChange={(e) => setForm({ ...form, item_type: e.target.value })}>
            {types.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Status">
          <Select value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
            <option value="1">Aktif</option>
            <option value="0">Nonaktif</option>
          </Select>
        </FormField>
        <FormField label="Deskripsi" error={errors.description?.[0]} className="sm:col-span-2">
          <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  );
}
