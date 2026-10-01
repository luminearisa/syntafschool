import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, Filter, Plus, Printer, ScanLine } from 'lucide-react';
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
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate } from '@/lib/utils';
import { assetsApi, reportsApi } from '../api/assetApi';
import {
  ConditionBadge,
  AssetStatusBadge,
  FormField,
  Money,
  useCategoryOptions,
  useCustodianOptions,
  useLocationOptions,
} from '../components/AssetUi';
import { acquisitionSource, assetCondition, assetStatus, criticality as criticalityLabels, tagStatus } from '../lib/labels';
import { toDateInput } from '../lib/format';
import type { Asset } from '../types';

const TONE = (v?: string | null) => criticalityLabels.tone(v);

/** Pendaftaran aset — satu formulir, dengan konteks pengakuan yang terlihat. */
function RegisterAssetModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: categoryOptions, categories } = useCategoryOptions();
  const { options: locationOptions } = useLocationOptions();
  const { options: custodianOptions } = useCustodianOptions();

  const [form, setForm] = useState({
    name: '',
    category_id: '' as number | '',
    location_id: '' as number | '',
    custodian_id: '' as number | '',
    acquisition_source: 'PURCHASE',
    purchase_date: toDateInput(new Date()),
    commission_date: '',
    acquisition_cost: '',
    residual_value: '',
    useful_life_months: '',
    criticality: 'NORMAL',
    condition: 'GOOD',
    serial_number: '',
    manufacturer: '',
    brand: '',
    model: '',
    warranty_end: '',
    description: '',
    force_asset: false,
    notes: '',
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const kategori = categories.find((c) => c.id === form.category_id);
  const ambang = Number(kategori?.capitalization_threshold ?? 0);
  const biaya = Number(form.acquisition_cost || 0);

  // Petunjuk pengakuan ditampilkan SEBELUM menyimpan: petugas melihat ke arah
  // mana barang ini akan dicatat, dan dapat menimpanya dengan alasan.
  const perkiraanPengakuan = useMemo(() => {
    if (!kategori) return null;
    if (kategori.kind === 'INVENTORY' || kategori.is_consumable) {
      return { tone: 'blue' as const, teks: 'Barang habis pakai — akan dicatat ke Persediaan, bukan sebagai aset.' };
    }
    if (ambang > 0 && biaya > 0 && biaya < ambang) {
      return {
        tone: 'amber' as const,
        teks: `Di bawah ambang kapitalisasi kategori (${new Intl.NumberFormat('id-ID').format(ambang)}) — akan dicatat sebagai beban, kecuali Anda memilih "tetap sebagai aset".`,
      };
    }
    return { tone: 'green' as const, teks: 'Memenuhi syarat dicatat sebagai aset.' };
  }, [kategori, ambang, biaya]);

  const simpan = useMutation({
    mutationFn: () =>
      assetsApi.create({
        ...form,
        category_id: form.category_id === '' ? undefined : form.category_id,
        location_id: form.location_id === '' ? undefined : form.location_id,
        custodian_id: form.custodian_id === '' ? undefined : form.custodian_id,
        acquisition_cost: form.acquisition_cost === '' ? undefined : form.acquisition_cost,
        residual_value: form.residual_value === '' ? undefined : form.residual_value,
        useful_life_months: form.useful_life_months === '' ? undefined : Number(form.useful_life_months),
        purchase_date: form.purchase_date || undefined,
        commission_date: form.commission_date || undefined,
        warranty_end: form.warranty_end || undefined,
      }),
    onSuccess: (asset) => {
      toast.success(`Aset ${asset.asset_code} terdaftar`);
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
      open={open}
      onClose={onClose}
      title="Daftarkan Aset"
      size="xl"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !form.name || form.category_id === ''}>
            {simpan.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Daftarkan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <FormField label="Nama barang" required error={errors.name?.[0]} className="sm:col-span-2">
          <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Misal: Laptop Lenovo ThinkPad E14" />
        </FormField>
        <FormField label="Kategori" required error={errors.category_id?.[0]}>
          <SearchSelect options={categoryOptions} value={form.category_id} onChange={(v) => set('category_id', v === '' ? '' : Number(v))} />
        </FormField>
        <FormField label="Sumber perolehan" error={errors.acquisition_source?.[0]}>
          <Select value={form.acquisition_source} onChange={(e) => set('acquisition_source', e.target.value)}>
            {acquisitionSource.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tanggal perolehan" required error={errors.purchase_date?.[0]}>
          <Input type="date" value={form.purchase_date} onChange={(e) => set('purchase_date', e.target.value)} />
        </FormField>
        <FormField label="Tanggal mulai dipakai" error={errors.commission_date?.[0]} hint="Menentukan awal penyusutan dan umur manfaat.">
          <Input type="date" value={form.commission_date} onChange={(e) => set('commission_date', e.target.value)} />
        </FormField>
        <FormField label="Nilai perolehan (Rp)" required error={errors.acquisition_cost?.[0]}>
          <Input type="number" min={0} value={form.acquisition_cost} onChange={(e) => set('acquisition_cost', e.target.value)} />
        </FormField>
        <FormField label="Nilai residu (Rp)" error={errors.residual_value?.[0]} hint="Perkiraan nilai di akhir umur manfaat.">
          <Input type="number" min={0} value={form.residual_value} onChange={(e) => set('residual_value', e.target.value)} />
        </FormField>
        <FormField label="Umur manfaat (bulan)" error={errors.useful_life_months?.[0]} hint={kategori?.useful_life_months ? `Kategori ini bawaannya ${kategori.useful_life_months} bulan.` : undefined}>
          <Input type="number" min={1} value={form.useful_life_months} onChange={(e) => set('useful_life_months', e.target.value)} />
        </FormField>
        <FormField label="Kekritisan" error={errors.criticality?.[0]}>
          <Select value={form.criticality} onChange={(e) => set('criticality', e.target.value)}>
            {criticalityLabels.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Kondisi awal" error={errors.condition?.[0]}>
          <Select value={form.condition} onChange={(e) => set('condition', e.target.value)}>
            {assetCondition.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Lokasi" error={errors.location_id?.[0]}>
          <SearchSelect options={locationOptions} value={form.location_id} onChange={(v) => set('location_id', v === '' ? '' : Number(v))} />
        </FormField>
        <FormField label="Pemegang" error={errors.custodian_id?.[0]} hint="Barang yang jelas pemegangnya jauh lebih jarang hilang.">
          <SearchSelect options={custodianOptions} value={form.custodian_id} onChange={(v) => set('custodian_id', v === '' ? '' : Number(v))} />
        </FormField>
        <FormField label="Nomor seri" error={errors.serial_number?.[0]}>
          <Input value={form.serial_number} onChange={(e) => set('serial_number', e.target.value)} />
        </FormField>
        <FormField label="Merek" error={errors.brand?.[0]}>
          <Input value={form.brand} onChange={(e) => set('brand', e.target.value)} />
        </FormField>
        <FormField label="Model" error={errors.model?.[0]}>
          <Input value={form.model} onChange={(e) => set('model', e.target.value)} />
        </FormField>
        <FormField label="Pabrikan" error={errors.manufacturer?.[0]}>
          <Input value={form.manufacturer} onChange={(e) => set('manufacturer', e.target.value)} />
        </FormField>
        <FormField label="Garansi berakhir" error={errors.warranty_end?.[0]}>
          <Input type="date" value={form.warranty_end} onChange={(e) => set('warranty_end', e.target.value)} />
        </FormField>
        <FormField label="Catatan" className="sm:col-span-2 lg:col-span-3" error={errors.notes?.[0]}>
          <Textarea rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} />
        </FormField>
      </div>

      {perkiraanPengakuan && (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
          <span className="text-slate-700">{perkiraanPengakuan.teks}</span>
          {perkiraanPengakuan.tone !== 'green' && (
            <label className="flex items-center gap-1.5 text-slate-700">
              <input type="checkbox" checked={form.force_asset} onChange={(e) => set('force_asset', e.target.checked)} />
              Tetap catat sebagai aset
            </label>
          )}
        </div>
      )}
    </Modal>
  );
}

export default function AssetListPage() {
  const [params, setParams] = useSearchParams();
  const [bukaDaftar, setBukaDaftar] = useState(false);
  const [terpilih, setTerpilih] = useState<number[]>([]);

  const page = Number(params.get('page') ?? 1);
  const filters = {
    search: params.get('search') ?? '',
    category_id: params.get('category_id') ?? '',
    status: params.get('status') ?? '',
    condition: params.get('condition') ?? '',
    location_id: params.get('location_id') ?? '',
    tag_status: params.get('tag_status') ?? '',
    per_page: 25,
  };

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'list', page, filters],
    queryFn: () => assetsApi.list({ ...filters, page }),
  });

  const { options: locationOptions } = useLocationOptions();
  const { options: categoryOptions } = useCategoryOptions();

  const update = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    next.delete('page');
    setParams(next);
  };

  const unduh = useMutation({
    mutationFn: () => reportsApi.download('asset-register', { ...filters }),
    onSuccess: (blob) => {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'daftar-aset.csv';
      a.click();
      URL.revokeObjectURL(url);
      toast.success('Daftar aset diunduh');
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Daftar Aset</h1>
          <p className="text-xs text-slate-500">
            {data?.meta?.total ?? 0} barang terdaftar. Setiap perpindahan, peminjaman, dan perubahan status tercatat pada jejak audit.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => unduh.mutate()} disabled={unduh.isPending}>
            <Download className="mr-1 h-4 w-4" /> Unduh CSV
          </Button>
          <Link to="/assets/labels">
            <Button variant="outline" size="sm">
              <Printer className="mr-1 h-4 w-4" /> Label &amp; QR
            </Button>
          </Link>
          <Can perm="asset.register">
            <Button size="sm" onClick={() => setBukaDaftar(true)}>
              <Plus className="mr-1 h-4 w-4" /> Daftarkan aset
            </Button>
          </Can>
        </div>
      </header>

      <Card className="p-4">
        <div className="mb-3 flex items-center gap-2 text-xs font-medium text-slate-600">
          <Filter className="h-3.5 w-3.5" /> Saringan
        </div>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
          <Input
            placeholder="Cari nama / kode / nomor seri…"
            value={filters.search}
            onChange={(e) => update('search', e.target.value)}
          />
          <SearchSelect options={categoryOptions} value={filters.category_id} onChange={(v) => update('category_id', String(v))} placeholder="Kategori" />
          <SearchSelect options={locationOptions} value={filters.location_id} onChange={(v) => update('location_id', String(v))} placeholder="Lokasi" />
          <Select value={filters.status} onChange={(e) => update('status', e.target.value)}>
            <option value="">Semua status</option>
            {assetStatus.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select value={filters.condition} onChange={(e) => update('condition', e.target.value)}>
            <option value="">Semua kondisi</option>
            {assetCondition.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select value={filters.tag_status} onChange={(e) => update('tag_status', e.target.value)}>
            <option value="">Semua label</option>
            {tagStatus.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>

        {terpilih.length > 0 && (
          <div className="mt-3 flex flex-wrap items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-800">
            <span>{terpilih.length} barang dipilih</span>
            <Link to={`/assets/labels?ids=${terpilih.join(',')}`}>
              <Button variant="outline" size="sm">
                <Printer className="mr-1 h-3.5 w-3.5" /> Cetak label
              </Button>
            </Link>
            <Button variant="ghost" size="sm" onClick={() => setTerpilih([])}>
              Bersihkan pilihan
            </Button>
          </div>
        )}
      </Card>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            title="Tidak ada aset yang cocok"
            description="Ubah saringan, atau daftarkan barang baru."
            action={
              <Can perm="asset.register">
                <Button size="sm" onClick={() => setBukaDaftar(true)}>
                  <Plus className="mr-1 h-4 w-4" /> Daftarkan aset
                </Button>
              </Can>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[880px] text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="w-10 px-4 py-3">
                    <input
                      type="checkbox"
                      checked={terpilih.length === rows.length && rows.length > 0}
                      onChange={(e) => setTerpilih(e.target.checked ? rows.map((r) => r.id) : [])}
                      aria-label="Pilih semua"
                    />
                  </th>
                  <th className="px-4 py-3">Kode</th>
                  <th className="px-4 py-3">Nama</th>
                  <th className="px-4 py-3">Kategori</th>
                  <th className="px-4 py-3">Lokasi</th>
                  <th className="px-4 py-3">Pemegang</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Kondisi</th>
                  <th className="px-4 py-3 text-right">Nilai buku</th>
                  <th className="px-4 py-3">Label</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((asset: Asset) => (
                  <tr key={asset.id} className="hover:bg-slate-50">
                    <td className="px-4 py-2.5">
                      <input
                        type="checkbox"
                        checked={terpilih.includes(asset.id)}
                        onChange={(e) =>
                          setTerpilih((prev) => (e.target.checked ? [...prev, asset.id] : prev.filter((id) => id !== asset.id)))
                        }
                        aria-label={`Pilih ${asset.name}`}
                      />
                    </td>
                    <td className="px-4 py-2.5 font-mono text-xs">
                      <Link to={`/assets/${asset.id}`} className="text-brand-700 hover:underline">
                        {asset.asset_code}
                      </Link>
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-slate-800">{asset.name}</span>
                        {asset.criticality && <Badge tone={TONE(asset.criticality)}>{criticalityLabels.label(asset.criticality)}</Badge>}
                      </div>
                      {asset.serial_number && <p className="text-[11px] text-slate-500">SN {asset.serial_number}</p>}
                    </td>
                    <td className="px-4 py-2.5 text-slate-600">{asset.category?.name ?? '-'}</td>
                    <td className="px-4 py-2.5 text-slate-600">{asset.location?.name ?? '-'}</td>
                    <td className="px-4 py-2.5 text-slate-600">{asset.custodian_label ?? '-'}</td>
                    <td className="px-4 py-2.5">
                      <AssetStatusBadge value={asset.status} />
                    </td>
                    <td className="px-4 py-2.5">
                      <ConditionBadge value={asset.condition} />
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      <Money value={asset.financial?.book_value} />
                    </td>
                    <td className="px-4 py-2.5">
                      <Badge tone={tagStatus.tone(asset.tag_status)}>{tagStatus.label(asset.tag_status)}</Badge>
                      {asset.purchase_date && <p className="text-[11px] text-slate-500">{formatDate(asset.purchase_date)}</p>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {data?.meta && (
          <Pagination
            meta={data.meta}
            onPageChange={(p) => {
              const next = new URLSearchParams(params);
              next.set('page', String(p));
              setParams(next);
            }}
          />
        )}
      </Card>

      <p className="flex items-center gap-2 text-[11px] text-slate-500">
        <ScanLine className="h-3.5 w-3.5" />
        Barang tanpa label tidak akan muncul pada opname begitu sekolah mulai memindai — cetak labelnya lebih dulu.
      </p>

      {bukaDaftar && <RegisterAssetModal open onClose={() => setBukaDaftar(false)} />}
    </div>
  );
}
