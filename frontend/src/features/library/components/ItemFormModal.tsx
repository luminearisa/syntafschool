import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { libraryCatalogApi, libraryLocationsApi, libraryMastersApi } from '../api/libraryApi';
import { ITEM_STATUS_LABELS, RFID_STATUS_LABELS } from '../lib/catalogLabels';
import { toOptions } from '../lib/labels';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import type { SelectOption } from '@/constants/masterData';
import type {
  ItemStatus,
  LibraryItem,
  LibraryItemPayload,
  RfidStatus,
} from '@/types/libraryCatalog';

interface Props {
  open: boolean;
  editing: LibraryItem | null;
  /** Bila diisi, pemilih judul disembunyikan (konteks dari record). */
  bibliographyId?: number;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryItemPayload, bibliographyId?: number) => void;
}

interface FormState {
  item_code: string;
  accession_number: string;
  barcode: string;
  rfid_tag: string;
  rfid_status: RfidStatus;
  location_id: number | '';
  condition_id: number | '';
  status_id: number | '';
  status: ItemStatus;
  acquisition_date: string;
  acquisition_source_id: number | '';
  acquisition_price: number | '';
  funding_source: string;
  notes: string;
}

const emptyForm: FormState = {
  item_code: '',
  accession_number: '',
  barcode: '',
  rfid_tag: '',
  rfid_status: 'unassigned',
  location_id: '',
  condition_id: '',
  status_id: '',
  status: 'available',
  acquisition_date: '',
  acquisition_source_id: '',
  acquisition_price: '',
  funding_source: '',
  notes: '',
};

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/**
 * Form eksemplar (koleksi).
 *
 * `item_code` dan `accession_number` adalah identitas yang dibangkitkan server
 * dan tidak boleh diubah setelah dibuat — keduanya dinonaktifkan saat menyunting.
 * Barcode mengikuti pengaturan `catalog.barcode_auto` perpustakaan.
 */
export function ItemFormModal({
  open,
  editing,
  bibliographyId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [selectedBibId, setSelectedBibId] = useState<number | ''>('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  // Daftar judul untuk pemilih record (dari konteks halaman maupun dipilih manual).
  const recordsQuery = useQuery({
    queryKey: ['library', 'items', 'record-options'],
    enabled: open && !editing,
    queryFn: () => libraryCatalogApi.list({ all: true }),
  });

  const records = recordsQuery.data?.data ?? [];

  const recordOptions = useMemo<SelectOption[]>(
    () =>
      records.map((r) => ({
        value: r.id,
        label: r.control_number ? `${r.control_number} — ${r.title}` : r.title,
      })),
    [records],
  );

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

  // Perpustakaan efektif → menentukan daftar lokasi yang tersedia.
  const effectiveBibId = editing ? editing.bibliography_id : selectedBibId;
  const libraryId = useMemo<number | null>(() => {
    if (editing) return editing.library_id;
    const record = records.find((r) => r.id === effectiveBibId);
    return record?.library_id ?? null;
  }, [editing, records, effectiveBibId]);

  const locationsQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'locations', libraryId],
    enabled: open && libraryId !== null,
    queryFn: () => libraryLocationsApi.list(libraryId as number, { all: true }),
  });

  const locationOptions = useMemo<SelectOption[]>(
    () => (locationsQuery.data?.data ?? []).map((l) => ({ value: l.id, label: l.name })),
    [locationsQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setSelectedBibId(editing.bibliography_id);
      setValues({
        item_code: editing.item_code ?? '',
        accession_number: editing.accession_number ?? '',
        barcode: editing.barcode ?? '',
        rfid_tag: editing.rfid_tag ?? '',
        rfid_status: editing.rfid_status,
        location_id: editing.location_id ?? '',
        condition_id: editing.condition_id ?? '',
        status_id: editing.status_id ?? '',
        status: editing.status,
        acquisition_date: editing.acquisition_date ?? '',
        acquisition_source_id: editing.acquisition_source_id ?? '',
        acquisition_price: editing.acquisition_price ?? '',
        funding_source: editing.funding_source ?? '',
        notes: editing.notes ?? '',
      });
    } else {
      setSelectedBibId(bibliographyId ?? '');
      setValues({ ...emptyForm });
    }
  }, [open, editing, bibliographyId]);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const hideRecordPicker = Boolean(editing) || Boolean(bibliographyId);
  const recordRequired = !editing && !bibliographyId;

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (recordRequired && selectedBibId === '') {
      local.bibliography_id = 'Judul (record) wajib dipilih';
    }
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const payload: LibraryItemPayload = {
      item_code: values.item_code.trim(),
      accession_number: values.accession_number.trim(),
      barcode: values.barcode.trim(),
      rfid_tag: values.rfid_tag.trim(),
      rfid_status: values.rfid_status,
      location_id: values.location_id,
      condition_id: values.condition_id,
      status_id: values.status_id,
      status: values.status,
      acquisition_date: values.acquisition_date,
      acquisition_source_id: values.acquisition_source_id,
      acquisition_price: values.acquisition_price,
      funding_source: values.funding_source.trim(),
      notes: values.notes.trim(),
    };

    onSubmit(payload, selectedBibId === '' ? undefined : selectedBibId);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Eksemplar' : 'Tambah Eksemplar'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-item-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="library-item-form" onSubmit={handleSubmit} className="space-y-4">
        {!hideRecordPicker && (
          <div>
            <Label required>Judul (Record Bibliografi)</Label>
            <SearchSelect
              options={recordOptions}
              value={selectedBibId}
              error={err('bibliography_id')}
              onChange={(v) => setSelectedBibId(toId(v))}
              placeholder="-- Pilih judul --"
            />
            <p className="mt-1 text-xs text-slate-500">
              Eksemplar selalu terikat pada satu judul. Satu judul dapat memiliki banyak eksemplar.
            </p>
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <Label>Kode Eksemplar</Label>
            <Input
              value={values.item_code}
              error={err('item_code')}
              disabled={Boolean(editing)}
              placeholder="Otomatis bila kosong"
              onChange={(e) => set('item_code', e.target.value)}
            />
            <p className="mt-1 text-xs text-slate-500">
              Dibiarkan kosong = dibangkitkan otomatis dari penomoran perpustakaan.
            </p>
          </div>
          <div>
            <Label>Nomor Inventaris</Label>
            <Input
              value={values.accession_number}
              error={err('accession_number')}
              disabled={Boolean(editing)}
              placeholder="Otomatis bila kosong"
              onChange={(e) => set('accession_number', e.target.value)}
            />
            <p className="mt-1 text-xs text-slate-500">Nomor induk/akses; kosong = otomatis.</p>
          </div>
          <div>
            <Label>Barcode</Label>
            <Input
              value={values.barcode}
              error={err('barcode')}
              placeholder="Otomatis bila kosong"
              onChange={(e) => set('barcode', e.target.value)}
            />
            <p className="mt-1 text-xs text-slate-500">
              Dibangkitkan dari kode eksemplar kecuali pemindaian manual (catalog.barcode_auto).
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <Label>Tag RFID</Label>
            <Input
              value={values.rfid_tag}
              error={err('rfid_tag')}
              placeholder="mis. 0001A2B3C4"
              onChange={(e) => {
                const next = e.target.value;
                setValues((prev) => ({
                  ...prev,
                  rfid_tag: next,
                  rfid_status:
                    next.trim() !== '' && prev.rfid_status === 'unassigned'
                      ? 'assigned'
                      : prev.rfid_status,
                }));
              }}
            />
          </div>
          <div>
            <Label>Status RFID</Label>
            <Select
              value={values.rfid_status}
              error={err('rfid_status')}
              onChange={(e) => set('rfid_status', e.target.value as RfidStatus)}
            >
              {toOptions(RFID_STATUS_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
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
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <Label>Lokasi</Label>
            <SearchSelect
              options={locationOptions}
              value={values.location_id}
              error={err('location_id')}
              disabled={libraryId === null}
              onChange={(v) => set('location_id', toId(v))}
              placeholder={libraryId === null ? 'Pilih judul dulu' : '-- Pilih lokasi --'}
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
            <Label>Harga Perolehan</Label>
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
            rows={3}
            value={values.notes}
            error={err('notes')}
            placeholder="Catatan kondisi, riwayat, atau keterangan lain"
            onChange={(e) => set('notes', e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}