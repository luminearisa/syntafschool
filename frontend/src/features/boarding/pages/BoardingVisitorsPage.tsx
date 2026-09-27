import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LogIn, LogOut, Plus, Users, XCircle } from 'lucide-react';
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
import { DetailRow, FormField, fmtDate, fmtDateTime } from '../components/BoardingUi';
import { visitorsApi } from '../api/boardingApi';
import { VISITOR_STATUS_LABELS, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingVisitor } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));

function statusTone(status: string): 'green' | 'amber' | 'red' | 'blue' | 'gray' {
  switch (status) {
    case 'visiting':
      return 'blue';
    case 'waiting':
      return 'amber';
    case 'finished':
      return 'green';
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

function todayStr(): string {
  return new Date().toISOString().slice(0, 10);
}

function CreateModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [visitorName, setVisitorName] = useState('');
  const [identityNumber, setIdentityNumber] = useState('');
  const [phone, setPhone] = useState('');
  const [relationship, setRelationship] = useState('');
  const [studentId, setStudentId] = useState<number | ''>('');
  const [visitDate, setVisitDate] = useState(todayStr());
  const [purpose, setPurpose] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const save = useMutation({
    mutationFn: () =>
      visitorsApi.create({
        visitor_name: visitorName,
        identity_number: identityNumber,
        phone,
        relationship,
        student_id: studentId,
        visit_date: visitDate,
        purpose,
        notes,
      }),
    onSuccess: () => {
      toast.success('Kunjungan dicatat');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const close = () => {
    setVisitorName('');
    setIdentityNumber('');
    setPhone('');
    setRelationship('');
    setStudentId('');
    setVisitDate(todayStr());
    setPurpose('');
    setNotes('');
    setErrors({});
    onClose();
  };

  return (
    <Modal open={open} onClose={close} title="Catat Kunjungan" size="lg">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Nama tamu" required error={errors.visitor_name?.[0]}>
          <Input value={visitorName} onChange={(e) => setVisitorName(e.target.value)} placeholder="Nama lengkap" />
        </FormField>
        <FormField label="Nomor identitas (KTP/SIM)" error={errors.identity_number?.[0]}>
          <Input value={identityNumber} onChange={(e) => setIdentityNumber(e.target.value)} />
        </FormField>
        <FormField label="Telepon" error={errors.phone?.[0]}>
          <Input value={phone} onChange={(e) => setPhone(e.target.value)} />
        </FormField>
        <FormField label="Hubungan dengan santri" error={errors.relationship?.[0]}>
          <Input value={relationship} onChange={(e) => setRelationship(e.target.value)} placeholder="Ayah, ibu, wali..." />
        </FormField>
        <FormField label="Santri yang dikunjungi" required error={errors.student_id?.[0]}>
          <SearchSelect options={studentOptions} value={studentId} onChange={(v) => setStudentId(toId(v))} placeholder="Cari santri..." />
        </FormField>
        <FormField label="Tanggal kunjungan" required error={errors.visit_date?.[0]}>
          <Input type="date" value={visitDate} onChange={(e) => setVisitDate(e.target.value)} />
        </FormField>
      </div>
      <FormField label="Keperluan" className="mt-3" error={errors.purpose?.[0]}>
        <Input value={purpose} onChange={(e) => setPurpose(e.target.value)} placeholder="Misal: mengantarkan makanan" />
      </FormField>
      <FormField label="Catatan" className="mt-3" error={errors.notes?.[0]}>
        <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={close}>Batal</Button>
        <Button onClick={() => save.mutate()} disabled={save.isPending || !visitorName || studentId === '' || !visitDate}>
          {save.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Simpan
        </Button>
      </div>
    </Modal>
  );
}

function CheckOutModal({ visitor, onClose }: { visitor: BoardingVisitor | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [notes, setNotes] = useState('');

  const checkOut = useMutation({
    mutationFn: () => visitorsApi.checkOut(visitor!.id, { notes: notes || undefined }),
    onSuccess: () => {
      toast.success('Tamu dicatat pulang');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setNotes('');
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (!visitor) return null;

  return (
    <Modal open={!!visitor} onClose={onClose} title={`Pulangkan tamu: ${visitor.visitor_name}`}>
      <p className="mb-3 text-sm text-slate-500">
        Check-in: <span className="font-medium text-slate-700">{fmtDateTime(visitor.check_in_at ?? '')}</span>
      </p>
      <FormField label="Catatan">
        <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button onClick={() => checkOut.mutate()} disabled={checkOut.isPending}>
          {checkOut.isPending ? <Spinner /> : <LogOut className="h-4 w-4" />} Konfirmasi Pulang
        </Button>
      </div>
    </Modal>
  );
}

function DetailModal({ visitor, onClose }: { visitor: BoardingVisitor | null; onClose: () => void }) {
  if (!visitor) return null;
  const v = visitor;

  return (
    <Modal open={!!visitor} onClose={onClose} title="Detail Kunjungan">
      <div className="space-y-1.5 text-sm">
        <DetailRow label="Nama tamu" value={v.visitor_name} />
        <DetailRow label="Identitas" value={v.identity_number ?? '-'} />
        <DetailRow label="Telepon" value={v.phone ?? '-'} />
        <DetailRow label="Hubungan" value={v.relationship ?? '-'} />
        <DetailRow label="Santri" value={`${v.student?.full_name ?? `#${v.student_id}`}${v.student?.nis ? ` (${v.student.nis})` : ''}`} />
        <DetailRow label="Tanggal" value={fmtDate(v.visit_date)} />
        <DetailRow label="Keperluan" value={v.purpose ?? '-'} />
        <DetailRow label="Status" value={v.status_label} />
        <DetailRow label="Check-in" value={v.check_in_at ? fmtDateTime(v.check_in_at) : '-'} />
        <DetailRow label="Check-out" value={v.check_out_at ? fmtDateTime(v.check_out_at) : '-'} />
        <DetailRow label="Durasi" value={v.duration_minutes !== null ? `${v.duration_minutes} menit` : '-'} />
        <DetailRow label="Dicatat oleh" value={v.recorded_by_name ?? '-'} />
        <DetailRow label="Catatan" value={v.notes ?? '-'} />
      </div>
      <div className="mt-4 flex justify-end">
        <Button variant="ghost" onClick={onClose}>Tutup</Button>
      </div>
    </Modal>
  );
}

export function BoardingVisitorsPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [visitDate, setVisitDate] = useState('');
  const [creating, setCreating] = useState(false);
  const [detail, setDetail] = useState<BoardingVisitor | null>(null);
  const [checkingOut, setCheckingOut] = useState<BoardingVisitor | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'visitors', page, search, status, visitDate],
    queryFn: () =>
      visitorsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: { ...(status ? { status } : {}), ...(visitDate ? { visit_date: visitDate } : {}) },
      }),
  });

  const presentQuery = useQuery({
    queryKey: ['boarding', 'visitors', 'present'],
    queryFn: () => visitorsApi.present(),
  });

  const flow = useMutation({
    mutationFn: ({ id, op }: { id: number; op: 'checkIn' | 'cancel' }) => visitorsApi[op](id),
    onSuccess: (_res, vars) => {
      toast.success(vars.op === 'checkIn' ? 'Tamu disambut (masuk)' : 'Kunjungan dibatalkan');
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta;
  const present = (presentQuery.data ?? []) as BoardingVisitor[];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kunjungan Tamu</h1>
          <p className="text-sm text-slate-500">Buku tamu digital asrama: catat, sambut, dan pulangkan tamu (§40).</p>
        </div>
        <Can perm="boarding.visitor.manage">
          <Button onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" /> Catat Kunjungan
          </Button>
        </Can>
      </div>

      <Card className="p-4">
        <div className="mb-2 flex items-center gap-2 text-sm font-medium text-slate-700">
          <Users className="h-4 w-4" /> Sedang di area asrama ({present.length})
        </div>
        {present.length === 0 ? (
          <p className="text-sm text-slate-400">Tidak ada tamu saat ini.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {present.map((v) => (
              <button
                key={v.id}
                className="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs text-blue-700 hover:bg-blue-100"
                onClick={() => setDetail(v)}
              >
                {v.visitor_name} → {v.student?.full_name ?? '-'} · {fmtDateTime(v.check_in_at ?? '')}
              </button>
            ))}
          </div>
        )}
      </Card>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {toOptions(VISITOR_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Tanggal kunjungan">
            <Input type="date" value={visitDate} onChange={(e) => { setVisitDate(e.target.value); setPage(1); }} />
          </FormField>
          <FormField label="Cari">
            <Input value={search} placeholder="Nama tamu / santri / identitas..." onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </FormField>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat kunjungan...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada kunjungan" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Tamu</th>
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Tanggal / Waktu</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-48 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((v) => (
                  <tr key={v.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <button className="font-medium text-slate-800 hover:underline" onClick={() => setDetail(v)}>
                        {v.visitor_name}
                      </button>
                      <p className="text-xs text-slate-400">{v.relationship ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {v.student?.full_name ?? '-'}
                      <p className="text-xs text-slate-400">{v.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {fmtDate(v.visit_date)}
                      <p>{v.check_in_at ? `masuk ${fmtDateTime(v.check_in_at)}` : ''}{v.check_out_at ? ` · keluar ${fmtDateTime(v.check_out_at)}` : ''}</p>
                    </td>
                    <td className="px-4 py-2"><Badge tone={statusTone(v.status)}>{v.status_label}</Badge></td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="boarding.visitor.manage">
                          {v.can_check_in && (
                            <Button size="sm" variant="outline" title="Sambut tamu" disabled={flow.isPending} onClick={() => flow.mutate({ id: v.id, op: 'checkIn' })}>
                              <LogIn className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {v.can_check_out && (
                            <Button size="sm" title="Pulangkan tamu" onClick={() => setCheckingOut(v)}>
                              <LogOut className="h-3.5 w-3.5" />
                            </Button>
                          )}
                          {v.can_cancel && (
                            <Button size="sm" variant="ghost" title="Batalkan" disabled={flow.isPending} onClick={() => flow.mutate({ id: v.id, op: 'cancel' })}>
                              <XCircle className="h-3.5 w-3.5 text-red-500" />
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
      <CheckOutModal visitor={checkingOut} onClose={() => setCheckingOut(null)} />
      <DetailModal visitor={detail} onClose={() => setDetail(null)} />
    </div>
  );
}
