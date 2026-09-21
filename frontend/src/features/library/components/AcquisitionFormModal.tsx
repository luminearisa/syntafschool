import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { libraryMastersApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { formatRupiah } from '@/lib/utils';
import type { SelectOption } from '@/constants/masterData';
import type { AcquisitionPayload, LibraryAcquisition } from '@/types/libraryCirculation';

interface AcquisitionFormModalProps {
  open: boolean;
  editing: LibraryAcquisition | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: AcquisitionPayload) => void;
}

interface ItemRow {
  title: string;
  isbn: string;
  quantity: string;
  unit_price: string;
}

function emptyRow(): ItemRow {
  return { title: '', isbn: '', quantity: '1', unit_price: '' };
}

/**
 * Form permintaan pengadaan.
 *
 * Baris koleksi bersifat berulang — minimal satu judul wajib diisi. Total
 * dihitung langsung di klien agar petugas dapat memeriksa nilai usulan.
 */
export function AcquisitionFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: AcquisitionFormModalProps) {
  const { options: libraryOptions } = useLibraryOptions();

  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [supplierId, setSupplierId] = useState<number | ''>('');
  const [supplierName, setSupplierName] = useState('');
  const [requestedAt, setRequestedAt] = useState('');
  const [expectedAt, setExpectedAt] = useState('');
  const [notes, setNotes] = useState('');
  const [items, setItems] = useState<ItemRow[]>([emptyRow()]);
  const [itemError, setItemError] = useState('');

  const suppliersQuery = useQuery({
    queryKey: ['library', 'masters', 'publishers', 'options'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => libraryMastersApi.list('publishers', { all: true }),
  });

  const supplierOptions: SelectOption[] = useMemo(
    () => (suppliersQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [suppliersQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setLibraryId(editing?.library_id ?? '');
    setSupplierId(editing?.supplier_id ?? '');
    setSupplierName(editing?.supplier_name ?? '');
    setRequestedAt(editing?.requested_at ?? '');
    setExpectedAt(editing?.expected_at ?? '');
    setNotes(editing?.notes ?? '');
    setItems(
      editing?.items && editing.items.length > 0
        ? editing.items.map((i) => ({
            title: i.title,
            isbn: i.isbn ?? '',
            quantity: String(i.quantity),
            unit_price: i.unit_price ? String(i.unit_price) : '',
          }))
        : [emptyRow()],
    );
    setItemError('');
  }, [open, editing]);

  const total = useMemo(
    () =>
      items.reduce((sum, row) => {
        const qty = Number(row.quantity) || 0;
        const price = Number(row.unit_price) || 0;
        return sum + qty * price;
      }, 0),
    [items],
  );

  const updateRow = (index: number, patch: Partial<ItemRow>) => {
    setItems((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)));
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const invalid = items.some((row) => row.title.trim() === '' || Number(row.quantity) < 1);
    if (invalid) {
      setItemError('Setiap baris wajib punya judul dan jumlah minimal 1');
      return;
    }

    setItemError('');
    onSubmit({
      library_id: libraryId,
      supplier_id: supplierId,
      supplier_name: supplierName.trim() || undefined,
      requested_at: requestedAt || undefined,
      expected_at: expectedAt || undefined,
      notes: notes.trim() || undefined,
      items: items.map((row) => ({
        title: row.title.trim(),
        isbn: row.isbn.trim() || undefined,
        quantity: row.quantity === '' ? '' : Number(row.quantity),
        unit_price: row.unit_price === '' ? '' : Number(row.unit_price),
      })),
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Pengadaan' : 'Tambah Pengadaan'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-acquisition-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="library-acquisition-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {/* Perpustakaan hanya dipilih saat membuat; saat mengubah sudah tetap. */}
          {!editing && (
            <div>
              <Label required>Perpustakaan</Label>
              <SearchSelect
                options={libraryOptions}
                value={libraryId}
                error={serverErrors?.library_id?.[0]}
                onChange={(v) => setLibraryId(v === '' ? '' : Number(v))}
                placeholder="-- Pilih perpustakaan --"
              />
            </div>
          )}
          <div>
            <Label>Pemasok (Master Penerbit)</Label>
            <SearchSelect
              options={supplierOptions}
              value={supplierId}
              error={serverErrors?.supplier_id?.[0]}
              onChange={(v) => setSupplierId(v === '' ? '' : Number(v))}
              placeholder="-- Pilih dari master --"
            />
          </div>
          <div>
            <Label>Nama Pemasok (manual)</Label>
            <Input
              value={supplierName}
              maxLength={150}
              placeholder="Diisi bila pemasok belum ada di master"
              error={serverErrors?.supplier_name?.[0]}
              onChange={(e) => setSupplierName(e.target.value)}
            />
          </div>
          <div>
            <Label>Tanggal Permintaan</Label>
            <Input
              type="date"
              value={requestedAt}
              error={serverErrors?.requested_at?.[0]}
              onChange={(e) => setRequestedAt(e.target.value)}
            />
          </div>
          <div>
            <Label>Perkiraan Tiba</Label>
            <Input
              type="date"
              value={expectedAt}
              error={serverErrors?.expected_at?.[0]}
              onChange={(e) => setExpectedAt(e.target.value)}
            />
          </div>
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            rows={2}
            value={notes}
            error={serverErrors?.notes?.[0]}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>

        <div className="rounded-lg border border-slate-200">
          <div className="flex items-center justify-between border-b border-slate-100 px-3 py-2">
            <span className="text-sm font-medium text-slate-700">Daftar Koleksi</span>
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => setItems((prev) => [...prev, emptyRow()])}
            >
              <Plus className="h-3.5 w-3.5" /> Tambah Baris
            </Button>
          </div>

          <div className="space-y-3 p-3">
            {items.map((row, index) => (
              <div
                key={index}
                className="grid grid-cols-1 gap-3 sm:grid-cols-12 sm:items-start"
              >
                <div className="sm:col-span-4">
                  <Label required className="text-xs">
                    Judul
                  </Label>
                  <Input
                    value={row.title}
                    placeholder="Judul koleksi"
                    error={serverErrors?.[`items.${index}.title`]?.[0]}
                    onChange={(e) => updateRow(index, { title: e.target.value })}
                  />
                </div>
                <div className="sm:col-span-3">
                  <Label className="text-xs">ISBN</Label>
                  <Input
                    value={row.isbn}
                    onChange={(e) => updateRow(index, { isbn: e.target.value })}
                  />
                </div>
                <div className="sm:col-span-1">
                  <Label required className="text-xs">
                    Jml
                  </Label>
                  <Input
                    type="number"
                    min={1}
                    value={row.quantity}
                    error={serverErrors?.[`items.${index}.quantity`]?.[0]}
                    onChange={(e) => updateRow(index, { quantity: e.target.value })}
                  />
                </div>
                <div className="sm:col-span-2">
                  <Label className="text-xs">Harga Satuan</Label>
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    value={row.unit_price}
                    onChange={(e) => updateRow(index, { unit_price: e.target.value })}
                  />
                </div>
                <div className="flex items-center justify-between gap-2 sm:col-span-2 sm:justify-end sm:pt-6">
                  <span className="text-xs text-slate-500">
                    {formatRupiah((Number(row.quantity) || 0) * (Number(row.unit_price) || 0))}
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    title="Hapus baris"
                    disabled={items.length <= 1}
                    onClick={() => setItems((prev) => prev.filter((_, i) => i !== index))}
                  >
                    <Trash2 className="h-3.5 w-3.5 text-red-500" />
                  </Button>
                </div>
              </div>
            ))}
          </div>

          <div className="flex items-center justify-between border-t border-slate-100 px-3 py-2">
            <span className="text-sm text-slate-500">Total</span>
            <span className="text-sm font-semibold text-slate-900">{formatRupiah(total)}</span>
          </div>
        </div>

        {(itemError || serverErrors?.items?.[0]) && (
          <p className="text-xs text-red-500">{itemError || serverErrors?.items?.[0]}</p>
        )}
      </form>
    </Modal>
  );
}