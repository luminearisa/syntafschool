import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { GraduationCap, RefreshCw, Search, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { promotionApi, type GraduationBulkRow } from '../api/promotionApi';
import type { Graduation, GraduationEvaluation, PromotionSnapshot } from '../types';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

const DECISION_LABELS: Record<string, string> = { lulus: 'Lulus', tidak_lulus: 'Tidak lulus' };

function snapshotOf(graduation: Graduation): PromotionSnapshot | null {
  return graduation.criteria_summary && 'criteria' in graduation.criteria_summary ? (graduation.criteria_summary as PromotionSnapshot) : null;
}

export function GraduationsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [academicYearId, setAcademicYearId] = useState<number | ''>('');
  const [decision, setDecision] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [evaluateOpen, setEvaluateOpen] = useState(false);

  const lookupsQuery = useQuery({ queryKey: ['promotion', 'graduation-lookups'], staleTime: 5 * 60_000, queryFn: () => promotionApi.graduationLookups() });
  const listQuery = useQuery({
    queryKey: ['promotion', 'graduations', page, academicYearId, decision, search],
    queryFn: () => promotionApi.graduations({ page, per_page: 15, academic_year_id: academicYearId, decision, search }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['promotion'] });
  const years = lookupsQuery.data?.academic_years ?? [];
  const rows = listQuery.data?.data ?? [];

  const applySearch = (event: FormEvent) => { event.preventDefault(); setSearch(searchInput.trim()); setPage(1); };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kelulusan</h1>
          <p className="text-sm text-slate-500">Evaluasi kelulusan tingkat akhir, tetapkan keputusan, lalu setujui untuk menerbitkan nomor.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => listQuery.refetch()}><RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button>
          <Can perm="graduation.decide"><Button onClick={() => setEvaluateOpen(true)}><GraduationCap className="h-4 w-4" /> Evaluasi Kelulusan</Button></Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <form onSubmit={applySearch} className="flex items-center gap-2 lg:col-span-2">
            <div className="relative w-full"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari nama atau NIS..." className="pl-9" /></div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <Select value={academicYearId} onChange={(event) => { setAcademicYearId(event.target.value ? Number(event.target.value) : ''); setPage(1); }}>
            <option value="">Semua tahun ajaran</option>
            {years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
          </Select>
          <Select value={decision} onChange={(event) => { setDecision(event.target.value); setPage(1); }}>
            <option value="">Semua keputusan</option>
            {Object.entries(DECISION_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </Select>
        </div>
      </Card>

      <Card>
        {listQuery.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat kelulusan...</div>
          : listQuery.isError ? <EmptyState title="Gagal memuat kelulusan" description={getErrorMessage(listQuery.error)} />
            : rows.length === 0 ? <EmptyState title="Belum ada data kelulusan" description="Jalankan Evaluasi Kelulusan untuk tingkat akhir pada tahun ajaran terkait." />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Siswa</th><th className="px-4 py-3">Tahun</th><th className="px-4 py-3 text-right">Rata²</th><th className="px-4 py-3">Keputusan</th><th className="px-4 py-3">No. Ijazah</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-4 py-3"><div className="font-medium text-slate-800">{row.student?.full_name ?? '—'}</div><div className="font-mono text-xs text-slate-400">{row.student?.nis ?? '—'}</div></td>
                    <td className="px-4 py-3 text-slate-600">{row.academic_year?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-right">{row.average_score ?? '—'}</td>
                    <td className="px-4 py-3"><Badge tone={row.decision === 'lulus' ? 'green' : 'red'}>{row.decision_label ?? DECISION_LABELS[row.decision]}</Badge></td>
                    <td className="px-4 py-3 font-mono text-xs text-slate-500">{row.graduation_number ?? (row.approved_at ? '—' : 'Belum terbit')}</td>
                    <td className="px-4 py-3 text-right"><Button size="sm" variant="outline" onClick={() => setSelectedId(row.id)}>Detail</Button></td>
                  </tr>
                ))}</tbody>
              </table></div>}
        {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <EvaluateGraduationModal
        open={evaluateOpen}
        onClose={() => setEvaluateOpen(false)}
        years={years}
        levels={lookupsQuery.data?.education_levels ?? []}
        classes={lookupsQuery.data?.classes ?? []}
        semesters={lookupsQuery.data?.semesters ?? []}
        onSaved={() => { invalidate(); setEvaluateOpen(false); }}
      />
      <GraduationDetailModal id={selectedId} open={selectedId !== null} onClose={() => setSelectedId(null)} onChanged={invalidate} />
    </div>
  );
}

function EvaluateGraduationModal({
  open,
  onClose,
  years,
  levels,
  classes,
  semesters,
  onSaved,
}: {
  open: boolean;
  onClose: () => void;
  years: { id: number; name: string }[];
  levels: { id: number; name: string }[];
  classes: { id: number; name: string; academic_year_id: number }[];
  semesters: { id: number; name: string; academic_year_id: number; academic_year?: { id: number; name: string } | null }[];
  onSaved: () => void;
}) {
  const [academicYearId, setAcademicYearId] = useState<number | ''>('');
  const [levelId, setLevelId] = useState<number | ''>('');
  const [classId, setClassId] = useState<number | ''>('');
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [evaluation, setEvaluation] = useState<GraduationEvaluation | null>(null);
  const [decisions, setDecisions] = useState<Record<number, 'lulus' | 'tidak_lulus'>>({});

  const classOptions = classes.filter((row) => !academicYearId || row.academic_year_id === academicYearId);
  const semesterOptions = semesters.filter((row) => !academicYearId || row.academic_year_id === academicYearId);

  const evaluateMutation = useMutation({
    mutationFn: () => promotionApi.evaluateGraduation({
      academic_year_id: Number(academicYearId),
      education_level_id: levelId ? Number(levelId) : null,
      class_id: classId ? Number(classId) : null,
      semester_id: semesterId ? Number(semesterId) : null,
    }),
    onSuccess: (data) => {
      setEvaluation(data);
      const next: Record<number, 'lulus' | 'tidak_lulus'> = {};
      data.students.forEach((row) => { next[row.student.id] = row.suggested_decision; });
      setDecisions(next);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload: GraduationBulkRow[] = Object.entries(decisions).map(([studentId, value]) => ({
        student_id: Number(studentId),
        decision: value,
        semester_id: semesterId ? Number(semesterId) : null,
      }));
      return promotionApi.bulkGraduations({ academic_year_id: Number(academicYearId), graduations: payload });
    },
    onSuccess: (saved) => { toast.success(`${saved.length} keputusan kelulusan disimpan`); onSaved(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const reset = () => { setEvaluation(null); setDecisions({}); };

  return (
    <Modal open={open} onClose={() => { reset(); onClose(); }} title="Evaluasi Kelulusan" size="xl">
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div><label className="mb-1 block text-xs font-medium text-slate-600">Tahun Ajaran</label><Select value={academicYearId} onChange={(event) => { setAcademicYearId(event.target.value ? Number(event.target.value) : ''); setClassId(''); setSemesterId(''); reset(); }}><option value="">Pilih tahun</option>{years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</Select></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-600">Jenjang (opsional)</label><Select value={levelId} onChange={(event) => { setLevelId(event.target.value ? Number(event.target.value) : ''); reset(); }}><option value="">Semua jenjang</option>{levels.map((level) => <option key={level.id} value={level.id}>{level.name}</option>)}</Select></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-600">Kelas (opsional)</label><Select value={classId} onChange={(event) => { setClassId(event.target.value ? Number(event.target.value) : ''); reset(); }}><option value="">Semua kelas tingkat akhir</option>{classOptions.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-600">Semester (opsional)</label><Select value={semesterId} onChange={(event) => { setSemesterId(event.target.value ? Number(event.target.value) : ''); reset(); }}><option value="">Semua semester</option>{semesterOptions.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select></div>
        </div>
        <div className="flex justify-end"><Button variant="outline" loading={evaluateMutation.isPending} onClick={() => academicYearId && evaluateMutation.mutate()}><GraduationCap className="h-4 w-4" /> Hitung</Button></div>

        {!evaluation ? <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Pilih tahun ajaran lalu tekan <strong>Hitung</strong>.</p> : (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone="blue">{evaluation.student_count} siswa</Badge>
              <Badge tone="green">{evaluation.lulus_count} disarankan lulus</Badge>
              <Badge tone="red">{evaluation.tidak_lulus_count} tidak lulus</Badge>
            </div>
            <div className="max-h-96 overflow-auto rounded-lg border border-slate-200">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-3 py-2">Siswa</th><th className="px-3 py-2">Kelas</th><th className="px-3 py-2 text-right">Rata²</th><th className="px-3 py-2">Keputusan</th></tr></thead>
                <tbody>
                  {evaluation.students.map((row) => (
                    <tr key={row.student.id} className="border-t border-slate-100">
                      <td className="px-3 py-2"><div className="font-medium text-slate-700">{row.student.full_name}</div><div className="font-mono text-xs text-slate-400">{row.student.nis}</div></td>
                      <td className="px-3 py-2 text-slate-600">{row.class.name}</td>
                      <td className="px-3 py-2 text-right">{row.average_score ?? '—'}</td>
                      <td className="px-3 py-2">
                        <Select value={decisions[row.student.id] ?? row.suggested_decision} onChange={(event) => setDecisions({ ...decisions, [row.student.id]: event.target.value as 'lulus' | 'tidak_lulus' })}>
                          <option value="lulus">Lulus</option>
                          <option value="tidak_lulus">Tidak lulus</option>
                        </Select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex justify-end gap-2 border-t border-slate-100 pt-3">
              <Button variant="outline" onClick={reset}>Reset</Button>
              <Button loading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Simpan Keputusan</Button>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}

function GraduationDetailModal({ id, open, onClose, onChanged }: { id: number | null; open: boolean; onClose: () => void; onChanged: () => void }) {
  const [busy, setBusy] = useState('');
  const [notes, setNotes] = useState('');
  const [loadedFor, setLoadedFor] = useState<number | null>(null);

  const detail = useQuery({
    queryKey: ['promotion', 'graduation', id],
    enabled: open && id !== null,
    queryFn: () => promotionApi.graduation(id!),
  });

  useEffect(() => {
    if (open && detail.data && loadedFor !== detail.data.id) {
      setLoadedFor(detail.data.id);
      setNotes(detail.data.notes ?? '');
    }
  }, [open, detail.data, loadedFor]);

  const run = async (action: string, fn: () => Promise<unknown>) => {
    setBusy(action);
    try {
      await fn();
      toast.success('Berhasil');
      await detail.refetch();
      onChanged();
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setBusy('');
    }
  };

  const graduation = detail.data;
  const snapshot = graduation ? snapshotOf(graduation) : null;

  return (
    <Modal open={open} onClose={onClose} title={graduation ? `Kelulusan · ${graduation.student?.full_name ?? ''}` : 'Detail Kelulusan'} size="lg">
      {detail.isLoading ? <div className="flex justify-center py-16"><Spinner /></div> : detail.isError ? <EmptyState title="Gagal memuat detail" description={getErrorMessage(detail.error)} /> : graduation ? (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 p-4">
            <Badge tone={graduation.decision === 'lulus' ? 'green' : 'red'}>{graduation.decision_label}</Badge>
            <span className="text-xs text-slate-500">{graduation.academic_year?.name} · {graduation.student?.nis}</span>
            {graduation.graduation_number && <Badge tone="blue">No. {graduation.graduation_number}</Badge>}
          </div>

          {snapshot && (
            <div className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
              <Stat label="Rata-rata" value={snapshot.average_score ?? '—'} />
              <Stat label="Kehadiran" value={`${snapshot.attendance_percent}%`} />
              <Stat label="Mapel tidak tuntas" value={snapshot.unpassed_subjects} />
              <Stat label="Poin pelanggaran" value={snapshot.violation_points} />
            </div>
          )}

          <div><label className="mb-1 block text-xs font-medium text-slate-600">Catatan</label><Textarea value={notes} onChange={(event) => setNotes(event.target.value)} /></div>

          <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3">
            <Can perm="graduation.manage"><Button variant="outline" loading={busy === 'save'} onClick={() => run('save', () => promotionApi.updateGraduation(graduation.id, { notes }))}>Simpan Catatan</Button></Can>
            {!graduation.approved_at && <Can perm="graduation.manage"><Button loading={busy === 'approve'} onClick={() => run('approve', () => promotionApi.approveGraduation(graduation.id))}><ShieldCheck className="h-4 w-4" /> Setujui & Terbitkan Nomor</Button></Can>}
          </div>
        </div>
      ) : null}
    </Modal>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-lg bg-slate-50 p-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className="font-semibold text-slate-800">{value}</div>
    </div>
  );
}
