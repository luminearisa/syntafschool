import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import type { InventoryItem, InventoryItemPayload, InventoryLookups } from '../types';

interface Props {
  open: boolean;
  editing: InventoryItem | null;
  lookups: InventoryLookups;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (payload: InventoryItemPayload) => void;
}

interface FormValues {
  sku: string;
  name: string;
  item_category_id: string;
  unit_id: string;
  storage_location_id: string;
  serial_number: string;
  quantity: string;
  minimum_quantity: string;
  unit_cost: string;
  purchase_date: string;
  condition: string;
  is_active: boolean;
  notes: string;
}

const blank: FormValues = {
  sku: '', name: '', item_category_id: '', unit_id: '', storage_location_id: '',
  serial_number: '', quantity: '0', minimum_quantity: '0', unit_cost: '',
  purchase_date: '', condition: 'good', is_active: true, notes: '',
};

export function InventoryItemFormModal({ open, editing, lookups, submitting, onClose, onSubmit }: Props) {
  const [values, setValues] = useState<FormValues>(blank);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setError('');
    setValues(editing ? {
      sku: editing.sku,
      name: editing.name,
      item_category_id: editing.item_category_id ? String(editing.item_category_id) : '',
      unit_id: editing.unit_id ? String(editing.unit_id) : '',
      storage_location_id: editing.storage_location_id ? String(editing.storage_location_id) : '',
      serial_number: editing.serial_number ?? '',
      quantity: String(editing.quantity),
      minimum_quantity: String(editing.minimum_quantity),
      unit_cost: editing.unit_cost === null ? '' : String(editing.unit_cost),
      purchase_date: editing.purchase_date ?? '',
      condition: editing.condition,
      is_active: editing.is_active,
      notes: editing.notes ?? '',
    } : { ...blank });
  }, [open, editing]);

  const set = <K extends keyof FormValues>(key: K, value: FormValues[K]) => setValues((previous) => ({ ...previous, [key]: value }));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!values.sku.trim() || !values.name.trim()) {
      setError('Kode dan nama barang wajib diisi.');
      return;
    }
    if (!editing && Number(values.quantity) < 0) {
      setError('Saldo awal tidak boleh negatif.');
      return;
    }
    setError('');
    onSubmit({
      sku: values.sku.trim(),
      name: values.name.trim(),
      item_category_id: values.item_category_id ? Number(values.item_category_id) : null,
      unit_id: values.unit_id ? Number(values.unit_id) : null,
      storage_location_id: values.storage_location_id ? Number(values.storage_location_id) : null,
      serial_number: values.serial_number.trim() || null,
      ...(!editing ? { quantity: Number(values.quantity) || 0 } : {}),
      minimum_quantity: Number(values.minimum_quantity) || 0,
      unit_cost: values.unit_cost === '' ? null : Number(values.unit_cost),
      purchase_date: values.purchase_date || null,
      condition: values.condition,
      is_active: values.is_active,
      notes: values.notes.trim() || null,
    });
  };

  return (
    <Modal open={open} onClose={onClose} title={editing ? 'Edit Barang Inventaris' : 'Tambah Barang Inventaris'} size="lg" footer={<><Button variant="outline" onClick={onClose} disabled={submitting}>Batal</Button><Button type="submit" form="inventory-item-form" loading={submitting}>{editing ? 'Simpan Perubahan' : 'Simpan Barang'}</Button></>}>
      <form id="inventory-item-form" onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div><Label required>Kode Barang / SKU</Label><Input value={values.sku} onChange={(event) => set('sku', event.target.value)} placeholder="INV-001" /></div>
          <div><Label required>Nama Barang</Label><Input value={values.name} onChange={(event) => set('name', event.target.value)} placeholder="Contoh: Proyektor kelas" /></div>
          <div><Label>Kategori</Label><Select value={values.item_category_id} onChange={(event) => set('item_category_id', event.target.value)}><option value="">Tanpa kategori</option>{lookups.categories.map((row) => <option key={row.id} value={row.id}>{row.name} · {row.type}</option>)}</Select></div>
          <div><Label>Satuan</Label><Select value={values.unit_id} onChange={(event) => set('unit_id', event.target.value)}><option value="">Pilih satuan</option>{lookups.units.map((row) => <option key={row.id} value={row.id}>{row.name}{row.symbol ? ` (${row.symbol})` : ''}</option>)}</Select></div>
          <div><Label>Lokasi</Label><Select value={values.storage_location_id} onChange={(event) => set('storage_location_id', event.target.value)}><option value="">Pilih lokasi</option>{lookups.locations.map((row) => <option key={row.id} value={row.id}>{row.name}{row.room?.name ? ` · ${row.room.name}` : ''}</option>)}</Select></div>
          <div><Label>Nomor Seri</Label><Input value={values.serial_number} onChange={(event) => set('serial_number', event.target.value)} /></div>
          {!editing && <div><Label>Saldo Awal</Label><Input type="number" min="0" step="0.01" value={values.quantity} onChange={(event) => set('quantity', event.target.value)} /><p className="mt-1 text-xs text-slate-500">Saldo awal otomatis dicatat sebagai mutasi inventaris.</p></div>}
          <div><Label>Batas Minimum Stok</Label><Input type="number" min="0" step="0.01" value={values.minimum_quantity} onChange={(event) => set('minimum_quantity', event.target.value)} /></div>
          <div><Label>Harga Per Unit</Label><Input type="number" min="0" step="1000" value={values.unit_cost} onChange={(event) => set('unit_cost', event.target.value)} /></div>
          <div><Label>Tanggal Pembelian</Label><Input type="date" value={values.purchase_date} onChange={(event) => set('purchase_date', event.target.value)} /></div>
          <div><Label>Kondisi</Label><Select value={values.condition} onChange={(event) => set('condition', event.target.value)}><option value="good">Baik</option><option value="needs_repair">Perlu perbaikan</option><option value="damaged">Rusak</option><option value="unusable">Tidak dapat digunakan</option></Select></div>
          <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700"><input type="checkbox" checked={values.is_active} onChange={(event) => set('is_active', event.target.checked)} /> Barang aktif</label>
          <div className="sm:col-span-2"><Label>Catatan</Label><Textarea value={values.notes} onChange={(event) => set('notes', event.target.value)} /></div>
        </div>
        {editing && <p className="text-xs text-slate-500">Jumlah stok tidak bisa diubah dari form ini. Gunakan aksi penyesuaian agar riwayat stok tetap tercatat.</p>}
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      </form>
    </Modal>
  );
}
