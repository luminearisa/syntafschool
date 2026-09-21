import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Info, Pencil, Plus, RefreshCw, Settings2, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import {
  instructorsApi,
  levelsApi,
  mistakeTypesApi,
  mutabaahItemsApi,
  programsApi,
  rubricsApi,
} from '../api/tahfizApi';
import { useProgramOptions } from '../hooks/useTahfizOptions';
import {
  DURATION_UNIT_LABELS,
  INSTRUCTOR_ROLE_LABELS,
  MISTAKE_CATEGORY_LABELS,
  MUTABAAH_CATEGORY_LABELS,
  MUTABAAH_VALUE_TYPE_LABELS,
  PARTICIPANT_TYPE_LABELS,
  PROGRAM_STATUS_LABELS,
  RUBRIC_SCOPE_LABELS,
  SPECIALIZATION_LABELS,
  TARGET_UNIT_LABELS,
  levelJuzRange,
  toOptions,
} from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { cn } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { PaginationMeta } from '@/types';
import type {
  TahfizDurationUnit,
  TahfizInstructor,
  TahfizInstructorPayload,
  TahfizInstructorRole,
  TahfizLevel,
  TahfizLevelPayload,
  TahfizMistakeCategory,
  TahfizMistakeType,
  TahfizMistakeTypePayload,
  TahfizMutabaahCategory,
  TahfizMutabaahItem,
  TahfizMutabaahItemPayload,
  TahfizMutabaahValueType,
  TahfizParticipantType,
  TahfizProgram,
  TahfizProgramPayload,
  TahfizProgramStatus,
  TahfizRubric,
  TahfizRubricPayload,
  TahfizRubricScope,
  TahfizSpecialization,
  TahfizTargetUnit,
} from '@/types/tahfiz';

/* ============================ SHARED HELPERS ============================ */

type ServerErrors = Record<string, string[]>;

const PER_PAGE = 15;

const toId = (value: string | number | ''): number | '' =>
  value === '' ? '' : Number(value);

const fieldError = (
  errors: Record<string, string>,
  serverErrors: ServerErrors | undefined,
  key: string,
): string | undefined => errors[key] || serverErrors?.[key]?.[0];

/** Hook kecil untuk state form + error lokal (dipakai semua modal di berkas ini). */
function useLocalForm<T extends object>(empty: T) {
  const [values, setValues] = useState<T>(empty);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const set = <K extends keyof T>(key: K, value: T[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const copy = { ...prev };
      delete copy[key as string];
      return copy;
    });
  };

  const reset = (next: T) => {
    setValues(next);
    setErrors({});
  };

  return { values, errors, setErrors, set, reset };
}

function HelperNote({ children }: { children: ReactNode }) {
  return (
    <p className="flex items-start gap-2 text-xs text-slate-400">
      <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
      <span>{children}</span>
    </p>
  );
}

/** Kerangka modal form: Modal + <form> + footer tombol simpan. */
function SettingsModal({
  open,
  title,
  formId,
  submitting,
  onClose,
  onSubmit,
  children,
  size = 'lg',
}: {
  open: boolean;
  title: string;
  formId: string;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (e: FormEvent) => void;
  children: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size={size}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form={formId} loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={onSubmit} className="space-y-4">
        {children}
      </form>
    </Modal>
  );
}

/** Toolbar tab: filter di kiri, tombol refresh + tambah di kanan. */
function TabHeader({
  filter,
  refreshing,
  onRefresh,
  onAdd,
  addLabel,
}: {
  filter: ReactNode;
  refreshing: boolean;
  onRefresh: () => void;
  onAdd: () => void;
  addLabel: string;
}) {
  return (
    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
      <div className="flex-1">{filter}</div>
      <div className="flex items-center gap-2">
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={onRefresh}>
          <RefreshCw className={refreshing ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
        <Can perm="tahfiz.create">
          <Button onClick={onAdd}>
            <Plus className="h-4 w-4" /> {addLabel}
          </Button>
        </Can>
      </div>
    </div>
  );
}

/** Tabel generik dengan status loading / error / kosong + paginasi. */
function SettingsTable<T extends { id: number }>({
  isLoading,
  isError,
  error,
  onRetry,
  emptyTitle,
  emptyDescription,
  columns,
  rows,
  renderRow,
  meta,
  onPageChange,
}: {
  isLoading: boolean;
  isError: boolean;
  error: unknown;
  onRetry: () => void;
  emptyTitle: string;
  emptyDescription?: string;
  columns: string[];
  rows: T[];
  renderRow: (row: T) => ReactNode;
  meta: PaginationMeta | undefined;
  onPageChange: (page: number) => void;
}) {
  if (isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat data...
        </div>
      </Card>
    );
  }

  if (isError) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat data"
          description={getErrorMessage(error)}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={onRetry}>
              Coba lagi
            </Button>
          }
        />
      </Card>
    );
  }

  if (rows.length === 0) {
    return (
      <Card>
        <EmptyState title={emptyTitle} description={emptyDescription} />
      </Card>
    );
  }

  return (
    <Card>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
              {columns.map((c) => (
                <th key={c} className="px-4 py-2 font-medium">
                  {c}
                </th>
              ))}
              <th className="w-24 px-4 py-2 font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                {renderRow(row)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {meta && <Pagination meta={meta} onPageChange={onPageChange} />}
    </Card>
  );
}

/** Kolom aksi baris (edit/hapus) yang sudah dibungkus izin. */
function RowActions({ onEdit, onDelete }: { onEdit: () => void; onDelete: () => void }) {
  return (
    <Can
      perm={['tahfiz.update', 'tahfiz.delete']}
      fallback={<td className="px-4 py-2 text-xs text-slate-400">—</td>}
    >
      <td className="px-4 py-2">
        <div className="flex flex-wrap gap-1">
          <Can perm="tahfiz.update">
            <Button variant="ghost" size="icon" title="Edit" onClick={onEdit}>
              <Pencil className="h-3.5 w-3.5" />
            </Button>
          </Can>
          <Can perm="tahfiz.delete">
            <Button variant="ghost" size="icon" title="Hapus" onClick={onDelete}>
              <Trash2 className="h-3.5 w-3.5 text-red-500" />
            </Button>
          </Can>
        </div>
      </td>
    </Can>
  );
}

function ToggleSwitch({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  label: string;
}) {
  return (
    <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
      <input
        type="checkbox"
        className="h-4 w-4 rounded border-slate-300"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
      />
      {label}
    </label>
  );
}

const programTone = (s: TahfizProgramStatus): 'gray' | 'green' | 'amber' =>
  s === 'active' ? 'green' : s === 'archived' ? 'amber' : 'gray';

function programDurationText(p: TahfizProgram): string {
  if (p.duration_value === null || !p.duration_unit) return '—';
  return `${p.duration_value} ${DURATION_UNIT_LABELS[p.duration_unit]}`;
}

function programTargetText(p: TahfizProgram): string {
  if (!p.target_type || p.target_type === 'none') return '—';
  const unit = TARGET_UNIT_LABELS[p.target_type];
  return p.target_value ? `${p.target_value} ${unit}` : unit;
}

/* ============================ TAB: PROGRAM ============================ */

interface ProgramFormState {
  name: string;
  code: string;
  description: string;
  participant_type: TahfizParticipantType | '';
  duration_value: number | '';
  duration_unit: TahfizDurationUnit | '';
  target_type: TahfizTargetUnit | 'none' | '';
  target_value: string;
  minimum_attendance: number | '';
  requires_placement_test: boolean;
  self_registration: boolean;
  status: TahfizProgramStatus;
}

const emptyProgram: ProgramFormState = {
  name: '',
  code: '',
  description: '',
  participant_type: '',
  duration_value: '',
  duration_unit: '',
  target_type: '',
  target_value: '',
  minimum_attendance: '',
  requires_placement_test: false,
  self_registration: false,
  status: 'draft',
};

function ProgramFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizProgram | null;
  submitting: boolean;
  serverErrors: ServerErrors;
  onClose: () => void;
  onSubmit: (payload: TahfizProgramPayload) => void;
}) {
  const { values, errors, setErrors, set, reset } = useLocalForm<ProgramFormState>(emptyProgram);

  useEffect(() => {
    if (!open) return;
    if (editing) {
      reset({
        name: editing.name,
        code: editing.code,
        description: editing.description ?? '',
        participant_type: editing.participant_type ?? '',
        duration_value: editing.duration_value ?? '',
        duration_unit: editing.duration_unit ?? '',
        target_type: editing.target_type ?? '',
        target_value: editing.target_value ?? '',
        minimum_attendance: editing.minimum_attendance ?? '',
        requires_placement_test: editing.requires_placement_test,
        self_registration: editing.self_registration,
        status: editing.status,
      });
    } else {
      reset({ ...emptyProgram });
    }
  }, [open, editing, reset]);

  const err = (key: string) => fieldError(errors, serverErrors, key);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.name.trim()) local.name = 'Nama wajib diisi';
    if (!values.code.trim()) local.code = 'Kode wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({
      name: values.name.trim(),
      code: values.code.trim(),
      description: values.description,
      participant_type: values.participant_type,
      duration_value: values.duration_value,
      duration_unit: values.duration_unit,
      target_type: values.target_type,
      target_value: values.target_value,
      minimum_attendance: values.minimum_attendance,
      requires_placement_test: values.requires_placement_test,
      self_registration: values.self_registration,
      status: values.status,
    });
  };

  return (
    <SettingsModal
      open={open}
      title={editing ? 'Edit Program' : 'Tambah Program'}
      formId="tahfiz-program-form"
      submitting={submitting}
      onClose={onClose}
      onSubmit={handleSubmit}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label required>Nama</Label>
          <Input value={values.name} error={err('name')} onChange={(e) => set('name', e.target.value)} />
        </div>
        <div>
          <Label required>Kode</Label>
          <Input value={values.code} error={err('code')} onChange={(e) => set('code', e.target.value)} />
        </div>
        <div className="sm:col-span-2">
          <Label>Deskripsi</Label>
          <Textarea
            value={values.description}
            error={err('description')}
            onChange={(e) => set('description', e.target.value)}
          />
        </div>
        <div>
          <Label>Jenis Peserta</Label>
          <Select
            value={values.participant_type}
            onChange={(e) => set('participant_type', e.target.value as TahfizParticipantType | '')}
          >
            <option value="">-- Semua jenis --</option>
            {toOptions(PARTICIPANT_TYPE_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Status</Label>
          <Select
            value={values.status}
            onChange={(e) => set('status', e.target.value as TahfizProgramStatus)}
          >
            {toOptions(PROGRAM_STATUS_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Durasi</Label>
          <Input
            type="number"
            min={1}
            value={values.duration_value}
            error={err('duration_value')}
            onChange={(e) => set('duration_value', toId(e.target.value))}
          />
        </div>
        <div>
          <Label>Satuan Durasi</Label>
          <Select
            value={values.duration_unit}
            onChange={(e) => set('duration_unit', e.target.value as TahfizDurationUnit | '')}
          >
            <option value="">-- Pilih --</option>
            {toOptions(DURATION_UNIT_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Jenis Target</Label>
          <Select
            value={values.target_type}
            onChange={(e) =>
              set('target_type', e.target.value as TahfizTargetUnit | 'none' | '')
            }
          >
            <option value="">-- Pilih --</option>
            <option value="none">Tanpa Target</option>
            {toOptions(TARGET_UNIT_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Nilai Target</Label>
          <Input
            value={values.target_value}
            error={err('target_value')}
            placeholder="mis. 5"
            onChange={(e) => set('target_value', e.target.value)}
          />
        </div>
        <div>
          <Label>Kehadiran Minimum (%)</Label>
          <Input
            type="number"
            min={0}
            max={100}
            value={values.minimum_attendance}
            error={err('minimum_attendance')}
            onChange={(e) => set('minimum_attendance', toId(e.target.value))}
          />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-2 sm:flex-row sm:gap-6">
          <ToggleSwitch
            label="Wajib tes penempatan"
            checked={values.requires_placement_test}
            onChange={(v) => set('requires_placement_test', v)}
          />
          <ToggleSwitch
            label="Pendaftaran mandiri"
            checked={values.self_registration}
            onChange={(v) => set('self_registration', v)}
          />
        </div>
      </div>
    </SettingsModal>
  );
}

function ProgramTab() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizProgram | null>(null);
  const [serverErrors, setServerErrors] = useState<ServerErrors>({});
  const [deleting, setDeleting] = useState<TahfizProgram | null>(null);

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'programs', page, search],
    queryFn: () =>
      programsApi.list({ page, per_page: PER_PAGE, search: search || undefined }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'programs'] });
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'options', 'programs'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizProgramPayload) =>
      editing ? programsApi.update(editing.id, payload) : programsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Program diperbarui' : 'Program dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => programsApi.remove(id),
    onSuccess: () => {
      toast.success('Program dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <TabHeader
        addLabel="Tambah Program"
        refreshing={listQuery.isFetching}
        onRefresh={() => listQuery.refetch()}
        onAdd={() => {
          setEditing(null);
          setServerErrors({});
          setFormOpen(true);
        }}
        filter={
          <Card className="p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
                <Input
                  value={search}
                  placeholder="Cari kode atau nama program..."
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                />
              </div>
            </div>
          </Card>
        }
      />

      <SettingsTable
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        emptyTitle="Belum ada program"
        emptyDescription="Tambahkan program Tahfiz pertama."
        columns={[
          'Kode',
          'Nama',
          'Peserta',
          'Durasi',
          'Target',
          'Min. Hadir',
          'Fitur',
          'Level/Enrollment/Halaqah',
          'Status',
        ]}
        rows={rows}
        meta={listQuery.data?.meta}
        onPageChange={setPage}
        renderRow={(row) => (
          <>
            <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
            <td className="px-4 py-2 font-medium text-slate-800">{row.name}</td>
            <td className="px-4 py-2 text-slate-600">
              {row.participant_type ? PARTICIPANT_TYPE_LABELS[row.participant_type] : '—'}
            </td>
            <td className="px-4 py-2 text-slate-600">{programDurationText(row)}</td>
            <td className="px-4 py-2 text-slate-600">{programTargetText(row)}</td>
            <td className="px-3 py-2 text-center text-slate-700">
              {row.minimum_attendance === null ? '—' : `${row.minimum_attendance}%`}
            </td>
            <td className="px-4 py-2">
              <div className="flex flex-wrap gap-1">
                {row.requires_placement_test && <Badge tone="blue">Tes Penempatan</Badge>}
                {row.self_registration && <Badge tone="brand">Daftar Mandiri</Badge>}
                {!row.requires_placement_test && !row.self_registration && (
                  <span className="text-xs text-slate-300">—</span>
                )}
              </div>
            </td>
            <td className="px-4 py-2 text-xs text-slate-600">
              {row.levels_count} / {row.enrollments_count} / {row.halaqahs_count}
            </td>
            <td className="px-4 py-2">
              <Badge tone={programTone(row.status)}>
                {PROGRAM_STATUS_LABELS[row.status] ?? row.status}
              </Badge>
            </td>
            <RowActions
              onEdit={() => {
                setEditing(row);
                setServerErrors({});
                setFormOpen(true);
              }}
              onDelete={() => setDeleting(row)}
            />
          </>
        )}
      />

      <ProgramFormModal
        open={formOpen}
        editing={editing}
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
        title="Hapus Program"
        message={`Hapus program "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}

/* ============================ TAB: LEVEL ============================ */

interface LevelFormState {
  program_id: number | '';
  name: string;
  code: string;
  order: number | '';
  juz_start: number | '';
  juz_end: number | '';
  description: string;
  status: 'active' | 'archived';
}

const emptyLevel: LevelFormState = {
  program_id: '',
  name: '',
  code: '',
  order: '',
  juz_start: '',
  juz_end: '',
  description: '',
  status: 'active',
};

function LevelFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizLevel | null;
  submitting: boolean;
  serverErrors: ServerErrors;
  onClose: () => void;
  onSubmit: (payload: TahfizLevelPayload) => void;
}) {
  const { values, errors, setErrors, set, reset } = useLocalForm<LevelFormState>(emptyLevel);
  const { options: programOptions } = useProgramOptions();

  useEffect(() => {
    if (!open) return;
    if (editing) {
      reset({
        program_id: editing.program_id ?? '',
        name: editing.name,
        code: editing.code ?? '',
        order: editing.order ?? '',
        juz_start: editing.juz_start ?? '',
        juz_end: editing.juz_end ?? '',
        description: editing.description ?? '',
        status: editing.status,
      });
    } else {
      reset({ ...emptyLevel });
    }
  }, [open, editing, reset]);

  const err = (key: string) => fieldError(errors, serverErrors, key);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.name.trim()) local.name = 'Nama wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({
      program_id: values.program_id,
      name: values.name.trim(),
      code: values.code,
      order: values.order,
      juz_start: values.juz_start,
      juz_end: values.juz_end,
      description: values.description,
      status: values.status,
    });
  };

  return (
    <SettingsModal
      open={open}
      title={editing ? 'Edit Level' : 'Tambah Level'}
      formId="tahfiz-level-form"
      submitting={submitting}
      onClose={onClose}
      onSubmit={handleSubmit}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label>Program</Label>
          <SearchSelect
            options={programOptions}
            value={values.program_id}
            error={err('program_id')}
            onChange={(v) => set('program_id', toId(v))}
            placeholder="-- Pilih Program --"
          />
        </div>
        <div>
          <Label required>Nama</Label>
          <Input value={values.name} error={err('name')} onChange={(e) => set('name', e.target.value)} />
        </div>
        <div>
          <Label>Kode</Label>
          <Input value={values.code} error={err('code')} onChange={(e) => set('code', e.target.value)} />
        </div>
        <div>
          <Label>Urutan</Label>
          <Input
            type="number"
            min={0}
            value={values.order}
            error={err('order')}
            onChange={(e) => set('order', toId(e.target.value))}
          />
        </div>
        <div>
          <Label>Status</Label>
          <Select
            value={values.status}
            onChange={(e) => set('status', e.target.value as 'active' | 'archived')}
          >
            <option value="active">Aktif</option>
            <option value="archived">Diarsipkan</option>
          </Select>
        </div>
        <div>
          <Label>Juz Mulai</Label>
          <Input
            type="number"
            min={1}
            max={30}
            value={values.juz_start}
            error={err('juz_start')}
            onChange={(e) => set('juz_start', toId(e.target.value))}
          />
        </div>
        <div>
          <Label>Juz Selesai</Label>
          <Input
            type="number"
            min={1}
            max={30}
            value={values.juz_end}
            error={err('juz_end')}
            onChange={(e) => set('juz_end', toId(e.target.value))}
          />
        </div>
        <div className="sm:col-span-2">
          <Label>Deskripsi</Label>
          <Textarea
            value={values.description}
            error={err('description')}
            onChange={(e) => set('description', e.target.value)}
          />
        </div>
      </div>
      <HelperNote>
        Bila Juz Mulai &gt; Juz Selesai, server akan menukar keduanya secara otomatis.
      </HelperNote>
    </SettingsModal>
  );
}

function LevelTab() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [programId, setProgramId] = useState<number | ''>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizLevel | null>(null);
  const [serverErrors, setServerErrors] = useState<ServerErrors>({});
  const [deleting, setDeleting] = useState<TahfizLevel | null>(null);

  const { options: programOptions } = useProgramOptions();

  const filters: Record<string, string | number> = {};
  if (programId) filters.program_id = programId;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'levels', page, filters],
    queryFn: () =>
      levelsApi.list({
        page,
        per_page: PER_PAGE,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'levels'] });
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'options', 'levels'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizLevelPayload) =>
      editing ? levelsApi.update(editing.id, payload) : levelsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Level diperbarui' : 'Level dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => levelsApi.remove(id),
    onSuccess: () => {
      toast.success('Level dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <TabHeader
        addLabel="Tambah Level"
        refreshing={listQuery.isFetching}
        onRefresh={() => listQuery.refetch()}
        onAdd={() => {
          setEditing(null);
          setServerErrors({});
          setFormOpen(true);
        }}
        filter={
          <Card className="p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Program</label>
                <SearchSelect
                  options={programOptions}
                  value={programId}
                  onChange={(v) => {
                    setProgramId(toId(v));
                    setPage(1);
                  }}
                  placeholder="-- Semua program --"
                />
              </div>
            </div>
          </Card>
        }
      />

      <SettingsTable
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        emptyTitle="Belum ada level"
        emptyDescription="Tambahkan level untuk program Tahfiz."
        columns={['Program', 'Nama', 'Kode', 'Urutan', 'Rentang Juz', 'Halaqah', 'Status']}
        rows={rows}
        meta={listQuery.data?.meta}
        onPageChange={setPage}
        renderRow={(row) => (
          <>
            <td className="px-4 py-2 text-slate-600">{row.program?.name ?? '—'}</td>
            <td className="px-4 py-2 font-medium text-slate-800">{row.name}</td>
            <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code ?? '—'}</td>
            <td className="px-3 py-2 text-center text-slate-700">{row.order ?? '—'}</td>
            <td className="px-4 py-2 text-slate-600">
              {row.juz_range ?? levelJuzRange(row)}
            </td>
            <td className="px-3 py-2 text-center text-slate-700">{row.halaqahs_count}</td>
            <td className="px-4 py-2">
              <Badge tone={row.status === 'active' ? 'green' : 'amber'}>
                {row.status === 'active' ? 'Aktif' : 'Diarsipkan'}
              </Badge>
            </td>
            <RowActions
              onEdit={() => {
                setEditing(row);
                setServerErrors({});
                setFormOpen(true);
              }}
              onDelete={() => setDeleting(row)}
            />
          </>
        )}
      />

      <LevelFormModal
        open={formOpen}
        editing={editing}
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
        title="Hapus Level"
        message={`Hapus level "${deleting?.name ?? ''}"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}

/* ============================ TAB: RUBRIK ============================ */

interface RubricFormState {
  name: string;
  scope: TahfizRubricScope | '';
  description: string;
  max_score: number | '';
  status: 'active' | 'archived';
}

interface RubricItemForm {
  criterion: string;
  weight: number | '';
  order: number | '';
}

const emptyRubric: RubricFormState = {
  name: '',
  scope: '',
  description: '',
  max_score: 100,
  status: 'active',
};

const emptyRubricItem: RubricItemForm = { criterion: '', weight: 1, order: '' };

function RubricFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizRubric | null;
  submitting: boolean;
  serverErrors: ServerErrors;
  onClose: () => void;
  onSubmit: (payload: TahfizRubricPayload) => void;
}) {
  const { values, errors, setErrors, set, reset } = useLocalForm<RubricFormState>(emptyRubric);
  const [items, setItems] = useState<RubricItemForm[]>([{ ...emptyRubricItem }]);
  const [itemErrors, setItemErrors] = useState<Record<number, string>>({});

  const detailQuery = useQuery({
    queryKey: ['tahfiz', 'rubrics', 'detail', editing?.id ?? null],
    queryFn: () => rubricsApi.show(editing!.id),
    enabled: open && !!editing,
  });

  useEffect(() => {
    if (!open) return;
    setItemErrors({});
    if (editing) {
      reset({
        name: editing.name,
        scope: editing.scope,
        description: editing.description ?? '',
        max_score: editing.max_score,
        status: editing.status,
      });
      setItems(
        editing.items && editing.items.length
          ? editing.items.map((i) => ({
              criterion: i.criterion,
              weight: i.weight,
              order: i.order ?? '',
            }))
          : [{ ...emptyRubricItem }],
      );
    } else {
      reset({ ...emptyRubric });
      setItems([{ ...emptyRubricItem }]);
    }
  }, [open, editing, reset]);

  useEffect(() => {
    const detail = detailQuery.data;
    if (!open || !detail) return;
    setItems(
      detail.items && detail.items.length
        ? detail.items.map((i) => ({
            criterion: i.criterion,
            weight: i.weight,
            order: i.order ?? '',
          }))
        : [{ ...emptyRubricItem }],
    );
  }, [open, detailQuery.data]);

  const err = (key: string) => fieldError(errors, serverErrors, key);

  const updateItem = (index: number, patch: Partial<RubricItemForm>) => {
    setItems((prev) => prev.map((it, i) => (i === index ? { ...it, ...patch } : it)));
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.name.trim()) local.name = 'Nama wajib diisi';
    if (!values.scope) local.scope = 'Ruang lingkup wajib dipilih';

    const ierr: Record<number, string> = {};
    items.forEach((it, index) => {
      if (!it.criterion.trim()) ierr[index] = 'Kriteria wajib diisi';
    });

    if (Object.keys(local).length || Object.keys(ierr).length) {
      setErrors(local);
      setItemErrors(ierr);
      return;
    }
    setItemErrors({});
    onSubmit({
      name: values.name.trim(),
      scope: values.scope as TahfizRubricScope,
      description: values.description,
      max_score: values.max_score,
      status: values.status,
      items: items.map((it, index) => ({
        criterion: it.criterion.trim(),
        weight: it.weight,
        order: it.order === '' ? index + 1 : it.order,
      })),
    });
  };

  return (
    <SettingsModal
      open={open}
      title={editing ? 'Edit Rubrik' : 'Tambah Rubrik'}
      formId="tahfiz-rubric-form"
      submitting={submitting}
      onClose={onClose}
      onSubmit={handleSubmit}
      size="xl"
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label required>Nama</Label>
          <Input value={values.name} error={err('name')} onChange={(e) => set('name', e.target.value)} />
        </div>
        <div>
          <Label required>Ruang Lingkup</Label>
          <Select
            value={values.scope}
            error={err('scope')}
            onChange={(e) => set('scope', e.target.value as TahfizRubricScope | '')}
          >
            <option value="">-- Pilih --</option>
            {toOptions(RUBRIC_SCOPE_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Nilai Maksimum</Label>
          <Input
            type="number"
            min={2}
            max={10}
            value={values.max_score}
            error={err('max_score')}
            onChange={(e) => set('max_score', toId(e.target.value))}
          />
        </div>
        <div>
          <Label>Status</Label>
          <Select
            value={values.status}
            onChange={(e) => set('status', e.target.value as 'active' | 'archived')}
          >
            <option value="active">Aktif</option>
            <option value="archived">Diarsipkan</option>
          </Select>
        </div>
        <div className="sm:col-span-2">
          <Label>Deskripsi</Label>
          <Textarea
            value={values.description}
            error={err('description')}
            onChange={(e) => set('description', e.target.value)}
          />
        </div>
      </div>

      <div className="space-y-2 rounded-lg border border-slate-200 p-3">
        <div className="flex items-center justify-between">
          <p className="text-sm font-semibold text-slate-800">Kriteria Penilaian</p>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => setItems((prev) => [...prev, { ...emptyRubricItem }])}
          >
            <Plus className="h-3.5 w-3.5" /> Tambah Kriteria
          </Button>
        </div>

        {editing && detailQuery.isFetching && (
          <p className="flex items-center gap-2 text-xs text-slate-400">
            <Spinner className="h-3.5 w-3.5" /> Memuat kriteria...
          </p>
        )}

        {items.map((item, index) => (
          <div key={index} className="grid grid-cols-12 items-start gap-2">
            <div className="col-span-6">
              <Input
                value={item.criterion}
                placeholder={`Kriteria #${index + 1}`}
                error={itemErrors[index]}
                onChange={(e) => updateItem(index, { criterion: e.target.value })}
              />
            </div>
            <div className="col-span-2">
              <Input
                type="number"
                min={0}
                value={item.weight}
                placeholder="Bobot"
                onChange={(e) => updateItem(index, { weight: toId(e.target.value) })}
              />
            </div>
            <div className="col-span-2">
              <Input
                type="number"
                min={0}
                value={item.order}
                placeholder="Urutan"
                onChange={(e) => updateItem(index, { order: toId(e.target.value) })}
              />
            </div>
            <div className="col-span-2 flex justify-end">
              <Button
                type="button"
                size="icon"
                variant="ghost"
                title="Hapus kriteria"
                disabled={items.length <= 1}
                onClick={() => setItems((prev) => prev.filter((_, i) => i !== index))}
              >
                <Trash2 className="h-3.5 w-3.5 text-red-500" />
              </Button>
            </div>
          </div>
        ))}
      </div>

      <HelperNote>
        Kriteria disinkronkan menyeluruh saat menyimpan — kriteria yang dihapus dari daftar juga
        terhapus di server (tanpa data yatim).
      </HelperNote>
    </SettingsModal>
  );
}

function RubricTab() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizRubric | null>(null);
  const [serverErrors, setServerErrors] = useState<ServerErrors>({});
  const [deleting, setDeleting] = useState<TahfizRubric | null>(null);

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'rubrics', page, search],
    queryFn: () =>
      rubricsApi.list({ page, per_page: PER_PAGE, search: search || undefined }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'rubrics'] });
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'options', 'rubrics'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizRubricPayload) =>
      editing ? rubricsApi.update(editing.id, payload) : rubricsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Rubrik diperbarui' : 'Rubrik dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => rubricsApi.remove(id),
    onSuccess: () => {
      toast.success('Rubrik dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <TabHeader
        addLabel="Tambah Rubrik"
        refreshing={listQuery.isFetching}
        onRefresh={() => listQuery.refetch()}
        onAdd={() => {
          setEditing(null);
          setServerErrors({});
          setFormOpen(true);
        }}
        filter={
          <Card className="p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
                <Input
                  value={search}
                  placeholder="Cari nama rubrik..."
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                />
              </div>
            </div>
          </Card>
        }
      />

      <SettingsTable
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        emptyTitle="Belum ada rubrik"
        emptyDescription="Tambahkan rubrik penilaian Tahsin/Hafalan."
        columns={['Nama', 'Ruang Lingkup', 'Nilai Maks', 'Total Bobot', 'Kriteria', 'Status']}
        rows={rows}
        meta={listQuery.data?.meta}
        onPageChange={setPage}
        renderRow={(row) => (
          <>
            <td className="px-4 py-2 font-medium text-slate-800">{row.name}</td>
            <td className="px-4 py-2 text-slate-600">
              {RUBRIC_SCOPE_LABELS[row.scope] ?? row.scope}
            </td>
            <td className="px-3 py-2 text-center text-slate-700">{row.max_score}</td>
            <td className="px-3 py-2 text-center text-slate-700">{row.total_weight}</td>
            <td className="px-3 py-2 text-center text-slate-700">{row.items?.length ?? 0}</td>
            <td className="px-4 py-2">
              <Badge tone={row.status === 'active' ? 'green' : 'amber'}>
                {row.status === 'active' ? 'Aktif' : 'Diarsipkan'}
              </Badge>
            </td>
            <RowActions
              onEdit={() => {
                setEditing(row);
                setServerErrors({});
                setFormOpen(true);
              }}
              onDelete={() => setDeleting(row)}
            />
          </>
        )}
      />

      <RubricFormModal
        open={formOpen}
        editing={editing}
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
        title="Hapus Rubrik"
        message={`Hapus rubrik "${deleting?.name ?? ''}"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}

/* ============================ TAB: JENIS KESALAHAN ============================ */

interface MistakeFormState {
  code: string;
  label: string;
  category: TahfizMistakeCategory | '';
  severity_weight: number | '';
  is_active: boolean;
}

const emptyMistake: MistakeFormState = {
  code: '',
  label: '',
  category: '',
  severity_weight: 1,
  is_active: true,
};

function MistakeTypeFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizMistakeType | null;
  submitting: boolean;
  serverErrors: ServerErrors;
  onClose: () => void;
  onSubmit: (payload: TahfizMistakeTypePayload) => void;
}) {
  const { values, errors, setErrors, set, reset } = useLocalForm<MistakeFormState>(emptyMistake);

  useEffect(() => {
    if (!open) return;
    if (editing) {
      reset({
        code: editing.code,
        label: editing.label,
        category: editing.category ?? '',
        severity_weight: editing.severity_weight,
        is_active: editing.is_active,
      });
    } else {
      reset({ ...emptyMistake });
    }
  }, [open, editing, reset]);

  const err = (key: string) => fieldError(errors, serverErrors, key);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.code.trim()) local.code = 'Kode wajib diisi';
    if (!values.label.trim()) local.label = 'Label wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({
      code: values.code.trim(),
      label: values.label.trim(),
      category: values.category,
      severity_weight: values.severity_weight,
      is_active: values.is_active,
    });
  };

  return (
    <SettingsModal
      open={open}
      title={editing ? 'Edit Jenis Kesalahan' : 'Tambah Jenis Kesalahan'}
      formId="tahfiz-mistake-form"
      submitting={submitting}
      onClose={onClose}
      onSubmit={handleSubmit}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label required>Kode</Label>
          <Input value={values.code} error={err('code')} onChange={(e) => set('code', e.target.value)} />
        </div>
        <div>
          <Label required>Label</Label>
          <Input value={values.label} error={err('label')} onChange={(e) => set('label', e.target.value)} />
        </div>
        <div>
          <Label>Kategori</Label>
          <Select
            value={values.category}
            onChange={(e) => set('category', e.target.value as TahfizMistakeCategory | '')}
          >
            <option value="">-- Pilih --</option>
            {toOptions(MISTAKE_CATEGORY_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Bobot Keparahan</Label>
          <Input
            type="number"
            min={0}
            value={values.severity_weight}
            error={err('severity_weight')}
            onChange={(e) => set('severity_weight', toId(e.target.value))}
          />
        </div>
        <div className="sm:col-span-2">
          <ToggleSwitch label="Aktif" checked={values.is_active} onChange={(v) => set('is_active', v)} />
        </div>
      </div>
      <HelperNote>
        Taksonomi ini menjadi dasar analitik kesalahan pada setoran hafalan.
      </HelperNote>
    </SettingsModal>
  );
}

function MistakeTypeTab() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState<TahfizMistakeCategory | ''>('');
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizMistakeType | null>(null);
  const [serverErrors, setServerErrors] = useState<ServerErrors>({});
  const [deleting, setDeleting] = useState<TahfizMistakeType | null>(null);

  const filters: Record<string, string | number> = {};
  if (category) filters.category = category;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'mistake-types', page, filters, search],
    queryFn: () =>
      mistakeTypesApi.list({
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'mistake-types'] });
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'options', 'mistake-types'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizMistakeTypePayload) =>
      editing ? mistakeTypesApi.update(editing.id, payload) : mistakeTypesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Jenis kesalahan diperbarui' : 'Jenis kesalahan dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => mistakeTypesApi.remove(id),
    onSuccess: () => {
      toast.success('Jenis kesalahan dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <TabHeader
        addLabel="Tambah Jenis"
        refreshing={listQuery.isFetching}
        onRefresh={() => listQuery.refetch()}
        onAdd={() => {
          setEditing(null);
          setServerErrors({});
          setFormOpen(true);
        }}
        filter={
          <Card className="p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Kategori</label>
                <Select
                  value={category}
                  onChange={(e) => {
                    setCategory(e.target.value as TahfizMistakeCategory | '');
                    setPage(1);
                  }}
                >
                  <option value="">Semua kategori</option>
                  {toOptions(MISTAKE_CATEGORY_LABELS).map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
                <Input
                  value={search}
                  placeholder="Cari kode atau label..."
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                />
              </div>
            </div>
          </Card>
        }
      />

      <SettingsTable
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        emptyTitle="Belum ada jenis kesalahan"
        emptyDescription="Tambahkan taksonomi kesalahan setoran."
        columns={['Kode', 'Label', 'Kategori', 'Bobot', 'Status']}
        rows={rows}
        meta={listQuery.data?.meta}
        onPageChange={setPage}
        renderRow={(row) => (
          <>
            <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
            <td className="px-4 py-2 font-medium text-slate-800">{row.label}</td>
            <td className="px-4 py-2 text-slate-600">
              {row.category ? MISTAKE_CATEGORY_LABELS[row.category] : '—'}
            </td>
            <td className="px-3 py-2 text-center text-slate-700">{row.severity_weight}</td>
            <td className="px-4 py-2">
              <Badge tone={row.is_active ? 'green' : 'gray'}>
                {row.is_active ? 'Aktif' : 'Tidak Aktif'}
              </Badge>
            </td>
            <RowActions
              onEdit={() => {
                setEditing(row);
                setServerErrors({});
                setFormOpen(true);
              }}
              onDelete={() => setDeleting(row)}
            />
          </>
        )}
      />

      <MistakeTypeFormModal
        open={formOpen}
        editing={editing}
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
        title="Hapus Jenis Kesalahan"
        message={`Hapus jenis kesalahan "${deleting?.label ?? ''}"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}

/* ============================ TAB: ITEM MUTABAAH ============================ */

interface MutabaahItemFormState {
  code: string;
  label: string;
  category: TahfizMutabaahCategory | '';
  value_type: TahfizMutabaahValueType | '';
  unit: string;
  target_value: number | '';
  order: number | '';
  is_active: boolean;
}

const emptyMutabaahItem: MutabaahItemFormState = {
  code: '',
  label: '',
  category: '',
  value_type: 'boolean',
  unit: '',
  target_value: '',
  order: '',
  is_active: true,
};

function MutabaahItemFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizMutabaahItem | null;
  submitting: boolean;
  serverErrors: ServerErrors;
  onClose: () => void;
  onSubmit: (payload: TahfizMutabaahItemPayload) => void;
}) {
  const { values, errors, setErrors, set, reset } = useLocalForm<MutabaahItemFormState>(emptyMutabaahItem);

  useEffect(() => {
    if (!open) return;
    if (editing) {
      reset({
        code: editing.code,
        label: editing.label,
        category: editing.category ?? '',
        value_type: editing.value_type ?? '',
        unit: editing.unit ?? '',
        target_value: editing.target_value ?? '',
        order: editing.order ?? '',
        is_active: editing.is_active,
      });
    } else {
      reset({ ...emptyMutabaahItem });
    }
  }, [open, editing, reset]);

  const err = (key: string) => fieldError(errors, serverErrors, key);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.code.trim()) local.code = 'Kode wajib diisi';
    if (!values.label.trim()) local.label = 'Label wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({
      code: values.code.trim(),
      label: values.label.trim(),
      category: values.category,
      value_type: values.value_type,
      unit: values.unit,
      target_value: values.target_value,
      order: values.order,
      is_active: values.is_active,
    });
  };

  return (
    <SettingsModal
      open={open}
      title={editing ? 'Edit Item Mutabaah' : 'Tambah Item Mutabaah'}
      formId="tahfiz-mutabaah-item-form"
      submitting={submitting}
      onClose={onClose}
      onSubmit={handleSubmit}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label required>Kode</Label>
          <Input value={values.code} error={err('code')} onChange={(e) => set('code', e.target.value)} />
        </div>
        <div>
          <Label required>Label</Label>
          <Input value={values.label} error={err('label')} onChange={(e) => set('label', e.target.value)} />
        </div>
        <div>
          <Label>Kategori</Label>
          <Select
            value={values.category}
            onChange={(e) => set('category', e.target.value as TahfizMutabaahCategory | '')}
          >
            <option value="">-- Pilih --</option>
            {toOptions(MUTABAAH_CATEGORY_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Jenis Nilai</Label>
          <Select
            value={values.value_type}
            onChange={(e) => set('value_type', e.target.value as TahfizMutabaahValueType | '')}
          >
            <option value="">-- Pilih --</option>
            {toOptions(MUTABAAH_VALUE_TYPE_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Satuan</Label>
          <Input
            value={values.unit}
            error={err('unit')}
            placeholder="mis. ayat, halaman"
            onChange={(e) => set('unit', e.target.value)}
          />
        </div>
        <div>
          <Label>Nilai Target</Label>
          <Input
            type="number"
            min={0}
            value={values.target_value}
            error={err('target_value')}
            onChange={(e) => set('target_value', toId(e.target.value))}
          />
        </div>
        <div>
          <Label>Urutan</Label>
          <Input
            type="number"
            min={0}
            value={values.order}
            error={err('order')}
            onChange={(e) => set('order', toId(e.target.value))}
          />
        </div>
        <div className="flex items-end">
          <ToggleSwitch label="Aktif" checked={values.is_active} onChange={(v) => set('is_active', v)} />
        </div>
      </div>
      <HelperNote>
        Item ini dirender secara dinamis pada form mutabaah harian — tidak ada daftar yang
        di-hardcode di sisi klien.
      </HelperNote>
    </SettingsModal>
  );
}

function MutabaahItemTab() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState<TahfizMutabaahCategory | ''>('');
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizMutabaahItem | null>(null);
  const [serverErrors, setServerErrors] = useState<ServerErrors>({});
  const [deleting, setDeleting] = useState<TahfizMutabaahItem | null>(null);

  const filters: Record<string, string | number> = {};
  if (category) filters.category = category;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'mutabaah-items', page, filters, search],
    queryFn: () =>
      mutabaahItemsApi.list({
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'mutabaah-items'] });
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'options', 'mutabaah-items'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizMutabaahItemPayload) =>
      editing
        ? mutabaahItemsApi.update(editing.id, payload)
        : mutabaahItemsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Item mutabaah diperbarui' : 'Item mutabaah dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => mutabaahItemsApi.remove(id),
    onSuccess: () => {
      toast.success('Item mutabaah dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <TabHeader
        addLabel="Tambah Item"
        refreshing={listQuery.isFetching}
        onRefresh={() => listQuery.refetch()}
        onAdd={() => {
          setEditing(null);
          setServerErrors({});
          setFormOpen(true);
        }}
        filter={
          <Card className="p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Kategori</label>
                <Select
                  value={category}
                  onChange={(e) => {
                    setCategory(e.target.value as TahfizMutabaahCategory | '');
                    setPage(1);
                  }}
                >
                  <option value="">Semua kategori</option>
                  {toOptions(MUTABAAH_CATEGORY_LABELS).map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
                <Input
                  value={search}
                  placeholder="Cari kode atau label..."
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                />
              </div>
            </div>
          </Card>
        }
      />

      <SettingsTable
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        emptyTitle="Belum ada item mutabaah"
        emptyDescription="Tambahkan item yang akan tampil pada form mutabaah harian."
        columns={['Kode', 'Label', 'Kategori', 'Jenis Nilai', 'Satuan', 'Target', 'Urutan', 'Status']}
        rows={rows}
        meta={listQuery.data?.meta}
        onPageChange={setPage}
        renderRow={(row) => (
          <>
            <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
            <td className="px-4 py-2 font-medium text-slate-800">{row.label}</td>
            <td className="px-4 py-2 text-slate-600">
              {row.category ? MUTABAAH_CATEGORY_LABELS[row.category] : '—'}
            </td>
            <td className="px-4 py-2 text-slate-600">
              {row.value_type ? MUTABAAH_VALUE_TYPE_LABELS[row.value_type] : '—'}
            </td>
            <td className="px-4 py-2 text-slate-600">{row.unit ?? '—'}</td>
            <td className="px-3 py-2 text-center text-slate-700">{row.target_value ?? '—'}</td>
            <td className="px-3 py-2 text-center text-slate-700">{row.order ?? '—'}</td>
            <td className="px-4 py-2">
              <Badge tone={row.is_active ? 'green' : 'gray'}>
                {row.is_active ? 'Aktif' : 'Tidak Aktif'}
              </Badge>
            </td>
            <RowActions
              onEdit={() => {
                setEditing(row);
                setServerErrors({});
                setFormOpen(true);
              }}
              onDelete={() => setDeleting(row)}
            />
          </>
        )}
      />

      <MutabaahItemFormModal
        open={formOpen}
        editing={editing}
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
        title="Hapus Item Mutabaah"
        message={`Hapus item mutabaah "${deleting?.label ?? ''}"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}

/* ============================ TAB: INSTRUKTUR ============================ */

interface InstructorFormState {
  employee_id: number | '';
  role: TahfizInstructorRole | '';
  specialization: TahfizSpecialization[];
  certificate: string;
  notes: string;
  is_active: boolean;
}

const emptyInstructor: InstructorFormState = {
  employee_id: '',
  role: '',
  specialization: [],
  certificate: '',
  notes: '',
  is_active: true,
};

function InstructorFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizInstructor | null;
  submitting: boolean;
  serverErrors: ServerErrors;
  onClose: () => void;
  onSubmit: (payload: TahfizInstructorPayload) => void;
}) {
  const { values, errors, setErrors, set, reset } = useLocalForm<InstructorFormState>(emptyInstructor);
  const { options: employeeOptions } = useEmployeeOptions(false);

  useEffect(() => {
    if (!open) return;
    if (editing) {
      reset({
        employee_id: editing.employee_id,
        role: editing.role ?? '',
        specialization: editing.specialization ?? [],
        certificate: editing.certificate ?? '',
        notes: editing.notes ?? '',
        is_active: editing.is_active,
      });
    } else {
      reset({ ...emptyInstructor });
    }
  }, [open, editing, reset]);

  const err = (key: string) => fieldError(errors, serverErrors, key);

  const toggleSpec = (key: TahfizSpecialization, checked: boolean) => {
    set(
      'specialization',
      checked
        ? [...values.specialization, key]
        : values.specialization.filter((s) => s !== key),
    );
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.employee_id) local.employee_id = 'Pegawai wajib dipilih';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({
      employee_id: values.employee_id,
      role: values.role,
      specialization: values.specialization,
      certificate: values.certificate,
      notes: values.notes,
      is_active: values.is_active,
    });
  };

  return (
    <SettingsModal
      open={open}
      title={editing ? 'Edit Instruktur' : 'Tambah Instruktur'}
      formId="tahfiz-instructor-form"
      submitting={submitting}
      onClose={onClose}
      onSubmit={handleSubmit}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label required>Pegawai</Label>
          <SearchSelect
            options={employeeOptions}
            value={values.employee_id}
            error={err('employee_id')}
            onChange={(v) => set('employee_id', toId(v))}
            placeholder="-- Pilih Pegawai --"
          />
        </div>
        <div>
          <Label>Peran</Label>
          <Select
            value={values.role}
            onChange={(e) => set('role', e.target.value as TahfizInstructorRole | '')}
          >
            <option value="">-- Pilih --</option>
            {toOptions(INSTRUCTOR_ROLE_LABELS).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Sertifikat</Label>
          <Input
            value={values.certificate}
            error={err('certificate')}
            placeholder="Nomor / nama sertifikat"
            onChange={(e) => set('certificate', e.target.value)}
          />
        </div>
        <div className="sm:col-span-2">
          <Label>Spesialisasi</Label>
          <div className="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-3">
            {(Object.keys(SPECIALIZATION_LABELS) as TahfizSpecialization[]).map((key) => (
              <ToggleSwitch
                key={key}
                label={SPECIALIZATION_LABELS[key]}
                checked={values.specialization.includes(key)}
                onChange={(checked) => toggleSpec(key, checked)}
              />
            ))}
          </div>
        </div>
        <div className="sm:col-span-2">
          <Label>Catatan</Label>
          <Textarea
            value={values.notes}
            error={err('notes')}
            onChange={(e) => set('notes', e.target.value)}
          />
        </div>
        <div className="sm:col-span-2">
          <ToggleSwitch label="Aktif" checked={values.is_active} onChange={(v) => set('is_active', v)} />
        </div>
      </div>
    </SettingsModal>
  );
}

function InstructorTab() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizInstructor | null>(null);
  const [serverErrors, setServerErrors] = useState<ServerErrors>({});
  const [deleting, setDeleting] = useState<TahfizInstructor | null>(null);

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'instructors', page, search],
    queryFn: () =>
      instructorsApi.list({ page, per_page: PER_PAGE, search: search || undefined }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'instructors'] });
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'options', 'instructors'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizInstructorPayload) =>
      editing
        ? instructorsApi.update(editing.id, payload)
        : instructorsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Instruktur diperbarui' : 'Instruktur dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => instructorsApi.remove(id),
    onSuccess: () => {
      toast.success('Instruktur dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <TabHeader
        addLabel="Tambah Instruktur"
        refreshing={listQuery.isFetching}
        onRefresh={() => listQuery.refetch()}
        onAdd={() => {
          setEditing(null);
          setServerErrors({});
          setFormOpen(true);
        }}
        filter={
          <Card className="p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
                <Input
                  value={search}
                  placeholder="Cari nama atau NIP..."
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                />
              </div>
            </div>
          </Card>
        }
      />

      <SettingsTable
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        emptyTitle="Belum ada instruktur"
        emptyDescription="Tambahkan profil instruktur Tahfiz."
        columns={['Nama', 'NIP', 'Peran', 'Spesialisasi', 'Sertifikat', 'Status', 'Halaqah']}
        rows={rows}
        meta={listQuery.data?.meta}
        onPageChange={setPage}
        renderRow={(row) => (
          <>
            <td className="px-4 py-2 font-medium text-slate-800">
              {row.employee?.full_name ?? `#${row.employee_id}`}
            </td>
            <td className="px-4 py-2 font-mono text-xs text-slate-600">
              {row.employee?.nip ?? '—'}
            </td>
            <td className="px-4 py-2 text-slate-600">
              {row.role ? INSTRUCTOR_ROLE_LABELS[row.role] : '—'}
            </td>
            <td className="px-4 py-2">
              <div className="flex flex-wrap gap-1">
                {row.specialization && row.specialization.length ? (
                  row.specialization.map((s) => (
                    <Badge key={s} tone="brand">
                      {SPECIALIZATION_LABELS[s] ?? s}
                    </Badge>
                  ))
                ) : (
                  <span className="text-xs text-slate-300">—</span>
                )}
              </div>
            </td>
            <td className="px-4 py-2 text-slate-600">{row.certificate ?? '—'}</td>
            <td className="px-4 py-2">
              <Badge tone={row.is_active ? 'green' : 'gray'}>
                {row.is_active ? 'Aktif' : 'Tidak Aktif'}
              </Badge>
            </td>
            <td className="px-3 py-2 text-center text-slate-700">{row.halaqahs_count}</td>
            <RowActions
              onEdit={() => {
                setEditing(row);
                setServerErrors({});
                setFormOpen(true);
              }}
              onDelete={() => setDeleting(row)}
            />
          </>
        )}
      />

      <InstructorFormModal
        open={formOpen}
        editing={editing}
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
        title="Hapus Instruktur"
        message={`Hapus instruktur "${
          deleting?.employee?.full_name ?? ''
        }"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}

/* ============================ HALAMAN ============================ */

type SettingsTab =
  | 'program'
  | 'level'
  | 'rubric'
  | 'mistake'
  | 'mutabaah'
  | 'instructor';

const TABS: { key: SettingsTab; label: string }[] = [
  { key: 'program', label: 'Program' },
  { key: 'level', label: 'Level' },
  { key: 'rubric', label: 'Rubrik' },
  { key: 'mistake', label: 'Jenis Kesalahan' },
  { key: 'mutabaah', label: 'Item Mutabaah' },
  { key: 'instructor', label: 'Instruktur' },
];

/**
 * Pengaturan modul Tahfiz / Al-Qur'an.
 *
 * Seluruh aturan Tahfiz bersifat data — program, level, rubrik (dengan kriteria
 * berbobot), taksonomi kesalahan, item mutabaah harian, dan profil instruktur
 * dikonfigurasi dari halaman ini, bukan di-hardcode.
 */
export function TahfizSettingsPage() {
  const [tab, setTab] = useState<SettingsTab>('program');

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3">
        <div className="rounded-lg bg-brand-50 p-2 text-brand-600">
          <Settings2 className="h-5 w-5" />
        </div>
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pengaturan Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Aturan Tahfiz bersifat data: program, level, rubrik, taksonomi kesalahan, item
            mutabaah, dan instruktur semuanya dikonfigurasi di sini.
          </p>
        </div>
      </div>

      <div className="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={cn(
              'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
              tab === t.key
                ? 'bg-white text-brand-700 shadow-sm'
                : 'text-slate-600 hover:text-slate-800',
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'program' && <ProgramTab />}
      {tab === 'level' && <LevelTab />}
      {tab === 'rubric' && <RubricTab />}
      {tab === 'mistake' && <MistakeTypeTab />}
      {tab === 'mutabaah' && <MutabaahItemTab />}
      {tab === 'instructor' && <InstructorTab />}
    </div>
  );
}