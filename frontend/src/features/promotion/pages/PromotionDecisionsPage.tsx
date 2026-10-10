import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardCheck, RefreshCw, Search, Send, ShieldCheck, Undo2 } from 'lucide-react';
import { toast } from 'sonner';
import { promotionApi, type PromotionBulkDecision } from '../api/promotionApi';
import type { PromotionDecision, PromotionEvaluation, PromotionSnapshot } from '../types';
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

const DECISION_LABELS: Record<string, string> = { naik: 'Naik kelas', tinggal: 'Tinggal kelas', lulus: 'Lulus', tidak_lulus: 'Tidak lulus' };

const statusTone = (status: string): 'gray' | 'blue' | 'green' | 'amber' => {
  switch (status) {
    case 'reviewed': return 'blue';
    case 'approved': return 'amber';
    case 'finalized': return 'green';
    default: return 'gray';
  }
};

function snapshotOf(decision: PromotionDecision): PromotionSnapshot | null {
  return decision.snapshot && 'criteria' in decision.snapshot ? (decision.snapshot as PromotionSnapshot) : null;
}

export function PromotionDecisionsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [classId, setClassId] = useState<number | ''>('');
  const [status, setStatus] = useState('');
  const [decision, setDecision] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [evaluateOpen, setEvaluateOpen] = useState(false);

  const lookupsQuery = useQuery({ queryKey: ['promotion', 'lookups'], staleTime: 5 * 60_000, queryFn: () => promotionApi.lookups() });
  const listQuery = useQuery({
    queryKey: ['promotion', 'decisions', page, classId, status, decision, search],
    queryFn: () => promotionApi.decisions({ page, per_page: 15, class_id: classId, status, decision, search }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['promotion'] });
  const classes = lookupsQuery.data?.classes ?? [];
  const statuses = lookupsQuery.data?.statuses ?? {};
  const rows = listQuery.data?.data ?? [];

  const applySearch = (event: FormEvent) => { event.preventDefault(); setSearch(searchInput.trim()); setPage(1); };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kenaikan Kelas</h1>
          <p className="text-sm text-slate-500">Evaluasi kriteria siswa, tetapkan keputusan, lalu ajukan untuk persetujuan.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => listQuery.refetch()}><RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button>
          <Can perm="promotion.decide"><Button onClick={() => setEvaluateOpen(true)}><ClipboardCheck className="h-4 w-4" /> Evaluasi Kelas</Button></Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <form onSubmit={applySearch} className="flex items-center gap-2 lg:col-span-2">
            <div className="relative w-full"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari nama atau NIS..." className="pl-9" /></div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <Select value={classId} onChange={(event) => { setClassId(event.target.value ? Number(event.target.value) : ''); setPage(1); }}>
            <option value="">Semua kelas</option>
            {classes.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <div className="grid grid-cols-2 gap-3">
            <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
              <option value="">Semua status</option>
              {Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
            <Select value={decision} onChange={(event) => { setDecision(event.target.value); setPage(1); }}>
              <option value="">Semua keputusan</option>
              {Object.entries(DECISION_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
          </div>
        </div>
      </Card>

      <Card>
        {listQuery.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat keputusan...</div>
          : listQuery.isError ? <EmptyState title="Gagal memuat keputusan" description={getErrorMessage(listQuery.error)} />
            : rows.length === 0 ? <EmptyState title="Belum ada keputusan" description="Jalankan Evaluasi Kelas untuk menghitung pratinjau kenaikan." />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Siswa</th><th className="px-4 py-3">Kelas</th><th className="px-4 py-3">Keputusan</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-4 py-3"><div className="font-medium text-slate-800">{row.student?.full_name ?? '—'}</div><div className="font-mono text-xs text-slate-400">{row.student?.nis ?? '—'}</div></td>
                    <td className="px-4 py-3 text-slate-600">{row.school_class?.name ?? '—'}</td>
                    <td className="px-4 py-3">{row.decision ? <Badge tone={row.decision === 'naik' || row.decision === 'lulus' ? 'green' : 'red'}>{row.decision_label ?? DECISION_LABELS[row.decision]}</Badge> : <span className="text-slate-400">—</span>}</td>
                    <td className="px-4 py-3"><Badge tone={statusTone(row.status)}>{row.status_label}</Badge></td>
                    <td className="px-4 py-3 text-right"><Button size="sm" variant="outline" onClick={() => setSelectedId(row.id)}>Detail</Button></td>
                  </tr>
                ))}</tbody>
              </table></div>}
        {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <EvaluateModal open={evaluateOpen} onClose={() => setEvaluateOpen(false)} classes={classes} semesters={lookupsQuery.data?.semesters ?? []} onSaved={() => { invalidate(); setEvaluateOpen(false); }} />
      <DecisionDetailModal id={selectedId} open={selectedId !== null} onClose={() => setSelectedId(null)} onChanged={invalidate} />
    </div>
  );
}

function EvaluateModal({
  open,
  onClose,
  classes,
  semesters,
  onSaved,
}: {
  open: boolean;
  onClose: () => void;
  classes: { id: number; name: string; code: string; academic_year_id: number }[];
  semesters: { id: number; name: string; academic_year_id: number; academic_year?: { id: number; name: string } | null }[];
  onSaved: () => void;
}) {
  const [classId, setClassId] = useState<number | ''>('');
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [evaluation, setEvaluation] = useState<PromotionEvaluation | null>(null);
  const [decisions, setDecisions] = useState<Record<number, { decision: 'naik' | 'tinggal'; target: number | '' }>>({});

  const evaluateMutation = useMutation({
    mutationFn: () => promotionApi.evaluate({ class_id: Number(classId), semester_id: semesterId ? Number(semesterId) : null }),
    onSuccess: (data) => {
      setEvaluation(data);
      const next: Record<number, { decision: 'naik' | 'tinggal'; target: number | '' }> = {};
      data.students.forEach((row) => { next[row.student.id] = { decision: row.suggested_decision, target: '' }; });
      setDecisions(next);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload: PromotionBulkDecision[] = Object.entries(decisions).map(([studentId, value]) => ({
        student_id: Number(studentId),
        decision: value.decision,
        target_class_id: value.target ? Number(value.target) : null,
        semester_id: semesterId ? Number(semesterId) : null,
      }));
      return promotionApi.bulkDecisions({ class_id: Number(classId), decisions: payload });
    },
    onSuccess: (saved) => { toast.success(`${saved.length} keputusan disimpan sebagai draft`); onSaved(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const reset = () => { setEvaluation(null); setDecisions({}); };

  const semesterForClass = evaluation ? semesters.filter((s) => s.academic_year_id === evaluation.academic_year_id) : semesters;

  return (
    <Modal open={open} onClose={() => { reset(); onClose(); }} title="Evaluasi Kenaikan Kelas" size="xl">
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto]">
          <div><label className="mb-1 block text-xs font-medium text-slate-600">Kelas</label><Select value={classId} onChange={(event) => { setClassId(event.target.value ? Number(event.target.value) : ''); reset(); }}><option value="">Pilih kelas</option>{classes.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Select></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-600">Semester (opsional)</label><Select value={semesterId} onChange={(event) => { setSemesterId(event.target.value ? Number(event.target.value) : ''); reset(); }}><option value="">Semua semester</option>{semesterForClass.map((row) => <option key={row.id} value={row.id}>{row.name} · {row.academic_year?.name ?? ''}</option>)}</Select></div>
          <div className="flex items-end"><Button className="w-full" variant="outline" loading={evaluateMutation.isPending} onClick={() => classId && evaluateMutation.mutate()}><ClipboardCheck className="h-4 w-4" /> Hitung</Button></div>
        </div>

        {!evaluation ? <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Pilih kelas lalu tekan <strong>Hitung</strong> untuk melihat pratinjau.</p> : (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone="blue">{evaluation.student_count} siswa</Badge>
              <Badge tone="green">{evaluation.naik_count} disarankan naik</Badge>
              <Badge tone="red">{evaluation.tinggal_count} disarankan tinggal</Badge>
              {evaluation.rule && <span className="text-xs text-slate-500">Aturan: {evaluation.rule.name}</span>}
            </div>
            <div className="max-h-96 overflow-auto rounded-lg border border-slate-200">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-3 py-2">Siswa</th><th className="px-3 py-2 text-right">Rata²</th><th className="px-3 py-2 text-right">Hadir</th><th className="px-3 py-2 text-right">Mapel TDK</th><th className="px-3 py-2">Keputusan</th><th className="px-3 py-2">Kelas tujuan</th></tr></thead>
                <tbody>
                  {evaluation.students.map((row) => (
                    <tr key={row.student.id} className="border-t border-slate-100">
                      <td className="px-3 py-2"><div className="font-medium text-slate-700">{row.student.full_name}</div><div className="font-mono text-xs text-slate-400">{row.student.nis}</div></td>
                      <td className="px-3 py-2 text-right">{row.snapshot.average_score ?? '—'}</td>
                      <td className="px-3 py-2 text-right">{row.snapshot.attendance_percent}%</td>
                      <td className="px-3 py-2 text-right">{row.snapshot.unpassed_subjects}</td>
                      <td className="px-3 py-2">
                        <Select value={decisions[row.student.id]?.decision ?? row.suggested_decision} onChange={(event) => setDecisions({ ...decisions, [row.student.id]: { ...decisions[row.student.id], decision: event.target.value as 'naik' | 'tinggal' } })}>
                          <option value="naik">Naik kelas</option>
                          <option value="tinggal">Tinggal kelas</option>
                        </Select>
                      </td>
                      <td className="px-3 py-2">
                        <Select value={decisions[row.student.id]?.target ?? ''} onChange={(event) => setDecisions({ ...decisions, [row.student.id]: { ...decisions[row.student.id], target: event.target.value ? Number(event.target.value) : '' } })}>
                          <option value="">—</option>
                          {classes.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
                        </Select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex justify-end gap-2 border-t border-slate-100 pt-3">
              <Button variant="outline" onClick={reset}>Reset</Button>
              <Button loading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Simpan sebagai Draft</Button>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}

function DecisionDetailModal({ id, open, onClose, onChanged }: { id: number | null; open: boolean; onClose: () => void; onChanged: () => void }) {
  const [busy, setBusy] = useState('');
  const [reason, setReason] = useState('');
  const [reopenReason, setReopenReason] = useState('');

  const detail = useQuery({
    queryKey: ['promotion', 'decision', id],
    enabled: open && id !== null,
    queryFn: () => promotionApi.decision(id!),
  });

  const run = async (action: string, fn: () => Promise<unknown>) => {
    setBusy(action);
    try {
      await fn();
      toast.success('Berhasil');
      setReason('');
      setReopenReason('');
      await detail.refetch();
      onChanged();
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setBusy('');
    }
  };

  const decision = detail.data;
  const snapshot = decision ? snapshotOf(decision) : null;

  return (
    <Modal open={open} onClose={onClose} title={decision ? `Keputusan · ${decision.student?.full_name ?? ''}` : 'Detail Keputusan'} size="xl">
      {detail.isLoading ? <div className="flex justify-center py-16"><Spinner /></div> : detail.isError ? <EmptyState title="Gagal memuat detail" description={getErrorMessage(detail.error)} /> : decision ? (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 p-4">
            <Badge tone={decision.decision === 'naik' || decision.decision === 'lulus' ? 'green' : 'red'}>{decision.decision_label ?? '—'}</Badge>
            <Badge tone={statusTone(decision.status)}>{decision.status_label}</Badge>
            <span className="text-xs text-slate-500">{decision.school_class?.name} · {decision.academic_year?.name}</span>
            {decision.target_class && <span className="text-xs text-slate-500">→ {decision.target_class.name}</span>}
          </div>

          {snapshot && (
            <div className="rounded-lg border border-slate-200 p-3">
              <h3 className="mb-2 text-sm font-semibold text-slate-700">Ringkasan Kriteria</h3>
              <div className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <Criterion label="Rata-rata" value={snapshot.average_score ?? '—'} ok={snapshot.criteria.average_score} />
                <Criterion label="Kehadiran" value={`${snapshot.attendance_percent}%`} ok={snapshot.criteria.attendance} />
                <Criterion label="Mapel tidak tuntas" value={snapshot.unpassed_subjects} ok={snapshot.criteria.unpassed_subjects} />
                <Criterion label="Poin pelanggaran" value={snapshot.violation_points} ok={snapshot.criteria.violation_points} />
              </div>
            </div>
          )}

          {decision.approvals && decision.approvals.length > 0 && (
            <div className="rounded-lg border border-slate-200 p-3">
              <h3 className="mb-2 text-sm font-semibold text-slate-700">Persetujuan</h3>
              <div className="space-y-1 text-sm">
                {decision.approvals.map((approval) => (
                  <div key={approval.id} className="flex items-center justify-between">
                    <span className="text-slate-600">{approval.approver_role_label}</span>
                    <span className="text-xs text-slate-400">{approval.approver?.name ?? '—'}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {decision.status !== 'finalized' && (
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Alasan / catatan</label><Textarea value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Opsional" /></div>
          )}

          <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3">
            {decision.status === 'draft' && (
              <>
                <Can perm="promotion.decide"><Button variant="outline" loading={busy === 'update'} onClick={() => run('update', () => promotionApi.updateDecision(decision.id, { reason }))}>Simpan Catatan</Button></Can>
                <Can perm="promotion.decide"><Button loading={busy === 'submit'} onClick={() => run('submit', () => promotionApi.submitDecision(decision.id))}><Send className="h-4 w-4" /> Ajukan</Button></Can>
              </>
            )}
            {decision.status === 'reviewed' && (
              <Can perm="promotion.approve"><Button loading={busy === 'approve'} onClick={() => run('approve', () => promotionApi.approveDecision(decision.id, { note: reason }))}><ShieldCheck className="h-4 w-4" /> Setujui</Button></Can>
            )}
            {decision.status === 'approved' && (
              <Can perm="promotion.finalize"><Button loading={busy === 'finalize'} onClick={() => run('finalize', () => promotionApi.finalizeDecision(decision.id))}>Finalisasi</Button></Can>
            )}
            {['reviewed', 'approved'].includes(decision.status) && (
              <Can perm="promotion.finalize"><Button variant="outline" loading={busy === 'reopen'} onClick={() => { const value = window.prompt('Alasan pembukaan kembali:', reopenReason); if (value) { setReopenReason(value); void run('reopen', () => promotionApi.reopenDecision(decision.id, value)); } }}><Undo2 className="h-4 w-4" /> Buka Kembali</Button></Can>
            )}
          </div>
        </div>
      ) : null}
    </Modal>
  );
}

function Criterion({ label, value, ok }: { label: string; value: string | number; ok: boolean }) {
  return (
    <div className="rounded-lg bg-slate-50 p-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className="flex items-center gap-2"><span className="font-semibold text-slate-800">{value}</span><Badge tone={ok ? 'green' : 'red'}>{ok ? 'OK' : 'Kurang'}</Badge></div>
    </div>
  );
}
