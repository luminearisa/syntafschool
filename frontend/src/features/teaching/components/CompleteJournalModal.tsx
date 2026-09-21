import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import type { CompleteJournalPayload, TeachingJournal } from '@/types/teaching';

interface Props {
  open: boolean;
  journal: TeachingJournal | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: CompleteJournalPayload) => void;
}

const emptyForm: CompleteJournalPayload = {
  realized_jp: '',
  student_present_count: '',
  activities: '',
  notes: '',
};

/**
 * Penyelesaian jurnal mengajar.
 *
 * Menandai jam mengajar benar-benar terlaksana sekaligus mencatat realisasi JP —
 * angka inilah yang dipakai rekap beban mengajar, bukan JP yang terjadwal.
 */
export function CompleteJournalModal({
  open,
  journal,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<CompleteJournalPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});

    setValues(
      journal
        ? {
            realized_jp: journal.realized_jp || '',
            student_present_count: journal.student_present_count,
            activities: journal.activities ?? '',
            notes: journal.notes ?? '',
          }
        : { ...emptyForm },
    );
  }, [open, journal]);

  const set = <K extends keyof CompleteJournalPayload>(
    key: K,
    value: CompleteJournalPayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (values.realized_jp === '' || Number(values.realized_jp) < 1) {
      local.realized_jp = 'JP terealisasi minimal 1 jam pelajaran';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit(values);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Selesaikan Jurnal Mengajar"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="complete-journal-form" loading={submitting}>
            Tandai Selesai
          </Button>
        </>
      }
    >
      <form id="complete-journal-form" onSubmit={handleSubmit} className="space-y-4">
        {journal && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
            <div className="font-medium text-slate-700">{journal.topic}</div>
            <div className="mt-1">
              {journal.date} · {journal.school_class?.name ?? '-'} · {journal.subject?.name ?? '-'}
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>JP Terealisasi</Label>
            <Input
              type="number"
              min={1}
              value={values.realized_jp}
              error={err('realized_jp')}
              onChange={(e) =>
                set('realized_jp', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Jumlah Siswa Hadir</Label>
            <Input
              type="number"
              min={0}
              value={values.student_present_count}
              error={err('student_present_count')}
              onChange={(e) =>
                set('student_present_count', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
        </div>

        <div>
          <Label>Kegiatan yang Terlaksana</Label>
          <Textarea
            value={values.activities}
            error={err('activities')}
            placeholder="mis. latihan soal halaman 42, tiga kelompok presentasi"
            onChange={(e) => set('activities', e.target.value)}
          />
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            value={values.notes}
            error={err('notes')}
            placeholder="Opsional — mis. jam terpotong kegiatan sekolah"
            onChange={(e) => set('notes', e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}
