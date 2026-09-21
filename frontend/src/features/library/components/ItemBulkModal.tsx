import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { libraryLocationsApi, libraryMastersApi } from '../api/libraryApi';
import { ITEM_STATUS_LABELS } from '../lib/catalogLabels';
import { toOptions } from '../lib/labels';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import type { SelectOption } from '@/constants/masterData';
import type { ItemStatus, LibraryItemBulkPayload } from '@/types/libraryCatalog';

interface Props {
  open: boolean;
  /** Perpustakaan pemilik record — menentukan daftar lokasi. */
  libraryId?: number | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryItemBulkPayload) => void;
}

interface FormState {
  quantity: number | '';
  location_id: number | '';
  condition_id: number | '';
  status_id: number | '';
  status: ItemStatus;
  acquisition_date: string;
  acquisition_source_id: number | '';
  acquisition_price: number | '';
  funding_source: string;
  notes: string;
  barcodes: string;
}

const emptyForm: FormState = {
  quantity: 1,
  location_id: '',
  condition_id: '',
  status_id: '',
  status: 'available',
  acquisition_date: '',
  acquisition_source_id: '',
  acquisition_price: '',
  funding_source: '',
  notes: '',
  barcodes: '',
};

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/**
 * Form tambah banyak eksemplar sekaligus (§28).
 *
 * Kode eksemplar & nomor inventaris selalu dibangkitkan server dari penomoran
 * perpustakaan. Barcode boleh diisi manual satu per baris — jumlahnya harus
 * sama dengan kuantitas bila diisi.
 */
export function ItemBulkModal({
  open,
  libraryId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const conditionsQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'conditions'],
    enabled: open,
    queryFn: () => libraryMastersApi.list('conditions', { all: true }),
  });
  const collectionStatusesQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'collection-statuses'],
    enabled: open,
    queryFn: () => libraryMastersApi.list('collection-statuses', { all: true }),
  });
  const acquisitionSourcesQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'acquisition-sources'],
    enabled: open,
    queryFn: () => libraryMastersApi.list('acquisition-sources', { all: true }),
  });
  const locationsQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'locations', libraryId ?? null],
    enabled: open && libraryId != null,
    queryFn: () => libraryLocationsApi.list(libraryId as number, { all: true }),
  });

  const conditionOptions = useMemo<SelectOption[]>(
    () => (conditionsQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [conditionsQuery.data],
  );
  const collectionStatusOptions = useMemo<SelectOption[]>(
    () => (collectionStatusesQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [collectionStatusesQuery.data],
  );
  const acquisitionSourceOptions = useMemo<SelectOption[]>(
    () => (acquisitionSourcesQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [acquisitionSourcesQuery.data],
  );
  const locationOptions = useMemo<SelectOption[]>(
    () => (locationsQuery.data?.data ?? []).map((l) => ({ value: l.id, label: l.name })),
    [locationsQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues({ ...emptyForm });
  }, [open]);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const barcodeLines = useMemo(
    () => values.barcodes.split('\n').map((line) => line.trim()).filter((line) => line !== ''),
    [values.barcodes],
  );

  const quantityNumber = values.quantity === '' ? 0 : Number(values.quantity);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (values.quantity === '' || Number.isNaN(quantityNumber)) {
      local.quantity = 'Jumlah eksemplar wajib diisi';
    } else if (quantityNumber < 1 || quantityNumber > 200) {
      local.quantity = 'Jumlah eksemplar harus antara 1 dan 200';
    }
    if (barcodeLines.length > 0 && barcodeLines.length !== quantityNumber) {
      local.barcodes = 'Jumlah barcode manual harus sama dengan jumlah eksemplar';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const payload: LibraryItemBulkPayload = {
      quantity: quantityNumber,
      location_id: values.location_id,
      condition_id: values.condition_id,
      status_id: values.status_id,
      status: values.status,
      acquisition_date: values.acquisition_date,
      acquisition_source_id: values.acquisition_source_id,
      acquisition_price: values.acquisition_price,
      funding_source: values.funding_source.trim(),
      notes: values.notes.trim(),
      ...(barcodeLines.length > 0 ? { barcodes: barcodeLines } : {}),
    };

    onSubmit(payload);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tambah Banyak Eksemplar"
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-item-bulk-form" loading={submitting}>
            Buat Eksemplar
          </Button>
        </>
      }
    >
      <form id="library-item-bulk-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <Label required>Jumlah Eksemplar</Label>
            <Input
              type="number"
              min={1}
              max={200}
              value={values.quantity}
              error={err('quantity')}
              onChange={(e) => set('quantity', e.target.value === '' ? '' : Number(e.target.value))}
            />
            <p className="mt-1 text-xs text-slate-500">Antara 1 sampai 200 eksemplar.</p>
          </div>
          <div>
            <Label>Status Eksemplar</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as ItemStatus)}
            >
              {toOptions(ITEM_STATUS_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Status Koleksi</Label>
            <SearchSelect
              options={collectionStatusOptions}
              value={values.status_id}
              error={err('status_id')}
              onChange={(v) => set('status_id', toId(v))}
              placeholder="-- Pilih status koleksi --"
            />
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label>Lokasi</Label>
            <SearchSelect
              options={locationOptions}
              value={values.location_id}
              error={err('location_id')}
              disabled={libraryId == null}
              onChange={(v) => set('location_id', toId(v))}
              placeholder={libraryId == null ? 'Perpustakaan tidak diketahui' : '-- Pilih lokasi --'}
            />
          </div>
          <div>
            <Label>Kondisi</Label>
            <SearchSelect
              options={conditionOptions}
              value={values.condition_id}
              error={err('condition_id')}
              onChange={(v) => set('condition_id', toId(v))}
              placeholder="-- Pilih kondisi --"
            />
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <Label>Tanggal Perolehan</Label>
            <Input
              type="date"
              value={values.acquisition_date}
              error={err('acquisition_date')}
              onChange={(e) => set('acquisition_date', e.target.value)}
            />
          </div>
          <div>
            <Label>Sumber Perolehan</Label>
            <SearchSelect
              options={acquisitionSourceOptions}
              value={values.acquisition_source_id}
              error={err('acquisition_source_id')}
              onChange={(v) => set('acquisition_source_id', toId(v))}
              placeholder="-- Pilih sumber --"
            />
          </div>
          <div>
            <Label>Harga Perolehan (satuan)</Label>
            <Input
              type="number"
              min={0}
              value={values.acquisition_price}
              error={err('acquisition_price')}
              placeholder="0"
              onChange={(e) => set('acquisition_price', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Sumber Dana</Label>
            <Input
              value={values.funding_source}
              error={err('funding_source')}
              placeholder="mis. BOS, Komite"
              onChange={(e) => set('funding_source', e.target.value)}
            />
          </div>
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            rows={2}
            value={values.notes}
            error={err('notes')}
            placeholder="Catatan untuk seluruh eksemplar yang dibuat"
            onChange={(e) => set('notes', e.target.value)}
          />
        </div>

        <div>
          <Label>Barcode Manual (opsional)</Label>
          <Textarea
            rows={4}
            value={values.barcodes}
            error={err('barcodes')}
            placeholder={'Satu barcode per baris\nmis. LIB-0001\nLIB-0002'}
            onChange={(e) => set('barcodes', e.target.value)}
          />
          <p className="mt-1 text-xs text-slate-500">
            Kosongkan agar barcode dibangkitkan otomatis. Bila diisi, jumlah baris harus sama dengan
            quantity.
          </p>
        </div>

        <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
          Akan dibuat <span className="font-semibold text-slate-800">{quantityNumber || 0}</span>{' '}
          eksemplar: kode &amp; nomor inventaris dibangkitkan otomatis.
          {barcodeLines.length > 0 && (
            <>
              {' '}
              Barcode manual: <span className="font-semibold">{barcodeLines.length}</span> baris.
            </>
          )}
        </div>
      </form>
    </Modal>
  );
}