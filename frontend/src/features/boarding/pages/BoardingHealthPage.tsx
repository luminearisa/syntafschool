import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { HeartPulse, Pencil, Plus, ShieldOff, Trash2 } from 'lucide-react';
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
import { DetailRow, FormField, Tabs, fmtDate } from '../components/BoardingUi';
import { bedsApi, healthApi, roomsApi } from '../api/boardingApi';
import { HEALTH_STATUS_LABELS, labelOf, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingHealthRecord, BoardingPlacement, BoardingRoom } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));
const today = () => new Date().toISOString().slice(0, 10);

function healthTone(status: string): 'green' | 'amber' | 'red' | 'blue' | 'gray' {
  switch (status) {
    case 'reported':
      return 'amber';
    case 'being_treated':
      return 'red';
    case 'recovered':
      return 'green';
    case 'referred':
      return 'blue';
    default:
      return 'gray';
  }
}

const STATUS_LABELS_OPTIONS = toOptions(HEALTH_STATUS_LABELS);

function HealthRecordModal({
  open,
  onClose,
  editing,
}: {
  open: boolean;
  onClose: () => void;
  editing: BoardingHealthRecord | null;
}) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [condition, setCondition] = useState('');
  const [complaint, setComplaint] = useState('');
  const [action, setAction] = useState('');
  const [date, setDate] = useState(today());
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (!open) return;
    setStudentId(editing?.student_id ?? '');
    setCondition(editing?.condition ?? '');
    setComplaint(editing?.complaint ?? '');
    setAction(editing?.action ?? '');
    setDate(editing?.date ?? today());
    setNotes(editing?.notes ?? '');
    setErrors({});
  }, [open, editing]);

  const save = useMutation({
    mutationFn: () =>
      editing
        ? healthApi.update(editing.id, {
            student_id: editing.student_id,
            condition,
            complaint,
            action,
            date,
            notes,
          })
        : healthApi.create({ student_id: studentId, condition, complaint, action, date, notes }),
    onSuccess: () => {
      toast.success(editing ? 'Catatan diperbarui' : 'Catatan kesehatan dibuat');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal open={open} onClose={onClose} title={editing ? 'Koreksi Catatan Kesehatan' : 'Catat Kondisi Kesehatan (§39)'} size="lg">
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
        <FormField label="Tanggal observasi" required error={errors.date?.[0]}>
          <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </FormField>
        <FormField label="Kondisi / gejala" required error={errors.condition?.[0]}>
          <Input value={condition} onChange={(e) => setCondition(e.target.value)} placeholder="mis. demam, batuk 3 hari" />
        </FormField>
      </div>
      <FormField label="Keluhan (uraian)" className="mt-3" error={errors.complaint?.[0]}>
        <Textarea rows={2} value={complaint} onChange={(e) => setComplaint(e.target.value)} />
      </FormField>
      <FormField label="Tindakan awal" className="mt-3" error={errors.action?.[0]}>
        <Textarea rows={2} value={action} onChange={(e) => setAction(e.target.value)} placeholder="mis. obat paracetamol, kompres, observasi ke UKS" />
      </FormField>
      <FormField label="Catatan" className="mt-3" error={errors.notes?.[0]}>
        <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button
          onClick={() => save.mutate()}
          disabled={save.isPending || (!editing && studentId === '') || !condition || !date}
        >
          {save.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Simpan
        </Button>
      </div>
    </Modal>
  );
}

function StatusModal({
  record,
  onClose,
}: {
  record: BoardingHealthRecord | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [status, setStatus] = useState('');
  const [action, setAction] = useState('');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    if (record) {
      setStatus(record.allowed_next_statuses[0] ?? '');
      setAction(record.action ?? '');
      setNotes('');
    }
  }, [record]);

  const submit = useMutation({
    mutationFn: () => healthApi.updateStatus(record!.id, { status, action: action || undefined, notes: notes || undefined }),
    onSuccess: () => {
      toast.success('Status kesehatan diperbarui');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (!record) return null;

  return (
    <Modal open={!!record} onClose={onClose} title={`Ubah status: ${record.student?.full_name ?? `#${record.student_id}`}`}>
      <p className="mb-3 text-sm text-slate-500">
        Kondisi saat ini: <span className="font-medium text-slate-700">{record.condition ?? '-'}</span> · status{' '}
        <Badge tone={healthTone(record.status)}>{record.status_label}</Badge>
      </p>
      <FormField label="Status baru" required>
        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
          {STATUS_LABELS_OPTIONS.filter((o) => record.allowed_next_statuses.includes(String(o.value))).map((o) => (
            <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
          ))}
        </Select>
      </FormField>
      {record.allowed_next_statuses.length === 0 && (
        <p className="mt-1 text-xs text-slate-400">Tidak ada transisi lanjut untuk catatan ini.</p>
      )}
      <FormField label="Tindakan" className="mt-3">
        <Input value={action} onChange={(e) => setAction(e.target.value)} placeholder="mis. dirujuk ke RS" />
      </FormField>
      <FormField label="Catatan" className="mt-3">
        <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button onClick={() => submit.mutate()} disabled={submit.isPending || !status}>
          <Plus className="h-4 w-4" /> Perbarui
        </Button>
      </div>
    </Modal>
  );
}

function IsolateModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [roomId, setRoomId] = useState<number | ''>('');
  const [bedId, setBedId] = useState<number | ''>('');
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const roomsQuery = useQuery({
    queryKey: ['boarding', 'rooms', 'isolation'],
    queryFn: () => roomsApi.available({}),
    staleTime: 5 * 60 * 1000,
  });

  const isolationRooms = ((roomsQuery.data ?? []) as BoardingRoom[]).filter((r) => r.room_type === 'isolation');

  const bedsQuery = useQuery({
    queryKey: ['boarding', 'beds', 'available', roomId],
    queryFn: () => bedsApi.available({ room_id: roomId }),
    enabled: typeof roomId === 'number' && roomId > 0,
  });

  const save = useMutation({
    mutationFn: () =>
      healthApi.isolate({
        student_id: studentId,
        room_id: roomId,
        bed_id: bedId === '' ? undefined : bedId,
        reason,
        notes,
      }),
    onSuccess: () => {
      toast.success('Santri dipindahkan ke ruang isolasi');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal open={open} onClose={onClose} title="Pindahkan ke Isolasi (§39)">
      <div className="space-y-3">
        <FormField label="Santri" required error={errors.student_id?.[0]}>
          <SearchSelect options={studentOptions} value={studentId} onChange={(v) => setStudentId(toId(v))} placeholder="Cari santri..." />
        </FormField>
        <FormField label="Kamar isolasi" required error={errors.room_id?.[0]}>
          <Select value={String(roomId)} onChange={(e) => { setRoomId(toId(e.target.value)); setBedId(''); }}>
            <option value="">-- Pilih kamar isolasi --</option>
            {isolationRooms.map((r) => (
              <option key={r.id} value={r.id}>
                {r.code}{r.name ? ` — ${r.name}` : ''} ({r.dormitory?.name ?? ''} · sisa {r.remaining_capacity ?? 0} bed)
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tempat tidur (opsional)" error={errors.bed_id?.[0]}>
          <Select value={String(bedId)} onChange={(e) => setBedId(toId(e.target.value))}>
            <option value="">Otomatis (bed tersedia pertama)</option>
            {(bedsQuery.data ?? []).map((b) => (
              <option key={b.id} value={b.id}>Bed {b.bed_number}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Alasan isolasi" required error={errors.reason?.[0]}>
          <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="mis. suspected scabies, perlu observasi terpisah" />
        </FormField>
        <FormField label="Catatan" error={errors.notes?.[0]}>
          <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button onClick={() => save.mutate()} disabled={save.isPending || studentId === '' || roomId === '' || reason.length < 3}>
          {save.isPending ? <Spinner /> : <HeartPulse className="h-4 w-4" />} Isolasi
        </Button>
      </div>
    </Modal>
  );
}

function ReleaseModal({ placement, onClose }: { placement: BoardingPlacement | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');

  const release = useMutation({
    mutationFn: () => healthApi.releaseIsolation(placement!.id, { reason: reason || undefined }),
    onSuccess: () => {
      toast.success('Santri dikembalikan dari isolasi');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setReason('');
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (!placement) return null;

  return (
    <Modal open={!!placement} onClose={onClose} title="Keluarkan dari Isolasi">
      <div className="mb-3 space-y-1.5 text-sm">
        <DetailRow label="Santri" value={placement.student?.full_name ?? `#${placement.student_id}`} />
        <DetailRow label="Kamar isolasi" value={`${placement.room?.code ?? ''}${placement.room?.name ? ` — ${placement.room.name}` : ''}`} />
      </div>
      <p className="mb-3 text-xs text-slate-500">
        Santri akan dipindahkan kembali ke kamar/bed asalnya (mutasi tercatat di riwayat penempatan).
      </p>
      <FormField label="Alasan / hasil">
        <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="mis. selesai perawatan 7 hari, dinyatakan sembuh" />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button onClick={() => release.mutate()} disabled={release.isPending}>
          {release.isPending ? <Spinner /> : <ShieldOff className="h-4 w-4" />} Kembalikan
        </Button>
      </div>
    </Modal>
  );
}

function RecordsTab() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [openOnly, setOpenOnly] = useState(false);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<BoardingHealthRecord | null>(null);
  const [statusTarget, setStatusTarget] = useState<BoardingHealthRecord | null>(null);
  const [deleting, setDeleting] = useState<BoardingHealthRecord | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'health', page, status, openOnly],
    queryFn: () =>
      healthApi.list({
        page,
        per_page: 15,
        filter: { ...(status ? { status } : {}), ...(openOnly ? { open_only: 1 } : {}) },
      }),
  });

  const remove = useMutation({
    mutationFn: (id: number) => healthApi.remove(id),
    onSuccess: () => {
      toast.success('Catatan dihapus');
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
        <Can perm="boarding.health.manage">
          <Button onClick={() => { setEditing(null); setCreating(true); }}>
            <Plus className="h-4 w-4" /> Catat Kondisi
          </Button>
        </Can>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {STATUS_LABELS_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <label className="flex items-center gap-2 pb-2 pt-6 text-sm text-slate-700">
            <input type="checkbox" checked={openOnly} onChange={(e) => { setOpenOnly(e.target.checked); setPage(1); }} className="h-4 w-4" />
            Hanya aktif (belum selesai)
          </label>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex justify-center py-14"><Spinner /></div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada catatan kesehatan" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Kondisi</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Dicatat oleh</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((h) => (
                  <tr key={h.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <p className="font-medium text-slate-800">{h.student?.full_name ?? `Santri #${h.student_id}`}</p>
                      <p className="text-xs text-slate-400">{h.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-700">
                      {h.condition ?? '-'}
                      {h.action && <p className="max-w-xs truncate text-xs text-slate-400" title={h.action}>Tindakan: {h.action}</p>}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{fmtDate(h.date)}</td>
                    <td className="px-4 py-2"><Badge tone={healthTone(h.status)}>{h.status_label || labelOf(HEALTH_STATUS_LABELS, h.status)}</Badge></td>
                    <td className="px-4 py-2 text-xs text-slate-500">{h.recorded_by_name ?? '-'}</td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.health.manage">
                        <div className="flex gap-1">
                          {h.is_open && (
                            <Button size="sm" variant="outline" title="Ubah status" onClick={() => setStatusTarget(h)}>
                              <HeartPulse className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          <Button size="sm" variant="ghost" title="Koreksi" onClick={() => { setEditing(h); setCreating(true); }}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button size="sm" variant="ghost" title="Hapus" onClick={() => setDeleting(h)}>
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
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <HealthRecordModal open={creating} editing={editing} onClose={() => { setCreating(false); setEditing(null); }} />
      <StatusModal record={statusTarget} onClose={() => setStatusTarget(null)} />
      <ConfirmDialog
        open={!!deleting}
        title="Hapus catatan kesehatan"
        message={`Hapus catatan "${deleting?.condition}" untuk ${deleting?.student?.full_name ?? ''}?`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}

function IsolationsTab() {
  const [isolating, setIsolating] = useState(false);
  const [releasing, setReleasing] = useState<BoardingPlacement | null>(null);

  const isolationsQuery = useQuery({
    queryKey: ['boarding', 'health', 'isolations'],
    queryFn: () => healthApi.isolations(),
  });

  const rows = (isolationsQuery.data ?? []) as BoardingPlacement[];

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Can perm="boarding.health.manage">
          <Button onClick={() => setIsolating(true)}>
            <HeartPulse className="h-4 w-4" /> Pindahkan ke Isolasi
          </Button>
        </Can>
      </div>
      {isolationsQuery.isLoading ? (
        <Card><div className="flex justify-center py-14"><Spinner /></div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Tidak ada santri di ruang isolasi" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Asrama / Kamar</th>
                  <th className="px-4 py-2 font-medium">Bed</th>
                  <th className="px-4 py-2 font-medium">Mulai</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <p className="font-medium text-slate-800">{p.student?.full_name ?? `Santri #${p.student_id}`}</p>
                      <p className="text-xs text-slate-400">{p.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {p.dormitory?.name ?? '-'} · {p.room?.code}{p.room?.name ? ` — ${p.room.name}` : ''}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{p.bed?.bed_number ?? '-'}</td>
                    <td className="px-4 py-2 text-slate-600">{fmtDate(p.start_date)}</td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.health.manage">
                        <Button size="sm" variant="outline" onClick={() => setReleasing(p)}>
                          <ShieldOff className="h-3.5 w-3.5" /> Kembalikan
                        </Button>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      <IsolateModal open={isolating} onClose={() => setIsolating(false)} />
      <ReleaseModal placement={releasing} onClose={() => setReleasing(null)} />
    </div>
  );
}

export function BoardingHealthPage() {
  const [tab, setTab] = useState('records');

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Kesehatan & Isolasi</h1>
        <p className="text-sm text-slate-500">Catatan sakit santri, rujukan, dan penanganan ruang isolasi (§39).</p>
      </div>
      <Tabs
        tabs={[
          { key: 'records', label: 'Catatan Kesehatan' },
          { key: 'isolations', label: 'Ruang Isolasi' },
        ]}
        active={tab}
        onChange={setTab}
      />
      {tab === 'records' && <RecordsTab />}
      {tab === 'isolations' && <IsolationsTab />}
    </div>
  );
}
