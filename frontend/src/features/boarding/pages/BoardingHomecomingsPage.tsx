import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, LogIn, LogOut, Plus, Send, ThumbsDown, XCircle } from 'lucide-react';
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
import { DetailRow, FormField, fmtDate } from '../components/BoardingUi';
import { homecomingsApi } from '../api/boardingApi';
import { HOMECOMING_STATUS_LABELS, HOMECOMING_TYPE_LABELS, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingHomecoming } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));

function statusTone(status: string): 'green' | 'amber' | 'red' | 'blue' | 'brand' | 'gray' {
  switch (status) {
    case 'approved':
      return 'green';
    case 'submitted':
      return 'amber';
    case 'out':
      return 'brand';
    case 'returned':
      return 'blue';
    case 'rejected':
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

function CreateModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [type, setType] = useState('weekend');
  const [homeDate, setHomeDate] = useState('');
  const [returnDate, setReturnDate] = useState('');
  const [transport, setTransport] = useState('');
  const [pickedUpBy, setPickedUpBy] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const save = useMutation({
    mutationFn: () =>
      homecomingsApi.create({
        student_id: studentId,
        type,
        home_date: homeDate,
        return_date: returnDate,
        transport,
        picked_up_by: pickedUpBy,
        notes,
      }),
    onSuccess: () => {
      toast.success('Kepulangan tercatat');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const close = () => {
    setStudentId('');
    setHomeDate('');
    setReturnDate('');
    setTransport('');
    setPickedUpBy('');
    setNotes('');
    setErrors({});
    onClose();
  };

  return (
    <Modal open={open} onClose={close} title="Catat Kepulangan" size="lg">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Santri" required error={errors.student_id?.[0]}>
          <SearchSelect options={studentOptions} value={studentId} onChange={(v) => setStudentId(toId(v))} placeholder="Cari santri..." />
        </FormField>
        <FormField label="Jenis kepulangan" required error={errors.type?.[0]}>
          <Select value={type} onChange={(e) => setType(e.target.value)}>
            {toOptions(HOMECOMING_TYPE_LABELS).map((o) => (
              <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tanggal pulang" required error={errors.home_date?.[0]}>
          <Input type="date" value={homeDate} onChange={(e) => setHomeDate(e.target.value)} />
        </FormField>
        <FormField label="Rencana kembali" required error={errors.return_date?.[0]}>
          <Input type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
        </FormField>
        <FormField label="Transportasi" error={errors.transport?.[0]}>
          <Input value={transport} onChange={(e) => setTransport(e.target.value)} placeholder="Mobil, bus, ..." />
        </FormField>
        <FormField label="Dijemput oleh" error={errors.picked_up_by?.[0]}>
          <Input value={pickedUpBy} onChange={(e) => setPickedUpBy(e.target.value)} placeholder="Nama penjemput" />
        </FormField>
      </div>
      <FormField label="Catatan" className="mt-3" error={errors.notes?.[0]}>
        <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={close}>Batal</Button>
        <Button
          onClick={() => save.mutate()}
          disabled={save.isPending || studentId === '' || !homeDate || !returnDate}
        >
          {save.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Simpan
        </Button>
      </div>
    </Modal>
  );
}

function CheckInModal({ homecoming, onClose }: { homecoming: BoardingHomecoming | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [actualReturnDate, setActualReturnDate] = useState('');
  const [returnedWith, setReturnedWith] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const checkIn = useMutation({
    mutationFn: () =>
      homecomingsApi.checkIn(homecoming!.id, {
        actual_return_date: actualReturnDate || undefined,
        returned_with: returnedWith || undefined,
        notes: notes || undefined,
      }),
    onSuccess: () => {
      toast.success('Santri dicatat kembali ke asrama');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setActualReturnDate('');
      setReturnedWith('');
      setNotes('');
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  if (!homecoming) return null;

  return (
    <Modal open={!!homecoming} onClose={onClose} title={`Check-in ${homecoming.student?.full_name ?? ''}`}>
      <p className="mb-3 text-sm text-slate-500">
        Rencana kembali: <span className="font-medium text-slate-700">{fmtDate(homecoming.return_date)}</span>
      </p>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Tanggal kembali actual" error={errors.actual_return_date?.[0]}>
          <Input type="date" value={actualReturnDate} onChange={(e) => setActualReturnDate(e.target.value)} />
        </FormField>
        <FormField label="Diantar oleh" error={errors.returned_with?.[0]}>
          <Input value={returnedWith} onChange={(e) => setReturnedWith(e.target.value)} />
        </FormField>
      </div>
      <FormField label="Catatan" className="mt-3" error={errors.notes?.[0]}>
        <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button onClick={() => checkIn.mutate()} disabled={checkIn.isPending}>
          {checkIn.isPending ? <Spinner /> : <LogIn className="h-4 w-4" />} Konfirmasi Kembali
        </Button>
      </div>
    </Modal>
  );
}

function RejectModal({ homecoming, onClose }: { homecoming: BoardingHomecoming | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [note, setNote] = useState('');

  const reject = useMutation({
    mutationFn: () => homecomingsApi.reject(homecoming!.id, note || undefined),
    onSuccess: () => {
      toast.success('Kepulangan ditolak');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setNote('');
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (!homecoming) return null;

  return (
    <Modal open={!!homecoming} onClose={onClose} title="Tolak Kepulangan">
      <FormField label="Alasan penolakan">
        <Textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Alasan (disimpan di riwayat)..." />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button variant="danger" onClick={() => reject.mutate()} disabled={reject.isPending}>
          {reject.isPending ? <Spinner /> : <XCircle className="h-4 w-4" />} Tolak
        </Button>
      </div>
    </Modal>
  );
}

function DetailModal({ homecoming, onClose }: { homecoming: BoardingHomecoming | null; onClose: () => void }) {
  if (!homecoming) return null;
  const h = homecoming;

  return (
    <Modal open={!!homecoming} onClose={onClose} title="Detail Kepulangan" size="lg">
      <div className="space-y-1.5 text-sm">
        <DetailRow label="Santri" value={`${h.student?.full_name ?? `#${h.student_id}`}${h.student?.nis ? ` (${h.student.nis})` : ''}`} />
        <DetailRow label="Jenis" value={h.type_label} />
        <DetailRow label="Status" value={h.status_label} />
        <DetailRow label="Tanggal pulang" value={fmtDate(h.home_date)} />
        <DetailRow label="Rencana kembali" value={fmtDate(h.return_date)} />
        <DetailRow label="Kembali actual" value={h.actual_return_date ? fmtDate(h.actual_return_date) : '-'} />
        {h.late_return && <DetailRow label="Catatan" value={<span className="font-medium text-red-600">Kembali terlambat (§31)</span>} />}
        <DetailRow label="Transportasi" value={h.transport ?? '-'} />
        <DetailRow label="Dijemput oleh" value={h.picked_up_by ?? '-'} />
        <DetailRow label="Diantar kembali oleh" value={h.returned_with ?? '-'} />
        <DetailRow label="Diajukan oleh" value={h.requested_by_name ?? '-'} />
        <DetailRow label="Disetujui oleh" value={h.approved_by_name ?? '-'} />
        <DetailRow label="Check-out oleh" value={h.checked_out_by_name ?? '-'} />
        <DetailRow label="Check-in oleh" value={h.checked_in_by_name ?? '-'} />
        <DetailRow label="Catatan" value={h.notes ?? '-'} />
      </div>
      <div className="mt-4 flex justify-end">
        <Button variant="ghost" onClick={onClose}>Tutup</Button>
      </div>
    </Modal>
  );
}

export function BoardingHomecomingsPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [overdue, setOverdue] = useState(false);
  const [creating, setCreating] = useState(false);
  const [detail, setDetail] = useState<BoardingHomecoming | null>(null);
  const [checkingIn, setCheckingIn] = useState<BoardingHomecoming | null>(null);
  const [rejecting, setRejecting] = useState<BoardingHomecoming | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'homecomings', page, search, status, type, overdue],
    queryFn: () =>
      homecomingsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: {
          ...(status ? { status } : {}),
          ...(type ? { type } : {}),
          ...(overdue ? { overdue: 1 } : {}),
        },
      }),
  });

  const flow = useMutation({
    mutationFn: ({ id, op }: { id: number; op: 'submit' | 'approve' | 'checkOut' | 'cancel' }) =>
      homecomingsApi[op](id),
    onSuccess: (_res, vars) => {
      toast.success(
        vars.op === 'submit' ? 'Kepulangan diajukan'
        : vars.op === 'approve' ? 'Kepulangan disetujui'
        : vars.op === 'checkOut' ? 'Santri dicatat pulang'
        : 'Kepulangan dibatalkan',
      );
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kepulangan Santri</h1>
          <p className="text-sm text-slate-500">Pulang akhir pekan, libur semester/Lebaran, dan check-in kembali (§30–§31).</p>
        </div>
        <Can perm="boarding.homecoming.manage">
          <Button onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" /> Catat Kepulangan
          </Button>
        </Can>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {toOptions(HOMECOMING_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Jenis">
            <Select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
              <option value="">Semua jenis</option>
              {toOptions(HOMECOMING_TYPE_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Cari">
            <Input value={search} placeholder="Nama / NIS / penjemput..." onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </FormField>
          <label className="flex items-center gap-2 pb-2 pt-6 text-sm text-slate-700">
            <input type="checkbox" checked={overdue} onChange={(e) => { setOverdue(e.target.checked); setPage(1); }} className="h-4 w-4" />
            Hanya terlambat kembali
          </label>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat kepulangan...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada kepulangan" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Pulang / Kembali</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-64 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((h) => (
                  <tr key={h.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <button className="font-medium text-slate-800 hover:underline" onClick={() => setDetail(h)}>
                        {h.student?.full_name ?? `Santri #${h.student_id}`}
                      </button>
                      <p className="text-xs text-slate-400">{h.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">{h.type_label}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {fmtDate(h.home_date)}
                      <p>
                        kembali: {h.actual_return_date ? `${fmtDate(h.actual_return_date)} (actual)` : fmtDate(h.return_date)}
                      </p>
                      {h.is_overdue && <p className="font-medium text-red-600">Belum kembali, lewat jadwal!</p>}
                      {h.late_return && !h.is_overdue && <p className="font-medium text-amber-600">Terlambat kembali</p>}
                    </td>
                    <td className="px-4 py-2"><Badge tone={statusTone(h.status)}>{h.status_label}</Badge></td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="boarding.homecoming.manage">
                          {h.can_submit && (
                            <Button size="sm" variant="outline" title="Ajukan" disabled={flow.isPending} onClick={() => flow.mutate({ id: h.id, op: 'submit' })}>
                              <Send className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {h.can_check_out && (
                            <Button size="sm" variant="outline" title="Catat berangkat pulang" disabled={flow.isPending} onClick={() => flow.mutate({ id: h.id, op: 'checkOut' })}>
                              <LogOut className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {h.can_check_in && (
                            <Button size="sm" title="Check-in kembali" onClick={() => setCheckingIn(h)}>
                              <LogIn className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {h.can_cancel && (
                            <Button size="sm" variant="ghost" title="Batalkan" disabled={flow.isPending} onClick={() => flow.mutate({ id: h.id, op: 'cancel' })}>
                              <XCircle className="h-3.5 w-3.5 text-red-500" />
                            </Button>
                          )}
                        </Can>
                        <Can perm="boarding.homecoming.approve">
                          {h.can_approve && (
                            <Button size="sm" variant="secondary" title="Setujui" disabled={flow.isPending} onClick={() => flow.mutate({ id: h.id, op: 'approve' })}>
                              <CheckCircle2 className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {h.can_reject && (
                            <Button size="sm" variant="outline" title="Tolak" onClick={() => setRejecting(h)}>
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

      <CreateModal open={creating} onClose={() => setCreating(false)} />
      <CheckInModal homecoming={checkingIn} onClose={() => setCheckingIn(null)} />
      <RejectModal homecoming={rejecting} onClose={() => setRejecting(null)} />
      <DetailModal homecoming={detail} onClose={() => setDetail(null)} />
    </div>
  );
}
