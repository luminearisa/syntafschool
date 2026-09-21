import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRightLeft, Eye, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { examsApi } from '../api/tahfizApi';
import { ExamFormModal } from '../components/ExamFormModal';
import { ExamTransitionModal } from '../components/ExamTransitionModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useHalaqahOptions, useProgramOptions } from '../hooks/useTahfizOptions';
import { EXAM_STATUS_LABELS, EXAM_TYPE_LABELS, examStatusTone, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizExam,
  TahfizExamPayload,
  TahfizExamStatus,
  TahfizExamType,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const TYPE_OPTIONS = toOptions(EXAM_TYPE_LABELS);
const STATUS_OPTIONS = toOptions(EXAM_STATUS_LABELS);

/**
 * Daftar ujian Tahfiz: menyaring per jenis, status, program, dan halaqah.
 *
 * Aksi mencakup pembuatan/penyuntingan, transisi status (butuh tahfiz.approve),
 * penghapusan, dan tautan ke halaman detail untuk mengelola peserta & butir.
 */
export function TahfizExamsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [type, setType] = useState<TahfizExamType | ''>('');
  const [status, setStatus] = useState<TahfizExamStatus | ''>('');
  const [programId, setProgramId] = useState<number | ''>('');
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizExam | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [transitioning, setTransitioning] = useState<TahfizExam | null>(null);
  const [deleting, setDeleting] = useState<TahfizExam | null>(null);

  const { options: programOptions } = useProgramOptions();
  const { options: halaqahOptions } = useHalaqahOptions(programId);

  const filters: Record<string, string | number> = {};
  if (type) filters.type = type;
  if (status) filters.status = status;
  if (programId) filters.program_id = programId;
  if (halaqahId) filters.halaqah_id = halaqahId;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'exams', 'list', page, filters, search],
    queryFn: () =>
      examsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'exams'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizExamPayload) =>
      editing ? examsApi.update(editing.id, payload) : examsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Ujian diperbarui' : 'Ujian dibuat');
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

  const transitionMutation = useMutation({
    mutationFn: ({ id, next }: { id: number; next: TahfizExamStatus }) =>
      examsApi.transition(id, next),
    onSuccess: (res) => {
      toast.success(res.message);
      setTransitioning(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => examsApi.remove(id),
    onSuccess: () => {
      toast.success('Ujian dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Ujian Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Ujian hafalan, sambung/ayat acak, tasmi&apos;, tahsin, hingga kenaikan level.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.create">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Ujian
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis</label>
            <Select
              value={type}
              onChange={(e) => {
                setType(e.target.value as TahfizExamType | '');
                setPage(1);
              }}
            >
              <option value="">Semua jenis</option>
              {TYPE_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizExamStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {STATUS_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Program</label>
            <SearchSelect
              options={programOptions}
              value={programId}
              onChange={(v) => {
                setProgramId(toId(v));
                setHalaqahId('');
                setPage(1);
              }}
              placeholder="-- Semua program --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Halaqah</label>
            <SearchSelect
              options={halaqahOptions}
              value={halaqahId}
              onChange={(v) => {
                setHalaqahId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua halaqah --"
            />
          </div>
          <div className="lg:col-span-2">
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari kode atau nama ujian..."
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
            <Spinner /> Memuat ujian...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat ujian"
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
            title="Belum ada ujian"
            description="Tambahkan ujian pertama, lalu daftarkan peserta pada halaman detail."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Jam</th>
                  <th className="px-4 py-2 font-medium">Penguji</th>
                  <th className="px-3 py-2 text-center font-medium">Nilai</th>
                  <th className="px-3 py-2 text-center font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-44 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">{row.name}</td>
                    <td className="px-4 py-2 text-slate-600">{row.type_label}</td>
                    <td className="px-4 py-2 text-slate-600">{row.exam_date ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">{row.start_time ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.examiner?.full_name ?? '—'}
                    </td>
                    <td className="px-3 py-2 text-center text-xs text-slate-700">
                      {row.passing_score} / {row.max_score}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.participants_count ?? 0}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={examStatusTone(row.status)}>
                        {EXAM_STATUS_LABELS[row.status] ?? row.status_label}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Link to={`/tahfiz/exams/${row.id}`}>
                          <Button variant="ghost" size="icon" title="Detail">
                            <Eye className="h-3.5 w-3.5" />
                          </Button>
                        </Link>
                        <Can perm="tahfiz.approve">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Transisi status"
                            onClick={() => setTransitioning(row)}
                          >
                            <ArrowRightLeft className="h-3.5 w-3.5" />
                          </Button>
                        </Can>
                        <Can perm="tahfiz.update">
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
                        </Can>
                        <Can perm="tahfiz.delete">
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
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <ExamFormModal
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

      <ExamTransitionModal
        exam={transitioning}
        submitting={transitionMutation.isPending}
        onClose={() => setTransitioning(null)}
        onSubmit={(next) => {
          if (transitioning) transitionMutation.mutate({ id: transitioning.id, next });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Ujian"
        message={`Hapus ujian "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Peserta dan butir terkait dapat ikut terdampak.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}