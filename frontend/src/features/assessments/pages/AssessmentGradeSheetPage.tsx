import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, Download, LockKeyhole, RefreshCw, Save, Send, UnlockKeyhole } from 'lucide-react';
import { toast } from 'sonner';
import { assessmentsApi } from '../api/assessmentsApi';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Can } from '@/components/common/Can';
import { Modal } from '@/components/ui/Modal';
import { getErrorMessage } from '@/lib/axios';
import type { BulkScoreEntry } from '../types';

const STATUS_LABEL: Record<string, string> = {
  draft: 'Draft', submitted: 'Diajukan', verified: 'Diverifikasi', finalized: 'Final / Terkunci', revision_requested: 'Revisi diminta',
};

export function AssessmentGradeSheetPage() {
  const { id } = useParams();
  const assessmentId = Number(id);
  const queryClient = useQueryClient();
  const [scores, setScores] = useState<Record<number, string>>({});
  const [notes, setNotes] = useState<Record<number, string>>({});
  const [reopenOpen, setReopenOpen] = useState(false);
  const [reopenReason, setReopenReason] = useState('');
  const [saveReason, setSaveReason] = useState('');
  const [forceAction, setForceAction] = useState<'submit' | 'finalize' | null>(null);
  const [forceReason, setForceReason] = useState('');
  const [exporting, setExporting] = useState(false);

  const sheetQuery = useQuery({
    queryKey: ['assessment-sheet', assessmentId],
    enabled: Number.isFinite(assessmentId) && assessmentId > 0,
    queryFn: () => assessmentsApi.sheet(assessmentId),
  });

  useEffect(() => {
    if (!sheetQuery.data) return;
    setScores(Object.fromEntries(sheetQuery.data.items.map((item) => [item.student_id, item.score === null ? '' : String(item.score)])));
    setNotes(Object.fromEntries(sheetQuery.data.items.map((item) => [item.student_id, item.notes ?? ''])));
  }, [sheetQuery.data]);

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['assessment-sheet', assessmentId] });
    queryClient.invalidateQueries({ queryKey: ['assessments'] });
  };
  const saveMutation = useMutation({
    mutationFn: (entries: BulkScoreEntry[]) => assessmentsApi.saveScores(assessmentId, entries, saveReason),
    onSuccess: (result) => { toast.success(`Nilai tersimpan (${result.total} siswa)`); setSaveReason(''); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const transitionMutation = useMutation({
    mutationFn: async (action: 'submit' | 'verify' | 'finalize') => {
      if (action === 'submit') return assessmentsApi.submit(assessmentId);
      if (action === 'verify') return assessmentsApi.verify(assessmentId);
      return assessmentsApi.finalize(assessmentId);
    },
    onSuccess: (_, action) => { toast.success(action === 'submit' ? 'Nilai diajukan untuk verifikasi' : action === 'verify' ? 'Nilai berhasil diverifikasi' : 'Nilai berhasil difinalisasi'); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const forceMutation = useMutation({
    mutationFn: ({ action, reason }: { action: 'submit' | 'finalize'; reason: string }) =>
      action === 'submit' ? assessmentsApi.submit(assessmentId, { force: true, reason }) : assessmentsApi.finalize(assessmentId, { force: true, reason }),
    onSuccess: (_, { action }) => { toast.success(action === 'submit' ? 'Nilai diajukan (paksa) — alasan tercatat' : 'Nilai difinalisasi (paksa) — alasan tercatat'); setForceAction(null); setForceReason(''); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const reopenMutation = useMutation({
    mutationFn: (reason: string) => assessmentsApi.reopen(assessmentId, reason),
    onSuccess: () => { toast.success('Penilaian dibuka kembali untuk revisi'); setReopenOpen(false); setReopenReason(''); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const sheet = sheetQuery.data;
  const pending = sheet?.pending ?? 0;
  const items = sheet?.items ?? [];
  const status = sheet?.assessment.status ?? '';
  const isLocked = !!sheet?.is_locked;

  const submitScores = (event: FormEvent) => {
    event.preventDefault();
    if (status !== 'draft' && !saveReason.trim()) {
      toast.error('Alasan perubahan wajib diisi karena nilai sudah diajukan/diverifikasi.');
      return;
    }
    const entries = items.map((item) => {
      const value = scores[item.student_id] ?? '';
      return {
        student_id: item.student_id,
        score: value === '' ? null : Number(value),
        notes: notes[item.student_id]?.trim() || null,
      };
    });
    saveMutation.mutate(entries);
  };

  const saveButtonText = useMemo(() => saveMutation.isPending ? 'Menyimpan...' : 'Simpan Semua Nilai', [saveMutation.isPending]);

  const exportCsv = async () => {
    if (!sheet) return;
    setExporting(true);
    try {
      const blob = await assessmentsApi.gradebookExport({
        class_id: sheet.assessment.class_id,
        subject_id: sheet.assessment.subject_id,
        semester_id: sheet.assessment.semester_id,
      });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `nilai-${sheet.assessment.school_class?.code ?? sheet.assessment.class_id}.csv`;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <Link to="/assessments" className="mb-2 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:text-brand-800"><ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar penilaian</Link>
          <h1 className="text-lg font-semibold text-slate-900">Input Nilai</h1>
          <p className="text-sm text-slate-500">{sheet ? `${sheet.assessment.name} · ${sheet.assessment.school_class?.name ?? ''} · ${sheet.assessment.subject?.name ?? ''}` : 'Lembar nilai per siswa'}</p>
        </div>
        {sheet && <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={() => void exportCsv()} loading={exporting}><Download className="h-4 w-4" /> Ekspor CSV</Button>
          <Can perm="academic.grade"><Button variant="outline" onClick={submitScores} disabled={isLocked || saveMutation.isPending}><Save className="h-4 w-4" /> {saveButtonText}</Button></Can>
          {['draft', 'revision_requested'].includes(status) && <Can perm="academic.grade"><Button onClick={() => { if (pending > 0) { setForceAction('submit'); return; } transitionMutation.mutate('submit'); }} loading={transitionMutation.isPending}><Send className="h-4 w-4" /> {status === 'draft' ? 'Ajukan Nilai' : 'Ajukan Ulang'}</Button></Can>}
          {status === 'submitted' && <Can perm="academic.approve"><Button variant="outline" onClick={() => transitionMutation.mutate('verify')} loading={transitionMutation.isPending}><Check className="h-4 w-4" /> Verifikasi</Button></Can>}
          {['submitted', 'verified'].includes(status) && <Can perm="academic.approve"><Button onClick={() => { if (pending > 0) { setForceAction('finalize'); return; } transitionMutation.mutate('finalize'); }} loading={transitionMutation.isPending}><LockKeyhole className="h-4 w-4" /> Finalisasi</Button></Can>}
          {['verified', 'finalized'].includes(status) && <Can perm="academic.approve"><Button variant="outline" onClick={() => setReopenOpen(true)}><UnlockKeyhole className="h-4 w-4" /> Buka Revisi</Button></Can>}
        </div>}
      </div>

      {sheetQuery.isLoading ? <Card><div className="flex justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat lembar nilai...</div></Card>
        : sheetQuery.isError ? <Card><EmptyState title="Gagal memuat lembar nilai" description={getErrorMessage(sheetQuery.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => sheetQuery.refetch()}>Coba lagi</Button>} /></Card>
          : sheet ? <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Card className="p-4"><p className="text-xs text-slate-500">Status</p><Badge className="mt-2" tone={isLocked ? 'green' : status === 'submitted' ? 'amber' : 'blue'}>{STATUS_LABEL[status] ?? status}</Badge></Card>
              <Card className="p-4"><p className="text-xs text-slate-500">Siswa</p><p className="mt-1 text-xl font-semibold">{sheet.total}</p></Card>
              <Card className="p-4"><p className="text-xs text-slate-500">Sudah dinilai</p><p className="mt-1 text-xl font-semibold text-green-700">{sheet.graded}</p></Card>
              <Card className="p-4"><p className="text-xs text-slate-500">Belum dinilai</p><p className="mt-1 text-xl font-semibold text-amber-700">{sheet.pending}</p></Card>
            </div>
            {sheet.passing_grade !== null && <p className="text-xs text-slate-500">KKTP: {sheet.passing_grade} · Nilai maksimum penilaian: {sheet.max_score}</p>}
            {!isLocked && status !== 'draft' && <Card className="p-3">
              <label className="mb-1 block text-xs font-medium text-slate-600">Alasan perubahan nilai (wajib — tercatat pada riwayat)</label>
              <Textarea value={saveReason} onChange={(event) => setSaveReason(event.target.value)} placeholder="Contoh: memperbaiki nilai yang salah input" />
            </Card>}
            <Card>
              {items.length === 0 ? <EmptyState title="Kelas belum memiliki siswa aktif" /> : <form id="score-sheet-form" onSubmit={submitScores}>
                <div className="overflow-x-auto"><table className="w-full text-sm">
                  <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">No</th><th className="px-4 py-3">Siswa</th><th className="px-4 py-3">Nilai (maks. {sheet.max_score})</th><th className="px-4 py-3">Predikat</th><th className="px-4 py-3 min-w-56">Catatan</th><th className="px-4 py-3">Status</th></tr></thead>
                  <tbody>{items.map((item, index) => <tr key={item.student_id} className="border-b border-slate-50"><td className="px-4 py-3 text-slate-400">{index + 1}</td><td className="px-4 py-3"><div className="font-medium text-slate-800">{item.full_name}</div><div className="font-mono text-xs text-slate-400">{item.nis}</div></td><td className="px-4 py-3"><Input className="w-28" type="number" min="0" max={sheet.max_score} step="0.01" value={scores[item.student_id] ?? ''} disabled={isLocked} onChange={(event) => setScores((previous) => ({ ...previous, [item.student_id]: event.target.value }))} aria-label={`Nilai ${item.full_name}`} /></td><td className="px-4 py-3 text-slate-600">{item.grade ?? '—'}</td><td className="px-4 py-3"><Input value={notes[item.student_id] ?? ''} disabled={isLocked} onChange={(event) => setNotes((previous) => ({ ...previous, [item.student_id]: event.target.value }))} placeholder="Catatan opsional" aria-label={`Catatan ${item.full_name}`} /></td><td className="px-4 py-3">{item.graded ? <Badge tone="green">Tersimpan</Badge> : <Badge tone="amber">Belum ada nilai</Badge>}</td></tr>)}</tbody>
                </table></div>
                {!isLocked && <div className="flex justify-end border-t border-slate-100 p-3"><Can perm="academic.grade"><Button type="submit" loading={saveMutation.isPending}><Save className="h-4 w-4" /> {saveButtonText}</Button></Can></div>}
              </form>}
            </Card>
            <div className="flex items-start gap-2 rounded-lg border border-blue-100 bg-blue-50 p-3 text-xs text-blue-800"><RefreshCw className="mt-0.5 h-4 w-4 shrink-0" /><p>Nilai disimpan sekaligus untuk seluruh siswa. Setelah difinalisasi, perubahan nilai memerlukan proses buka revisi dan alasan agar jejak audit tetap tersimpan.</p></div>
          </> : null}

      <Modal open={reopenOpen} onClose={() => setReopenOpen(false)} title="Buka Kembali Nilai" footer={<><Button variant="outline" onClick={() => setReopenOpen(false)}>Batal</Button><Button onClick={() => { if (!reopenReason.trim()) { toast.error('Alasan wajib diisi.'); return; } reopenMutation.mutate(reopenReason.trim()); }} loading={reopenMutation.isPending}>Buka untuk Revisi</Button></>}>
        <div className="space-y-3"><p className="text-sm text-slate-600">Pembukaan kembali akan dicatat pada riwayat penilaian. Jelaskan alasan perubahan.</p><Textarea value={reopenReason} onChange={(event) => setReopenReason(event.target.value)} placeholder="Alasan revisi nilai (wajib)" /></div>
      </Modal>

      <Modal open={forceAction !== null} onClose={() => { setForceAction(null); setForceReason(''); }} title={forceAction === 'finalize' ? 'Finalisasi Tanpa Lengkap' : 'Ajukan Tanpa Lengkap'} footer={<><Button variant="outline" onClick={() => { setForceAction(null); setForceReason(''); }}>Batal</Button><Button onClick={() => { if (!forceReason.trim()) { toast.error('Alasan wajib diisi.'); return; } if (forceAction) forceMutation.mutate({ action: forceAction, reason: forceReason.trim() }); }} loading={forceMutation.isPending}>{forceAction === 'finalize' ? 'Finalisasi (paksa)' : 'Ajukan (paksa)'}</Button></>}>
        <div className="space-y-3"><p className="text-sm text-slate-600">Masih ada {pending} siswa yang belum dinilai. Bila tetap diteruskan, tindakan ini dicatat beserta alasan untuk keperluan audit.</p><Textarea value={forceReason} onChange={(event) => setForceReason(event.target.value)} placeholder="Alasan menyerahkan/memfinalisasi tanpa nilai lengkap (wajib)" /></div>
      </Modal>
    </div>
  );
}
