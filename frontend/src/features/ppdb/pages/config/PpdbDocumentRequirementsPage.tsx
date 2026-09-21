import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Paperclip, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbDocumentRequirementsApi } from '../../api/ppdbApi';
import { getValidationErrors } from '../../api/http';
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
import {
  formatKb,
  PpdbActiveBadge,
  PpdbErrorCard,
  PpdbField,
  PpdbFilterCard,
  PpdbLoadingCard,
  PpdbPageHeader,
  PpdbRequiredBadge,
  describeError,
  splitList,
  toNumOrEmpty,
} from '../../components/config/PpdbConfigUi';
import { PpdbNoPeriodState, PpdbPeriodPicker } from '../../components/config/PpdbPeriodPicker';
import {
  PpdbScopeBadge,
  PpdbScopeControls,
  PpdbScopeFilters,
} from '../../components/config/PpdbScopeSelectors';
import type { PpdbDocumentRequirement, PpdbDocumentRequirementPayload } from '@/types/ppdb';

const PER_PAGE = 10;
const CODE_PATTERN = /^[A-Z0-9][A-Z0-9_-]*$/;

interface RequirementForm {
  period_id: string;
  program_id: string;
  wave_id: string;
  code: string;
  name: string;
  description: string;
  file_types: string;
  max_size_kb: string;
  min_size_kb: string;
  required: boolean;
  active: boolean;
  order: string;
}

type FormErrors = Partial<Record<keyof RequirementForm, string>>;

function requirementToForm(
  row: PpdbDocumentRequirement | null,
  fallbackPeriodId: number | '',
): RequirementForm {
  return {
    period_id: String(row?.period_id ?? fallbackPeriodId ?? ''),
    program_id: row?.program_id == null ? '' : String(row.program_id),
    wave_id: row?.wave_id == null ? '' : String(row.wave_id),
    code: row?.code ?? '',
    name: row?.name ?? '',
    description: row?.description ?? '',
    file_types: row?.file_types ?? '',
    max_size_kb: row?.max_size_kb == null ? '' : String(row.max_size_kb),
    min_size_kb: row?.min_size_kb == null ? '' : String(row.min_size_kb),
    required: row?.required ?? true,
    active: row?.active ?? true,
    order: row ? String(row.order) : '',
  };
}

function validate(form: RequirementForm): FormErrors {
  const errors: FormErrors = {};

  if (form.period_id === '') errors.period_id = 'Periode wajib dipilih.';
  if (!form.code.trim()) errors.code = 'Kode dokumen wajib diisi.';
  else if (form.code.length > 50) errors.code = 'Kode maksimal 50 karakter.';
  else if (!CODE_PATTERN.test(form.code.trim()))
    errors.code = 'Kode hanya boleh huruf kapital, angka, garis bawah, atau tanda hubung.';

  if (!form.name.trim()) errors.name = 'Nama dokumen wajib diisi.';
  else if (form.name.length > 150) errors.name = 'Nama dokumen maksimal 150 karakter.';

  const types = splitList(form.file_types);
  if (types.length === 0)
    errors.file_types = 'Daftar format wajib diisi, mis. pdf, jpg, png.';
  else if (types.some((type) => !/^[a-z0-9]{1,10}$/i.test(type)))
    errors.file_types = 'Format berupa ekstensi saja, tanpa titik dan tanpa koma.';
  else if (form.file_types.length > 100) errors.file_types = 'Daftar format maksimal 100 karakter.';

  const max = Number(form.max_size_kb);
  if (form.max_size_kb === '') errors.max_size_kb = 'Ukuran maksimal wajib diisi (KB).';
  else if (!Number.isFinite(max) || max <= 0) errors.max_size_kb = 'Ukuran maksimal harus lebih dari 0.';

  const min = Number(form.min_size_kb);
  if (form.min_size_kb !== '' && (!Number.isFinite(min) || min < 0))
    errors.min_size_kb = 'Ukuran minimal tidak boleh negatif.';
  else if (form.min_size_kb !== '' && form.max_size_kb !== '' && min > max)
    errors.min_size_kb = 'Ukuran minimal melebihi ukuran maksimal.';

  if (form.order !== '' && (Number(form.order) < 0 || Number(form.order) > 65535))
    errors.order = 'Urutan harus berada di rentang 0 sampai 65535.';

  return errors;
}

/** Ekstensi yang boleh diunggah — pakai hitungan backend bila tersedia. */
function extensionsOf(row: PpdbDocumentRequirement): string[] {
  return row.allowed_extensions?.length ? row.allowed_extensions : splitList(row.file_types);
}

/* ============================ FORM MODAL ============================ */

function RequirementFormModal({
  open,
  editing,
  periodId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: PpdbDocumentRequirement | null;
  periodId: number | '';
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PpdbDocumentRequirementPayload) => void;
}) {
  const { options: periodOptions } = usePpdbPeriodOptions();
  const [form, setForm] = useState<RequirementForm>(() => requirementToForm(editing, periodId));
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  useEffect(() => {
    if (!open) return;
    setForm(requirementToForm(editing, periodId));
    setLocalErrors({});
  }, [open, editing, periodId]);

  const set = <K extends keyof RequirementForm>(key: K, value: RequirementForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const errorOf = (key: keyof RequirementForm) => localErrors[key] ?? serverErrors[key]?.[0];

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    const errors = validate(form);
    setLocalErrors(errors);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali syarat dokumen.');
      return;
    }

    const scope: { program_id: number | ''; wave_id: number | '' } = {
      program_id: form.program_id === '' ? '' : Number(form.program_id),
      wave_id: form.wave_id === '' ? '' : Number(form.wave_id),
    };

    onSubmit({
      period_id: Number(form.period_id),
      ...scope,
      code: form.code.trim().toUpperCase(),
      name: form.name.trim(),
      description: form.description.trim(),
      file_types: splitList(form.file_types)
        .map((type) => type.toLowerCase())
        .join(','),
      max_size_kb: toNumOrEmpty(form.max_size_kb),
      min_size_kb: toNumOrEmpty(form.min_size_kb),
      required: form.required,
      active: form.active,
      order: toNumOrEmpty(form.order),
    });
  };

  const previewExtensions = splitList(form.file_types).map((type) => type.toLowerCase());

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Syarat Dokumen' : 'Tambah Syarat Dokumen'}
      size="lg"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="ppdb-document-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="ppdb-document-form" onSubmit={handleSubmit} className="space-y-3">
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
          <PpdbField label="Kode" required error={errorOf('code')} hint="Pengujuk di verifikasi & laporan, mis. KARTU_KELUARGA.">
            <Input
              value={form.code}
              placeholder="KARTU_KELUARGA"
              error={errorOf('code')}
              onChange={(e) => set('code', e.target.value.toUpperCase())}
            />
          </PpdbField>
        </div>

        <PpdbField label="Nama Dokumen" required error={errorOf('name')}>
          <Input
            value={form.name}
            placeholder="Kartu Keluarga"
            error={errorOf('name')}
            onChange={(e) => set('name', e.target.value)}
          />
        </PpdbField>

        <PpdbField label="Keterangan" error={errorOf('description')}>
          <Textarea
            rows={2}
            value={form.description}
            placeholder="Unggah hasil scan/foto KK yang terlihat jelas."
            error={errorOf('description')}
            onChange={(e) => set('description', e.target.value)}
          />
        </PpdbField>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <PpdbField
            label="Format Diizinkan"
            required
            className="sm:col-span-3"
            error={errorOf('file_types')}
            hint="Pisahkan dengan koma. Backend memakai nilai ini untuk menolak berkas salah format."
          >
            <Input
              value={form.file_types}
              placeholder="pdf, jpg, png"
              error={errorOf('file_types')}
              onChange={(e) => set('file_types', e.target.value)}
            />
          </PpdbField>
          {previewExtensions.length > 0 && (
            <div className="flex flex-wrap gap-1 sm:col-span-3">
              {previewExtensions.map((type, index) => (
                <Badge key={`${type}-${index}`} tone="blue">
                  .{type}
                </Badge>
              ))}
            </div>
          )}
          <PpdbField label="Ukuran Maksimal (KB)" required error={errorOf('max_size_kb')}>
            <Input
              type="number"
              min={1}
              value={form.max_size_kb}
              error={errorOf('max_size_kb')}
              placeholder="500"
              onChange={(e) => set('max_size_kb', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Ukuran Minimal (KB)" error={errorOf('min_size_kb')} hint="Kosong = tanpa batas bawah.">
            <Input
              type="number"
              min={0}
              value={form.min_size_kb}
              error={errorOf('min_size_kb')}
              onChange={(e) => set('min_size_kb', e.target.value)}
            />
          </PpdbField>
          <PpdbField label="Urutan" error={errorOf('order')}>
            <Input
              type="number"
              min={0}
              value={form.order}
              error={errorOf('order')}
              onChange={(e) => set('order', e.target.value)}
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
            Wajib diunggah
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

export function PpdbDocumentRequirementsPage() {
  const queryClient = useQueryClient();
  const { options: periodOptions, firstPeriodId, isInitialLoading } = usePpdbPeriodOptions();
  const [picked, setPicked] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [programFilter, setProgramFilter] = useState<number | ''>('');
  const [waveFilter, setWaveFilter] = useState<number | ''>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PpdbDocumentRequirement | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<PpdbDocumentRequirement | null>(null);

  const periodId = picked === '' ? firstPeriodId : picked;

  const listQuery = useQuery({
    queryKey: [
      'ppdb',
      'document-requirements',
      'list',
      periodId,
      page,
      search,
      programFilter,
      waveFilter,
    ],
    enabled: periodId !== '',
    queryFn: () =>
      ppdbDocumentRequirementsApi.list({
        period_id: periodId,
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        program_id: programFilter === '' ? undefined : programFilter,
        wave_id: waveFilter === '' ? undefined : waveFilter,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'document-requirements'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'periods'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PpdbDocumentRequirementPayload) =>
      editing
        ? ppdbDocumentRequirementsApi.update(editing.id, payload)
        : ppdbDocumentRequirementsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Syarat dokumen diperbarui' : 'Syarat dokumen dibuat');
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
    mutationFn: (id: number) => ppdbDocumentRequirementsApi.remove(id),
    onSuccess: () => {
      toast.success('Syarat dokumen dihapus');
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
  const requiredCount = rows.filter((row) => row.required && row.active).length;

  const openCreate = () => {
    setEditing(null);
    setServerErrors({});
    setFormOpen(true);
  };

  const openEdit = (row: PpdbDocumentRequirement) => {
    setEditing(row);
    setServerErrors({});
    setFormOpen(true);
  };

  if (!isInitialLoading && periodOptions.length === 0) {
    return (
      <div className="space-y-4">
        <PpdbPageHeader
          title="Persyaratan Dokumen"
          description="Daftar berkas yang harus diunggah pendaftar. Buat periode lebih dulu."
        />
        <PpdbNoPeriodState />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <PpdbPageHeader
        title="Persyaratan Dokumen"
        description="Syarat dapat berlaku umum atau hanya untuk program/gelombang tertentu. Berkas ditolak bila format atau ukuran tidak sesuai."
        actions={
          <>
            <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="ppdb.document.manage">
              <Button onClick={openCreate} disabled={periodId === ''}>
                <Plus className="h-4 w-4" /> Tambah Syarat
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
            placeholder="Cari kode atau nama dokumen..."
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

      {listQuery.isLoading ? (
        <PpdbLoadingCard label="Memuat syarat dokumen..." />
      ) : listQuery.isError ? (
        <PpdbErrorCard error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada syarat dokumen"
            description="Tanpa syarat dokumen, pendaftar dapat langsung mengirim formulir tanpa melampirkan berkas apa pun."
            action={
              <Can perm="ppdb.document.manage">
                <Button size="sm" className="mt-2" onClick={openCreate} disabled={periodId === ''}>
                  <Plus className="h-4 w-4" /> Tambah Syarat
                </Button>
              </Can>
            }
          />
        </Card>
      ) : (
        <Card>
          <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
            <Paperclip className="h-3.5 w-3.5" />
            {rows.length} syarat pada halaman ini, {requiredCount} wajib diunggah.
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Dokumen</th>
                  <th className="px-4 py-2 font-medium">Format</th>
                  <th className="px-4 py-2 font-medium">Batas Ukuran</th>
                  <th className="px-4 py-2 font-medium">Cakupan</th>
                  <th className="px-4 py-2 font-medium">Sifat</th>
                  <th className="px-4 py-2 font-medium">Urutan</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">{row.name}</div>
                      {row.description && (
                        <div className="mt-0.5 max-w-xs text-xs text-slate-400">{row.description}</div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex max-w-[10rem] flex-wrap gap-1">
                        {extensionsOf(row).map((type, index) => (
                          <Badge key={`${type}-${index}`} tone="blue">
                            .{type.toLowerCase()}
                          </Badge>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      <div>
                        maks <span className="font-medium text-slate-800">{formatKb(row.max_size_kb)}</span>
                      </div>
                      <div className="text-slate-400">
                        {row.min_size_kb ? `min ${formatKb(row.min_size_kb)}` : 'tanpa batas bawah'}
                      </div>
                    </td>
                    <td className="px-4 py-2">
                      <PpdbScopeBadge
                        programId={row.program_id}
                        programName={row.program?.name}
                        waveId={row.wave_id}
                        waveName={row.wave?.name}
                      />
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <PpdbRequiredBadge required={row.required} />
                        <PpdbActiveBadge active={row.active} />
                      </div>
                    </td>
                    <td className="px-4 py-2 text-slate-700">{row.order}</td>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-1">
                        <Can perm="ppdb.document.manage">
                          <Button variant="ghost" size="icon" title="Edit" onClick={() => openEdit(row)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Hapus" onClick={() => setDeleting(row)}>
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

      <RequirementFormModal
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
        title="Hapus Syarat Dokumen"
        message={`Hapus "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Berkas yang sudah diunggah pendaftar biasanya membuat backend menolak penghapusan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
