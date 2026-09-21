import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Target, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { targetsApi } from '../api/tahfizApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import {
  useHalaqahOptions,
  useProgramOptions,
  useSurahOptions,
} from '../hooks/useTahfizOptions';
import { TARGET_PERIOD_LABELS, TARGET_UNIT_LABELS } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizTarget,
  TahfizTargetPayload,
  TahfizTargetPeriod,
  TahfizTargetUnit,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const SCOPE_OPTIONS = [
  { value: 'program', label: 'Program' },
  { value: 'halaqah', label: 'Halaqah' },
];

const PERIOD_OPTIONS = (Object.keys(TARGET_PERIOD_LABELS) as TahfizTargetPeriod[]).map((key) => ({
  value: key,
  label: TARGET_PERIOD_LABELS[key],
}));

const TYPE_OPTIONS = (Object.keys(TARGET_UNIT_LABELS) as TahfizTargetUnit[]).map((key) => ({
  value: key,
  label: TARGET_UNIT_LABELS[key],
}));

/* ============================ MODAL FORM TARGET ============================ */

function TargetFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizTarget | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizTargetPayload) => void;
}) {
  const { options: programOptions } = useProgramOptions();
  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: surahOptions } = useSurahOptions();

  const [scope, setScope] = useState<'program' | 'halaqah'>('program');
  const [programId, setProgramId] = useState<number | ''>('');
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [surahId, setSurahId] = useState<number | ''>('');
  const [targetType, setTargetType] = useState<TahfizTargetUnit>('juz');
  const [value, setValue] = useState<number | ''>('');
  const [period, setPeriod] = useState<TahfizTargetPeriod>('daily');
  const [effectiveFrom, setEffectiveFrom] = useState('');
  const [effectiveUntil, setEffectiveUntil] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setScope(editing.scope);
      setProgramId(editing.program_id ?? '');
      setHalaqahId(editing.halaqah_id ?? '');
      setSurahId(editing.surah_id ?? '');
      setTargetType(editing.target_type);
      setValue(editing.value);
      setPeriod(editing.period);
      setEffectiveFrom(editing.effective_from ?? '');
      setEffectiveUntil(editing.effective_until ?? '');
      setIsActive(editing.is_active);
    } else {
      setScope('program');
      setProgramId('');
      setHalaqahId('');
      setSurahId('');
      setTargetType('juz');
      setValue('');
      setPeriod('daily');
      setEffectiveFrom('');
      setEffectiveUntil('');
      setIsActive(true);
    }
  }, [open, editing]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (scope === 'program' && !programId) local.program_id = 'Program wajib dipilih';
    if (scope === 'halaqah' && !halaqahId) local.halaqah_id = 'Halaqah wajib dipilih';
    if (!targetType) local.target_type = 'Satuan target wajib dipilih';
    if (value === '' || Number(value) < 0) local.value = 'Nilai target wajib diisi';
    if (!period) local.period = 'Periode wajib dipilih';
    if (effectiveFrom && effectiveUntil && effectiveUntil < effectiveFrom) {
      local.effective_until = 'Tanggal berakhir tidak boleh sebelum tanggal mulai';
    }
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      scope,
      program_id: scope === 'program' ? programId : '',
      halaqah_id: scope === 'halaqah' ? halaqahId : '',
      surah_id: surahId,
      target_type: targetType,
      value,
      period,
      is_active: isActive,
      effective_from: effectiveFrom,
      effective_until: effectiveUntil,
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Target' : 'Tambah Target'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="target-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="target-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Cakupan</Label>
            <Select
              value={scope}
              error={err('scope')}
              onChange={(e) => {
                setScope(e.target.value as 'program' | 'halaqah');
                setProgramId('');
                setHalaqahId('');
              }}
            >
              {SCOPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Periode</Label>
            <Select
              value={period}
              error={err('period')}
              onChange={(e) => setPeriod(e.target.value as TahfizTargetPeriod)}
            >
              {PERIOD_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          {scope === 'program' ? (
            <div>
              <Label required>Program</Label>
              <SearchSelect
                options={programOptions}
                value={programId}
                error={err('program_id')}
                onChange={(v) => setProgramId(toId(v))}
                placeholder="-- Pilih Program --"
              />
            </div>
          ) : (
            <div>
              <Label required>Halaqah</Label>
              <SearchSelect
                options={halaqahOptions}
                value={halaqahId}
                error={err('halaqah_id')}
                onChange={(v) => setHalaqahId(toId(v))}
                placeholder="-- Pilih Halaqah --"
              />
            </div>
          )}
          <div>
            <Label>Surat (opsional)</Label>
            <SearchSelect
              options={surahOptions}
              value={surahId}
              error={err('surah_id')}
              onChange={(v) => setSurahId(toId(v))}
              placeholder="-- Semua / opsional --"
            />
          </div>
          <div>
            <Label required>Satuan Target</Label>
            <Select
              value={targetType}
              error={err('target_type')}
              onChange={(e) => setTargetType(e.target.value as TahfizTargetUnit)}
            >
              {TYPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label required>Nilai Target</Label>
            <Input
              type="number"
              min={0}
              value={value}
              error={err('value')}
              onChange={(e) => setValue(e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Berlaku Mulai</Label>
            <Input
              type="date"
              value={effectiveFrom}
              error={err('effective_from')}
              onChange={(e) => setEffectiveFrom(e.target.value)}
            />
          </div>
          <div>
            <Label>Berlaku Hingga</Label>
            <Input
              type="date"
              value={effectiveUntil}
              error={err('effective_until')}
              onChange={(e) => setEffectiveUntil(e.target.value)}
            />
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
            />
            Target aktif
          </label>
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Target default hafalan pada scope program atau halaqah.
 *
 * Target per peserta dikelola terpisah (target individual) agar sebuah
 * halaqah tidak dipaksa memiliki target yang sama untuk semua anggota.
 */
export function TahfizTargetsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [scope, setScope] = useState<'program' | 'halaqah' | ''>('');
  const [programId, setProgramId] = useState<number | ''>('');
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [period, setPeriod] = useState<TahfizTargetPeriod | ''>('');
  const [isActive, setIsActive] = useState<'1' | '0' | ''>('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizTarget | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizTarget | null>(null);

  const { options: programOptions } = useProgramOptions();
  const { options: halaqahOptions } = useHalaqahOptions();

  const filters: Record<string, string | number> = {};
  if (scope) filters.scope = scope;
  if (programId) filters.program_id = programId;
  if (halaqahId) filters.halaqah_id = halaqahId;
  if (period) filters.period = period;
  if (isActive !== '') filters.is_active = Number(isActive);

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'targets', page, filters],
    queryFn: () =>
      targetsApi.list({
        page,
        per_page: 15,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'targets'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizTargetPayload) =>
      editing ? targetsApi.update(editing.id, payload) : targetsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Target diperbarui' : 'Target dibuat');
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
    mutationFn: (id: number) => targetsApi.remove(id),
    onSuccess: () => {
      toast.success('Target dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Target Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Target default hafalan pada scope program atau halaqah, per periode.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.create">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Target
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cakupan</label>
            <Select
              value={scope}
              onChange={(e) => {
                setScope(e.target.value as 'program' | 'halaqah' | '');
                setPage(1);
              }}
            >
              <option value="">Semua cakupan</option>
              {SCOPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
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
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Halaqah</label>
            <SearchSelect
              options={halaqahOptions}
              value={halaqahId}
              onChange={(v) => {
                setHalaqahId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua halaqah --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Periode</label>
            <Select
              value={period}
              onChange={(e) => {
                setPeriod(e.target.value as TahfizTargetPeriod | '');
                setPage(1);
              }}
            >
              <option value="">Semua periode</option>
              {PERIOD_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={isActive}
              onChange={(e) => {
                setIsActive(e.target.value as '1' | '0' | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              <option value="1">Aktif</option>
              <option value="0">Tidak Aktif</option>
            </Select>
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat target...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat target"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada target"
            description="Tetapkan target default untuk program atau halaqah."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Cakupan</th>
                  <th className="px-4 py-2 font-medium">Pemilik</th>
                  <th className="px-4 py-2 font-medium">Satuan</th>
                  <th className="px-3 py-2 text-center font-medium">Nilai</th>
                  <th className="px-4 py-2 font-medium">Periode</th>
                  <th className="px-4 py-2 font-medium">Masa Berlaku</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 text-slate-700">{row.scope_label ?? row.scope}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {(row.scope === 'program' ? row.program?.name : row.halaqah?.name) ?? '—'}
                      {row.surah?.name && (
                        <span className="ml-1 text-xs font-normal text-slate-500">
                          ({row.surah.name})
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-2 text-slate-700">
                      {row.target_type_label ?? TARGET_UNIT_LABELS[row.target_type] ?? row.target_type}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">{row.value}</td>
                    <td className="px-4 py-2 text-slate-700">
                      {row.period_label ?? TARGET_PERIOD_LABELS[row.period] ?? row.period}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      {row.effective_from ?? '—'} s.d. {row.effective_until ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={row.is_active ? 'green' : 'gray'}>
                        {row.is_active ? 'Aktif' : 'Tidak Aktif'}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="tahfiz.update">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Edit"
                            onClick={() => {
                              setEditing(row);
                              setServerErrors({});
                              setFormOpen(true);
                            }}
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                        </Can>
                        <Can perm="tahfiz.delete">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(row)}
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

      <div className="flex items-center gap-2 text-xs text-slate-400">
        <Target className="h-3.5 w-3.5" />
        Target scope program/halaqah berlaku untuk anggota; target individual diatur per peserta.
      </div>

      <TargetFormModal
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
        title="Hapus Target"
        message={`Hapus target ${
          deleting ? (deleting.scope === 'program' ? deleting.program?.name : deleting.halaqah?.name) : ''
        } (${deleting ? TARGET_PERIOD_LABELS[deleting.period] : ''})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}