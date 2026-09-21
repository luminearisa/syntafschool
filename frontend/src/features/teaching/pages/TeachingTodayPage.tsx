import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, CalendarCheck, CheckCircle2, ClipboardList, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { teachingJournalsApi } from '../api/teachingApi';
import { CompleteJournalModal } from '../components/CompleteJournalModal';
import { JournalFormModal } from '../components/JournalFormModal';
import { journalTone } from '../lib/status';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Can } from '@/components/common/Can';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import {
  JOURNAL_STATUS_LABELS,
  type CompleteJournalPayload,
  type TeachingJournal,
  type TeachingJournalPayload,
  type TeachingTodayItem,
} from '@/types/teaching';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Tanggal hari ini dalam format `YYYY-MM-DD` (waktu lokal). */
function todayDate(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

/**
 * Jurnal hari ini.
 *
 * Menampilkan jadwal mengajar pada satu tanggal dan menandai mana yang belum
 * dijurnal — supaya guru tidak perlu mencari satu per satu.
 */
export function TeachingTodayPage() {
  const queryClient = useQueryClient();

  const [date, setDate] = useState(todayDate());
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [teacherId, setTeacherId] = useState<number | ''>('');

  const [formOpen, setFormOpen] = useState(false);
  const [defaults, setDefaults] = useState<Partial<TeachingJournalPayload> | null>(null);
  const [editing, setEditing] = useState<TeachingJournal | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [completing, setCompleting] = useState<TeachingJournal | null>(null);
  const [completeErrors, setCompleteErrors] = useState<Record<string, string[]>>({});

  const { options: semesterOptions } = useOptions('semesters');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const todayQuery = useQuery({
    queryKey: ['teaching-today', date, semesterId, teacherId],
    queryFn: () =>
      teachingJournalsApi.today({
        date,
        semester_id: semesterId,
        teacher_id: teacherId,
      }),
  });

  const data = todayQuery.data;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['teaching-today'] });
    queryClient.invalidateQueries({ queryKey: ['teaching-journals'] });
    queryClient.invalidateQueries({ queryKey: ['teaching-journal-recap'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: TeachingJournalPayload) =>
      editing
        ? teachingJournalsApi.update(editing.id, payload)
        : teachingJournalsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Jurnal mengajar diperbarui' : 'Jurnal mengajar dicatat');
      setFormOpen(false);
      setEditing(null);
      setDefaults(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const completeMutation = useMutation({
    mutationFn: (payload: CompleteJournalPayload) =>
      teachingJournalsApi.complete(completing!.id, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setCompleting(null);
      setCompleteErrors({});
      invalidate();
    },
    onError: (err) => {
      setCompleteErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const items = data?.items ?? [];

  /** Nilai awal form jurnal diambil dari baris jadwal yang diklik. */
  const openFormFor = (item: TeachingTodayItem) => {
    setEditing(null);
    setServerErrors({});
    setDefaults({
      schedule_id: item.schedule_id,
      class_id: item.class_id,
      subject_id: item.subject_id,
      time_slot_id: item.time_slot_id ?? '',
      teacher_id: data?.teacher_id ?? '',
      semester_id: data?.semester_id ?? '',
      date: data?.date ?? date,
    });
    setFormOpen(true);
  };

  const progress = useMemo(() => {
    if (!data || data.total === 0) return 0;
    return Math.round((data.journaled / data.total) * 100);
  }, [data]);

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Jurnal Hari Ini</h1>
          <p className="text-sm text-slate-500">
            Jadwal mengajar pada satu tanggal beserta penanda jam yang belum dijurnal.
          </p>
        </div>
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => todayQuery.refetch()}>
          <RefreshCw className={todayQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Tanggal</label>
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => setSemesterId(toId(v))}
              placeholder="-- Semester Aktif --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">
              Guru (kosongkan = akun sendiri)
            </label>
            <SearchSelect
              options={teacherOptions}
              value={teacherId}
              onChange={(v) => setTeacherId(toId(v))}
              placeholder="-- Guru Pemanggil --"
            />
          </div>
        </div>
      </Card>

      {todayQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat jadwal hari ini...
          </div>
        </Card>
      ) : todayQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat jadwal hari ini"
            description={getErrorMessage(todayQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => todayQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : !data || items.length === 0 ? (
        <Card>
          <EmptyState
            title="Tidak ada jam mengajar"
            description="Tidak ada jadwal mengajar pada tanggal ini. Coba ganti tanggal atau pilih guru lain."
          />
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <SummaryCard label="Total Jam" value={String(data.total)} />
            <SummaryCard label="Sudah Dijurnal" value={String(data.journaled)} tone="green" />
            <SummaryCard label="Belum Dijurnal" value={String(data.pending)} tone="red" />
            <SummaryCard label="Progres" value={`${progress}%`} />
          </div>

          {data.pending > 0 && (
            <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
              <AlertCircle className="h-4 w-4" />
              {data.pending} jam mengajar belum dijurnal. Jurnal yang terlewat membuat JP terealisasi
              pada rekap beban mengajar lebih kecil dari JP terjadwal.
            </div>
          )}

          <Card>
            <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
              <ClipboardList className="h-4 w-4 text-slate-400" />
              <h2 className="text-sm font-semibold text-slate-800">Jadwal Mengajar</h2>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="px-4 py-2 font-medium">Jam</th>
                    <th className="px-4 py-2 font-medium">Kelas</th>
                    <th className="px-4 py-2 font-medium">Mata Pelajaran</th>
                    <th className="px-4 py-2 font-medium">Topik Tercatat</th>
                    <th className="px-4 py-2 font-medium">Status</th>
                    <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr key={item.schedule_id} className="border-b border-slate-50 last:border-0 align-top">
                      <td className="px-4 py-2">
                        <div className="font-mono text-xs text-slate-700">
                          {item.start_time ?? '—'}–{item.end_time ?? '—'}
                        </div>
                        {item.time_slot?.label && (
                          <div className="text-xs text-slate-400">{item.time_slot.label}</div>
                        )}
                      </td>
                      <td className="px-4 py-2 font-medium text-slate-800">
                        {item.school_class?.name ?? '-'}
                      </td>
                      <td className="px-4 py-2 text-slate-600">{item.subject?.name ?? '-'}</td>
                      <td className="max-w-[18rem] px-4 py-2 text-xs text-slate-500">
                        {item.journal ? (
                          <>
                            <div className="font-medium text-slate-700">{item.journal.topic}</div>
                            <div className="mt-0.5">
                              {item.journal.realized_jp} JP · {item.journal.student_present_count} siswa
                            </div>
                          </>
                        ) : (
                          <span className="text-slate-400">Belum dijurnal</span>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        {item.has_journal && item.journal ? (
                          <Badge tone={journalTone(item.journal.status)}>
                            {JOURNAL_STATUS_LABELS[item.journal.status] ?? item.journal.status}
                          </Badge>
                        ) : (
                          <Badge tone="red">Belum dijurnal</Badge>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        <Can
                          perm={['academic.create', 'academic.attendance']}
                          fallback={<span className="text-xs text-slate-400">—</span>}
                        >
                          {item.has_journal && item.journal ? (
                            <div className="flex flex-wrap gap-1">
                              {!item.journal.is_completed && (
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => {
                                    setCompleteErrors({});
                                    setCompleting(item.journal);
                                  }}
                                >
                                  <CheckCircle2 className="h-3.5 w-3.5" /> Selesai
                                </Button>
                              )}
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                  setEditing(item.journal);
                                  setDefaults(null);
                                  setServerErrors({});
                                  setFormOpen(true);
                                }}
                              >
                                Edit
                              </Button>
                            </div>
                          ) : (
                            <Button size="sm" onClick={() => openFormFor(item)}>
                              <CalendarCheck className="h-3.5 w-3.5" /> Catat Jurnal
                            </Button>
                          )}
                        </Can>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}

      <JournalFormModal
        open={formOpen}
        editing={editing}
        defaults={defaults}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
          setDefaults(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <CompleteJournalModal
        open={!!completing}
        journal={completing}
        submitting={completeMutation.isPending}
        serverErrors={completeErrors}
        onClose={() => setCompleting(null)}
        onSubmit={(payload) => completeMutation.mutate(payload)}
      />
    </div>
  );
}

function SummaryCard({
  label,
  value,
  tone = 'slate',
}: {
  label: string;
  value: string;
  tone?: 'slate' | 'green' | 'red';
}) {
  const tones = {
    slate: 'text-slate-800',
    green: 'text-green-600',
    red: 'text-red-600',
  };

  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-1 text-xl font-semibold ${tones[tone]}`}>{value}</p>
    </Card>
  );
}
