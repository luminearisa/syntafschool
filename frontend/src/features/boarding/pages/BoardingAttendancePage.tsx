import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ScanLine, Save, UserCheck } from 'lucide-react';
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
import { FormField, Tabs, fmtDateTime } from '../components/BoardingUi';
import { attendancesApi } from '../api/boardingApi';
import { useActivityOptions, useAttendanceTypeOptions, useDormitoryOptions } from '../hooks/useBoardingOptions';
import { getErrorMessage } from '@/lib/axios';
import type { BoardingAttendance } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));
const today = () => new Date().toISOString().slice(0, 10);

interface RosterChoice {
  typeId: number | '';
  note: string;
}

function RosterTab() {
  const qc = useQueryClient();
  const { options: activityOptions } = useActivityOptions();
  const { options: dormitoryOptions } = useDormitoryOptions();

  const [activityId, setActivityId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [dormitoryId, setDormitoryId] = useState<number | ''>('');
  const [choices, setChoices] = useState<Record<number, RosterChoice>>({});
  const [scanUid, setScanUid] = useState('');

  const rosterQuery = useQuery({
    queryKey: ['boarding', 'roster', activityId, date, dormitoryId],
    enabled: activityId !== '',
    queryFn: () =>
      attendancesApi.roster({
        activity_id: activityId,
        date,
        ...(dormitoryId ? { dormitory_id: dormitoryId } : {}),
      }),
  });

  const saveAll = useMutation({
    mutationFn: (markAllPresent: boolean) => {
      const presentType = rosterQuery.data?.attendance_types.find((t) => t.is_present);
      const records = (rosterQuery.data?.students ?? []).map((row) => {
        const sid = row.student_id;
        const chosen = choices[sid];
        const existing = (row.attendance as { attendance_type_id?: number } | null | undefined);
        return {
          student_id: sid,
          attendance_type_id:
            chosen?.typeId ||
            (existing?.attendance_type_id ?? 0) ||
            (markAllPresent ? (presentType?.id ?? 0) : 0) ||
            0,
          note: chosen?.note || undefined,
        };
      });
      return attendancesApi.bulk({
        activity_id: Number(activityId),
        date,
        records: records.filter((r) => r.attendance_type_id),
      });
    },
    onSuccess: (res) => {
      toast.success(`Tersimpan: ${res.created} baru, ${res.updated} diubah, ${res.skipped} tetap.`);
      setChoices({});
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const scan = useMutation({
    mutationFn: () =>
      attendancesApi.scan({
        uid: scanUid.trim(),
        ...(activityId ? { activity_id: Number(activityId) } : {}),
        date,
      }),
    onSuccess: (res) => {
      const data = (res as { message?: string }) ?? {};
      toast.success(data.message ?? 'Scan tercatat');
      setScanUid('');
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const roster = rosterQuery.data;
  const students = roster?.students ?? [];

  const choiceFor = (sid: number): number | '' => {
    const c = choices[sid];
    if (c) return c.typeId;
    const row = students.find((s) => s.student_id === sid);
    const existing = row?.attendance as { attendance_type_id?: number } | null | undefined;
    return existing?.attendance_type_id ?? '';
  };

  return (
    <div className="space-y-3">
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Kegiatan">
            <SearchSelect options={activityOptions} value={activityId} onChange={(v) => setActivityId(toId(v))} placeholder="-- Pilih kegiatan --" />
          </FormField>
          <FormField label="Tanggal">
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </FormField>
          <FormField label="Asrama (opsional)">
            <SearchSelect options={dormitoryOptions} value={dormitoryId} onChange={(v) => setDormitoryId(toId(v))} placeholder="-- Semua --" />
          </FormField>
          <FormField label="Scan kartu RFID">
            <div className="flex gap-1">
              <Input
                value={scanUid}
                placeholder="UID kartu..."
                onChange={(e) => setScanUid(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && scanUid.trim() && scan.mutate()}
              />
              <Button variant="outline" size="icon" title="Scan" disabled={!scanUid.trim() || scan.isPending} onClick={() => scan.mutate()}>
                <ScanLine className="h-4 w-4" />
              </Button>
            </div>
          </FormField>
        </div>
      </Card>

      {activityId === '' ? (
        <Card><EmptyState title="Pilih kegiatan" description="Pilih kegiatan untuk memunculkan daftar sasaran absensi." /></Card>
      ) : rosterQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat roster...</div></Card>
      ) : students.length === 0 ? (
        <Card><EmptyState title="Tidak ada santri sasaran" /></Card>
      ) : (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2">
            <p className="text-xs text-slate-500">
              {rosterQuery.data?.summary.recorded ?? 0}/{rosterQuery.data?.summary.total ?? 0} tercatat •{' '}
              {rosterQuery.data?.summary.present ?? 0} hadir • {rosterQuery.data?.summary.late ?? 0} terlambat
            </p>
            <div className="flex gap-2">
              <Can perm="boarding.attendance.manage">
                <Button size="sm" variant="outline" onClick={() => saveAll.mutate(true)} disabled={saveAll.isPending}>
                  <UserCheck className="h-3.5 w-3.5" /> Hadir Semua
                </Button>
                <Button size="sm" onClick={() => saveAll.mutate(false)} disabled={saveAll.isPending}>
                  <Save className="h-3.5 w-3.5" /> {saveAll.isPending ? 'Menyimpan...' : 'Simpan Absensi'}
                </Button>
              </Can>
            </div>
          </div>
          <ul className="divide-y divide-slate-50">
            {students.map((row) => {
              const sid = row.student_id;
              const student = row.student ?? { full_name: String(row.full_name ?? ''), nis: String(row.nis ?? '') };
              const existing = row.attendance as BoardingAttendance | null;
              return (
                <li key={sid} className="flex flex-col gap-2 px-4 py-2 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p className="text-sm font-medium text-slate-800">{student?.full_name ?? `Santri #${sid}`}</p>
                    <p className="text-xs text-slate-400">
                      {student?.nis ?? ''}
                      {existing ? ` • tercatat ${fmtDateTime(existing.recorded_at ?? existing.date)}` : ''}
                    </p>
                  </div>
                  <div className="flex w-full items-end gap-2 sm:w-auto">
                    <Select
                      className="w-44"
                      value={String(choiceFor(sid))}
                      onChange={(e) =>
                        setChoices((s) => ({ ...s, [sid]: { typeId: toId(e.target.value), note: s[sid]?.note ?? '' } }))
                      }
                    >
                      <option value="">-- Belum absen --</option>
                      {(roster?.attendance_types ?? []).map((t) => (
                        <option key={t.id} value={String(t.id)}>{t.name}</option>
                      ))}
                    </Select>
                    <Input
                      className="w-40"
                      placeholder="Catatan (opsional)"
                      value={choices[sid]?.note ?? ''}
                      onChange={(e) =>
                        setChoices((s) => ({
                          ...s,
                          [sid]: { typeId: s[sid]?.typeId ?? '', note: e.target.value },
                        }))
                      }
                    />
                  </div>
                </li>
              );
            })}
          </ul>
        </Card>
      )}
    </div>
  );
}

function RecordsTab() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [date, setDate] = useState('');
  const [revising, setRevising] = useState<BoardingAttendance | null>(null);
  const [reviseType, setReviseType] = useState<number | ''>('');
  const [reviseReason, setReviseReason] = useState('');

  const { options: activityOptions } = useActivityOptions();
  const { options: typeOptions } = useAttendanceTypeOptions();
  const [activityId, setActivityId] = useState<number | ''>('');

  const listQuery = useQuery({
    queryKey: ['boarding', 'attendances', page, date, activityId],
    queryFn: () =>
      attendancesApi.list({
        page,
        per_page: 20,
        filter: {
          ...(date ? { date } : {}),
          ...(activityId ? { activity_id: activityId } : {}),
        },
      }),
  });

  const revise = useMutation({
    mutationFn: () =>
      attendancesApi.revise(revising!.id, { attendance_type_id: Number(reviseType), note: reviseReason || undefined }),
    onSuccess: () => {
      toast.success('Absensi dikoreksi');
      setRevising(null);
      qc.invalidateQueries({ queryKey: ['boarding', 'attendances'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = (listQuery.data?.rows ?? []) as BoardingAttendance[];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-3">
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FormField label="Tanggal">
            <Input type="date" value={date} onChange={(e) => { setDate(e.target.value); setPage(1); }} />
          </FormField>
          <FormField label="Kegiatan">
            <SearchSelect options={activityOptions} value={activityId} onChange={(v) => { setActivityId(toId(v)); setPage(1); }} placeholder="-- Semua --" />
          </FormField>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada catatan absensi" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Kegiatan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Sumber</th>
                  <th className="w-20 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => (
                  <tr key={a.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {a.student?.full_name ?? `Santri #${a.student_id}`}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{a.activity?.name ?? '—'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={a.is_present ? 'green' : a.is_late ? 'amber' : 'red'}>
                        {a.attendance_type?.name ?? '—'}
                        {a.is_late ? ` (+${a.late_minutes ?? 0} mnt)` : ''}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{a.date}{a.session ? ` • ${a.session}` : ''}</td>
                    <td className="px-4 py-2 text-xs text-slate-500">{a.source_label}</td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.attendance.manage">
                        <Button size="sm" variant="ghost" onClick={() => {
                          setRevising(a);
                          setReviseType(a.attendance_type_id);
                          setReviseReason('');
                        }}>
                          Koreksi
                        </Button>
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

      <Modal
        open={!!revising}
        onClose={() => setRevising(null)}
        title={`Koreksi Absensi — ${revising?.student?.full_name ?? ''}`}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setRevising(null)}>Batal</Button>
            <Button disabled={!reviseType || revise.isPending} onClick={() => revise.mutate()}>
              {revise.isPending ? 'Menyimpan...' : 'Koreksi'}
            </Button>
          </div>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-500">
            Nilai lama: {revising?.attendance_type?.name ?? '—'} (versi ke-{(revising?.revision ?? 0) + 1}).
            Koreksi tercatat di riwayat.
          </p>
          <FormField label="Status Baru">
            <Select value={String(reviseType)} onChange={(e) => setReviseType(toId(e.target.value))}>
              <option value="">-- Pilih --</option>
              {typeOptions.map((t) => (
                <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Alasan Koreksi">
            <Textarea value={reviseReason} onChange={(e) => setReviseReason(e.target.value)} placeholder="mis. santri sakit, lupa dicatat..." />
          </FormField>
        </div>
      </Modal>
    </div>
  );
}

/** Opsional — dropdown tipe absensi untuk koreksi (diisi lewat query ringan). */

export function BoardingAttendancePage() {
  const [tab, setTab] = useState('roster');
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Absensi Kegiatan</h1>
        <p className="text-sm text-slate-500">
          Rekap kehadiran manual, "Hadir Semua", maupun scan kartu RFID (kartu Wallet yang sudah ada).
        </p>
      </div>
      <Tabs
        tabs={[
          { key: 'roster', label: 'Layar Absensi' },
          { key: 'records', label: 'Catatan Absensi' },
        ]}
        active={tab}
        onChange={setTab}
      />
      {tab === 'roster' ? <RosterTab /> : <RecordsTab />}
    </div>
  );
}
