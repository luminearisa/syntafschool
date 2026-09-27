import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Pencil, Plus, Search, ShieldAlert, Trash2, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useStudentOptions } from '@/hooks/useLookupOptions';
import {
  ConfigFormModal,
  DetailRow,
  FormField,
  StatCard,
  Tabs,
  defaultValues,
  fmtDate,
  type FormFieldConfig,
  type FormValues,
} from '../components/BoardingUi';
import { disciplinaryActionsApi, violationTypesApi, violationsApi } from '../api/boardingApi';
import { useViolationTypeOptions } from '../hooks/useBoardingOptions';
import {
  ACTION_STATUS_LABELS,
  DISCIPLINARY_ACTION_LABELS,
  VIOLATION_CATEGORY_LABELS,
  VIOLATION_SEVERITY_LABELS,
  VIOLATION_STATUS_LABELS,
  labelOf,
  toOptions,
} from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  BoardingDisciplinaryAction,
  BoardingPointsSummary,
  BoardingViolation,
  BoardingViolationType,
} from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));
const today = () => new Date().toISOString().slice(0, 10);

function violationStatusTone(status: string): 'green' | 'amber' | 'red' | 'gray' {
  if (status === 'open') return 'red';
  if (status === 'actioned') return 'amber';
  if (status === 'closed') return 'green';
  return 'gray';
}

/* ============================ TAB: PELANGGARAN ============================ */

function ViolationCreateModal({
  open,
  onClose,
  editing,
}: {
  open: boolean;
  onClose: () => void;
  editing: BoardingViolation | null;
}) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const { options: typeOptions } = useViolationTypeOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [typeId, setTypeId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [status, setStatus] = useState('open');
  const [narrative, setNarrative] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const save = useMutation({
    mutationFn: () =>
      editing
        ? violationsApi.update(editing.id, {
            student_id: editing.student_id,
            violation_type_id: editing.violation_type_id,
            date,
            status,
            narrative,
          })
        : violationsApi.create({ student_id: studentId, violation_type_id: typeId, date, status, narrative }),
    onSuccess: () => {
      toast.success(editing ? 'Pelanggaran diperbarui' : 'Pelanggaran dicatat');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const openFor = (v: BoardingViolation | null) => {
    setStudentId(v?.student_id ?? '');
    setTypeId(v?.violation_type_id ?? '');
    setDate(v?.date ?? today());
    setStatus(v?.status ?? 'open');
    setNarrative(v?.narrative ?? '');
    setErrors({});
  };

  useEffect(() => {
    if (open) openFor(editing);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editing]);

  return (
    <Modal
      open={open}
      onClose={() => { openFor(null); onClose(); }}
      title={editing ? 'Koreksi Pelanggaran' : 'Catat Pelanggaran'}
      size="lg"
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Santri" required error={errors.student_id?.[0]}>
          <SearchSelect
            options={studentOptions}
            value={editing ? editing.student_id : studentId}
            onChange={(v) => setStudentId(toId(v))}
            disabled={!!editing}
            placeholder="Cari santri..."
          />
        </FormField>
        <FormField label="Jenis pelanggaran" required error={errors.violation_type_id?.[0]}>
          <Select value={String(typeId)} onChange={(e) => setTypeId(toId(e.target.value))}>
            <option value="">-- Pilih jenis --</option>
            {typeOptions.map((o) => (
              <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tanggal" required error={errors.date?.[0]}>
          <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </FormField>
        {editing && (
          <FormField label="Status" error={errors.status?.[0]}>
            <Select value={status} onChange={(e) => setStatus(e.target.value)}>
              {toOptions(VIOLATION_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
        )}
      </div>
      <FormField label="Kronologi / catatan" className="mt-3" error={errors.narrative?.[0]}>
        <Textarea rows={3} value={narrative} onChange={(e) => setNarrative(e.target.value)} placeholder="Apa yang terjadi, di mana, siapa yang melihat..." />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={() => { openFor(null); onClose(); }}>Batal</Button>
        <Button onClick={() => save.mutate()} disabled={save.isPending || (!editing && (studentId === '' || typeId === ''))}>
          {save.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Simpan
        </Button>
      </div>
    </Modal>
  );
}

function PointsPanel() {
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');

  const pointsQuery = useQuery({
    queryKey: ['boarding', 'points', studentId],
    queryFn: () => violationsApi.points(studentId as number),
    enabled: typeof studentId === 'number' && studentId > 0,
  });

  const s = pointsQuery.data as BoardingPointsSummary | undefined;

  return (
    <Card className="p-4">
      <div className="mb-3 flex items-center gap-2 text-sm font-medium text-slate-700">
        <Search className="h-4 w-4" /> Rekap poin santri
      </div>
      <div className="max-w-sm">
        <SearchSelect options={studentOptions} value={studentId} onChange={(v) => setStudentId(toId(v))} placeholder="Cari santri untuk cek poin..." />
      </div>
      {pointsQuery.isFetching && s === undefined && <div className="mt-3 flex justify-center py-4"><Spinner /></div>}
      {s && (
        <div className="mt-4 space-y-4">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatCard label={`Total ${s.point_label}`} value={s.total_points} tone={s.total_points > 0 ? 'red' : 'green'} />
            <StatCard label="Pelanggaran" value={s.violation_count} />
            <StatCard label="Masih terbuka" value={s.open_count} tone={s.open_count > 0 ? 'amber' : 'default'} />
            <StatCard
              label="Ambang berikutnya"
              value={s.next_threshold ? s.next_threshold.remaining : '—'}
              hint={s.next_threshold ? s.next_threshold.label : 'Semua ambang terlewati'}
            />
          </div>
          <div>
            <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Ambang tindakan (§35–§37)</p>
            <div className="flex flex-wrap gap-2">
              {s.thresholds.map((t) => (
                <Badge key={t.key} tone={t.reached ? 'red' : 'gray'}>
                  {t.label} ({t.threshold}) {t.reached ? '✓ tercapai' : ''}
                </Badge>
              ))}
            </div>
          </div>
          {s.by_category.length > 0 && (
            <div>
              <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Per kategori</p>
              <ul className="space-y-1 text-sm text-slate-600">
                {s.by_category.map((c) => (
                  <li key={c.category} className="flex justify-between border-b border-slate-50">
                    <span>{c.category_label}</span>
                    <span className="font-medium">{c.points} poin · {c.count} kasus</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {s.recent.length > 0 && (
            <div>
              <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Pelanggaran terakhir</p>
              <ul className="space-y-1 text-sm text-slate-600">
                {s.recent.map((r) => (
                  <li key={r.id} className="flex justify-between border-b border-slate-50">
                    <span>{fmtDate(r.date)} — {r.type_name ?? '-'}</span>
                    <span className="font-medium">+{r.points}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

function ViolationsTab() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [category, setCategory] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<BoardingViolation | null>(null);
  const [deleting, setDeleting] = useState<BoardingViolation | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'violations', page, status, category, from, to],
    queryFn: () =>
      violationsApi.list({
        page,
        per_page: 15,
        filter: {
          ...(status ? { status } : {}),
          ...(category ? { category } : {}),
          ...(from ? { from } : {}),
          ...(to ? { to } : {}),
        },
      }),
  });

  const update = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<{ status: string }> }) =>
      violationsApi.update(id, { ...payload } as never),
    onSuccess: () => {
      toast.success('Status pelanggaran diperbarui');
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const remove = useMutation({
    mutationFn: (id: number) => violationsApi.remove(id),
    onSuccess: () => {
      toast.success('Pelanggaran dihapus');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Can perm="boarding.violation.manage">
          <Button onClick={() => { setEditing(null); setCreating(true); }}>
            <Plus className="h-4 w-4" /> Catat Pelanggaran
          </Button>
        </Can>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {toOptions(VIOLATION_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Kategori">
            <Select value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }}>
              <option value="">Semua kategori</option>
              {toOptions(VIOLATION_CATEGORY_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Dari tanggal">
            <Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
          </FormField>
          <FormField label="Sampai tanggal">
            <Input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
          </FormField>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat pelanggaran...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada pelanggaran" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Poin</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-56 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((v) => (
                  <tr key={v.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <p className="font-medium text-slate-800">{v.student?.full_name ?? `Santri #${v.student_id}`}</p>
                      <p className="text-xs text-slate-400">{v.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {v.type_name ?? '-'}
                      <p className="text-xs text-slate-400">
                        {v.category ? labelOf(VIOLATION_CATEGORY_LABELS, v.category) : ''}
                        {v.severity ? ` · ${labelOf(VIOLATION_SEVERITY_LABELS, v.severity)}` : ''}
                      </p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">{fmtDate(v.date)}</td>
                    <td className="px-4 py-2 font-medium text-slate-700">{v.points}</td>
                    <td className="px-4 py-2">
                      <Badge tone={violationStatusTone(v.status)}>{v.status_label}</Badge>
                      {v.disciplinary_actions_count > 0 && (
                        <p className="text-xs text-slate-400">{v.disciplinary_actions_count} tindakan</p>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="boarding.violation.manage">
                          {v.can_mark_actioned && (
                            <Button
                              size="sm"
                              variant="outline"
                              title="Tandai ada tindakan"
                              disabled={update.isPending}
                              onClick={() => update.mutate({ id: v.id, payload: { status: 'actioned' } })}
                            >
                              <ShieldAlert className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {v.can_close && (
                            <Button
                              size="sm"
                              variant="secondary"
                              title="Tutup"
                              disabled={update.isPending}
                              onClick={() => update.mutate({ id: v.id, payload: { status: 'closed' } })}
                            >
                              <CheckCircle2 className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          <Button size="sm" variant="ghost" title="Koreksi" onClick={() => { setEditing(v); setCreating(true); }}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button size="sm" variant="ghost" title="Hapus" onClick={() => setDeleting(v)}>
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

      <ViolationCreateModal open={creating} editing={editing} onClose={() => { setCreating(false); setEditing(null); }} />
      <ConfirmDialog
        open={!!deleting}
        title="Hapus pelanggaran"
        message={`Hapus pelanggaran "${deleting?.type_name ?? ''}" untuk ${deleting?.student?.full_name ?? ''}? Riwayat poin akan berubah.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
      <PointsPanel />
    </div>
  );
}

/* ============================ TAB: JENIS PELANGGARAN (master) ============================ */

const VIOLATION_TYPE_FIELDS: FormFieldConfig[] = [
  { name: 'code', label: 'Kode', type: 'text', required: true, placeholder: 'mis. TERLAMBAT-SHALAT' },
  { name: 'name', label: 'Nama pelanggaran', type: 'text', required: true },
  { name: 'category', label: 'Kategori', type: 'select', options: toOptions(VIOLATION_CATEGORY_LABELS), required: true },
  { name: 'severity', label: 'Tingkat', type: 'select', options: toOptions(VIOLATION_SEVERITY_LABELS), required: true },
  { name: 'point', label: 'Poin', type: 'number', required: true },
  { name: 'description', label: 'Deskripsi', type: 'textarea', className: 'sm:col-span-2' },
  { name: 'is_active', label: 'Aktif', type: 'checkbox' },
];

function ViolationTypesTab() {
  const qc = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<BoardingViolationType | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<BoardingViolationType | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'violation-types'],
    queryFn: () => violationTypesApi.list({ per_page: 200 }),
  });

  const save = useMutation({
    mutationFn: () =>
      editing ? violationTypesApi.update(editing.id, values as never) : violationTypesApi.create(values as never),
    onSuccess: () => {
      toast.success(editing ? 'Jenis diperbarui' : 'Jenis dibuat');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setFormOpen(false);
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => violationTypesApi.remove(id),
    onSuccess: () => {
      toast.success('Jenis pelanggaran dihapus');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.rows ?? [];

  const openForm = (t: BoardingViolationType | null) => {
    setEditing(t);
    setErrors({});
    setValues(
      t
        ? defaultValues(VIOLATION_TYPE_FIELDS, t as unknown as Record<string, unknown>)
        : defaultValues(VIOLATION_TYPE_FIELDS),
    );
    setFormOpen(true);
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Can perm="boarding.violation.manage">
          <Button onClick={() => openForm(null)}>
            <Plus className="h-4 w-4" /> Tambah Jenis
          </Button>
        </Can>
      </div>
      {listQuery.isLoading ? (
        <Card><div className="flex justify-center py-14"><Spinner /></div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada jenis pelanggaran" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Kategori</th>
                  <th className="px-4 py-2 font-medium">Tingkat</th>
                  <th className="px-4 py-2 font-medium">Poin</th>
                  <th className="px-4 py-2 font-medium">Pemakaian</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((t) => (
                  <tr key={t.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 font-mono text-xs text-slate-500">{t.code}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">{t.name}</td>
                    <td className="px-4 py-2 text-slate-600">{t.category_label || labelOf(VIOLATION_CATEGORY_LABELS, t.category)}</td>
                    <td className="px-4 py-2"><Badge tone={t.severity === 'severe' ? 'red' : t.severity === 'moderate' ? 'amber' : 'gray'}>{t.severity_label || labelOf(VIOLATION_SEVERITY_LABELS, t.severity)}</Badge></td>
                    <td className="px-4 py-2 font-medium">{t.point}</td>
                    <td className="px-4 py-2 text-slate-600">{t.violations_count ?? 0}×</td>
                    <td className="px-4 py-2"><Badge tone={t.is_active ? 'green' : 'gray'}>{t.is_active ? 'Aktif' : 'Nonaktif'}</Badge></td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.violation.manage">
                        <div className="flex gap-1">
                          <Button size="sm" variant="ghost" title="Edit" onClick={() => openForm(t)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button size="sm" variant="ghost" title="Hapus" onClick={() => setDeleting(t)}>
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </div>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      <ConfigFormModal
        open={formOpen}
        title={editing ? 'Edit Jenis Pelanggaran' : 'Jenis Pelanggaran Baru'}
        fields={VIOLATION_TYPE_FIELDS}
        values={values}
        serverErrors={errors}
        submitting={save.isPending}
        onChange={setValues}
        onClose={() => setFormOpen(false)}
        onSubmit={() => save.mutate()}
      />
      <ConfirmDialog
        open={!!deleting}
        title="Hapus jenis pelanggaran"
        message={`Hapus "${deleting?.name}"? Jenis yang sudah dipakai mencatat pelanggaran tidak dapat dihapus.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}

/* ============================ TAB: TINDAKAN DISIPLINER ============================ */

function ActionStatusModal({
  action,
  target,
  onClose,
}: {
  action: BoardingDisciplinaryAction | null;
  target: 'completed' | 'cancelled' | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const submit = useMutation({
    mutationFn: () => disciplinaryActionsApi.status(action!.id, { status: target!, notes: notes || undefined }),
    onSuccess: () => {
      toast.success(target === 'completed' ? 'Tindakan ditandai selesai' : 'Tindakan dibatalkan');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setNotes('');
      setErrors({});
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  if (!action || !target) return null;

  return (
    <Modal open onClose={onClose} title={target === 'completed' ? 'Tandai Selesai' : 'Batalkan Tindakan'}>
      <div className="mb-3 space-y-1.5 text-sm">
        <DetailRow label="Santri" value={action.student?.full_name ?? `#${action.student_id}`} />
        <DetailRow label="Tindakan" value={action.action_label} />
      </div>
      <FormField
        label={target === 'completed' ? 'Hasil tindakan (wajib)' : 'Alasan pembatalan'}
        error={errors.notes}
      >
        <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button variant={target === 'completed' ? 'primary' : 'danger'} onClick={() => submit.mutate()} disabled={submit.isPending}>
          {submit.isPending ? <Spinner /> : null}
          {target === 'completed' ? 'Simpan selesai' : 'Batalkan'}
        </Button>
      </div>
    </Modal>
  );
}

function ActionsTab() {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [action, setAction] = useState('');
  const [creating, setCreating] = useState(false);
  const [studentId, setStudentId] = useState<number | ''>('');
  const [actionType, setActionType] = useState('warning');
  const [actionDate, setActionDate] = useState(today());
  const [officer, setOfficer] = useState('');
  const [actionNotes, setActionNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [statusTarget, setStatusTarget] = useState<{ row: BoardingDisciplinaryAction; target: 'completed' | 'cancelled' } | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'disciplinary-actions', page, status, action],
    queryFn: () =>
      disciplinaryActionsApi.list({
        page,
        per_page: 15,
        filter: { ...(status ? { status } : {}), ...(action ? { action } : {}) },
      }),
  });

  const save = useMutation({
    mutationFn: () =>
      disciplinaryActionsApi.create({
        student_id: studentId,
        action: actionType,
        date: actionDate,
        officer,
        notes: actionNotes,
      }),
    onSuccess: () => {
      toast.success('Tindakan dicatat');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setCreating(false);
      setStudentId('');
      setOfficer('');
      setActionNotes('');
      setErrors({});
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Can perm="boarding.violation.manage">
          <Button onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" /> Tindakan Manual
          </Button>
        </Can>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {toOptions(ACTION_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Jenis tindakan">
            <Select value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }}>
              <option value="">Semua jenis</option>
              {toOptions(DISCIPLINARY_ACTION_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex justify-center py-14"><Spinner /></div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada tindakan disipliner" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Tindakan</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Petugas</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => (
                  <tr key={a.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <p className="font-medium text-slate-800">{a.student?.full_name ?? `Santri #${a.student_id}`}</p>
                      <p className="text-xs text-slate-400">{a.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {a.action_label}
                      {a.is_auto && <Badge tone="gray">otomatis</Badge>}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{fmtDate(a.date)}</td>
                    <td className="px-4 py-2 text-slate-600">{a.officer ?? '-'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={a.status === 'completed' ? 'green' : a.status === 'cancelled' ? 'red' : 'amber'}>{a.status_label}</Badge>
                      {a.notes && <p className="max-w-xs truncate text-xs text-slate-400" title={a.notes}>{a.notes}</p>}
                    </td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.violation.manage">
                        <div className="flex gap-1">
                          {a.can_complete && (
                            <Button size="sm" variant="secondary" title="Tandai selesai" onClick={() => setStatusTarget({ row: a, target: 'completed' })}>
                              <CheckCircle2 className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {a.can_cancel && (
                            <Button size="sm" variant="ghost" title="Batalkan" onClick={() => setStatusTarget({ row: a, target: 'cancelled' })}>
                              <XCircle className="h-3.5 w-3.5 text-red-500" />
                            </Button>
                          )}
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

      <Modal open={creating} onClose={() => setCreating(false)} title="Tindakan Disipliner Manual (§38)">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField label="Santri" required error={errors.student_id?.[0]}>
            <SearchSelect options={studentOptions} value={studentId} onChange={(v) => setStudentId(toId(v))} placeholder="Cari santri..." />
          </FormField>
          <FormField label="Jenis tindakan" required error={errors.action?.[0]}>
            <Select value={actionType} onChange={(e) => setActionType(e.target.value)}>
              {toOptions(DISCIPLINARY_ACTION_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Tanggal" error={errors.date?.[0]}>
            <Input type="date" value={actionDate} onChange={(e) => setActionDate(e.target.value)} />
          </FormField>
          <FormField label="Petugas" error={errors.officer?.[0]}>
            <Input value={officer} onChange={(e) => setOfficer(e.target.value)} placeholder="Nama petugas" />
          </FormField>
        </div>
        <FormField label="Catatan" className="mt-3" error={errors.notes?.[0]}>
          <Textarea rows={3} value={actionNotes} onChange={(e) => setActionNotes(e.target.value)} />
        </FormField>
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setCreating(false)}>Batal</Button>
          <Button onClick={() => save.mutate()} disabled={save.isPending || studentId === ''}>
            {save.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Simpan
          </Button>
        </div>
      </Modal>

      <ActionStatusModal
        action={statusTarget?.row ?? null}
        target={statusTarget?.target ?? null}
        onClose={() => setStatusTarget(null)}
      />
    </div>
  );
}

/* ============================ PAGE ============================ */

export function BoardingViolationsPage() {
  const [tab, setTab] = useState('violations');

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Kedisiplinan Santri</h1>
        <p className="text-sm text-slate-500">Pelanggaran, poin, ambang tindakan otomatis (§35–§38), dan rekap poin per santri.</p>
      </div>
      <Tabs
        tabs={[
          { key: 'violations', label: 'Pelanggaran' },
          { key: 'types', label: 'Jenis Pelanggaran' },
          { key: 'actions', label: 'Tindakan Disipliner' },
        ]}
        active={tab}
        onChange={setTab}
      />
      {tab === 'violations' && <ViolationsTab />}
      {tab === 'types' && <ViolationTypesTab />}
      {tab === 'actions' && <ActionsTab />}
    </div>
  );
}
