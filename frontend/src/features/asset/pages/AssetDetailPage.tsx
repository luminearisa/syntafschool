import type { ReactNode } from 'react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowRightLeft,
  ClipboardCheck,
  Clock,
  FileText,
  History,
  MapPin,
  Pin,
  Printer,
  QrCode,
  RotateCcw,
  ShieldCheck,
  Undo2,
  Wallet,
  Wrench,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate } from '@/lib/utils';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { assetsApi, labelsApi } from '../api/assetApi';
import { inspectionsApi, loansApi, maintenanceApi, transfersApi } from '../api/assetOpsApi';
import { replacementsApi } from '../api/assetGovernanceApi';
import { AssetLabelCard } from '../components/AssetLabelCard';
import {
  AssetStatusBadge,
  ConditionBadge,
  CriticalityBadge,
  DetailRow,
  FormField,
  Money,
  RestrictedNotice,
  SectionCard,
  useCustodianOptions,
  useLocationOptions,
} from '../components/AssetUi';
import { fmtDateTime, isOverdue, lifeProgress, toDateTimeInput } from '../lib/format';
import {
  acquisitionSource,
  assetCondition,
  assetStatus,
  loanStatus,
  maintenanceRequestStatus,
  replacementRecommendation,
  tagStatus,
  transferStatus,
  transferType,
  valuationType,
} from '../lib/labels';
import type { Asset } from '../types';

type Tab = 'ringkasan' | 'penempatan' | 'peminjaman' | 'pemindahan' | 'pemeliharaan' | 'inspeksi' | 'keuangan' | 'label' | 'penggantian' | 'riwayat';

const TABS: { key: Tab; label: string; icon: ReactNode }[] = [
  { key: 'ringkasan', label: 'Ringkasan', icon: <FileText className="h-3.5 w-3.5" /> },
  { key: 'penempatan', label: 'Penempatan', icon: <MapPin className="h-3.5 w-3.5" /> },
  { key: 'peminjaman', label: 'Peminjaman', icon: <ClipboardCheck className="h-3.5 w-3.5" /> },
  { key: 'pemindahan', label: 'Pemindahan', icon: <ArrowRightLeft className="h-3.5 w-3.5" /> },
  { key: 'pemeliharaan', label: 'Pemeliharaan', icon: <Wrench className="h-3.5 w-3.5" /> },
  { key: 'inspeksi', label: 'Inspeksi', icon: <ShieldCheck className="h-3.5 w-3.5" /> },
  { key: 'keuangan', label: 'Nilai & Penyusutan', icon: <Wallet className="h-3.5 w-3.5" /> },
  { key: 'label', label: 'Label & QR', icon: <QrCode className="h-3.5 w-3.5" /> },
  { key: 'penggantian', label: 'Penggantian', icon: <RotateCcw className="h-3.5 w-3.5" /> },
  { key: 'riwayat', label: 'Jejak Audit', icon: <History className="h-3.5 w-3.5" /> },
];

/** Ubah status: alasan WAJIB — status tanpa alasan adalah perubahan yang tak dapat dimintai pertanggungjawaban. */
function StatusModal({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const qc = useQueryClient();
  const [status, setStatus] = useState(asset.status);
  const [reason, setReason] = useState('');

  const simpan = useMutation({
    mutationFn: () => assetsApi.changeStatus(asset.id, { status, reason }),
    onSuccess: () => {
      toast.success('Status diperbarui');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Ubah status aset"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !reason.trim()}>
            Simpan
          </Button>
        </div>
      }
    >
      <FormField label="Status baru" required>
        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
          {assetStatus.options().map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      </FormField>
      <FormField label="Alasan" required className="mt-3" hint="Alasan tersimpan pada jejak audit dan tidak dapat diubah setelahnya.">
        <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function PlaceModal({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: locationOptions } = useLocationOptions();
  const { options: custodianOptions } = useCustodianOptions();
  const [locationId, setLocationId] = useState<number | ''>(asset.location?.id ?? '');
  const [custodianId, setCustodianId] = useState<number | ''>(asset.custodian_id ?? '');
  const [catatan, setCatatan] = useState('');

  const simpan = useMutation({
    mutationFn: () => assetsApi.place(asset.id, { location_id: locationId, custodian_id: custodianId }),
    onSuccess: () => {
      toast.success('Penempatan dicatat — riwayat pemegang diperbarui');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Ubah penempatan"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || (locationId === '' && custodianId === '')}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Lokasi" hint="Pemindahan ruang tidak menghapus riwayat ruang sebelumnya.">
          <SearchSelect options={locationOptions} value={locationId} onChange={(v) => setLocationId(v === '' ? '' : Number(v))} />
        </FormField>
        <FormField label="Pemegang">
          <SearchSelect options={custodianOptions} value={custodianId} onChange={(v) => setCustodianId(v === '' ? '' : Number(v))} />
        </FormField>
        <FormField label="Catatan">
          <Textarea rows={2} value={catatan} onChange={(e) => setCatatan(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

/** Peminjaman cepat dari halaman aset: peminjam adalah pengguna yang sedang bekerja. */
function LoanModal({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const [form, setForm] = useState({
    purpose: '',
    start_at: toDateTimeInput(new Date()),
    due_at: toDateTimeInput(new Date(Date.now() + 86_400_000)),
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () =>
      loansApi.quickLoan(asset.id, {
        ...form,
        borrower_id: user?.id,
        borrower_type: 'USER',
      }),
    onSuccess: () => {
      toast.success('Peminjaman diajukan');
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
      title={`Pinjamkan ${asset.name}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !form.purpose}>
            Ajukan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Keperluan" required error={errors.purpose?.[0]}>
          <Input value={form.purpose} onChange={(e) => setForm({ ...form, purpose: e.target.value })} placeholder="Misal: rapat komite" />
        </FormField>
        <FormField label="Mulai" required error={errors.start_at?.[0]}>
          <Input type="datetime-local" value={form.start_at} onChange={(e) => setForm({ ...form, start_at: e.target.value })} />
        </FormField>
        <FormField label="Batas kembali" required error={errors.due_at?.[0]} hint="Batas hari ditentukan kebijakan sekolah.">
          <Input type="datetime-local" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  );
}

/**
 * Pengembalian SELALU meminta kondisi barang.
 *
 * Ini bukan keramahan formulir, melainkan inti pengendalian: tanpa kondisi,
 * barang yang kembali rusak akan berubah menjadi "hilang" beberapa minggu
 * kemudian tanpa siapa pun tahu kapan kerusakannya terjadi.
 */
function ReturnModal({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const qc = useQueryClient();
  const [condition, setCondition] = useState('GOOD');
  const [damageFound, setDamageFound] = useState(false);
  const [notes, setNotes] = useState('');

  const simpan = useMutation({
    mutationFn: () => loansApi.quickReturn(asset.id, { condition, damage_found: damageFound, notes }),
    onSuccess: () => {
      toast.success(damageFound ? 'Barang dikembalikan — laporan kerusakan dibuat' : 'Barang dikembalikan');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Terima kembali ${asset.name}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending}>
            Catat pengembalian
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Kondisi saat kembali" required>
          <Select
            value={condition}
            onChange={(e) => {
              setCondition(e.target.value);
              setDamageFound(e.target.value !== 'GOOD');
            }}
          >
            {assetCondition.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Catatan" hint="Bila ada kerusakan, sistem membuat laporan kerusakan dan permintaan pemeliharaan.">
          <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
        {damageFound && (
          <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Kondisi bukan “Baik”: laporan kerusakan akan dibuat dan permintaan pemeliharaan diterbitkan otomatis.
          </p>
        )}
      </div>
    </Modal>
  );
}

function TransferModal({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: locationOptions } = useLocationOptions();
  const [toLocationId, setToLocationId] = useState<number | ''>('');
  const [type, setType] = useState('ROOM_TRANSFER');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () => transfersApi.quickTransfer(asset.id, { to_location_id: toLocationId, type, reason }),
    onSuccess: () => {
      toast.success('Permintaan pemindahan diajukan — menunggu persetujuan');
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
      title={`Pindahkan ${asset.name}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || toLocationId === ''}>
            Ajukan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Tujuan" required error={errors.to_location_id?.[0]} hint="Pemindahan tanpa tujuan jelas selalu ditolak.">
          <SearchSelect options={locationOptions} value={toLocationId} onChange={(v) => setToLocationId(v === '' ? '' : Number(v))} />
        </FormField>
        <FormField label="Jenis">
          <Select value={type} onChange={(e) => setType(e.target.value)}>
            {transferType.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Alasan" required error={errors.reason?.[0]}>
          <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function LabelTab({ asset }: { asset: Asset }) {
  const qc = useQueryClient();
  const [bukaGanti, setBukaGanti] = useState(false);
  const [alasan, setAlasan] = useState('');

  const { data: label, isLoading } = useQuery({
    queryKey: ['asset', 'label', asset.id],
    queryFn: () => labelsApi.get(asset.id),
  });

  const tandai = useMutation({
    mutationFn: () => labelsApi.markPrinted(asset.id, { copies: 1 }),
    onSuccess: () => {
      toast.success('Ditandai tercetak');
      qc.invalidateQueries({ queryKey: ['asset', 'label', asset.id] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const pasang = useMutation({
    mutationFn: () => labelsApi.attach(asset.id, {}),
    onSuccess: () => {
      toast.success('Label ditandai terpasang');
      qc.invalidateQueries({ queryKey: ['asset', 'label', asset.id] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const ganti = useMutation({
    mutationFn: () => labelsApi.replace(asset.id, { reason: alasan }),
    onSuccess: () => {
      toast.success('Label diganti — cetak ulang lalu tempel yang baru');
      qc.invalidateQueries({ queryKey: ['asset', 'label', asset.id] });
      setBukaGanti(false);
      setAlasan('');
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (isLoading) {
    return (
      <div className="flex justify-center py-10">
        <Spinner />
      </div>
    );
  }
  if (!label) return <EmptyState title="Label belum tersedia" />;

  return (
    <div className="space-y-4">
      <div className="max-w-sm">
        <AssetLabelCard label={label} />
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={tagStatus.tone(label.label_status ?? asset.tag_status)}>{tagStatus.label(label.label_status ?? asset.tag_status)}</Badge>
        {label.print_count !== null && label.print_count !== undefined && (
          <span className="text-xs text-slate-500">Dicetak {label.print_count}×</span>
        )}
        <Can perm="asset.manage">
          <Button variant="outline" size="sm" onClick={() => tandai.mutate()} disabled={tandai.isPending}>
            <Printer className="mr-1 h-3.5 w-3.5" /> Tandai tercetak
          </Button>
          <Button variant="outline" size="sm" onClick={() => pasang.mutate()} disabled={pasang.isPending}>
            <Pin className="mr-1 h-3.5 w-3.5" /> Tandai terpasang
          </Button>
          <Button variant="ghost" size="sm" onClick={() => setBukaGanti(true)}>
            <Undo2 className="mr-1 h-3.5 w-3.5" /> Ganti label
          </Button>
        </Can>
        {label.qr_url && (
          <Link to={label.qr_url} target="_blank" className="text-xs text-brand-700 hover:underline">
            Buka halaman publik QR
          </Link>
        )}
      </div>
      <p className="text-[11px] text-slate-500">
        QR hanya memuat kode aset, nama, lokasi, dan kondisi — tanpa harga atau pemegang, karena label dapat dipindai siapa pun.
      </p>

      {bukaGanti && (
        <Modal
          open
          onClose={() => setBukaGanti(false)}
          title="Ganti label"
          footer={
            <div className="flex justify-end gap-2">
              <Button variant="ghost" onClick={() => setBukaGanti(false)}>
                Batal
              </Button>
              <Button onClick={() => ganti.mutate()} disabled={ganti.isPending || !alasan.trim()}>
                Ganti
              </Button>
            </div>
          }
        >
          <FormField label="Alasan penggantian" required>
            <Textarea rows={3} value={alasan} onChange={(e) => setAlasan(e.target.value)} />
          </FormField>
        </Modal>
      )}
    </div>
  );
}

/** Formulir penilaian ulang — mengubah nilai resmi aset, jadi butuh izin tersendiri. */
function RevalueForm({ assetId }: { assetId: number }) {
  const qc = useQueryClient();
  const [buka, setBuka] = useState(false);
  const [form, setForm] = useState({ valuation_type: 'REVALUATION', amount: '', reason: '', document_reference: '' });

  const simpan = useMutation({
    mutationFn: () => assetsApi.revalue(assetId, form),
    onSuccess: () => {
      toast.success('Penilaian ulang dicatat');
      qc.invalidateQueries({ queryKey: ['asset'] });
      setBuka(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <>
      <Button variant="outline" size="sm" className="mt-4" onClick={() => setBuka(true)}>
        Catat penilaian ulang
      </Button>
      {buka && (
        <Modal
          open
          onClose={() => setBuka(false)}
          title="Penilaian ulang aset"
          footer={
            <div className="flex justify-end gap-2">
              <Button variant="ghost" onClick={() => setBuka(false)}>
                Batal
              </Button>
              <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !form.amount || !form.reason}>
                Simpan
              </Button>
            </div>
          }
        >
          <div className="space-y-3">
            <FormField label="Jenis penilaian" required>
              <Select value={form.valuation_type} onChange={(e) => setForm({ ...form, valuation_type: e.target.value })}>
                {valuationType.options().map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Nilai baru (Rp)" required>
              <Input type="number" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </FormField>
            <FormField label="Alasan" required hint="Alasan tercatat pada jejak audit.">
              <Textarea rows={2} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
            </FormField>
            <FormField label="Dokumen rujukan">
              <Input value={form.document_reference} onChange={(e) => setForm({ ...form, document_reference: e.target.value })} />
            </FormField>
          </div>
        </Modal>
      )}
    </>
  );
}

export default function AssetDetailPage() {
  const { assetId } = useParams();
  const id = Number(assetId);
  const { can } = usePermission();
  const [tab, setTab] = useState<Tab>('ringkasan');
  const [modal, setModal] = useState<'status' | 'place' | 'loan' | 'return' | 'transfer' | null>(null);

  const { data: asset, isLoading } = useQuery({
    queryKey: ['asset', 'detail', id],
    queryFn: () => assetsApi.show(id),
    enabled: Number.isFinite(id),
  });

  const { data: timeline } = useQuery({
    queryKey: ['asset', 'timeline', id],
    queryFn: () => assetsApi.timeline(id),
    enabled: Number.isFinite(id),
  });

  const { data: loans } = useQuery({
    queryKey: ['asset', 'loans', id],
    queryFn: () => loansApi.list({ asset_id: id, per_page: 20 }),
    enabled: tab === 'peminjaman',
  });

  const { data: transfers } = useQuery({
    queryKey: ['asset', 'transfers', id],
    queryFn: () => transfersApi.list({ asset_id: id, per_page: 20 }),
    enabled: tab === 'pemindahan',
  });

  const { data: maintenance } = useQuery({
    queryKey: ['asset', 'maintenance', id],
    queryFn: () => maintenanceApi.requests({ asset_id: id, per_page: 20 }),
    enabled: tab === 'pemeliharaan',
  });

  const { data: inspeksi } = useQuery({
    queryKey: ['asset', 'inspections', id],
    queryFn: () => inspectionsApi.list({ asset_id: id, per_page: 20 }),
    enabled: tab === 'inspeksi' && can('asset.inspect.view'),
  });

  const { data: replacement } = useQuery({
    queryKey: ['asset', 'replacement', id],
    queryFn: () => replacementsApi.quick(id),
    enabled: tab === 'penggantian',
    retry: false,
  });

  if (isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  if (!asset) {
    return <EmptyState title="Aset tidak ditemukan" description="Barang ini mungkin berada di unit sekolah lain, atau sudah tidak dapat Anda akses." />;
  }

  const garansiLewat = asset.warranty?.until ? new Date(asset.warranty.until).getTime() < Date.now() : false;
  const umur = lifeProgress(asset.life?.expected_life_end, asset.life?.useful_life_months);
  const persenUmur = umur.percent;

  return (
    <div className="space-y-4">
      <nav className="text-xs text-slate-500">
        <Link to="/assets" className="hover:underline">
          Daftar Aset
        </Link>{' '}
        / <span className="font-mono">{asset.asset_code}</span>
      </nav>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-lg font-semibold text-slate-800">{asset.name}</h1>
            <AssetStatusBadge value={asset.status} />
            <ConditionBadge value={asset.condition} />
            <CriticalityBadge value={asset.criticality} />
            {asset.is_archived && <Badge tone="gray">Diarsipkan</Badge>}
          </div>
          <p className="mt-1 text-xs text-slate-500">
            <span className="font-mono">{asset.asset_code}</span>
            {asset.location?.name && <> · {asset.location.name}</>}
            {asset.custodian_label && <> · pemegang: {asset.custodian_label}</>}
            {asset.serial_number && <> · SN {asset.serial_number}</>}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Can perm="asset.assign">
            <Button variant="outline" size="sm" onClick={() => setModal('place')}>
              <MapPin className="mr-1 h-4 w-4" /> Penempatan
            </Button>
          </Can>
          {asset.status === 'ON_LOAN' ? (
            <Can perm="asset.loan.manage">
              <Button variant="outline" size="sm" onClick={() => setModal('return')}>
                <Undo2 className="mr-1 h-4 w-4" /> Terima kembali
              </Button>
            </Can>
          ) : (
            <Can perm="asset.loan.request">
              <Button variant="outline" size="sm" onClick={() => setModal('loan')}>
                <ClipboardCheck className="mr-1 h-4 w-4" /> Pinjamkan
              </Button>
            </Can>
          )}
          <Can perm="asset.transfer.request">
            <Button variant="outline" size="sm" onClick={() => setModal('transfer')}>
              <ArrowRightLeft className="mr-1 h-4 w-4" /> Pindahkan
            </Button>
          </Can>
          <Can perm="asset.manage">
            <Button variant="outline" size="sm" onClick={() => setModal('status')}>
              Ubah status
            </Button>
          </Can>
        </div>
      </header>

      <div className="flex flex-wrap gap-1 border-b border-slate-200">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`flex items-center gap-1.5 border-b-2 px-3 py-2 text-xs font-medium transition ${
              tab === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            {t.icon}
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'ringkasan' && (
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          <SectionCard title="Identitas barang">
            <div className="grid grid-cols-2 gap-x-4">
              <DetailRow label="Kode aset">
                <span className="font-mono">{asset.asset_code}</span>
              </DetailRow>
              <DetailRow label="Barcode">{asset.barcode ?? '-'}</DetailRow>
              <DetailRow label="Kategori">{asset.category?.name ?? '-'}</DetailRow>
              <DetailRow label="Jenis">{asset.type?.name ?? '-'}</DetailRow>
              <DetailRow label="Merek / Model">{[asset.brand, asset.model].filter(Boolean).join(' ') || '-'}</DetailRow>
              <DetailRow label="Nomor seri">{asset.serial_number ?? '-'}</DetailRow>
              <DetailRow label="Pabrikan">{asset.manufacturer ?? '-'}</DetailRow>
              <DetailRow label="Sumber perolehan">{acquisitionSource.label(asset.acquisition_source)}</DetailRow>
              <DetailRow label="Tanggal perolehan">{formatDate(asset.purchase_date)}</DetailRow>
              <DetailRow label="Mulai dipakai">{formatDate(asset.commission_date)}</DetailRow>
              <DetailRow label="Garansi sampai">
                {asset.warranty?.until ? (
                  <span className={garansiLewat ? 'text-amber-700' : ''}>
                    {formatDate(asset.warranty.until)} {garansiLewat && '(berakhir)'}
                  </span>
                ) : (
                  '-'
                )}
              </DetailRow>
              <DetailRow label="Kondisi sekarang">
                <ConditionBadge value={asset.condition} />
              </DetailRow>
            </div>
            {asset.description && <p className="mt-3 text-sm text-slate-600">{asset.description}</p>}
          </SectionCard>

          <div className="space-y-3">
            <SectionCard title="Pengakuan &amp; umur manfaat" description="Mengapa barang ini dicatat sebagai aset, dan seberapa jauh umurnya terpakai.">
              <div className="grid grid-cols-2 gap-x-4">
                <DetailRow label="Keputusan pengakuan">{asset.capitalization?.decision_label ?? asset.capitalization?.decision ?? 'Aset'}</DetailRow>
                <DetailRow label="Umur manfaat">{asset.life?.useful_life_months ? `${asset.life.useful_life_months} bulan` : '-'}</DetailRow>
                <DetailRow label="Sisa umur">
                  {umur.remainingMonths !== null ? `${umur.remainingMonths} bulan${umur.lewat ? ' (habis)' : ''}` : '-'}
                </DetailRow>
                <DetailRow label="Akhir umur manfaat">{asset.life?.expected_life_end ? formatDate(asset.life.expected_life_end) : '-'}</DetailRow>
                <DetailRow label="Riwayat peminjaman/perbaikan">
                  {asset.life?.loan_count ?? 0}× pinjam · {asset.life?.maintenance_count ?? 0}× perbaikan
                </DetailRow>
                <DetailRow label="Terpakai">{persenUmur !== null ? `${persenUmur}%` : '-'}</DetailRow>
              </div>
              {persenUmur !== null && (
                <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                  <div
                    className={`h-full ${persenUmur > 90 ? 'bg-red-500' : persenUmur > 70 ? 'bg-amber-500' : 'bg-brand-500'}`}
                    style={{ width: `${Math.min(100, persenUmur)}%` }}
                  />
                </div>
              )}
              {asset.capitalization?.reason && <p className="mt-2 text-xs text-slate-600">{asset.capitalization.reason}</p>}
            </SectionCard>

            <SectionCard title="Kejadian terakhir" description="Riwayat lengkap ada pada tab Jejak Audit.">
              {(timeline ?? []).length === 0 ? (
                <EmptyState title="Belum ada kejadian tercatat" />
              ) : (
                <ul className="space-y-2">
                  {(timeline ?? []).slice(0, 5).map((entry, index) => (
                    <li key={`${entry.at}-${index}`} className="flex items-start gap-2 text-xs">
                      <Clock className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                      <span className="text-slate-500">{fmtDateTime(entry.at)}</span>
                      <span className="text-slate-800">{entry.label ?? entry.action}</span>
                    </li>
                  ))}
                </ul>
              )}
            </SectionCard>
          </div>
        </div>
      )}

      {tab === 'penempatan' && (
        <SectionCard title="Lokasi &amp; pemegang" description="Riwayat pemegang dibuka dan ditutup — pertanyaan “siapa memegang ini bulan lalu” tetap terjawab.">
          <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
            <DetailRow label="Lokasi sekarang">{asset.location?.name ?? '-'}</DetailRow>
            <DetailRow label="Pemegang sekarang">{asset.custodian_label ?? '-'}</DetailRow>
            <DetailRow label="Jenis pemegang">{asset.custodian_type ?? '-'}</DetailRow>
          </div>
          <Can perm="asset.assign">
            <Button variant="outline" size="sm" className="mt-4" onClick={() => setModal('place')}>
              <MapPin className="mr-1 h-3.5 w-3.5" /> Ubah penempatan
            </Button>
          </Can>
        </SectionCard>
      )}

      {tab === 'peminjaman' && (
        <SectionCard title="Riwayat peminjaman" description="Satu barang hanya dapat dipinjam satu transaksi pada satu waktu.">
          {(loans?.data ?? []).length === 0 ? (
            <EmptyState title="Belum pernah dipinjam" />
          ) : (
            <ul className="divide-y divide-slate-100">
              {(loans?.data ?? []).map((loan) => (
                <li key={loan.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                  <div>
                    <span className="font-mono text-xs">{loan.loan_number}</span>
                    <span className="ml-2 text-slate-700">{loan.purpose ?? '-'}</span>
                    <p className="text-[11px] text-slate-500">
                      {fmtDateTime(loan.start_at)} → {fmtDateTime(loan.due_at)}
                      {loan.borrower && <> · {loan.borrower.name}</>}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={loanStatus.tone(loan.status)}>{loanStatus.label(loan.status)}</Badge>
                    {isOverdue(loan.due_at, Boolean(loan.returned_at)) && <Badge tone="red">Terlambat</Badge>}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      )}

      {tab === 'pemindahan' && (
        <SectionCard title="Riwayat pemindahan" description="Pemindahan selesai hanya setelah penerima mengonfirmasi penerimaan barang.">
          {(transfers?.data ?? []).length === 0 ? (
            <EmptyState title="Belum ada pemindahan" />
          ) : (
            <ul className="divide-y divide-slate-100">
              {(transfers?.data ?? []).map((transfer) => (
                <li key={transfer.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                  <div>
                    <span className="font-mono text-xs">{transfer.transfer_number}</span>
                    <span className="ml-2 text-slate-700">
                      {transfer.from_location?.name ?? '-'} → {transfer.to_location?.name ?? '-'}
                    </span>
                    <p className="text-[11px] text-slate-500">{transfer.reason ?? '-'}</p>
                  </div>
                  <Badge tone={transferStatus.tone(transfer.status)}>{transferStatus.label(transfer.status)}</Badge>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      )}

      {tab === 'pemeliharaan' && (
        <SectionCard title="Riwayat pemeliharaan" description="Termasuk biaya dan waktu henti yang pernah terjadi.">
          {(maintenance?.data ?? []).length === 0 ? (
            <EmptyState title="Belum ada pemeliharaan" />
          ) : (
            <ul className="divide-y divide-slate-100">
              {(maintenance?.data ?? []).map((request) => (
                <li key={request.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                  <div>
                    <span className="font-mono text-xs">{request.request_number}</span>
                    <span className="ml-2 text-slate-700">{request.title}</span>
                    <p className="text-[11px] text-slate-500">
                      Dilaporkan {fmtDateTime(request.reported_at)}
                      {request.completed_at && <> · selesai {fmtDateTime(request.completed_at)}</>}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={maintenanceRequestStatus.tone(request.status)}>{maintenanceRequestStatus.label(request.status)}</Badge>
                    <Money value={request.work_order?.total_cost} />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      )}

      {tab === 'inspeksi' && (
        <SectionCard title="Inspeksi" description="Temuan yang gagal melahirkan permintaan pemeliharaan — bukan sekadar formulir.">
          {!can('asset.inspect.view') ? (
            <EmptyState title="Perlu izin inspeksi" description="Hubungi pengelola aset untuk akses ini." />
          ) : (inspeksi?.data ?? []).length === 0 ? (
            <EmptyState title="Belum ada inspeksi" />
          ) : (
            <ul className="divide-y divide-slate-100">
              {(inspeksi?.data ?? []).map((row) => (
                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                  <div>
                    <span className="font-mono text-xs">{row.inspection_number}</span>
                    <span className="ml-2 text-slate-700">{row.inspection_type}</span>
                    <p className="text-[11px] text-slate-500">Dijadwalkan {fmtDateTime(row.scheduled_at)}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={row.status === 'PASSED' ? 'green' : row.status === 'FAILED' ? 'red' : 'blue'}>{row.status.replace(/_/g, ' ')}</Badge>
                    {row.follow_up_required && <Badge tone="amber">Perlu tindak lanjut</Badge>}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      )}

      {tab === 'keuangan' && (
        <RestrictedNotice perm="asset.financial.view">
          <SectionCard title="Nilai &amp; penyusutan" description="Angka ini ditarik dari catatan keuangan; modul aset tidak menyimpan buku besar kedua.">
            <div className="grid grid-cols-2 gap-x-4 lg:grid-cols-4">
              <DetailRow label="Nilai perolehan">
                <Money value={asset.financial?.acquisition_cost} />
              </DetailRow>
              <DetailRow label="Akumulasi penyusutan">
                <Money value={asset.financial?.accumulated_depreciation} />
              </DetailRow>
              <DetailRow label="Nilai buku">
                <Money value={asset.financial?.book_value} />
              </DetailRow>
              <DetailRow label="Nilai residu">
                <Money value={asset.financial?.salvage_value} />
              </DetailRow>
              <DetailRow label="Faktur">{asset.financial?.invoice_number ?? '-'}</DetailRow>
              <DetailRow label="Pemasok">{asset.financial?.vendor ?? '-'}</DetailRow>
              <DetailRow label="Penilaian terakhir">{fmtDateTime(asset.financial?.last_valuation_at)}</DetailRow>
            </div>
            <Can perm="asset.financial.manage">
              <RevalueForm assetId={asset.id} />
            </Can>
          </SectionCard>
        </RestrictedNotice>
      )}

      {tab === 'label' && <LabelTab asset={asset} />}

      {tab === 'penggantian' && (
        <SectionCard title="Rekomendasi penggantian" description="Penilaian ini REKOMENDASI — pembelian tetap urusan modul Pengadaan.">
          {!replacement ? (
            <EmptyState title="Belum ada rekomendasi" description="Sistem menilai aset berkala berdasarkan umur, biaya pemeliharaan, dan waktu henti." />
          ) : (
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <Badge tone={replacementRecommendation.tone(replacement.recommendation)}>
                  {replacementRecommendation.label(replacement.recommendation)}
                </Badge>
                {replacement.priority && <Badge tone="amber">{replacement.priority}</Badge>}
              </div>
              <div className="grid grid-cols-2 gap-x-4 lg:grid-cols-3">
                <DetailRow label="Rasio umur">{replacement.age_ratio ?? '-'}</DetailRow>
                <DetailRow label="Rasio biaya perbaikan">{replacement.maintenance_cost_ratio ?? '-'}</DetailRow>
                <DetailRow label="Hari henti">{replacement.downtime_days ?? '-'}</DetailRow>
              </div>
              {(replacement.trigger_reasons ?? []).length > 0 && (
                <ul className="list-inside list-disc text-xs text-slate-600">
                  {(replacement.trigger_reasons ?? []).map((reason) => (
                    <li key={reason}>{reason}</li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </SectionCard>
      )}

      {tab === 'riwayat' && (
        <SectionCard title="Jejak audit" description="Catatan ini tidak dapat diubah atau dihapus — termasuk dari sisi aplikasi.">
          {(timeline ?? []).length === 0 ? (
            <EmptyState title="Belum ada kejadian" />
          ) : (
            <ol className="relative space-y-4 border-l border-slate-200 pl-4">
              {(timeline ?? []).map((entry, index) => (
                <li key={`${entry.at}-${index}`} className="relative">
                  <span className="absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-brand-500" />
                  <p className="text-sm text-slate-800">{entry.label ?? entry.action}</p>
                  <p className="text-[11px] text-slate-500">
                    {fmtDateTime(entry.at)}
                    {entry.actor && <> · {entry.actor}</>}
                  </p>
                  {entry.reason && <p className="mt-0.5 text-xs text-slate-600">“{entry.reason}”</p>}
                </li>
              ))}
            </ol>
          )}
        </SectionCard>
      )}

      {modal === 'status' && <StatusModal asset={asset} onClose={() => setModal(null)} />}
      {modal === 'place' && <PlaceModal asset={asset} onClose={() => setModal(null)} />}
      {modal === 'loan' && <LoanModal asset={asset} onClose={() => setModal(null)} />}
      {modal === 'return' && <ReturnModal asset={asset} onClose={() => setModal(null)} />}
      {modal === 'transfer' && <TransferModal asset={asset} onClose={() => setModal(null)} />}
    </div>
  );
}
