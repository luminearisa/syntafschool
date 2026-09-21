import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, Link2, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Link } from 'react-router-dom';
import { ppdbProgramsApi } from '../../api/ppdbApi';
import { getValidationErrors } from '../../api/http';
import { usePpdbMasterProgramOptions } from '../../hooks/usePpdbScopeOptions';
import { usePpdbPeriodOptions } from '../../hooks/usePpdbPeriodOptions';
import { usePpdbReferenceOptions } from '../../hooks/usePpdbReferenceOptions';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage } from '@/lib/axios';
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
  toNumOrEmpty,
  toOptions,
} from '../../components/config/PpdbConfigUi';
import { PpdbNoPeriodState, PpdbPeriodPicker } from '../../components/config/PpdbPeriodPicker';
import type { PpdbProgram, PpdbProgramPayload, PpdbSimpleStatus } from '@/types/ppdb';

const PER_PAGE = 12;

interface ProgramForm {
  period_id: string;
  program_id: string;
  education_level_id: string;
  code: string;
  name: string;
  quota: string;
  description: string;
  status: PpdbSimpleStatus;
  sort_order: string;
}

type FormErrors = Partial<Record<keyof ProgramForm, string>>;

function programToForm(program: PpdbProgram | null, fallbackPeriodId: number | ''): ProgramForm {
  return {
    period_id: String(program?.period_id ?? fallbackPeriodId ?? ''),
    program_id: program?.program_id === null || program?.program_id === undefined ? '' : String(program.program_id),
    education_level_id:
      program?.education_level_id === null || program?.education_level_id === undefined
        ? ''
        : String(program.education_level_id),
    code: program?.code ?? '',
    name: program?.name ?? '',
    quota: program?.quota === null || program?.quota === undefined ? '' : String(program.quota),
    description: program?.description ?? '',
    status: program?.status ?? 'active',
    sort_order: program ? String(program.sort_order) : '0',
  };
}

function validate(form: ProgramForm): FormErrors {
  const errors: FormErrors = {};

  if (form.period_id === '') errors.period_id = 'Periode wajib dipilih.';
  if (!form.code.trim()) errors.code = 'Kode program wajib diisi.';
  else if (form.code.length > 30) errors.code = 'Kode maksimal 30 karakter.';
  if (!form.name.trim()) errors.name = 'Nama penawaran wajib diisi.';

  if (form.quota !== '' && (Number(form.quota) < 0 || !Number.isInteger(Number(form.quota))))
    errors.quota = 'Kuota harus bilangan bulat tidak negatif.';
  if (form.sort_order !== '' && Number(form.sort_order) < 0)
    errors.sort_order = 'Urutan tampil tidak boleh negatif.';

  return errors;
}

/* ============================ FORM MODAL ============================ */

function ProgramFormModal({
  open,
  editing,
  periodId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: PpdbProgram | null;
  periodId: number | '';
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PpdbProgramPayload) => void;
}) {
  const { options: periodOptions } = usePpdbPeriodOptions();
  const { options: educationLevels } = usePpdbReferenceOptions('education_levels');
  const masters = usePpdbMasterProgramOptions();
  const [form, setForm] = useState<ProgramForm>(() => programToForm(editing, periodId));
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  useEffect(() => {
    if (!open) return;
    setForm(programToForm(editing, periodId));
    setLocalErrors({});
  }, [open, editing, periodId]);

  const set = <K extends keyof ProgramForm>(key: K, value: ProgramForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const errorOf = (key: keyof ProgramForm) => localErrors[key] ?? serverErrors[key]?.[0];

  const masterOptions = useMemo(() => {
    // Tawaran lama tetap bisa ditampilkan walau master-nya sudah dihapus.
    if (editing?.program_id && !masters.options.some((o) => String(o.value) === String(editing.program_id))) {
      return [
        {
          value: editing.program_id,
          label: editing.program?.name ? `${editing.program.name} (master tidak aktif)` : 'Master terhapus',
        },
        ...masters.options,
      ];
    }
    return masters.options;
  }, [editing, masters.options]);

  const linkMaster = (value: string | number | '') => {
    const next = value === '' ? '' : String(value);
    set('program_id', next);
    const master = masters.rows.find((row) => String(row.id) === next);
    if (!master) return;
    // Hanya mengisi kolom yang masih kosong agar nama khusus PPDB tidak tertimpa.
    setForm((prev) => ({
      ...prev,
      program_id: next,
      code: prev.code || master.code || '',
      name: prev.name || master.name || '',
      education_level_id: prev.education_level_id || (master.education_level_id ? String(master.education_level_id) : ''),
      description: prev.description || master.description || '',
    }));
  };

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    const errors = validate(form);
    setLocalErrors(errors);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali isian program.');
      return;
    }

    onSubmit({
      period_id: Number(form.period_id),
      program_id: form.program_id === '' ? '' : Number(form.program_id),
      education_level_id: form.education_level_id === '' ? '' : Number(form.education_level_id),
      code: form.code.trim().toUpperCase(),
      name: form.name.trim(),
      quota: toNumOrEmpty(form.quota),
      description: form.description.trim(),
      status: form.status,
      sort_order: toNumOrEmpty(form.sort_order),
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Program PPDB' : 'Tambah Program PPDB'}
      size="lg"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="ppdb-program-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="ppdb-program-form" onSubmit={handleSubmit} className="space-y-3">
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Definisi program (peminatan) milik master Kurikulum. Halaman ini hanya membuka program itu
          sebagai penawaran pada satu periode: kuota, urutan tampil, dan statusnya milik PPDB.
        </p>

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

          <PpdbField
            label="Master Program (Kurikulum)"
            className="sm:col-span-2"
            error={errorOf('program_id')}
            hint="Opsional. Biarkan kosong bila penawaran PPDB tidak sama dengan master akademik."
          >
            <div className="flex items-start gap-2">
              <SearchSelect
                className="flex-1"
                options={masterOptions}
                value={form.program_id}
                placeholder="-- Tidak ditautkan --"
                onChange={(value) => linkMaster(value)}
              />
              <Link
                to="/academics/curriculum/programs"
                className="mt-0.5 inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
              >
                <ExternalLink className="h-3.5 w-3.5" /> Kelola master
              </Link>
            </div>
          </PpdbField>

          <PpdbField label="Tingkat / Jenjang" error={errorOf('education_level_id')}>
            <Select
              value={form.education_level_id}
              error={errorOf('education_level_id')}
              onChange={(e) => set('education_level_id', e.target.value)}
            >
              <option value="">-- Semua tingkat --</option>
              {educationLevels.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </Select>
          </PpdbField>
          <PpdbField label="Kode Penawaran" required error={errorOf('code')}>
            <Input
              value={form.code}
              placeholder="SMP-BOARDING"
              error={errorOf('code')}
              onChange={(e) => set('code', e.target.value.toUpperCase())}
            />
          </PpdbField>
          <PpdbField label="Nama Penawaran" required error={errorOf('name')} className="sm:col-span-2">
            <Input
              value={form.name}
              placeholder="SMP Boarding Puteri"
              error={errorOf('name')}
              onChange={(e) => set('name', e.target.value)}
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
          <PpdbField label="Urutan Tampil" error={errorOf('sort_order')} hint="Dipakai di halaman publik.">
            <Input
              type="number"
              min={0}
              value={form.sort_order}
              error={errorOf('sort_order')}
              onChange={(e) => set('sort_order', e.target.value)}
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

export function PpdbProgramsPage() {
  const queryClient = useQueryClient();
  const { options: periodOptions, firstPeriodId, isInitialLoading } = usePpdbPeriodOptions();
  const [picked, setPicked] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'' | PpdbSimpleStatus>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PpdbProgram | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<PpdbProgram | null>(null);

  const periodId = picked === '' ? firstPeriodId : picked;

  const listQuery = useQuery({
    queryKey: ['ppdb', 'programs', 'list', periodId, page, search, statusFilter],
    enabled: periodId !== '',
    queryFn: () =>
      ppdbProgramsApi.list({
        period_id: periodId,
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        status: statusFilter || undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'programs'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'periods'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PpdbProgramPayload) =>
      editing ? ppdbProgramsApi.update(editing.id, payload) : ppdbProgramsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Program diperbarui' : 'Program dibuat');
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
    mutationFn: (id: number) => ppdbProgramsApi.remove(id),
    onSuccess: () => {
      toast.success('Program dihapus');
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

  const openEdit = (program: PpdbProgram) => {
    setEditing(program);
    setServerErrors({});
    setFormOpen(true);
  };

  const linkedCount = rows.filter((row) => row.program_id !== null).length;

  if (!isInitialLoading && periodOptions.length === 0) {
    return (
      <div className="space-y-4">
        <PpdbPageHeader
          title="Program PPDB"
          description="Program dibuka lewat periode. Buat periode lebih dulu agar program dapat ditambahkan."
        />
        <PpdbNoPeriodState />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <PpdbPageHeader
        title="Program PPDB"
        description="Program yang dibuka pada satu periode, ditautkan (opsional) ke master program Kurikulum."
        actions={
          <>
            <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="ppdb.program.manage">
              <Button onClick={openCreate} disabled={periodId === ''}>
                <Plus className="h-4 w-4" /> Tambah Program
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
            placeholder="Cari kode atau nama program..."
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
        <div className="flex items-end">
          <p className="text-xs text-slate-500">
            {linkedCount} dari {rows.length} baris ditautkan ke master program.
          </p>
        </div>
      </PpdbFilterCard>

      {listQuery.isLoading ? (
        <PpdbLoadingCard label="Memuat program..." />
      ) : listQuery.isError ? (
        <PpdbErrorCard error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada program"
            description="Pendaftar memilih satu program pada saat mendaftar; tambahkan minimal satu program aktif."
            action={
              <Can perm="ppdb.program.manage">
                <Button size="sm" className="mt-2" onClick={openCreate} disabled={periodId === ''}>
                  <Plus className="h-4 w-4" /> Tambah Program
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
                  <th className="px-4 py-2 font-medium">Program</th>
                  <th className="px-4 py-2 font-medium">Master</th>
                  <th className="px-4 py-2 font-medium">Tingkat</th>
                  <th className="px-4 py-2 font-medium">Kuota</th>
                  <th className="px-4 py-2 font-medium">Urutan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((program) => (
                  <tr key={program.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{program.code}</td>
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">{program.name}</div>
                      {program.description && (
                        <div className="mt-0.5 text-xs text-slate-400">{program.description}</div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-xs">
                      {program.program_id === null ? (
                        <span className="text-slate-400">Tanpa master</span>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-slate-600">
                          <Link2 className="h-3.5 w-3.5 text-slate-400" />
                          {program.program?.name ?? `#${program.program_id}`}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {program.education_level?.name ?? <span className="text-slate-400">—</span>}
                    </td>
                    <td className="px-4 py-2">
                      <PpdbQuotaCell quota={program.quota} used={program.registrations_count} />
                    </td>
                    <td className="px-4 py-2 text-slate-700">{program.sort_order}</td>
                    <td className="px-4 py-2">
                      <PpdbSimpleStatusBadge status={program.status} />
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-1">
                        <Can perm="ppdb.program.manage">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Edit"
                            onClick={() => openEdit(program)}
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(program)}
                          >
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

      <ProgramFormModal
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
        title="Hapus Program PPDB"
        message={`Hapus "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Program yang sudah jadi pilihan pendaftar biasanya ditolak backend.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
