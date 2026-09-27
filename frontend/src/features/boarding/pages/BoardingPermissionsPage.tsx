import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LogIn, LogOut, Pencil, Plus, Send, ThumbsDown, ThumbsUp, XCircle } from 'lucide-react';
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
import { useStudentOptions } from '@/hooks/useLookupOptions';
import { DetailRow, FormField, fmtDateTime } from '../components/BoardingUi';
import { permissionsApi } from '../api/boardingApi';
import { PERMISSION_STATUS_LABELS, PERMISSION_TYPE_LABELS, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingPermission } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));

function statusTone(status: string): 'green' | 'amber' | 'red' | 'blue' | 'gray' {
  switch (status) {
    case 'approved':
    case 'returned':
      return 'green';
    case 'submitted':
      return 'amber';
    case 'out':
      return 'blue';
    case 'rejected':
    case 'expired':
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

function CreateModal({
  open,
  onClose,
  editing,
}: {
  open: boolean;
  onClose: () => void;
  editing: BoardingPermission | null;
}) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [type, setType] = useState('out');
  const [destination, setDestination] = useState('');
  const [reason, setReason] = useState('');
  const [startAt, setStartAt] = useState('');
  const [expectedReturnAt, setExpectedReturnAt] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        student_id: editing ? undefined : (studentId as number),
        permission_type: type,
        destination,
        reason,
        start_at: startAt,
        expected_return_at: expectedReturnAt,
        notes,
      };
      return editing
        ? permissionsApi.update(editing.id, payload as never)
        : permissionsApi.create(payload as never);
    },
    onSuccess: () => {
      toast.success('Izin tersimpan');
      qc.invalidateQueries({ queryKey: ['boarding', 'permissions'] });
      closeNow();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const closeNow = () => {
    setStudentId('');
    setDestination('');
    setReason('');
    setStartAt('');
    setExpectedReturnAt('');
    setNotes('');
    setErrors({});
    onClose();
  };

  return (
    <Modal
      open={open}
      onClose={closeNow}
      title={editing ? 'Edit Izin Keluar' : 'Ajukan Izin Keluar'}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={closeNow}>Batal</Button>
          <Button onClick={() => save.mutate()} disabled={save.isPending}>
            {save.isPending ? 'Menyimpan...' : 'Simpan (Draft)'}
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Santri" error={errors.student_id}>
          <SearchSelect
            options={studentOptions}
            value={editing ? editing.student_id : studentId}
            onChange={(v) => setStudentId(toId(v))}
            disabled={!!editing}
            placeholder="Cari santri..."
          />
        </FormField>
        <FormField label="Jenis Izin" error={errors.permission_type}>
          <Select value={type} onChange={(e) => setType(e.target.value)}>
            {toOptions(PERMISSION_TYPE_LABELS).map((o) => (
              <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tujuan" error={errors.destination}>
          <Input value={destination} onChange={(e) => setDestination(e.target.value)} placeholder="mis. Rumah Sakit" />
        </FormField>
        <FormField label="Alasan" error={errors.reason}>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Alasan keluar" />
        </FormField>
        <FormField label="Jam Berangkat" error={errors.start_at}>
          <Input type="datetime-local" value={startAt} onChange={(e) => setStartAt(e.target.value)} />
        </FormField>
        <FormField label="Perkiraan Kembali" error={errors.expected_return_at}>
          <Input type="datetime-local" value={expectedReturnAt} onChange={(e) => setExpectedReturnAt(e.target.value)} />
        </FormField>
        <FormField label="Catatan" error={errors.notes} className="sm:col-span-2">
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function DetailModal({
  permission,
  onClose,
}: {
  permission: BoardingPermission | null;
  onClose: () => void;
}) {
  return (
    <Modal open={!!permission} onClose={onClose} title={`Izin — ${permission?.student?.full_name ?? ''}`} size="lg"
      footer={<Button variant="outline" onClick={onClose}>Tutup</Button>}
    >
      {permission && (
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <DetailRow label="Jenis">{permission.permission_type_label}</DetailRow>
            <DetailRow label="Status">
              <Badge tone={statusTone(permission.status)}>{permission.status_label}</Badge>
            </DetailRow>
            <DetailRow label="Tujuan">{permission.destination ?? '—'}</DetailRow>
            <DetailRow label="Alasan">{permission.reason ?? '—'}</DetailRow>
            <DetailRow label="Berangkat">{fmtDateTime(permission.start_at)}</DetailRow>
            <DetailRow label="Perkiraan Kembali">{fmtDateTime(permission.expected_return_at)}</DetailRow>
            <DetailRow label="Check Out">{fmtDateTime(permission.checked_out_at)}</DetailRow>
            <DetailRow label="Check In">{fmtDateTime(permission.checked_in_at)}</DetailRow>
            {permission.late_return && <DetailRow label="Pulang"><Badge tone="red">Terlambat</Badge></DetailRow>}
          </div>
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
              Riwayat Persetujuan ({permission.current_step}/{permission.required_steps})
            </p>
            {permission.approvals.length === 0 ? (
              <p className="text-sm text-slate-500">Belum ada persetujuan.</p>
            ) : (
              <ul className="space-y-1 text-sm text-slate-600">
                {permission.approvals.map((a) => (
                  <li key={a.id} className="flex justify-between border-b border-slate-50 py-1">
                    <span>
                      Langkah {a.step}: {a.action_label} — {a.acted_by_name ?? '—'}
                      {a.note ? ` (${a.note})` : ''}
                    </span>
                    <span className="text-xs text-slate-400">{fmtDateTime(a.acted_at)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}

function RejectModal({
  permission,
  onClose,
}: {
  permission: BoardingPermission | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [note, setNote] = useState('');

  const reject = useMutation({
    mutationFn: () => permissionsApi.reject(permission!.id, note || undefined),
    onSuccess: () => {
      toast.success('Izin ditolak');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setNote('');
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal open={!!permission} onClose={onClose} title="Tolak Izin"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Batal</Button>
          <Button variant="danger" disabled={reject.isPending} onClick={() => reject.mutate()}>
            {reject.isPending ? 'Menolak...' : 'Tolak'}
          </Button>
        </div>
      }
    >
      <FormField label="Alasan Penolakan">
        <Textarea value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </Modal>
  );
}

export function BoardingPermissionsPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [overdue, setOverdue] = useState(false);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<BoardingPermission | null>(null);
  const [detail, setDetail] = useState<BoardingPermission | null>(null);
  const [rejecting, setRejecting] = useState<BoardingPermission | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'permissions', page, search, status, overdue],
    queryFn: () =>
      permissionsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: { ...(status ? { status } : {}), ...(overdue ? { overdue: 1 } : {}) },
      }),
  });

  // aksi alur (submit/approve/checkOut/checkIn/cancel) — satu mutasi generik
  const flow = useMutation({
    mutationFn: ({ id, op }: { id: number; op: 'submit' | 'approve' | 'checkOut' | 'checkIn' | 'cancel' }) =>
      permissionsApi[op](id),
    onSuccess: (_res, vars) => {
      toast.success(
        vars.op === 'submit' ? 'Izin diajukan'
        : vars.op === 'approve' ? 'Izin disetujui'
        : vars.op === 'checkOut' ? 'Santri dicatat keluar'
        : vars.op === 'checkIn' ? 'Santri kembali'
        : 'Izin dibatalkan',
      );
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = (listQuery.data?.rows ?? []) as BoardingPermission[];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Izin Keluar Santri</h1>
          <p className="text-sm text-slate-500">Pengajuan, persetujuan, dan check-out/check-in santri yang keluar asrama.</p>
        </div>
        <Can perm="boarding.permission.manage">
          <Button onClick={() => { setEditing(null); setCreating(true); }}>
            <Plus className="h-4 w-4" /> Ajukan Izin
          </Button>
        </Can>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {toOptions(PERMISSION_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Cari">
            <Input value={search} placeholder="Nama / NIS / tujuan..." onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </FormField>
          <label className="flex items-center gap-2 pb-2 pt-6 text-sm text-slate-700">
            <input type="checkbox" checked={overdue} onChange={(e) => { setOverdue(e.target.checked); setPage(1); }} className="h-4 w-4" />
            Hanya yang terlambat
          </label>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat izin...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada izin keluar" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Jenis / Tujuan</th>
                  <th className="px-4 py-2 font-medium">Jendela Waktu</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-64 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <button className="font-medium text-slate-800 hover:underline" onClick={() => setDetail(p)}>
                        {p.student?.full_name ?? `Santri #${p.student_id}`}
                      </button>
                      <p className="text-xs text-slate-400">{p.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {p.permission_type_label}
                      <p className="text-xs text-slate-400">{p.destination ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {fmtDateTime(p.start_at)}
                      <p>s/d {fmtDateTime(p.expected_return_at)}</p>
                      {p.is_overdue && <p className="font-medium text-red-600">Terlambat!</p>}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={statusTone(p.status)}>{p.status_label}</Badge>
                      <p className="text-xs text-slate-400">
                        langkah {p.current_step}/{p.required_steps}
                      </p>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="boarding.permission.manage">
                          {p.can_submit && (
                            <Button size="sm" variant="outline" title="Ajukan" disabled={flow.isPending} onClick={() => flow.mutate({ id: p.id, op: 'submit' })}>
                              <Send className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {p.can_check_out && (
                            <Button size="sm" variant="outline" title="Check out" disabled={flow.isPending} onClick={() => flow.mutate({ id: p.id, op: 'checkOut' })}>
                              <LogOut className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {p.can_check_in && (
                            <Button size="sm" variant="outline" title="Check in" disabled={flow.isPending} onClick={() => flow.mutate({ id: p.id, op: 'checkIn' })}>
                              <LogIn className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {p.can_cancel && (
                            <Button size="sm" variant="ghost" title="Batalkan" disabled={flow.isPending} onClick={() => flow.mutate({ id: p.id, op: 'cancel' })}>
                              <XCircle className="h-3.5 w-3.5 text-slate-400" />
                            </Button>
                          )}
                          {p.status === 'draft' && (
                            <Button size="sm" variant="ghost" title="Edit" onClick={() => { setEditing(p); setCreating(true); }}>
                              <Pencil className="h-3.5 w-3.5" />
                            </Button>
                          )}
                        </Can>
                        <Can perm="boarding.permission.approve">
                          {p.can_approve && (
                            <Button size="sm" title="Setujui" disabled={flow.isPending} onClick={() => flow.mutate({ id: p.id, op: 'approve' })}>
                              <ThumbsUp className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {p.can_reject && (
                            <Button size="sm" variant="outline" title="Tolak" onClick={() => setRejecting(p)}>
                              <ThumbsDown className="h-3.5 w-3.5 text-red-500" />
                            </Button>
                          )}
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

      <CreateModal open={creating} editing={editing} onClose={() => { setCreating(false); setEditing(null); }} />
      <DetailModal permission={detail} onClose={() => setDetail(null)} />
      <RejectModal permission={rejecting} onClose={() => setRejecting(null)} />
    </div>
  );
}
