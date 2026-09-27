import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Users } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Modal } from '@/components/ui/Modal';
import { Can } from '@/components/common/Can';
import { DetailRow, FormField, StatCard, StudentStatusBadge } from '../components/BoardingUi';
import { monitoringApi } from '../api/boardingApi';
import { useDormitoryOptions, useRoomOptions } from '../hooks/useBoardingOptions';
import { STUDENT_STATUS_LABELS } from '../lib/labels';
import type { BoardingMonitoringOverview, BoardingStudentSummary } from '@/types/boarding';

const STUDENT_STATUS_ORDER = Object.keys(STUDENT_STATUS_LABELS);

const PER_PAGE = 25;

function StudentCardModal({ studentId, onClose }: { studentId: number | null; onClose: () => void }) {
  const cardQuery = useQuery({
    queryKey: ['boarding', 'monitoring', 'student', studentId],
    queryFn: () => monitoringApi.student(studentId as number),
    enabled: studentId !== null,
  });

  const s = cardQuery.data as BoardingStudentSummary | undefined;

  if (studentId === null) return null;

  return (
    <Modal open={!!studentId} onClose={onClose} title="Kartu Santri (§42)">
      {cardQuery.isLoading || !s ? (
        <div className="flex justify-center py-10"><Spinner /></div>
      ) : (
        <div className="space-y-1.5 text-sm">
          <DetailRow label="Nama" value={s.full_name ?? `Santri #${s.student_id}`} />
          <DetailRow label="NIS" value={s.nis ?? '-'} />
          <DetailRow label="Status" value={<StudentStatusBadge status={s.status} />} />
          {s.in_boarding ? (
            <>
              <DetailRow label="Asrama" value={s.dormitory_name ?? '-'} />
              <DetailRow label="Gedung" value={s.building_name ?? '-'} />
              <DetailRow label="Lantai" value={s.floor_name ?? '-'} />
              <DetailRow label="Kamar" value={`${s.room_code ?? ''}${s.room_name ? ` — ${s.room_name}` : ''}`} />
              <DetailRow label="Bed" value={s.bed_number ?? '-'} />
            </>
          ) : (
            <DetailRow label="Penempatan" value="Tidak ada penempatan aktif" />
          )}
          <DetailRow label="Pengasuh" value={s.musyrif_name ?? '-'} />
        </div>
      )}
      <div className="mt-4 flex justify-end">
        <Button variant="ghost" onClick={onClose}>Tutup</Button>
      </div>
    </Modal>
  );
}

export function BoardingMonitoringPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [dormitoryId, setDormitoryId] = useState('');
  const [roomId, setRoomId] = useState('');
  const [cardId, setCardId] = useState<number | null>(null);

  const { options: dormitoryOptions } = useDormitoryOptions();
  const { options: roomOptions } = useRoomOptions(dormitoryId ? Number(dormitoryId) : undefined);

  const overviewQuery = useQuery({
    queryKey: ['boarding', 'monitoring', page, status, search, dormitoryId, roomId],
    queryFn: () =>
      monitoringApi.overview({
        page,
        per_page: PER_PAGE,
        ...(status ? { status } : {}),
        ...(search ? { search } : {}),
        ...(dormitoryId ? { dormitory_id: dormitoryId } : {}),
        ...(roomId ? { room_id: roomId } : {}),
      }),
  });

  const data = overviewQuery.data as BoardingMonitoringOverview | undefined;
  const rows = data?.rows ?? [];
  const counts = data?.counts as Record<string, number> | undefined;
  const total = data?.total ?? 0;
  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Monitoring Keberadaan Santri</h1>
        <p className="text-sm text-slate-500">Siapa di kamar, siapa keluar, siapa pulang, siapa sakit — satu jawaban (§42).</p>
      </div>

      <Can perm="boarding.dashboard.view">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {STUDENT_STATUS_ORDER.map((key) => (
            <button key={key} onClick={() => { setStatus(status === key ? '' : key); setPage(1); }} className="text-left">
              <StatCard
                label={data?.status_options?.[key] ?? key}
                value={counts?.[key] ?? 0}
                tone={key === 'in_dorm' ? 'green' : key === 'sick' || key === 'isolation' ? 'amber' : key === 'unknown' ? 'red' : 'default'}
              />
            </button>
          ))}
        </div>
      </Can>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {Object.entries(data?.status_options ?? {}).map(([k, label]) => (
                <option key={k} value={k}>{label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Asrama">
            <Select value={dormitoryId} onChange={(e) => { setDormitoryId(e.target.value); setRoomId(''); setPage(1); }}>
              <option value="">Semua asrama</option>
              {dormitoryOptions.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Kamar">
            <Select value={roomId} onChange={(e) => { setRoomId(e.target.value); setPage(1); }}>
              <option value="">Semua kamar</option>
              {roomOptions.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Cari">
            <Input value={search} placeholder="Nama / NIS..." onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </FormField>
        </div>
      </Card>

      {overviewQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat monitoring...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Tidak ada santri yang cocok" /></Card>
      ) : (
        <Card>
          <div className="mb-2 flex items-center gap-2 px-4 pt-3 text-xs text-slate-500">
            <Users className="h-4 w-4" /> {total} santri dalam lingkup
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Lokasi</th>
                  <th className="px-4 py-2 font-medium">Bed</th>
                  <th className="px-4 py-2 font-medium">Pengasuh</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr
                    key={r.student_id}
                    className="cursor-pointer border-b border-slate-50 hover:bg-slate-50 last:border-0"
                    onClick={() => setCardId(r.student_id)}
                  >
                    <td className="px-4 py-2">
                      <p className="font-medium text-slate-800">{r.full_name ?? `Santri #${r.student_id}`}</p>
                      <p className="text-xs text-slate-400">{r.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2"><StudentStatusBadge status={r.status} /></td>
                    <td className="px-4 py-2 text-slate-600">
                      {r.in_boarding ? (
                        <>
                          {r.dormitory_name ?? '-'} · {r.room_code ?? ''}{r.room_name ? ` ${r.room_name}` : ''}
                          {r.room_type === 'isolation' && <Badge tone="red" className="ml-1">Isolasi</Badge>}
                        </>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{r.bed_number ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">{r.musyrif_name ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between px-4 py-3">
            <p className="text-xs text-slate-400">Halaman {data?.page ?? 1} dari {totalPages}</p>
            <div className="flex gap-1">
              <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                <ChevronLeft className="h-4 w-4" /> Sebelumnya
              </Button>
              <Button size="sm" variant="outline" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>
                Berikutnya <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          </div>
        </Card>
      )}

      <StudentCardModal studentId={cardId} onClose={() => setCardId(null)} />
    </div>
  );
}
