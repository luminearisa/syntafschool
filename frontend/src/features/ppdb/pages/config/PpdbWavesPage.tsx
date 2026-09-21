import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbWavesApi } from '../../api/ppdbApi';
import { getValidationErrors } from '../../api/http';
import { usePpdbPeriodFees } from '../../hooks/usePpdbScopeOptions';
import { usePpdbPeriodOptions } from '../../hooks/usePpdbPeriodOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage } from '@/lib/axios';
import { formatRupiah } from '@/lib/utils';
import {
  PpdbErrorCard,
  PpdbField,
  PpdbFilterCard,
  PpdbLoadingCard,
  PpdbPageHeader,
  PpdbQuotaCell,
  PpdbSimpleStatusBadge,
  SIMPLE_STATUS_LABELS,
  describeError,
  formatRange,
  toDateInput,
  toNumOrEmpty,
  toOptions,
} from '../../components/config/PpdbConfigUi';
import { PpdbNoPeriodState, PpdbPeriodPicker } from '../../components/config/PpdbPeriodPicker';
import type { PpdbSimpleStatus, PpdbWave, PpdbWavePayload } from '@/types/ppdb';

const PER_PAGE = 12;

interface WaveForm {
  period_id: string;
  code: string;
  name: string;
  registration_start: string;
  registration_end: string;
  quota: string;
  priority: string;
  status: PpdbSimpleStatus;
  description: string;
}

type FormErrors = Partial<Record<keyof WaveForm, string>>;

function waveToForm(wave: PpdbWave | null, fallbackPeriodId: number | ''): WaveForm {
  return {
    period_id: String(wave?.period_id ?? fallbackPeriodId ?? ''),
    code: wave?.code ?? '',
    name: wave?.name ?? '',
    registration_start: toDateInput(wave?.registration_start),
    registration_end: toDateInput(wave?.registration_end),
    quota: wave?.quota === null || wave?.quota === undefined ? '' : String(wave.quota),
    priority: wave ? String(wave.priority) : '0',
    status: wave?.status ?? 'active',
    description: wave?.description ?? '',
  };
}

function validate(form: WaveForm): FormErrors {
  const errors: FormErrors = {};

  if (form.period_id === '') errors.period_id = 'Periode wajib dipilih.';
  if (!form.code.trim()) errors.code = 'Kode gelombang wajib diisi.';
  else if (form.code.length > 30) errors.code = 'Kode maksimal 30 karakter.';
  if (!form.name.trim()) errors.name = 'Nama gelombang wajib diisi.';

  if (form.quota !== '' && (Number(form.quota) < 0 || !Number.isInteger(Number(form.quota))))
    errors.quota = 'Kuota harus bilangan bulat tidak negatif.';

  if (form.priority !== '' && Number(form.priority) < 0)
    errors.priority = 'Prioritas tidak boleh negatif.';

  if (
    form.registration_start &&
    form.registration_end &&
    form.registration_end < form.registration_start
  )
    errors.registration_end = 'Akhir gelombang tidak boleh mendahului awal gelombang.';

  return errors;
}

/* ============================ FORM MODAL ============================ */

function WaveFormModal({
  open,
  editing,
  periodId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: PpdbWave | null;
  periodId: number | '';
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PpdbWavePayload) => void;
}) {
  const { options: periodOptions } = usePpdbPeriodOptions();
  const [form, setForm] = useState<WaveForm>(() => waveToForm(editing, periodId));
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  useEffect(() => {
    if (!open) return;
    setForm(waveToForm(editing, periodId));
    setLocalErrors({});
  }, [open, editing, periodId]);

  const set = <K extends keyof WaveForm>(key: K, value: WaveForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const errorOf = (key: keyof WaveForm) => localErrors[key] ?? serverErrors[key]?.[0];

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    const errors = validate(form);
    setLocalErrors(errors);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali isian gelombang.');
      return;
    }

    onSubmit({
      period_id: Number(form.period_id),
      code: form.code.trim().toUpperCase(),
      name: form.name.trim(),
      registration_start: form.registration_start,
      registration_end: form.registration_end,
      quota: toNumOrEmpty(form.quota),
      priority: toNumOrEmpty(form.priority),
      status: form.status,
      description: form.description.trim(),
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Gelombang' : 'Tambah Gelombang'}
      size="lg"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="ppdb-wave-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="ppdb-wave-form" onSubmit={handleSubmit} className="space-y-3">
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
          <PpdbField label="Status" error={errorOf('status')}>
            <Select
              value={form.status}
              error={errorOf('status')}
              onChange={(e) => set('status', e.target.value as PpdbSimpleStatus)}
            >
              {toOptions(SIMPLE_STATUS_LABELS).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </PpdbField>
          <PpdbField label="Kode" required error={errorOf('code')}>
            <Input
              value={form.code}
              placeholder="GELOMBANG-1"
              error={errorOf('code')}
              onChange={(e) => set('code', e.target.value.toUpperCase())}
            />
          </PpdbField>
          <PpdbField label="Nama" required error={errorOf('name')}>
            <Input
              value={form.name}
              placeholder="Gelombang 1"
              error={errorOf('name')}
              onChange={(e) => set('name', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Awal Pendaftaran" error={errorOf('registration_start')}>
            <Input
              type="date"
              value={form.registration_start}
              error={errorOf('registration_start')}
              onChange={(e) => set('registration_start', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Akhir Pendaftaran" error={errorOf('registration_end')}>
            <Input
              type="date"
              value={form.registration_end}
              error={errorOf('registration_end')}
              onChange={(e) => set('registration_end', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Kuota" error={errorOf('quota')} hint="Kosong = tanpa batas.">
            <Input
              type="number"
              min={0}
              value={form.quota}
              error={errorOf('quota')}
              onChange={(e) => set('quota', e.target.value)}
            />
          </PpdbField>
          <PpdbField
            label="Prioritas"
            error={errorOf('priority')}
            hint="Angka kecil diproses lebih dulu saat seleksi."
          >
            <Input
              type="number"
              min={0}
              value={form.priority}
              error={errorOf('priority')}
              onChange={(e) => set('priority', e.target.value)}
            />
          </PpdbField>
        </div>

        <PpdbField label="Deskripsi" error={errorOf('description')}>
          <Textarea
            rows={2}
            value={form.description}
            error={errorOf('description')}
            onChange={(e) => set('description', e.target.value)}
          />
        </PpdbField>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

export function PpdbWavesPage() {
  const queryClient = useQueryClient();
  const { options: periodOptions, firstPeriodId, isInitialLoading } = usePpdbPeriodOptions();
  const [picked, setPicked] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'' | PpdbSimpleStatus>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PpdbWave | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<PpdbWave | null>(null);

  const periodId = picked === '' ? firstPeriodId : picked;

  const listQuery = useQuery({
    queryKey: ['ppdb', 'waves', 'list', periodId, page, search, statusFilter],
    enabled: periodId !== '',
    queryFn: () =>
      ppdbWavesApi.list({
        period_id: periodId,
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        status: statusFilter || undefined,
      }),
  });

  const fees = usePpdbPeriodFees(periodId);

  const feeOverrideByWave = useMemo(() => {
    const map = new Map<number, { total: number; count: number }>();
    for (const fee of fees.rows) {
      if (fee.wave_id === null) continue;
      const current = map.get(fee.wave_id) ?? { total: 0, count: 0 };
      map.set(fee.wave_id, { total: current.total + Number(fee.amount ?? 0), count: current.count + 1 });
    }
    return map;
  }, [fees.rows]);

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'waves'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'periods'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PpdbWavePayload) =>
      editing ? ppdbWavesApi.update(editing.id, payload) : ppdbWavesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Gelombang diperbarui' : 'Gelombang dibuat');
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
    mutationFn: (id: number) => ppdbWavesApi.remove(id),
    onSuccess: () => {
      toast.success('Gelombang dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (error) => {
      toast.error(describeError(error));
      setDeleting(null);
    },
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const openCreate = () => {
    setEditing(null);
    setServerErrors({});
    setFormOpen(true);
  };

  const openEdit = (wave: PpdbWave) => {
    setEditing(wave);
    setServerErrors({});
    setFormOpen(true);
  };

  if (!isInitialLoading && periodOptions.length === 0) {
    return (
      <div className="space-y-4">
        <PpdbPageHeader
          title="Gelombang PPDB"
          description="Gelombang mengikuti periode. Buat periode lebih dulu agar gelombang dapat ditambahkan."
        />
        <PpdbNoPeriodState />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <PpdbPageHeader
        title="Gelombang PPDB"
        description="Satu periode dapat punya beberapa gelombang dengan jendela tanggal dan kuota sendiri."
        actions={
          <>
            <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="ppdb.wave.manage">
              <Button onClick={openCreate} disabled={periodId === ''}>
                <Plus className="h-4 w-4" /> Tambah Gelombang
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
            placeholder="Cari kode atau nama gelombang..."
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </PpdbField>
        <PpdbField label="Status">
          <Select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value as '' | PpdbSimpleStatus);
              setPage(1);
            }}
          >
            <option value="">Semua status</option>
            {toOptions(SIMPLE_STATUS_LABELS).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </PpdbField>
      </PpdbFilterCard>

      {listQuery.isLoading ? (
        <PpdbLoadingCard label="Memuat gelombang..." />
      ) : listQuery.isError ? (
        <PpdbErrorCard error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada gelombang"
            description="Pendaftar harus memilih satu gelombang; tambahkan minimal satu gelombang aktif."
            action={
              <Can perm="ppdb.wave.manage">
                <Button size="sm" className="mt-2" onClick={openCreate} disabled={periodId === ''}>
                  <Plus className="h-4 w-4" /> Tambah Gelombang
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
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Gelombang</th>
                  <th className="px-4 py-2 font-medium">Kuota</th>
                  <th className="px-4 py-2 font-medium">Prioritas</th>
                  <th className="px-4 py-2 font-medium">Jendela Pendaftaran</th>
                  <th className="px-4 py-2 font-medium">Override Biaya</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((wave) => {
                  const override = feeOverrideByWave.get(wave.id);
                  return (
                    <tr key={wave.id} className="border-b border-slate-50 align-top last:border-0">
                      <td className="px-4 py-2 font-mono text-xs text-slate-600">{wave.code}</td>
                      <td className="px-4 py-2">
                        <div className="font-medium text-slate-800">{wave.name}</div>
                        {wave.description && (
                          <div className="mt-0.5 text-xs text-slate-400">{wave.description}</div>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        <PpdbQuotaCell quota={wave.quota} used={wave.registrations_count} />
                      </td>
                      <td className="px-4 py-2 text-slate-700">{wave.priority}</td>
                      <td className="px-4 py-2 text-xs text-slate-600">
                        {formatRange(wave.registration_start, wave.registration_end)}
                      </td>
                      <td className="px-4 py-2 text-xs">
                        {override ? (
                          <div>
                            <span className="font-medium text-slate-800">{formatRupiah(override.total)}</span>
                            <div className="text-slate-400">{override.count} biaya khusus</div>
                          </div>
                        ) : (
                          <Link to="/ppdb/fees" className="text-slate-400 hover:text-brand-600">
                            Ikut biaya periode
                          </Link>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        <PpdbSimpleStatusBadge status={wave.status} />
                        {(wave.registrations_count ?? 0) > 0 && (
                          <div className="mt-1">
                            <Badge tone="blue">{wave.registrations_count} pendaftar</Badge>
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex items-center gap-1">
                          <Can perm="ppdb.wave.manage">
                            <Button variant="ghost" size="icon" title="Edit" onClick={() => openEdit(wave)}>
                              <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Hapus"
                              onClick={() => setDeleting(wave)}
                            >
                              <Trash2 className="h-3.5 w-3.5 text-red-500" />
                            </Button>
                          </Can>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <WaveFormModal
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
        title="Hapus Gelombang"
        message={`Hapus "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Gelombang yang sudah dipakai pendaftar biasanya ditolak backend.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
