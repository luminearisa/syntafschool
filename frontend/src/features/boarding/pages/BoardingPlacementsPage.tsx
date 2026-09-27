import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, History, Plus, RefreshCw, StopCircle } from 'lucide-react';
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
import { FormField, fmtDate } from '../components/BoardingUi';
import { bedsApi, placementsApi } from '../api/boardingApi';
import { useDormitoryOptions, useRoomOptions } from '../hooks/useBoardingOptions';
import { END_REASON_LABELS, PLACEMENT_STATUS_LABELS, labelOf } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingPlacement } from '@/types/boarding';

const toId = (v: string): number | '' => (v === '' ? '' : Number(v));

/** Opsi bed untuk satu kamar (hanya yang tersedia / belum aktif terisi). */
function useBedOptions(roomId: number | '') {
  return useQuery({
    queryKey: ['boarding', 'options', 'beds', roomId],
    enabled: roomId !== '',
    staleTime: 0,
    queryFn: async () => {
      const beds = await bedsApi.available({ room_id: roomId });
      return beds.map((b) => ({ value: b.id, label: `Bed ${b.bed_number}${b.label ? ` — ${b.label}` : ''}` }));
    },
  });
}

function AssignModal({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [roomId, setRoomId] = useState<number | ''>('');
  const [bedId, setBedId] = useState<number | ''>('');
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const bedQuery = useBedOptions(roomId);

  const save = useMutation({
    mutationFn: () =>
      placementsApi.create({
        student_id: studentId,
        room_id: roomId,
        bed_id: bedId === '' ? undefined : bedId,
        start_date: startDate,
        notes,
      }),
    onSuccess: () => {
      toast.success('Penempatan santri disimpan');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const reset = () => {
    setStudentId('');
    setRoomId('');
    setBedId('');
    setNotes('');
    setErrors({});
  };

  return (
    <Modal
      open={open}
      onClose={() => {
        reset();
        onClose();
      }}
      title="Tempatkan Santri di Asrama"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={() => { reset(); onClose(); }}>Batal</Button>
          <Button onClick={() => save.mutate()} disabled={save.isPending || !studentId || !roomId}>
            {save.isPending ? 'Menyimpan...' : 'Tempatkan'}
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Santri" error={errors.student_id}>
          <SearchSelect options={studentOptions} value={studentId} onChange={(v) => setStudentId(toId(String(v)))} placeholder="Cari santri..." />
        </FormField>
        <FormField label="Mulai Tanggal" error={errors.start_date}>
          <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
        </FormField>
        <FormField label="Kamar" error={errors.room_id}>
          <RoomSelect value={roomId} onChange={(v) => { setRoomId(v); setBedId(''); }} />
        </FormField>
        <FormField label="Tempat Tidur (opsional)" error={errors.bed_id}>
          <Select
            value={String(bedId ?? '')}
            onChange={(e) => setBedId(toId(e.target.value))}
            disabled={!roomId || bedQuery.isLoading}
          >
            <option value="">-- Tanpa bed tertentu --</option>
            {(bedQuery.data ?? []).map((o) => (
              <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Catatan" error={errors.notes} className="sm:col-span-2">
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function RoomSelect({ value, onChange }: { value: number | ''; onChange: (v: number | '') => void }) {
  const { options } = useRoomOptions();
  return (
    <SearchSelect
      options={options}
      value={value}
      onChange={(v) => onChange(toId(String(v)))}
      placeholder="-- Pilih kamar --"
    />
  );
}

function TransferModal({
  placement,
  onClose,
}: {
  placement: BoardingPlacement | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [roomId, setRoomId] = useState<number | ''>('');
  const [bedId, setBedId] = useState<number | ''>('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const bedQuery = useBedOptions(roomId);

  const move = useMutation({
    mutationFn: () =>
      placementsApi.transfer(placement!.id, {
        room_id: roomId,
        bed_id: bedId === '' ? undefined : bedId,
        reason: reason || undefined,
      }),
    onSuccess: () => {
      toast.success('Santri dipindahkan');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
      setRoomId('');
      setBedId('');
      setReason('');
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal
      open={!!placement}
      onClose={onClose}
      title={`Pindah Kamar — ${placement?.student?.full_name ?? ''}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Batal</Button>
          <Button onClick={() => move.mutate()} disabled={move.isPending || !roomId}>
            {move.isPending ? 'Memindahkan...' : 'Pindahkan'}
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <p className="text-sm text-slate-500">
          Saat ini: {placement?.dormitory?.name} / {placement?.room?.code}
          {placement?.bed ? ` / Bed ${placement.bed.bed_number}` : ''}
        </p>
        <FormField label="Kamar Tujuan" error={errors.room_id}>
          <RoomSelect value={roomId} onChange={(v) => { setRoomId(v); setBedId(''); }} />
        </FormField>
        <FormField label="Tempat Tidur (opsional)" error={errors.bed_id}>
          <Select value={String(bedId)} onChange={(e) => setBedId(toId(e.target.value))} disabled={!roomId}>
            <option value="">-- Tanpa bed tertentu --</option>
            {(bedQuery.data ?? []).map((o) => (
              <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Alasan Pindah" error={errors.reason}>
          <Textarea value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function EndModal({
  placement,
  onClose,
}: {
  placement: BoardingPlacement | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));
  const [endReason, setEndReason] = useState('checkout');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const end = useMutation({
    mutationFn: () => placementsApi.end(placement!.id, { end_date: endDate, end_reason: endReason, notes }),
    onSuccess: () => {
      toast.success('Penempatan diakhiri');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal
      open={!!placement}
      onClose={onClose}
      title={`Akhiri Penempatan — ${placement?.student?.full_name ?? ''}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Batal</Button>
          <Button variant="danger" onClick={() => end.mutate()} disabled={end.isPending}>
            {end.isPending ? 'Menyimpan...' : 'Akhiri'}
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Tanggal Berakhir" error={errors.end_date}>
          <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        </FormField>
        <FormField label="Alasan" error={errors.end_reason}>
          <Select value={endReason} onChange={(e) => setEndReason(e.target.value)}>
            <option value="checkout">Keluar dari asrama</option>
            <option value="graduation">Lulus</option>
            <option value="drop_out">Keluar / DO</option>
            <option value="transfer">Pindah (manual)</option>
          </Select>
        </FormField>
        <FormField label="Catatan" error={errors.notes}>
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function HistoryModal({
  studentId,
  onClose,
}: {
  studentId: number | null;
  onClose: () => void;
}) {
  const query = useQuery({
    queryKey: ['boarding', 'placements', 'history', studentId],
    enabled: studentId !== null,
    queryFn: () => placementsApi.history(studentId!),
  });

  return (
    <Modal open={studentId !== null} onClose={onClose} title="Riwayat Penempatan" size="lg"
      footer={<Button variant="outline" onClick={onClose}>Tutup</Button>}
    >
      {query.isLoading ? (
        <div className="flex items-center justify-center gap-2 py-10 text-slate-400"><Spinner /> Memuat...</div>
      ) : (query.data ?? []).length === 0 ? (
        <EmptyState title="Belum ada riwayat penempatan" />
      ) : (
        <div className="space-y-3">
          {(query.data ?? []).map((p) => (
            <Card key={p.id} className="p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-semibold text-slate-800">
                  {p.dormitory?.name} / {p.room?.code}
                  {p.bed ? ` / Bed ${p.bed.bed_number}` : ''}
                </span>
                <Badge tone={p.status === 'active' ? 'green' : 'gray'}>{p.status_label}</Badge>
              </div>
              <div className="mt-1 text-xs text-slate-500">
                {fmtDate(p.start_date)} — {fmtDate(p.end_date)}
                {p.end_reason ? ` • ${labelOf(END_REASON_LABELS, p.end_reason)}` : ''}
              </div>
            </Card>
          ))}
        </div>
      )}
    </Modal>
  );
}

export function BoardingPlacementsPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [dormitoryId, setDormitoryId] = useState<number | ''>('');
  const [status, setStatus] = useState('active');
  const [assignOpen, setAssignOpen] = useState(false);
  const [transferring, setTransferring] = useState<BoardingPlacement | null>(null);
  const [ending, setEnding] = useState<BoardingPlacement | null>(null);
  const [historyOf, setHistoryOf] = useState<number | null>(null);

  const { options: dormitoryOptions } = useDormitoryOptions();

  const filter = {
    ...(dormitoryId ? { dormitory_id: dormitoryId } : {}),
    ...(status ? { status } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['boarding', 'placements', page, search, filter],
    queryFn: () =>
      placementsApi.list({ page, per_page: 15, search: search || undefined, filter }),
  });

  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Penempatan Santri</h1>
          <p className="text-sm text-slate-500">Tempatkan, pindahkan, dan akhiri penempatan santri di kamar asrama.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="boarding.placement.manage">
            <Button onClick={() => setAssignOpen(true)}>
              <Plus className="h-4 w-4" /> Tempatkan Santri
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Asrama</label>
            <SearchSelect options={dormitoryOptions} value={dormitoryId} onChange={(v) => { setDormitoryId(toId(String(v))); setPage(1); }} placeholder="-- Semua --" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua</option>
              {Object.entries(PLACEMENT_STATUS_LABELS).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input value={search} placeholder="Nama / NIS santri..." onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat penempatan...</div>
        </Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada penempatan" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Asrama</th>
                  <th className="px-4 py-2 font-medium">Kamar / Bed</th>
                  <th className="px-4 py-2 font-medium">Mulai</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <button className="font-medium text-slate-800 hover:underline" onClick={() => setHistoryOf(p.student_id)}>
                        {p.student?.full_name ?? `Santri #${p.student_id}`}
                      </button>
                      <p className="text-xs text-slate-400">{p.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-600">{p.dormitory?.name ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">
                      {p.room?.code ?? '—'}{p.bed ? ` / ${p.bed.bed_number}` : ''}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{fmtDate(p.start_date)}</td>
                    <td className="px-4 py-2">
                      <Badge tone={p.status === 'active' ? 'green' : 'gray'}>{p.status_label}</Badge>
                      {p.end_reason_label && <p className="mt-0.5 text-xs text-slate-400">{p.end_reason_label}</p>}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Button variant="ghost" size="icon" title="Riwayat" onClick={() => setHistoryOf(p.student_id)}>
                          <History className="h-3.5 w-3.5" />
                        </Button>
                        <Can perm="boarding.mutation.manage">
                          {p.status === 'active' && (
                            <Button variant="ghost" size="icon" title="Pindah" onClick={() => setTransferring(p)}>
                              <ArrowRightLeft className="h-3.5 w-3.5" />
                            </Button>
                          )}
                        </Can>
                        <Can perm="boarding.placement.manage">
                          {p.status === 'active' && (
                            <Button variant="ghost" size="icon" title="Akhiri" onClick={() => setEnding(p)}>
                              <StopCircle className="h-3.5 w-3.5 text-red-500" />
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

      <AssignModal open={assignOpen} onClose={() => setAssignOpen(false)} />
      <TransferModal placement={transferring} onClose={() => setTransferring(null)} />
      <EndModal placement={ending} onClose={() => setEnding(null)} />
      <HistoryModal studentId={historyOf} onClose={() => setHistoryOf(null)} />
    </div>
  );
}
