import { useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { gradebookApi } from '../../api/gradebookApi';
import { formatDate } from '../../lib/lmsLabels';
import type { GradeReview } from '../../types.phase3';

const TONE: Record<string, 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'> = {
  PENDING: 'amber',
  UNDER_REVIEW: 'blue',
  APPROVED: 'green',
  REJECTED: 'red',
  CLOSED: 'gray',
};

/**
 * Ajukan banding nilai (§59 siswa).
 */
export function GradeReviewRequestForm({
  gradeItemId,
  gradeItemName,
  currentScore,
  onSubmitted,
}: {
  gradeItemId: number;
  gradeItemName: string;
  currentScore: number | null;
  onSubmitted?: () => void | Promise<void>;
}) {
  const [reason, setReason] = useState('');
  const [requested, setRequested] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true);
    setError(null);
    setMessage(null);

    try {
      await gradebookApi.requestReview(gradeItemId, {
        reason,
        requested_score: requested === '' ? null : Number(requested),
      });

      setReason('');
      setRequested('');
      setMessage('Pengajuan banding terkirim. Guru akan meninjau nilaimu.');
      await onSubmitted?.();
    } catch {
      setError('Pengajuan gagal. Pastikan nilai sudah dirilis dan belum ada banding yang sedang diproses.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Ajukan peninjauan nilai</CardTitle>
      </CardHeader>
      <CardBody className="space-y-3">
        <p className="text-xs text-slate-500">
          {gradeItemName} · nilai saat ini <strong>{currentScore === null ? '—' : currentScore.toFixed(2)}</strong>
        </p>

        <Textarea
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          rows={3}
          placeholder="Jelaskan bagian mana yang menurutmu perlu ditinjau…"
        />

        <Input
          value={requested}
          inputMode="decimal"
          onChange={(event) => setRequested(event.target.value)}
          placeholder="Nilai yang diharapkan (opsional)"
        />

        {message && <p className="text-xs text-emerald-700">{message}</p>}
        {error && <p className="text-xs text-red-600">{error}</p>}

        <Button type="button" disabled={busy || reason.trim().length < 5} onClick={() => void submit()}>
          Kirim pengajuan
        </Button>
      </CardBody>
    </Card>
  );
}

/**
 * Keputusan banding oleh guru (§59 guru).
 */
export function GradeReviewDecisionCard({
  review,
  onDecided,
}: {
  review: GradeReview;
  onDecided?: () => void | Promise<void>;
}) {
  const [score, setScore] = useState(review.requested_score === null ? '' : String(review.requested_score));
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (action: 'start' | 'approve' | 'reject' | 'close') => {
    setBusy(true);
    setError(null);

    try {
      if (action === 'start') {
        await gradebookApi.startReview(review.id);
      } else if (action === 'approve') {
        await gradebookApi.approveReview(review.id, Number(score), note);
      } else if (action === 'reject') {
        await gradebookApi.rejectReview(review.id, note);
      } else {
        await gradebookApi.closeReview(review.id, note === '' ? undefined : note);
      }

      await onDecided?.();
    } catch {
      setError('Aksi gagal. Periksa status banding dan kelengkapan catatan.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <CardTitle>{review.grade_item_name ?? 'Banding nilai'}</CardTitle>
        <Badge tone={TONE[review.status] ?? 'gray'}>{review.status_label}</Badge>
      </CardHeader>
      <CardBody className="space-y-3">
        <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
          <p>
            Nilai saat ini <strong>{review.current_score === null ? '—' : review.current_score.toFixed(2)}</strong>
            {review.requested_score !== null && ` · diminta ${review.requested_score.toFixed(2)}`}
          </p>
          <p className="mt-1">{review.reason}</p>
          {review.created_at && <p className="mt-1 text-slate-400">Diajukan {formatDate(review.created_at, true)}</p>}
        </div>

        {review.status === 'PENDING' && (
          <Button type="button" variant="outline" disabled={busy} onClick={() => void run('start')}>
            Mulai tinjau
          </Button>
        )}

        {review.status === 'UNDER_REVIEW' && (
          <>
            <Input value={score} inputMode="decimal" onChange={(event) => setScore(event.target.value)} placeholder="Nilai baru" />
            <Textarea value={note} onChange={(event) => setNote(event.target.value)} rows={2} placeholder="Catatan keputusan…" />
            <div className="flex flex-wrap gap-2">
              <Button type="button" disabled={busy || score === '' || note.trim() === ''} onClick={() => void run('approve')}>
                Setujui &amp; koreksi nilai
              </Button>
              <Button type="button" variant="outline" disabled={busy || note.trim() === ''} onClick={() => void run('reject')}>
                Tolak
              </Button>
            </div>
          </>
        )}

        {review.decided_score !== null && (
          <p className="text-xs text-slate-600">
            Keputusan: nilai baru <strong>{review.decided_score.toFixed(2)}</strong>
            {review.teacher_note ? ` — ${review.teacher_note}` : ''}
          </p>
        )}

        {error && <p className="text-xs text-red-600">{error}</p>}

        <div className="space-y-1">
          {review.events.map((event, index) => (
            <p key={`${event.action}-${index}`} className="text-[11px] text-slate-500">
              {event.action} · {event.note ?? '—'}
              {event.created_at ? ` · ${formatDate(event.created_at, true)}` : ''}
            </p>
          ))}
        </div>
      </CardBody>
    </Card>
  );
}
