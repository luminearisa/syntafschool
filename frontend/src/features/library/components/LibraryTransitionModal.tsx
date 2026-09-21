import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import {
  LIBRARY_STATUS_HINTS,
  LIBRARY_STATUS_LABELS,
} from '../lib/labels';
import type { Library, LibraryStatus, LibraryTransitionPayload } from '@/types/library';

interface Props {
  open: boolean;
  library: Library | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryTransitionPayload) => void;
}

/**
 * Dialog perubahan status perpustakaan.
 *
 * Hanya status yang ada di `allowed_transitions` yang boleh dipilih; status
 * yang bersifat final (mis. archived) tidak punya transisi lanjutan.
 */
export function LibraryTransitionModal({
  open,
  library,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [status, setStatus] = useState<LibraryStatus | ''>('');
  const [reason, setReason] = useState('');

  const allowed = library?.allowed_transitions ?? [];

  useEffect(() => {
    if (!open) return;
    setStatus('');
    setReason('');
  }, [open, library]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!status) return;
    onSubmit({ status, reason: reason.trim() || undefined });
  };

  const err = (key: string) => serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ubah Status Perpustakaan"
      size="md"
      footer={
        allowed.length === 0 ? (
          <Button variant="outline" onClick={onClose}>
            Tutup
          </Button>
        ) : (
          <>
            <Button variant="outline" onClick={onClose} disabled={submitting}>
              Batal
            </Button>
            <Button
              type="submit"
              form="library-transition-form"
              loading={submitting}
              disabled={!status}
            >
              Simpan Status
            </Button>
          </>
        )
      }
    >
      {library && (
        <p className="mb-3 text-sm text-slate-600">
          Status saat ini:{' '}
          <span className="font-medium text-slate-800">
            {LIBRARY_STATUS_LABELS[library.status]}
          </span>{' '}
          — {library.name} ({library.code})
        </p>
      )}

      {allowed.length === 0 ? (
        <p className="text-sm text-slate-600">
          Status perpustakaan ini sudah final dan tidak dapat diubah lagi.
        </p>
      ) : (
        <form id="library-transition-form" onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label required>Status Baru</Label>
            <Select
              value={status}
              error={err('status')}
              onChange={(e) => setStatus(e.target.value as LibraryStatus)}
            >
              <option value="">-- Pilih status --</option>
              {allowed.map((s) => (
                <option key={s} value={s}>
                  {LIBRARY_STATUS_LABELS[s]}
                </option>
              ))}
            </Select>
            {status && (
              <p className="mt-1 text-xs text-slate-500">{LIBRARY_STATUS_HINTS[status]}</p>
            )}
          </div>

          <div>
            <Label>Alasan</Label>
            <Textarea
              value={reason}
              error={err('reason')}
              placeholder="Opsional — catatan mengapa status diubah"
              onChange={(e) => setReason(e.target.value)}
            />
          </div>
        </form>
      )}
    </Modal>
  );
}