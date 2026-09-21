import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import type { TahfizExamParticipant, TahfizExamResultPayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  participant: TahfizExamParticipant | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizExamResultPayload) => void;
}

/**
 * Catat hasil akhir peserta.
 *
 * Hasil (lulus / tidak lulus / remedial) dan grade dihitung server-side
 * berdasarkan nilai dan nilai kelulusan ujian — jangan menghitung sendiri.
 */
export function ExamResultModal({ open, participant, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [score, setScore] = useState<number | ''>('');
  const [feedback, setFeedback] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setScore(participant?.score ?? '');
    setFeedback(participant?.feedback ?? '');
  }, [open, participant]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (score !== '' && Number(score) < 0) local.score = 'Nilai tidak boleh negatif';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({ score, feedback });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Catat Hasil Ujian"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="exam-result-form" loading={submitting}>
            Simpan Hasil
          </Button>
        </>
      }
    >
      <form id="exam-result-form" onSubmit={handleSubmit} className="space-y-4">
        {participant && (
          <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
            Peserta:{' '}
            <span className="font-medium text-slate-800">
              {participant.participant?.name ?? `Peserta #${participant.participant_id}`}
            </span>
          </p>
        )}

        <div>
          <Label>Nilai Akhir</Label>
          <Input
            type="number"
            min={0}
            value={score}
            error={err('score')}
            placeholder={
              participant?.exam
                ? `mis. 85 (maks ${participant.exam.max_score})`
                : 'mis. 85'
            }
            onChange={(e) => setScore(e.target.value === '' ? '' : Number(e.target.value))}
          />
        </div>

        <div>
          <Label>Umpan Balik</Label>
          <Textarea
            value={feedback}
            error={err('feedback')}
            placeholder="Catatan untuk peserta"
            onChange={(e) => setFeedback(e.target.value)}
          />
        </div>

        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Hasil (lulus / tidak lulus / remedial) dan grade dihitung otomatis di server berdasarkan
          nilai dan nilai kelulusan.
        </p>
      </form>
    </Modal>
  );
}