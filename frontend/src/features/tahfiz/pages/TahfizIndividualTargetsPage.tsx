import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Target, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { individualTargetsApi } from '../api/tahfizApi';
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
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import {
  useParticipantOptions,
  useProgramOptions,
  useSurahOptions,
} from '../hooks/useTahfizOptions';
import { TARGET_PERIOD_LABELS, TARGET_UNIT_LABELS } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizIndividualTarget,
  TahfizIndividualTargetPayload,
  TahfizTargetPeriod,
  TahfizTargetUnit,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const rangeText = (start: number | null, end: number | null): string => {
  if (start === null && end === null) return '—';
  return `${start ?? '—'}–${end ?? '—'}`;
};

/* ============================ MODAL FORM ============================ */

interface FormState {
  participant_id: number | '';
  enrollment_id: number | '';
  period: TahfizTargetPeriod | '';
  target_type: TahfizTargetUnit | '';
  surah_id: number | '';
  ayah_start: number | '';
  ayah_end: number | '';
  juz: number | '';
  page_start: number | '';
  page_end: number | '';
  value: number | '';
  unit: string;
  effective_from: string;
  effective_until: string;
  notes: string;
  is_active: boolean;
}

const emptyForm: FormState = {
  participant_id: '',
  enrollment_id: '',
  period: 'monthly',
  target_type: 'ayat',
  surah_id: '',
  ayah_start: '',
  ayah_end: '',
  juz: '',
  page_start: '',
  page_end: '',
  value: '',
  unit: '',
  effective_from: '',
  effective_until: '',
  notes: '',
  is_active: true,
};

function IndividualTargetFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: TahfizIndividualTarget | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizIndividualTargetPayload) => void;
}) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: surahOptions } = useSurahOptions();

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setValues({
        participant_id: editing.participant_id ?? '',
        enrollment_id: editing.enrollment_id ?? '',
        period: editing.period,
        target_type: editing.target_type,
        surah_id: editing.surah_id ?? '',
        ayah_start: editing.ayah_start ?? '',
        ayah_end: editing.ayah_end ?? '',
        juz: editing.juz ?? '',
        page_start: editing.page_start ?? '',
        page_end: editing.page_end ?? '',
        value: editing.value ?? '',
        unit: editing.unit ?? '',
        effective_from: editing.effective_from ?? '',
        effective_until: editing.effective_until ?? '',
        notes: editing.notes ?? '',
        is_active: editing.is_active,
      });
    } else {
      setValues({ ...emptyForm });
    }
  }, [open, editing]);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const copy = { ...prev };
      delete copy[key];
      return copy;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.participant_id) local.participant_id = 'Peserta wajib dipilih';
    if (!values.period) local.period = 'Periode wajib dipilih';
    if (!values.target_type) local.target_type = 'Jenis target wajib dipilih';

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const payload: TahfizIndividualTargetPayload = {
      participant_id: values.participant_id,
      enrollment_id: values.enrollment_id,
      period: values.period as TahfizTargetPeriod,
      target_type: values.target_type as TahfizTargetUnit,
      surah_id: values.surah_id,
      ayah_start: values.ayah_start,
      ayah_end: values.ayah_end,
      juz: values.juz,
      page_start: values.page_start,
      page_end: values.page_end,
      value: values.value,
      unit: values.unit,
      effective_from: values.effective_from,
      effective_until: values.effective_until,
      notes: values.notes,
      is_active: values.is_active,
    };

    onSubmit(payload);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Target Individual' : 'Tambah Target Individual'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="individual-target-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="individual-target-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label required>Peserta</Label>
            <SearchSelect
              options={participantOptions}
              value={values.participant_id}
              error={err('participant_id')}
              onChange={(v) => set('participant_id', toId(v))}
              placeholder="-- Pilih Peserta --"
            />
          </div>
          <div>
            <Label required>Periode</Label>
            <Select
              value={values.period}
              error={err('period')}
              onChange={(e) => set('period', e.target.value as TahfizTargetPeriod | '')}
            >
              <option value="">-- Pilih Periode --</option>
              {(Object.keys(TARGET_PERIOD_LABELS) as TahfizTargetPeriod[]).map((key) => (
                <option key={key} value={key}>
                  {TARGET_PERIOD_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label required>Jenis Target</Label>
            <Select
              value={values.target_type}
              error={err('target_type')}
              onChange={(e) => set('target_type', e.target.value as TahfizTargetUnit | '')}
            >
              {(Object.keys(TARGET_UNIT_LABELS) as TahfizTargetUnit[]).map((key) => (
                <option key={key} value={key}>
                  {TARGET_UNIT_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>

          <div>
            <Label>Surah</Label>
            <SearchSelect
              options={surahOptions}
              value={values.surah_id}
              error={err('surah_id')}
              onChange={(v) => set('surah_id', toId(v))}
              placeholder="-- Tidak ada --"
            />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Ayat Mulai</Label>
              <Input
                type="number"
                min={1}
                value={values.ayah_start}
                error={err('ayah_start')}
                onChange={(e) => set('ayah_start', toId(e.target.value))}
              />
            </div>
            <div>
              <Label>Ayat Selesai</Label>
              <Input
                type="number"
                min={1}
                value={values.ayah_end}
                error={err('ayah_end')}
                onChange={(e) => set('ayah_end', toId(e.target.value))}
              />
            </div>
          </div>

          <div>
            <Label>Juz</Label>
            <Input
              type="number"
              min={1}
              max={30}
              value={values.juz}
              error={err('juz')}
              onChange={(e) => set('juz', toId(e.target.value))}
            />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Halaman Mulai</Label>
              <Input
                type="number"
                min={1}
                value={values.page_start}
                error={err('page_start')}
                onChange={(e) => set('page_start', toId(e.target.value))}
              />
            </div>
            <div>
              <Label>Halaman Selesai</Label>
              <Input
                type="number"
                min={1}
                value={values.page_end}
                error={err('page_end')}
                onChange={(e) => set('page_end', toId(e.target.value))}
              />
            </div>
          </div>

          <div>
            <Label>Nilai Target</Label>
            <Input
              type="number"
              min={0}
              value={values.value}
              error={err('value')}
              onChange={(e) => set('value', toId(e.target.value))}
            />
          </div>
          <div>
            <Label>Satuan</Label>
            <Input
              value={values.unit}
              error={err('unit')}
              placeholder="mis. ayat/hari"
              onChange={(e) => set('unit', e.target.value)}
            />
          </div>

          <div>
            <Label>Berlaku Dari</Label>
            <Input
              type="date"
              value={values.effective_from}
              error={err('effective_from')}
              onChange={(e) => set('effective_from', e.target.value)}
            />
          </div>
          <div>
            <Label>Berlaku Sampai</Label>
            <Input
              type="date"
              value={values.effective_until}
              error={err('effective_until')}
              onChange={(e) => set('effective_until', e.target.value)}
            />
          </div>

          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Textarea
              value={values.notes}
              error={err('notes')}
              placeholder="Catatan target (opsional)"
              onChange={(e) => set('notes', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={values.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
              />
              Aktif
            </label>
          </div>
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Target individual per peserta.
 *
 * Target individual menimpa target halaqah dan program (prioritas:
 * individual → halaqah → program).
 */
export function TahfizIndividualTargetsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [programId, setProgramId] = useState<number | ''>('');
  const [period, setPeriod] = useState<TahfizTargetPeriod | ''>('');
  const [isActive, setIsActive] = useState<'1' | '0' | ''>('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizIndividualTarget | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizIndividualTarget | null>(null);

  const { options: participantOptions } = useParticipantOptions();
  const { options: programOptions } = useProgramOptions();

  const filters: Record<string, string | number> = {};
  if (participantId) filters.participant_id = participantId;
  if (programId) filters.program_id = programId;
  if (period) filters.period = period;
  if (isActive !== '') filters.is_active = Number(isActive);

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'individual-targets', page, filters],
    queryFn: () =>
      individualTargetsApi.list({
        page,
        per_page: 15,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'individual-targets'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizIndividualTargetPayload) =>
      editing
        ? individualTargetsApi.update(editing.id, payload)
        : individualTargetsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Target individual diperbarui' : 'Target individual dibuat');
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
    mutationFn: (id: number) => individualTargetsApi.remove(id),
    onSuccess: () => {
      toast.success('Target individual dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Target Individual</h1>
          <p className="text-sm text-slate-500">
            Target per peserta yang menimpa target halaqah dan program.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm={['tahfiz.create', 'tahfiz.update']}>
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
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Peserta</label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              onChange={(v) => {
                setParticipantId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua peserta --"
            />
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Periode</label>
            <Select
              value={period}
              onChange={(e) => {
                setPeriod(e.target.value as TahfizTargetPeriod | '');
                setPage(1);
              }}
            >
              <option value="">Semua periode</option>
              {(Object.keys(TARGET_PERIOD_LABELS) as TahfizTargetPeriod[]).map((key) => (
                <option key={key} value={key}>
                  {TARGET_PERIOD_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status Aktif</label>
            <Select
              value={isActive}
              onChange={(e) => {
                setIsActive(e.target.value as '1' | '0' | '');
                setPage(1);
              }}
            >
              <option value="">Semua</option>
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
            title="Belum ada target individual"
            description="Tambahkan target khusus untuk peserta tertentu."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Periode</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Surah</th>
                  <th className="px-4 py-2 font-medium">Ayat</th>
                  <th className="px-3 py-2 text-center font-medium">Juz</th>
                  <th className="px-4 py-2 font-medium">Halaman</th>
                  <th className="px-4 py-2 font-medium">Nilai</th>
                  <th className="px-4 py-2 font-medium">Masa Berlaku</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {row.participant?.name ?? `#${row.participant_id}`}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.period_label}</td>
                    <td className="px-4 py-2 text-slate-600">
                      {TARGET_UNIT_LABELS[row.target_type] ?? row.target_type}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.surah?.name ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">
                      {rangeText(row.ayah_start, row.ayah_end)}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">{row.juz ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">
                      {rangeText(row.page_start, row.page_end)}
                    </td>
                    <td className="px-4 py-2 text-slate-700">
                      {row.value ?? '—'} {row.unit ?? ''}
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
                      <Can
                        perm={['tahfiz.update', 'tahfiz.delete']}
                        fallback={<span className="text-xs text-slate-400">—</span>}
                      >
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
                      </Can>
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
        Target individual menimpa target halaqah dan program (prioritas: individual → halaqah →
        program).
      </div>

      <IndividualTargetFormModal
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
        title="Hapus Target Individual"
        message={`Hapus target individual untuk ${
          deleting?.participant?.name ?? 'peserta'
        }? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}