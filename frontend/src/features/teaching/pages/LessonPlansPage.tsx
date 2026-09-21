import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  CheckCircle2,
  FileText,
  Pencil,
  Plus,
  RefreshCw,
  Send,
  Trash2,
  XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import { lessonPlansApi } from '../api/teachingApi';
import { LessonPlanFormModal } from '../components/LessonPlanFormModal';
import { RejectLessonPlanModal } from '../components/RejectLessonPlanModal';
import { isLessonPlanEditable, lessonPlanTone } from '../lib/status';
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
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import {
  LESSON_PLAN_STATUS_LABELS,
  type LessonPlan,
  type LessonPlanPayload,
  type LessonPlanStatus,
} from '@/types/teaching';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/**
 * Daftar RPP / modul ajar.
 *
 * Alur status: draft → diajukan → disetujui | ditolak. Isi RPP hanya bisa
 * diubah saat draft atau setelah ditolak; persetujuan butuh `academic.approve`.
 */
export function LessonPlansPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<LessonPlanStatus | ''>('');
  const [teacherId, setTeacherId] = useState<number | ''>('');
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<LessonPlan | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [rejecting, setRejecting] = useState<LessonPlan | null>(null);
  const [rejectErrors, setRejectErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<LessonPlan | null>(null);

  const { options: semesterOptions } = useOptions('semesters');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const filters = {
    ...(status ? { status } : {}),
    ...(teacherId ? { teacher_id: teacherId } : {}),
    ...(semesterId ? { semester_id: semesterId } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['lesson-plans', page, filters, search],
    queryFn: () =>
      lessonPlansApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['lesson-plans'] });

  const saveMutation = useMutation({
    mutationFn: (payload: LessonPlanPayload) =>
      editing ? lessonPlansApi.update(editing.id, payload) : lessonPlansApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'RPP diperbarui' : 'RPP dibuat');
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

  const submitMutation = useMutation({
    mutationFn: (id: number) => lessonPlansApi.submit(id),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => lessonPlansApi.approve(id),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      lessonPlansApi.reject(id, reason),
    onSuccess: (res) => {
      toast.success(res.message);
      setRejecting(null);
      setRejectErrors({});
      invalidate();
    },
    onError: (err) => {
      setRejectErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => lessonPlansApi.remove(id),
    onSuccess: () => {
      toast.success('RPP dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">RPP / Modul Ajar</h1>
          <p className="text-sm text-slate-500">
            Rencana pembelajaran per mapel dan semester, dengan alur pengajuan dan persetujuan.
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
              <Plus className="h-4 w-4" /> Tambah RPP
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as LessonPlanStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(LESSON_PLAN_STATUS_LABELS) as LessonPlanStatus[]).map((key) => (
                <option key={key} value={key}>
                  {LESSON_PLAN_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Guru Penyusun</label>
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari kode atau judul..."
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
            <Spinner /> Memuat RPP...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat RPP"
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
            title="Belum ada RPP"
            description="Tambahkan RPP pertama, lalu ajukan untuk disetujui."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Judul</th>
                  <th className="px-4 py-2 font-medium">Mapel</th>
                  <th className="px-4 py-2 font-medium">Kelas / Jenjang</th>
                  <th className="px-4 py-2 font-medium">Guru</th>
                  <th className="px-3 py-2 text-center font-medium">JP</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
                    <td className="max-w-[18rem] px-4 py-2">
                      <div className="font-medium text-slate-800">{row.title}</div>
                      {row.status === 'rejected' && row.rejection_reason && (
                        <div className="mt-0.5 text-xs text-red-600">
                          Ditolak: {row.rejection_reason}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.subject?.name ?? '-'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.school_class?.name ?? row.education_level?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.teacher?.full_name ?? '-'}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">{row.duration_jp}</td>
                    <td className="px-4 py-2">
                      <Badge tone={lessonPlanTone(row.status)}>
                        {LESSON_PLAN_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                      {row.approver?.name && (
                        <div className="mt-1 text-xs text-slate-400">oleh {row.approver.name}</div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        {row.status === 'submitted' ? (
                          <Can
                            perm="academic.approve"
                            fallback={<span className="text-xs text-slate-400">Menunggu pemutus</span>}
                          >
                            <Button
                              size="sm"
                              variant="outline"
                              loading={approveMutation.isPending}
                              onClick={() => approveMutation.mutate(row.id)}
                            >
                              <CheckCircle2 className="h-3.5 w-3.5" /> Setujui
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => {
                                setRejectErrors({});
                                setRejecting(row);
                              }}
                            >
                              <XCircle className="h-3.5 w-3.5" /> Tolak
                            </Button>
                          </Can>
                        ) : (
                          <Can
                            perm={['academic.update', 'academic.attendance']}
                            fallback={<span className="text-xs text-slate-400">—</span>}
                          >
                            {row.status === 'draft' && (
                              <Button
                                size="sm"
                                variant="outline"
                                loading={submitMutation.isPending}
                                onClick={() => submitMutation.mutate(row.id)}
                              >
                                <Send className="h-3.5 w-3.5" /> Ajukan
                              </Button>
                            )}
                            <Button
                              variant="ghost"
                              size="icon"
                              title={isLessonPlanEditable(row.status) ? 'Edit' : 'Tidak bisa diubah'}
                              disabled={!isLessonPlanEditable(row.status)}
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
                          </Can>
                        )}
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

      <div className="flex items-center gap-2 text-xs text-slate-400">
        <FileText className="h-3.5 w-3.5" />
        Isi RPP hanya bisa diubah saat draft atau setelah ditolak — RPP yang sudah disetujui terkunci.
      </div>

      <LessonPlanFormModal
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

      <RejectLessonPlanModal
        open={!!rejecting}
        plan={rejecting}
        submitting={rejectMutation.isPending}
        serverErrors={rejectErrors}
        onClose={() => setRejecting(null)}
        onSubmit={(reason) => {
          if (rejecting) rejectMutation.mutate({ id: rejecting.id, reason });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus RPP"
        message={`Hapus RPP "${deleting?.title ?? ''}" (${deleting?.code ?? ''})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
