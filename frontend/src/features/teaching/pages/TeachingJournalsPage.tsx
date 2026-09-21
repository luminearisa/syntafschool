import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpenCheck, CheckCircle2, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
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
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { classesApi } from '@/features/academics/api/academicsApi';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { JOURNAL_STATUS_LABELS, type CompleteJournalPayload, type JournalStatus, type TeachingJournal, type TeachingJournalPayload } from '@/types/teaching';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Daftar jurnal mengajar dengan filter periode, kelas, guru, dan status. */
export function TeachingJournalsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [classId, setClassId] = useState<number | ''>('');
  const [teacherId, setTeacherId] = useState<number | ''>('');
  const [status, setStatus] = useState<JournalStatus | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TeachingJournal | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [completing, setCompleting] = useState<TeachingJournal | null>(null);
  const [completeErrors, setCompleteErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TeachingJournal | null>(null);

  const { options: semesterOptions } = useOptions('semesters');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const classesQuery = useQuery({
    queryKey: ['classes', 'all-for-journal-list'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => classesApi.list({ all: true, per_page: 300 }),
  });

  const filters = {
    ...(semesterId ? { semester_id: semesterId } : {}),
    ...(classId ? { class_id: classId } : {}),
    ...(teacherId ? { teacher_id: teacherId } : {}),
    ...(status ? { status } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['teaching-journals', page, filters, from, to, search],
    queryFn: () =>
      teachingJournalsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        from: from || undefined,
        to: to || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['teaching-journals'] });
    queryClient.invalidateQueries({ queryKey: ['teaching-journal-recap'] });
    queryClient.invalidateQueries({ queryKey: ['teaching-today'] });
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

  const deleteMutation = useMutation({
    mutationFn: (id: number) => teachingJournalsApi.remove(id),
    onSuccess: () => {
      toast.success('Jurnal mengajar dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const classes = classesQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Jurnal Mengajar</h1>
          <p className="text-sm text-slate-500">
            Catatan jam mengajar yang benar-benar terlaksana — topik, realisasi JP, dan jumlah siswa
            hadir.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm={['academic.create', 'academic.attendance']}>
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Catat Jurnal
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => {
                setSemesterId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Kelas</label>
            <SearchSelect
              options={classes.map((c) => ({ value: c.id, label: `${c.name} — ${c.code}` }))}
              value={classId}
              onChange={(v) => {
                setClassId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Guru</label>
            <SearchSelect
              options={teacherOptions}
              value={teacherId}
              onChange={(v) => {
                setTeacherId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as JournalStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(JOURNAL_STATUS_LABELS) as JournalStatus[]).map((key) => (
                <option key={key} value={key}>
                  {JOURNAL_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari</label>
            <Input
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai</label>
            <Input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="sm:col-span-2 lg:col-span-3 xl:col-span-6">
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari topik atau materi..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat jurnal...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat jurnal"
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
            title="Belum ada jurnal pada filter ini"
            description="Ubah filter atau catat jurnal baru."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Kelas / Mapel</th>
                  <th className="px-4 py-2 font-medium">Guru</th>
                  <th className="px-4 py-2 font-medium">Topik</th>
                  <th className="px-3 py-2 text-center font-medium">JP</th>
                  <th className="px-3 py-2 text-center font-medium">Hadir</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-32 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2">
                      <div className="font-mono text-xs text-slate-700">{row.date}</div>
                      <div className="text-xs text-slate-400">
                        {row.time_slot
                          ? `${row.time_slot.start_time}–${row.time_slot.end_time}`
                          : row.schedule
                            ? `${row.schedule.start_time}–${row.schedule.end_time}`
                            : '—'}
                      </div>
                    </td>
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">
                        {row.school_class?.name ?? '-'}
                      </div>
                      <div className="text-xs text-slate-500">{row.subject?.name ?? '-'}</div>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.teacher?.full_name ?? '-'}
                    </td>
                    <td className="max-w-[18rem] px-4 py-2">
                      <div className="font-medium text-slate-800">{row.topic}</div>
                      {row.material && (
                        <div
                          className="mt-0.5 max-w-[18rem] truncate text-xs text-slate-500"
                          title={row.material}
                        >
                          {row.material}
                        </div>
                      )}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">{row.realized_jp}</td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.student_present_count}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={journalTone(row.status)}>
                        {JOURNAL_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <Can
                        perm={['academic.update', 'academic.attendance', 'academic.approve']}
                        fallback={<span className="text-xs text-slate-400">—</span>}
                      >
                        <div className="flex flex-wrap gap-1">
                          {!row.is_completed && (
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Tandai selesai"
                              onClick={() => {
                                setCompleteErrors({});
                                setCompleting(row);
                              }}
                            >
                              <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
                            </Button>
                          )}
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
                          <Can perm={['academic.delete', 'academic.attendance']}>
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
        <BookOpenCheck className="h-3.5 w-3.5" />
        Jurnal yang ditandai selesai akan dihitung sebagai JP terealisasi pada rekap beban mengajar.
      </div>

      <JournalFormModal
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

      <CompleteJournalModal
        open={!!completing}
        journal={completing}
        submitting={completeMutation.isPending}
        serverErrors={completeErrors}
        onClose={() => setCompleting(null)}
        onSubmit={(payload) => completeMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Jurnal Mengajar"
        message={`Hapus jurnal "${deleting?.topic ?? ''}" tanggal ${deleting?.date ?? ''}? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
