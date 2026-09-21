import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, Paperclip, Scale } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbPortalAppealsApi } from '../../api/ppdbApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDateTime } from './format';
import type { PpdbAppeal } from '@/types/ppdb';

const MIN_REASON = 20;
const MAX_REASON = 2000;
const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;
const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png'];

const STATUS_TONES: Record<string, 'gray' | 'amber' | 'green' | 'red'> = {
  pending: 'amber',
  accepted: 'green',
  rejected: 'red',
};

interface Props {
  registrationId: number;
  /** Dipakai untuk menandai kartu ini relevan hanya bila hasil bisa disanggah. */
  onSubmitted?: () => void;
}

/**
 * Kartu sanggah orang tua. Kelayakan dibaca dari `eligibility` backend supaya
 * tombol tidak sekadar disembunyikan — alasannya ikut ditampilkan. Menerima
 * sanggahan tidak pernah mengubah hasil seleksi; itu keputusan panitia menyusul.
 */
export function AppealCard({ registrationId, onSubmitted }: Props) {
  const [reason, setReason] = useState('');
  const [attachment, setAttachment] = useState<File | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const fileRef = useRef<HTMLInputElement>(null);

  const queryClient = useQueryClient();
  const queryKey = ['ppdb', 'portal', 'appeals', registrationId];

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey,
    queryFn: () => ppdbPortalAppealsApi.forRegistration(registrationId),
  });

  const submitMutation = useMutation({
    mutationFn: () => ppdbPortalAppealsApi.submit(registrationId, reason.trim(), attachment),
    onSuccess: (appeal) => {
      toast.success('Sanggahan terkirim dan menunggu pemeriksaan panitia.');
      setReason('');
      setAttachment(null);
      setFieldErrors({});
      if (fileRef.current) fileRef.current.value = '';
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal'] });
      onSubmitted?.();
      return appeal;
    },
    onError: (err) => {
      const violations = getValidationErrors(err);
      setFieldErrors(
        Object.fromEntries(
          Object.entries(violations ?? {}).map(([key, value]) => [key, value[0] ?? '']),
        ),
      );
      toast.error(getErrorMessage(err));
    },
  });

  const eligibility = data?.eligibility;
  const appeals: PpdbAppeal[] = data?.appeals ?? [];
  const reasons = eligibility?.reasons ?? [];
  const canAppeal = Boolean(eligibility?.can_appeal);
  const trimmed = reason.trim();

  const pickFile = (file: File | null) => {
    if (file && (file.size > MAX_ATTACHMENT_BYTES || !ALLOWED.includes(file.type))) {
      setFieldErrors((prev) => ({
        ...prev,
        attachment: 'Lampiran berupa PDF, JPG, atau PNG maksimal 5 MB.',
      }));
      if (fileRef.current) fileRef.current.value = '';
      setAttachment(null);
      return;
    }
    setFieldErrors((prev) => ({ ...prev, attachment: '' }));
    setAttachment(file);
  };

  if (isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-10 text-sm text-slate-500">
          <Spinner /> Memuat masa sanggah...
        </div>
      </Card>
    );
  }

  if (isError) {
    return (
      <Card>
        <div className="space-y-2 py-6 text-center">
          <p className="text-sm text-slate-600">{getErrorMessage(error)}</p>
          <Button size="sm" variant="outline" onClick={() => refetch()}>
            Coba lagi
          </Button>
        </div>
      </Card>
    );
  }

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 pb-3">
        <div className="flex items-start gap-2">
          <Scale className="mt-0.5 h-4 w-4 text-slate-400" />
          <div>
            <h3 className="text-sm font-semibold text-slate-900">Sanggah hasil seleksi</h3>
            <p className="text-xs text-slate-500">
              {eligibility?.appeal_start && eligibility?.appeal_end
                ? `Masa sanggah ${eligibility.appeal_start} s.d. ${eligibility.appeal_end}`
                : 'Masa sanggah belum dijadwalkan pada periode ini.'}
              {typeof eligibility?.days_left === 'number' && (
                <span className="text-slate-400"> · sisa {eligibility.days_left} hari</span>
              )}
            </p>
          </div>
        </div>
        {appeals.length > 0 && <Badge tone={STATUS_TONES[appeals[0].status] ?? 'gray'}>{appeals[0].status_label}</Badge>}
      </div>

      {reasons.length > 0 && (
        <p className="mt-3 flex items-start gap-1.5 text-xs text-slate-500">
          <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          <span>{reasons.join(' ')}</span>
        </p>
      )}

      {canAppeal && (
        <div className="mt-3 space-y-2">
          <Textarea
            rows={4}
            value={reason}
            maxLength={MAX_REASON}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Jelaskan sanggahan Anda disertai bukti yang bisa diperiksa panitia (minimal 20 karakter)."
          />
          <div className="flex items-center justify-between gap-2 text-xs text-slate-400">
            <span>{trimmed.length}/{MAX_REASON} karakter</span>
            {fieldErrors.reason && <span className="text-red-500">{fieldErrors.reason}</span>}
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <input
              ref={fileRef}
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              onChange={(event) => pickFile(event.target.files?.[0] ?? null)}
              className="block text-xs text-slate-500 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:text-slate-600"
            />
            {attachment && (
              <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                <Paperclip className="h-3 w-3" /> {attachment.name}
              </span>
            )}
          </div>
          {fieldErrors.attachment && <p className="text-xs text-red-500">{fieldErrors.attachment}</p>}

          <Button
            size="sm"
            loading={submitMutation.isPending}
            disabled={!canAppeal || trimmed.length < MIN_REASON}
            onClick={() => submitMutation.mutate()}
          >
            Kirim sanggahan
          </Button>
          <p className="text-xs text-slate-400">
            Sanggahan yang diterima membuka kembali keputusan untuk ditinjau panitia; hasilnya tidak berubah
            dengan sendirinya.
          </p>
        </div>
      )}

      {appeals.length > 0 && (
        <ul className="mt-4 space-y-2 border-t border-slate-100 pt-3">
          {appeals.map((row) => (
            <li key={row.id} className="text-sm">
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={STATUS_TONES[row.status] ?? 'gray'}>{row.status_label ?? row.status}</Badge>
                <span className="text-xs text-slate-400">diajukan {formatDateTime(row.created_at)}</span>
                {row.attachment_url && (
                  <a
                    href={row.attachment_url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline"
                  >
                    <Paperclip className="h-3 w-3" /> lampiran
                  </a>
                )}
              </div>
              <p className="mt-1 whitespace-pre-line text-slate-600">{row.reason}</p>
              {row.review_note && (
                <p className="mt-1 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                  Catatan panitia: {row.review_note}
                  {row.reviewed_at && <span className="block text-slate-400">{formatDateTime(row.reviewed_at)}</span>}
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
