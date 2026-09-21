import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Info, Pencil, Plus, RefreshCw, Trash2, Wallet } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbFeesApi } from '../../api/ppdbApi';
import { getValidationErrors } from '../../api/http';
import { usePpdbPeriodOptions } from '../../hooks/usePpdbPeriodOptions';
import { usePpdbPeriodFees } from '../../hooks/usePpdbScopeOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage } from '@/lib/axios';
import { formatDate, formatRupiah } from '@/lib/utils';
import {
  FEE_TYPE_LABELS,
  FEE_TYPE_ORDER,
  FEE_TYPE_TONES,
  PpdbActiveBadge,
  PpdbErrorCard,
  PpdbField,
  PpdbFilterCard,
  PpdbLoadingCard,
  PpdbPageHeader,
  PpdbRequiredBadge,
  describeError,
  toDateInput,
} from '../../components/config/PpdbConfigUi';
import { PpdbNoPeriodState, PpdbPeriodPicker } from '../../components/config/PpdbPeriodPicker';
import {
  PpdbScopeBadge,
  PpdbScopeControls,
  PpdbScopeFilters,
} from '../../components/config/PpdbScopeSelectors';
import type { PpdbFeePayload, PpdbFeeType, PpdbRegistrationFee } from '@/types/ppdb';

const PER_PAGE = 10;

interface FeeForm {
  period_id: string;
  program_id: string;
  wave_id: string;
  type: PpdbFeeType;
  name: string;
  amount: string;
  payment_deadline: string;
  required: boolean;
  active: boolean;
}

type FormErrors = Partial<Record<keyof FeeForm, string>>;

function feeToForm(fee: PpdbRegistrationFee | null, fallbackPeriodId: number | ''): FeeForm {
  return {
    period_id: String(fee?.period_id ?? fallbackPeriodId ?? ''),
    program_id: fee?.program_id == null ? '' : String(fee.program_id),
    wave_id: fee?.wave_id == null ? '' : String(fee.wave_id),
    type: fee?.type ?? 'registration',
    name: fee?.name ?? '',
    amount: fee == null ? '' : String(fee.amount),
    payment_deadline: toDateInput(fee?.payment_deadline),
    required: fee?.required ?? true,
    active: fee?.active ?? true,
  };
}

function validate(form: FeeForm): FormErrors {
  const errors: FormErrors = {};

  if (form.period_id === '') errors.period_id = 'Periode wajib dipilih.';
  if (!form.name.trim()) errors.name = 'Nama biaya wajib diisi.';
  else if (form.name.length > 150) errors.name = 'Nama maksimal 150 karakter.';

  const amount = Number(String(form.amount).replace(/[^0-9.-]/g, ''));
  if (form.amount.trim() === '') errors.amount = 'Jumlah wajib diisi.';
  else if (!Number.isFinite(amount) || amount < 0) errors.amount = 'Jumlah harus angka tidak negatif.';
  else if (amount > 0 && !Number.isInteger(amount)) errors.amount = 'Jumlah dalam rupiah penuh, tanpa desimal.';

  if (form.payment_deadline && Number.isNaN(Date.parse(form.payment_deadline)))
    errors.payment_deadline = 'Tanggal tenggat tidak sah.';

  return errors;
}

/**
 * Lapisan biaya yang berlaku untuk satu cakupan.
 *
 * Backend menyimpan biaya per (periode, program, gelombang) dengan `null` =
 * semua, dan tagihan memakai set paling spesifik yang ada. Tanpa aturan ini
 * pendaftar akan ditagih dua kali (biaya periode + biaya gelombang).
 */
type FeeLayer = 'wave' | 'program' | 'period';

const LAYER_LABELS: Record<FeeLayer, string> = {
  wave: 'biaya khusus gelombang',
  program: 'biaya khusus program',
  period: 'biaya umum periode',
};

function resolveApplicableFees(
  fees: PpdbRegistrationFee[],
  programId: number | '',
  waveId: number | '',
): { rows: PpdbRegistrationFee[]; layer: FeeLayer } {
  const enabled = fees.filter((fee) => fee.active);

  if (waveId !== '') {
    const waveFees = enabled.filter((fee) => fee.wave_id === waveId);
    if (waveFees.length > 0) return { rows: waveFees, layer: 'wave' };
  }

  if (programId !== '') {
    const programFees = enabled.filter(
      (fee) => fee.program_id === programId && fee.wave_id === null,
    );
    if (programFees.length > 0) return { rows: programFees, layer: 'program' };
  }

  return {
    rows: enabled.filter((fee) => fee.program_id === null && fee.wave_id === null),
    layer: 'period',
  };
}

/* ============================ FORM MODAL ============================ */

function FeeFormModal({
  open,
  editing,
  periodId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: PpdbRegistrationFee | null;
  periodId: number | '';
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PpdbFeePayload) => void;
}) {
  const { options: periodOptions } = usePpdbPeriodOptions();
  const [form, setForm] = useState<FeeForm>(() => feeToForm(editing, periodId));
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  useEffect(() => {
    if (!open) return;
    setForm(feeToForm(editing, periodId));
    setLocalErrors({});
  }, [open, editing, periodId]);

  const set = <K extends keyof FeeForm>(key: K, value: FeeForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const errorOf = (key: keyof FeeForm) => localErrors[key] ?? serverErrors[key]?.[0];

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    const errors = validate(form);
    setLocalErrors(errors);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali isian biaya.');
      return;
    }

    const scope: { program_id: number | ''; wave_id: number | '' } = {
      program_id: form.program_id === '' ? '' : Number(form.program_id),
      wave_id: form.wave_id === '' ? '' : Number(form.wave_id),
    };

    onSubmit({
      period_id: Number(form.period_id),
      ...scope,
      type: form.type,
      name: form.name.trim(),
      amount: Number(String(form.amount).replace(/[^0-9]/g, '')),
      payment_deadline: form.payment_deadline,
      required: form.required,
      active: form.active,
    });
  };

  const amountNumber = Number(String(form.amount).replace(/[^0-9]/g, ''));

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Biaya Pendaftaran' : 'Tambah Biaya Pendaftaran'}
      size="lg"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="ppdb-fee-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="ppdb-fee-form" onSubmit={handleSubmit} className="space-y-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbField label="Periode" required error={errorOf('period_id')}>
            <Select
              value={form.period_id}
              error={errorOf('period_id')}
              onChange={(e) => set('period_id', e.target.value)}
            >
              <option value="">-- Pilih periode --</option>
              {periodOptions.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </Select>
          </PpdbField>
          <PpdbField label="Jenis Biaya" required error={errorOf('type')}>
            <Select
              value={form.type}
              error={errorOf('type')}
              onChange={(e) => set('type', e.target.value as PpdbFeeType)}
            >
              {FEE_TYPE_ORDER.map((type) => (
                <option key={type} value={type}>
                  {FEE_TYPE_LABELS[type]}
                </option>
              ))}
            </Select>
          </PpdbField>
        </div>

        <PpdbField label="Nama Biaya" required error={errorOf('name')}>
          <Input
            value={form.name}
            placeholder="Biaya formulir & seleksi"
            error={errorOf('name')}
            onChange={(e) => set('name', e.target.value)}
          />
        </PpdbField>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbField
            label="Jumlah (Rp)"
            required
            error={errorOf('amount')}
            hint={Number.isFinite(amountNumber) && amountNumber > 0 ? formatRupiah(amountNumber) : 'Nol = gratis.'}
          >
            <Input
              type="number"
              min={0}
              step={100}
              value={form.amount}
              error={errorOf('amount')}
              onChange={(e) => set('amount', e.target.value)}
            />
          </PpdbField>
          <PpdbField
            label="Tenggat Pembayaran"
            error={errorOf('payment_deadline')}
            hint="Kosong = mengikuti akhir pendaftaran periode."
          >
            <Input
              type="date"
              value={form.payment_deadline}
              error={errorOf('payment_deadline')}
              onChange={(e) => set('payment_deadline', e.target.value)}
            />
          </PpdbField>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbScopeControls
            periodId={form.period_id === '' ? '' : Number(form.period_id)}
            programId={form.program_id === '' ? '' : Number(form.program_id)}
            waveId={form.wave_id === '' ? '' : Number(form.wave_id)}
            programError={errorOf('program_id')}
            waveError={errorOf('wave_id')}
            onProgramChange={(value) => set('program_id', String(value))}
            onWaveChange={(value) => set('wave_id', String(value))}
          />
        </div>

        <div className="flex gap-6">
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={form.required}
              onChange={(e) => set('required', e.target.checked)}
            />
            Wajib dibayar
          </label>
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={form.active}
              onChange={(e) => set('active', e.target.checked)}
            />
            Aktif
          </label>
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

export function PpdbFeesPage() {
  const queryClient = useQueryClient();
  const { options: periodOptions, firstPeriodId, isInitialLoading } = usePpdbPeriodOptions();
  const [picked, setPicked] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [programFilter, setProgramFilter] = useState<number | ''>('');
  const [waveFilter, setWaveFilter] = useState<number | ''>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PpdbRegistrationFee | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<PpdbRegistrationFee | null>(null);

  const periodId = picked === '' ? firstPeriodId : picked;

  const listQuery = useQuery({
    queryKey: ['ppdb', 'fees', 'list', periodId, page, search, programFilter, waveFilter],
    enabled: periodId !== '',
    queryFn: () =>
      ppdbFeesApi.list({
        period_id: periodId,
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        program_id: programFilter === '' ? undefined : programFilter,
        wave_id: waveFilter === '' ? undefined : waveFilter,
      }),
  });

  // Total dihitung dari seluruh baris periode, bukan hanya halaman aktif.
  const allFees = usePpdbPeriodFees(periodId);
  const applicable = useMemo(
    () => resolveApplicableFees(allFees.rows, programFilter, waveFilter),
    [allFees.rows, programFilter, waveFilter],
  );
  const applicableTotal = applicable.rows
    .filter((fee) => fee.required)
    .reduce((sum, fee) => sum + Number(fee.amount ?? 0), 0);

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'fees'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'periods'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PpdbFeePayload) =>
      editing ? ppdbFeesApi.update(editing.id, payload) : ppdbFeesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Biaya diperbarui' : 'Biaya dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (error) => {
      setServerErrors(getValidationErrors(error) ?? {});
      toast.error(getErrorMessage(error));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => ppdbFeesApi.remove(id),
    onSuccess: () => {
      toast.success('Biaya dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (error) => {
      toast.error(describeError(error));
      setDeleting(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setServerErrors({});
    setFormOpen(true);
  };

  const openEdit = (fee: PpdbRegistrationFee) => {
    setEditing(fee);
    setServerErrors({});
    setFormOpen(true);
  };

  if (!isInitialLoading && periodOptions.length === 0) {
    return (
      <div className="space-y-4">
        <PpdbPageHeader
          title="Biaya Pendaftaran"
          description="Rincian biaya dihitung per periode. Buat periode lebih dulu."
        />
        <PpdbNoPeriodState />
      </div>
    );
  }

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <PpdbPageHeader
        title="Biaya Pendaftaran"
        description="Satu periode dapat punya biaya umum plus variasi per program atau per gelombang."
        actions={
          <>
            <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="ppdb.fee.manage">
              <Button onClick={openCreate} disabled={periodId === ''}>
                <Plus className="h-4 w-4" /> Tambah Biaya
              </Button>
            </Can>
          </>
        }
      />

      <PpdbFilterCard>
        <PpdbPeriodPicker
          value={periodId}
          options={periodOptions}
          isLoading={isInitialLoading}
          onChange={(value) => {
            setPicked(value);
            setPage(1);
          }}
        />
        <PpdbField label="Cari">
          <Input
            value={search}
            placeholder="Cari nama biaya..."
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </PpdbField>
        <PpdbScopeFilters
          periodId={periodId}
          programId={programFilter}
          waveId={waveFilter}
          onProgramChange={(value) => {
            setProgramFilter(value);
            setPage(1);
          }}
          onWaveChange={(value) => {
            setWaveFilter(value);
            setPage(1);
          }}
        />
      </PpdbFilterCard>

      <Card>
        <CardHeader className="flex items-center justify-between">
          <CardTitle className="flex items-center gap-2 text-sm">
            <Wallet className="h-4 w-4 text-brand-600" />
            Estimasi tagihan untuk cakupan terpilih
          </CardTitle>
          <Badge tone="gray">{applicable.rows.length} komponen</Badge>
        </CardHeader>
        <CardBody className="space-y-1">
          <p className="text-2xl font-semibold text-slate-900">{formatRupiah(applicableTotal)}</p>
          <p className="text-xs text-slate-500">
            Sumber: {LAYER_LABELS[applicable.layer]} — hanya biaya aktif yang wajib dibayar.
          </p>
          <p className="flex items-start gap-1.5 pt-1 text-xs text-slate-400">
            <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Nomor tagihan dan kode pembayaran dibuat sistem saat invoice terbit, bukan dari isian di halaman
            ini.
          </p>
        </CardBody>
      </Card>

      {listQuery.isLoading ? (
        <PpdbLoadingCard label="Memuat biaya pendaftaran..." />
      ) : listQuery.isError ? (
        <PpdbErrorCard error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada biaya"
            description="Tanpa biaya, pendaftar tetap dapat mengirim formulir namun tidak menerima tagihan PPDB."
            action={
              <Can perm="ppdb.fee.manage">
                <Button size="sm" className="mt-2" onClick={openCreate} disabled={periodId === ''}>
                  <Plus className="h-4 w-4" /> Tambah Biaya
                </Button>
              </Can>
            }
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Nama Biaya</th>
                  <th className="px-4 py-2 text-right font-medium">Jumlah</th>
                  <th className="px-4 py-2 font-medium">Tenggat</th>
                  <th className="px-4 py-2 font-medium">Cakupan</th>
                  <th className="px-4 py-2 font-medium">Sifat</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((fee) => (
                  <tr key={fee.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2">
                      <Badge tone={FEE_TYPE_TONES[fee.type] ?? 'gray'}>{FEE_TYPE_LABELS[fee.type] ?? fee.type}</Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">{fee.name}</div>
                    </td>
                    <td className="px-4 py-2 text-right font-medium text-slate-800">
                      {formatRupiah(fee.amount)}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {fee.payment_deadline ? formatDate(fee.payment_deadline) : '—'}
                    </td>
                    <td className="px-4 py-2">
                      <PpdbScopeBadge
                        programId={fee.program_id}
                        programName={fee.program?.name}
                        waveId={fee.wave_id}
                        waveName={fee.wave?.name}
                      />
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <PpdbRequiredBadge required={fee.required} />
                        <PpdbActiveBadge active={fee.active} />
                      </div>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-1">
                        <Can perm="ppdb.fee.manage">
                          <Button variant="ghost" size="icon" title="Edit" onClick={() => openEdit(fee)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Hapus" onClick={() => setDeleting(fee)}>
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <FeeFormModal
        open={formOpen}
        editing={editing}
        periodId={periodId}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Biaya Pendaftaran"
        message={`Hapus "${deleting?.name ?? ''}"? Biaya yang sudah masuk tagihan pendaftar biasanya ditolak backend.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
