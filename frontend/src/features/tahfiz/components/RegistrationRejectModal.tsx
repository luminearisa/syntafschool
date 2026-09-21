import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import type { TahfizRegistrationRequest } from '@/types/tahfiz';

interface Props {
  open: boolean;
  registration: TahfizRegistrationRequest | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (reviewNote: string) => void;
}

/** Tolak pengajuan pendaftaran — catatan review wajib diisi. */
export function RegistrationRejectModal({
  open,
  registration,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [note, setNote] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setNote('');
    setError('');
  }, [open]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!note.trim()) {
      setError('Catatan penolakan wajib diisi');
      return;
    }
    onSubmit(note);
  };

  const err = error || serverErrors?.review_note?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tolak Pengajuan Pendaftaran"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" variant="danger" form="registration-reject-form" loading={submitting}>
            Tolak
          </Button>
        </>
      }
    >
      <form id="registration-reject-form" onSubmit={handleSubmit} className="space-y-4">
        {registration && (
          <div className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            Menolak pengajuan dari{' '}
            <span className="font-medium text-slate-800">{registration.name}</span>
            {registration.program?.name ? ` — ${registration.program.name}` : ''}.
          </div>
        )}
        <div>
          <Label required>Catatan Penolakan</Label>
          <Textarea
            value={note}
            error={err}
            placeholder="Alasan pengajuan ditolak"
            onChange={(e) => {
              setNote(e.target.value);
              setError('');
            }}
          />
        </div>
      </form>
    </Modal>
  );
}