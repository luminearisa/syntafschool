import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, Cloud, Send } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { toast } from 'sonner';
import { getErrorMessage } from '@/lib/axios';
import { competitionApi } from '../api/competitionApi';

interface Criterion {
  id: number;
  name: string;
  description?: string | null;
  weight: number | string;
  min_score: number | string;
  max_score: number | string;
  is_required: boolean;
  required_comment: boolean;
  help_text?: string | null;
}

export default function JudgeScorePage() {
  const { matchId: matchIdParam } = useParams();
  const matchId = Number(matchIdParam);
  const query = useQuery({ queryKey: ['competition', 'match', matchId], queryFn: () => competitionApi.match(matchId), enabled: matchId > 0 });
  const [participantId, setParticipantId] = useState('');
  const [scores, setScores] = useState<Record<number, { score: string; comment: string }>>({});
  const [generalComment, setGeneralComment] = useState('');
  const criteria = useMemo(() => query.data?.rubric?.competition_rubric_criterions ?? [], [query.data]);
  const participants = [query.data?.home_participant, query.data?.away_participant].filter((row): row is NonNullable<typeof row> => Boolean(row));
  const selectedParticipant = participants.find((row) => String(row.id) === participantId) ?? participants[0];
  const storageKey = `competition-score-draft:${matchId}:${participantId || 'first'}`;
  const saved = typeof window !== 'undefined' ? window.localStorage.getItem(storageKey) : null;

  useEffect(() => {
    if (!saved) {
      setScores({});
      setGeneralComment('');
      return;
    }
    try {
      const draft = JSON.parse(saved) as { scores?: Record<number, { score: string; comment: string }>; comment?: string };
      if (draft.scores) setScores(draft.scores);
      if (draft.comment) setGeneralComment(draft.comment);
    } catch {
      window.localStorage.removeItem(storageKey);
    }
  }, [saved, storageKey]);

  useEffect(() => {
    if (participantId && scores && typeof window !== 'undefined') {
      window.localStorage.setItem(storageKey, JSON.stringify({ scores, comment: generalComment }));
    }
  }, [scores, generalComment, participantId, storageKey]);

  const saveMutation = useMutation({
    mutationFn: (submit: boolean) => competitionApi.saveScores(matchId, {
      participant_id: Number(selectedParticipant?.id),
      rubric_id: query.data?.rubric?.id,
      scores: criteria.filter((criterion) => scores[criterion.id]?.score !== undefined && scores[criterion.id]?.score !== '').map((criterion) => ({
        criterion_id: criterion.id,
        score: Number(scores[criterion.id]?.score),
        comment: scores[criterion.id]?.comment || undefined,
      })),
      comment: generalComment || undefined,
      idempotency_key: `judge-${matchId}-${selectedParticipant?.id}-${query.data?.rubric?.id}`,
      submit,
    }),
    onSuccess: (_result, submit) => {
      if (submit) {
        toast.success('Skor dikirim untuk ditinjau dan dikunci.');
        window.localStorage.removeItem(storageKey);
      } else toast.success('Draft skor tersimpan di server.');
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  if (query.isLoading) return <div className="flex justify-center py-16"><Spinner /></div>;
  if (query.isError || !query.data) return <EmptyState title="Pertandingan tidak tersedia" description="Penugasan mungkin sudah dibatalkan atau tidak berada dalam cakupan akun Anda." action={<Link to="/judge/assignments"><Button variant="outline">Kembali</Button></Link>} />;
  const match = query.data.match;
  const locked = ['COMPLETED', 'CANCELLED', 'VOID'].includes(match.status);

  function save(event: FormEvent<HTMLFormElement>, submit: boolean) {
    event.preventDefault();
    if (!selectedParticipant) {
      toast.error('Pertandingan belum memiliki peserta.');
      return;
    }
    const missing = criteria.filter((criterion: Criterion) => criterion.is_required && (scores[criterion.id]?.score === undefined || scores[criterion.id]?.score === ''));
    if (submit && missing.length > 0) {
      toast.error(`Lengkapi ${missing.length} kriteria wajib sebelum mengirim skor.`);
      return;
    }
    saveMutation.mutate(submit);
  }

  return (
    <div className="mx-auto max-w-3xl space-y-4 pb-10">
      <Link to="/judge/assignments" className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> Penugasan saya</Link>
      <header className="rounded-2xl bg-slate-900 p-5 text-white sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-[0.16em] text-brand-200">Judge scoring</p><h1 className="mt-1 text-xl font-semibold">Match {match.match_number}</h1><p className="mt-1 text-sm text-slate-300">{query.data.category?.name ?? 'Kategori'} · {query.data.round.name} · {match.venue_name ?? 'Venue belum ditentukan'}</p></div><Badge tone={locked ? 'neutral' : 'success'}>{match.status.replace(/_/g, ' ')}</Badge></div>
        <div className="mt-4 flex flex-wrap gap-2">{participants.map((participant) => <button key={participant.id} type="button" onClick={() => setParticipantId(String(participant.id))} className={`rounded-lg px-3 py-2 text-sm font-medium ${String(selectedParticipant?.id) === String(participant.id) ? 'bg-white text-slate-900' : 'bg-white/10 text-white hover:bg-white/20'}`}>{participant.display_name ?? `Peserta #${participant.id}`}</button>)}</div>
      </header>

      {!query.data.rubric ? (
        <Card><EmptyState title="Rubric belum tersedia" description="Koordinator harus menerbitkan rubric untuk kategori/babak ini sebelum penilaian dimulai." /></Card>
      ) : (
        <form onSubmit={(event) => save(event, true)} className="space-y-4">
          <Card><CardHeader><div className="flex items-center justify-between gap-3"><div><CardTitle>{query.data.rubric.name}</CardTitle><p className="mt-1 text-xs text-slate-500">Masukkan nilai per kriteria. Draft lokal disimpan di perangkat dan dapat dikirim ulang.</p></div><span className="inline-flex items-center gap-1 text-xs text-emerald-700"><Cloud className="h-4 w-4" /> Draft lokal aktif</span></div></CardHeader>
            <div className="divide-y divide-slate-100">
              {criteria.map((criterion: Criterion) => <div key={criterion.id} className="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_150px] sm:items-center sm:p-5"><div><div className="flex flex-wrap items-center gap-2"><h2 className="text-sm font-semibold text-slate-900">{criterion.name}</h2><Badge tone="info">Bobot {criterion.weight}%</Badge>{criterion.is_required && <span className="text-xs text-red-600">Wajib</span>}</div>{criterion.description && <p className="mt-1 text-xs text-slate-500">{criterion.description}</p>}{criterion.help_text && <p className="mt-1 text-xs text-slate-400">{criterion.help_text}</p>}<Input aria-label={`Komentar ${criterion.name}`} className="mt-2" placeholder={criterion.required_comment ? 'Komentar wajib…' : 'Komentar (opsional)'} value={scores[criterion.id]?.comment ?? ''} onChange={(e) => setScores((current) => ({ ...current, [criterion.id]: { score: current[criterion.id]?.score ?? '', comment: e.target.value } }))} /></div><label className="space-y-1 text-xs font-medium text-slate-600">Skor ({criterion.min_score}–{criterion.max_score})<Input aria-label={`Skor ${criterion.name}`} type="number" inputMode="decimal" step="any" min={criterion.min_score} max={criterion.max_score} required={criterion.is_required} value={scores[criterion.id]?.score ?? ''} onChange={(e) => setScores((current) => ({ ...current, [criterion.id]: { score: e.target.value, comment: current[criterion.id]?.comment ?? '' } }))} /></label></div>)}
            </div>
            <CardBody className="border-t border-slate-100"><label className="space-y-1 text-xs font-medium text-slate-600">Catatan umum<textarea className="mt-1 min-h-20 w-full rounded-lg border border-slate-300 p-3 text-sm" value={generalComment} onChange={(e) => setGeneralComment(e.target.value)} placeholder="Catatan untuk head judge/koordinator" /></label></CardBody>
          </Card>
          {locked && <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Match telah ditutup. Skor tidak dapat diubah langsung; ajukan score amendment beralasan kepada koordinator.</div>}
          <div className="sticky bottom-2 flex flex-col gap-2 rounded-xl border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur sm:flex-row sm:justify-end"><Button type="button" variant="outline" disabled={locked || saveMutation.isPending} loading={saveMutation.isPending && saveMutation.variables === false} onClick={(event) => save(event as unknown as FormEvent<HTMLFormElement>, false)}>Simpan draft</Button><Button type="submit" disabled={locked || saveMutation.isPending} loading={saveMutation.isPending && saveMutation.variables === true}><Send className="h-4 w-4" /> Kirim skor</Button></div>
          <p className="text-center text-xs text-slate-400"><CheckCircle2 className="mr-1 inline h-3.5 w-3.5" /> Skor yang dikirim masih perlu ditinjau dan difinalisasi sebelum masuk ranking.</p>
        </form>
      )}
    </div>
  );
}
