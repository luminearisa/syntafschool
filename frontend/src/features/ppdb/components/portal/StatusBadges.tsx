import { Badge } from '@/components/ui/Badge';
import {
  DOCUMENT_STATUS_LABELS,
  PAYMENT_STATUS_LABELS,
  REGISTRATION_STATUS_LABELS,
  documentStatusTone,
  paymentStatusTone,
  registrationStatusTone,
  type DocumentState,
} from './labels';
import { cn } from '@/lib/utils';
import type { PpdbPaymentStatus, PpdbRegistrationStatus } from '@/types/ppdb';

interface Props {
  status?: PpdbRegistrationStatus | null;
  paymentStatus?: PpdbPaymentStatus | null;
  documentStatus?: DocumentState | null;
  className?: string;
}

/**
 * Tiga sumbu status ditampilkan terpisah agar tidak terbaca sebagai satu status:
 * pil isi penuh = alur pendaftaran, pil bergaris = pembayaran, pil kotak = dokumen.
 */
export function StatusBadges({ status, paymentStatus, documentStatus, className }: Props) {
  return (
    <div className={cn('flex flex-wrap items-center gap-1.5', className)}>
      {status && (
        <Badge tone={registrationStatusTone(status)} className="gap-1 rounded-md">
          <span aria-hidden className="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
          Pendaftaran: {REGISTRATION_STATUS_LABELS[status] ?? status}
        </Badge>
      )}
      {paymentStatus && (
        <Badge
          tone={paymentStatusTone(paymentStatus)}
          className="rounded-full border border-dashed border-current/40"
        >
          Pembayaran: {PAYMENT_STATUS_LABELS[paymentStatus] ?? paymentStatus}
        </Badge>
      )}
      {documentStatus && (
        <Badge tone={documentStatusTone(documentStatus)} className="rounded-none border border-current/30">
          Dokumen: {DOCUMENT_STATUS_LABELS[documentStatus] ?? documentStatus}
        </Badge>
      )}
    </div>
  );
}

/** Sumbu tunggal berlabel, untuk baris tabel & kartu. */
export function StatusAxis({
  label,
  tone,
  text,
  variant = 'pill',
}: {
  label: string;
  tone: 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';
  text: string;
  variant?: 'pill' | 'solid' | 'block';
}) {
  const shape =
    variant === 'solid'
      ? 'rounded-md'
      : variant === 'block'
        ? 'rounded-none border border-current/30'
        : 'rounded-full border border-dashed border-current/40';
  return (
    <span className="inline-flex items-center gap-1 text-[11px] text-slate-500">
      {label}
      <Badge tone={tone} className={shape}>
        {text}
      </Badge>
    </span>
  );
}
