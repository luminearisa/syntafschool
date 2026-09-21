import { Link } from 'react-router-dom';
import { ArrowRight, CalendarClock, Hash } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { StatusBadges } from './StatusBadges';
import { formatDate, nameOf, truncate } from './format';
import type { PpdbPortalApplicationCard } from '@/types/ppdbPortal';
import type { PpdbRegistrationStatus } from '@/types/ppdb';

/** Kemajuan tampilan — kartu portal tidak membawa angka progres dari server. */
const PROGRESS_BY_STATUS: Record<PpdbRegistrationStatus, number> = {
  draft: 15,
  submitted: 35,
  under_review: 50,
  need_revision: 45,
  verified: 65,
  selection: 80,
  re_registration: 90,
  passed: 95,
  waitlisted: 90,
  failed: 100,
  completed: 100,
  cancelled: 100,
};

export function applicationProgress(status: PpdbRegistrationStatus): number {
  return PROGRESS_BY_STATUS[status] ?? 10;
}

interface Props {
  card: PpdbPortalApplicationCard;
  showPeriod?: boolean;
}

/** Kartu "anak saya" — satu kartu per pendaftaran, CTA ke langkah berikutnya. */
export function ApplicationCard({ card, showPeriod = false }: Props) {
  // `next_action` dari server hanya `{key, label, status, status_label}` — tanpa
  // rute, jadi tujuannya selalu halaman rincian pendaftaran ini.
  const target = `/ppdb/portal/registrations/${card.registration_id}`;
  const progress = applicationProgress(card.status);
  const editable = card.is_editable;
  const applicantName = card.applicant?.full_name || 'Calon siswa belum dipilih';
  const selectedProgram = nameOf(card.program) ?? 'Program belum dipilih';
  const selectedWave = nameOf(card.wave);
  const periodName = nameOf(card.period);

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-slate-900">{applicantName}</p>
          <p className="mt-0.5 truncate text-xs text-slate-500">
            {selectedProgram}
            {selectedWave ? ` · Gelombang ${selectedWave}` : ''}
          </p>
          <p className="mt-1 inline-flex items-center gap-1 text-[11px] text-slate-400">
            <Hash className="h-3 w-3" />
            {card.registration_number}
          </p>
        </div>
        {showPeriod && periodName && (
          <Badge tone="gray" className="shrink-0">
            {truncate(periodName, 24)}
          </Badge>
        )}
      </div>

      <StatusBadges
        status={card.status}
        paymentStatus={card.payment_status}
        documentStatus={card.document_status}
      />

      <div>
        <div className="mb-1 flex items-center justify-between text-[11px] text-slate-500">
          <span> Kelengkapan berkas & proses</span>
          <span>{progress}%</span>
        </div>
        <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
          <div className="h-full rounded-full bg-brand-600" style={{ width: `${progress}%` }} />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
        {card.submitted_at ? (
          <span className="inline-flex items-center gap-1">
            <CalendarClock className="h-3 w-3" /> Dikirim {formatDate(card.submitted_at)}
          </span>
        ) : (
          <span>Belum dikirim — data tersimpan sebagai draf</span>
        )}
        {!editable && <Badge tone="gray">tidak dapat diubah</Badge>}
      </div>

      <Link to={target} className="mt-auto">
        <span className="flex min-h-11 w-full items-center justify-between gap-2 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white transition-colors hover:bg-brand-700">
          {card.next_action.label || (editable ? 'Lanjutkan pendaftaran' : 'Buka rincian')}
          <ArrowRight className="h-4 w-4 shrink-0" />
        </span>
      </Link>
    </Card>
  );
}
