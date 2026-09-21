import { useEffect, useState, type FormEvent } from 'react';
import { CheckCircle2, XCircle } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { EXAM_ITEM_TYPE_LABELS } from '../lib/labels';
import type { TahfizExamItem, TahfizExamScoreItemPayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  item: TahfizExamItem | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizExamScoreItemPayload) => void;
}

/**
 * Nilai satu butir ujian.
 *
 * `actual_ayah_end` menerima rentang jawaban yang benar-benar dibaca peserta —
 * tidak wajib mencapai `expected_ayah_end`, tetapi tidak boleh melebihi jumlah
 * ayat surah (divalidasi server).
 */
export function ExamScoreItemModal({ open, item, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [actualAyahEnd, setActualAyahEnd] = useState<number | ''>('');
  const [score, setScore] = useState<number | ''>('');
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setActualAyahEnd(item?.actual_ayah_end ?? '');
    setScore(item?.score ?? '');
    setNote(item?.note ?? '');
  }, [open, item]);

  const surahName = item?.surah?.name ?? '—';
  const expected = item?.expected_ayah_end ?? null;
  const surahAyahCount = item?.surah?.ayah_count ?? null;

  const answeredCorrectly =
    actualAyahEnd !== '' && expected !== null && Number(actualAyahEnd) >= expected;

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (score === '' || Number(score) < 0) local.score = 'Nilai wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit({ actual_ayah_end: actualAyahEnd, score, note });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Nilai Butir Ujian"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="score-item-form" loading={submitting}>
            Simpan Nilai
          </Button>
        </>
      }
    >
      <form id="score-item-form" onSubmit={handleSubmit} className="space-y-4">
        {item && (
          <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
            <p>
              Tipe:{' '}
              <span className="font-medium text-slate-800">
                {EXAM_ITEM_TYPE_LABELS[item.item_type] ?? item.item_type}
              </span>
            </p>
            <p>
              Surah: <span className="font-medium text-slate-800">{surahName}</span>
              {surahAyahCount !== null && ` (${surahAyahCount} ayat)`}
            </p>
            <p>
              Rentang ayat: <span className="font-medium text-slate-800">{item.ayah_start ?? '—'}</span> –{' '}
              <span className="font-medium text-slate-800">{expected ?? '—'}</span>
            </p>
          </div>
        )}

        <div>
          <Label>Ayat Terakhir Dibaca</Label>
          <Input
            type="number"
            min={1}
            value={actualAyahEnd}
            error={err('actual_ayah_end')}
            placeholder="mis. 12"
            onChange={(e) =>
              setActualAyahEnd(e.target.value === '' ? '' : Number(e.target.value))
            }
          />
          <p className="mt-1 text-xs text-slate-500">
            Tidak wajib mencapai ayat yang diharapkan, namun tidak boleh melebihi jumlah ayat surah
            — kelebihannya ditolak server.
          </p>
          {actualAyahEnd !== '' && expected !== null && (
            <p
              className={
                answeredCorrectly
                  ? 'mt-1 inline-flex items-center gap-1 text-xs font-medium text-green-600'
                  : 'mt-1 inline-flex items-center gap-1 text-xs font-medium text-amber-600'
              }
            >
              {answeredCorrectly ? (
                <>
                  <CheckCircle2 className="h-3.5 w-3.5" /> Dianggap benar (mencapai ayat yang diharapkan)
                </>
              ) : (
                <>
                  <XCircle className="h-3.5 w-3.5" /> Belum mencapai ayat yang diharapkan
                </>
              )}
            </p>
          )}
        </div>

        <div>
          <Label required>Nilai</Label>
          <Input
            type="number"
            min={0}
            value={score}
            error={err('score')}
            placeholder="mis. 80"
            onChange={(e) => setScore(e.target.value === '' ? '' : Number(e.target.value))}
          />
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            value={note}
            error={err('note')}
            placeholder="Opsional"
            onChange={(e) => setNote(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}