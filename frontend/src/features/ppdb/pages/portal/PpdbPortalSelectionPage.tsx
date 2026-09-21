import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { CalendarClock, CheckCircle2, Clock3, MapPin, MinusCircle, Trophy } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { DataState } from '../../components/portal/DataState';
import { ApplicantIdentity } from '../../components/portal/ApplicantForm';
import { AppealCard } from '../../components/portal/AppealCard';
import { StatusBadges } from '../../components/portal/StatusBadges';
import { countdownLabel, formatDateTime, formatRupiah, remainingLabel } from '../../components/portal/format';
import {
  PARTICIPANT_STATUS_LABELS,
  PARTICIPANT_STATUS_TONES,
  SELECTION_RESULT_LABELS,
  SELECTION_RESULT_TONES,
  STAGE_STATUS_LABELS,
  STAGE_STATUS_TONES,
  STAGE_TYPE_LABELS,
  registrationStatusLabel,
  type Tone,
} from '../../components/portal/labels';
import { ppdbPortalRegistrationsApi, ppdbPortalSelectionApi } from '../../api/ppdbApi';
import type { PpdbParticipantStatus, PpdbStageStatus, PpdbStageType } from '@/types/ppdb';
import type { PpdbPortalSelection, PpdbPortalSelectionResult } from '@/types/ppdbPortal';

/** Hasil & tahapan seleksi satu berkas pendaftaran. */
export function PpdbPortalSelectionPage() {
  const { id: idParam = '' } = useParams();
  const registrationId = Number(idParam);
  const enabled = Number.isFinite(registrationId) && registrationId > 0;

  const selectionQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'selection', registrationId],
    queryFn: () => ppdbPortalSelectionApi.forRegistration(registrationId),
    enabled,
  });

  const detailQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'registration', registrationId],
    queryFn: () => ppdbPortalRegistrationsApi.show(registrationId),
    enabled,
    staleTime: 30_000,
  });

  if (!enabled) {
    return (
      <Card>
        <EmptyState title="Tautan tidak valid" description="Nomor pendaftaran tidak ditemukan pada tautan Anda." />
      </Card>
    );
  }

  const selection = selectionQuery.data;
  const detail = detailQuery.data;

  return (
    <div className="space-y-4">
      <Link to={`/ppdb/portal/registrations/${registrationId}`} className="text-sm text-brand-600 hover:underline">
        &larr; Kembali ke formulir
      </Link>

      <div>
        <h1 className="text-lg font-semibold text-slate-900">Hasil seleksi</h1>
        <p className="text-sm text-slate-500">
          Nomor pendaftaran {selection?.registration_number ?? detail?.registration_number ?? '-'}.
          Nilai setiap tahap muncul setelah panitia menginput hasilnya.
        </p>
      </div>

      <DataState
        isLoading={selectionQuery.isLoading}
        isError={selectionQuery.isError}
        error={selectionQuery.error}
        onRetry={() => selectionQuery.refetch()}
        isEmpty={!selection || selection.stages.length === 0}
        loadingLabel="Memuat hasil seleksi..."
        errorTitle="Hasil seleksi gagal dimuat"
        emptyTitle="Belum ada tahapan seleksi"
        emptyDescription="Panitia belum menetapkan tahapan seleksi untuk periode ini. Pantau halaman ini setelah pengumuman jadwal."
      >
        {detail && (
          <Card className="p-4">
            <ApplicantIdentity applicant={detail.applicant} subtitle={detail.program?.name} />
            <StatusBadges
              className="mt-3"
              status={detail.status}
              paymentStatus={detail.payment_status}
              documentStatus={detail.document_status}
            />
          </Card>
        )}

        {selection && <ResultBanner selection={selection} registrationId={registrationId} />}

        {selection && (
          <div className="space-y-3">
            {selection.stages.map((entry, index) => (
              <StageCard key={entry.stage.id} entry={entry} index={index} total={selection.stages.length} />
            ))}
          </div>
        )}

        {/* Masa sanggah: riwayat sanggahan + formulir pengajuan (kelayakan dibaca dari backend). */}
        {selection && <AppealCard registrationId={registrationId} />}

        {selection?.re_registration && <ReRegistrationPanel selection={selection} registrationId={registrationId} />}
      </DataState>
    </div>
  );
}

/**
 * label & tone untuk nilai enum yang tiba sebagai string bebas
 * (`stages[].stage.type`, `stages[].stage.status`, `participant.status`).
 * Peta labelnya dikunci per enum, jadi pembacaannya lewat penolong ini agar
 * nilai di luar daftar tetap tampil apa adanya, bukan crash.
 */
function stageTypeLabel(type: string): string {
  return STAGE_TYPE_LABELS[type as PpdbStageType] ?? 'Tahap seleksi';
}

function stageStatusLabel(status: string): string {
  return STAGE_STATUS_LABELS[status as PpdbStageStatus] ?? status;
}

function stageStatusTone(status: string): Tone {
  return STAGE_STATUS_TONES[status as PpdbStageStatus] ?? 'gray';
}

function participantStatusLabel(status: string): string {
  return PARTICIPANT_STATUS_LABELS[status as PpdbParticipantStatus] ?? status;
}

function participantStatusTone(status: string): Tone {
  return PARTICIPANT_STATUS_TONES[status as PpdbParticipantStatus] ?? 'gray';
}

function ResultBanner({ selection, registrationId }: { selection: PpdbPortalSelectionResult; registrationId: number }) {
  const result = selection.selection_result;

  if (!result) {
    return (
      <Card className="p-4">
        <div className="flex items-start gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
            <Clock3 className="h-5 w-5" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-900">Hasil akhir belum diumumkan</p>
            <p className="mt-1 text-xs text-slate-500">
              Status berkas Anda saat ini: {registrationStatusLabel(selection.status)}. Nilai per tahap pada daftar di
              bawah tetap dapat Anda pantau, dan notifikasi akan terkirim begitu hasil akhir ditetapkan panitia.
            </p>
            {selection.announced_at && (
              <p className="mt-1 text-xs text-slate-500">Jadwal pengumuman: {formatDateTime(selection.announced_at)}.</p>
            )}
          </div>
        </div>
      </Card>
    );
  }

  const tone = SELECTION_RESULT_TONES[result];
  const copy =
    result === 'passed'
      ? 'Selamat, anak Anda dinyatakan diterima. Selesaikan daftar ulang sesuai instruksi di bawah.'
      : result === 'waitlisted'
        ? 'Anak Anda masuk daftar tunggu. Panitia akan menghubungi sesuai urutan bila ada kuota yang kosong.'
        : 'Anak Anda belum diterima pada periode ini. Catatan panitia tercantum pada tahap terkait.';

  return (
    <Card
      className={
        tone === 'green'
          ? 'border-green-200 bg-green-50/70 p-4'
          : tone === 'amber'
            ? 'border-amber-200 bg-amber-50/70 p-4'
            : 'border-red-200 bg-red-50/70 p-4'
      }
    >
      <div className="flex items-start gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-slate-700">
          <Trophy className="h-5 w-5" />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm font-semibold text-slate-900">{SELECTION_RESULT_LABELS[result]}</p>
            <Badge tone={tone}>{selection.program ?? 'Program tujuan'}</Badge>
          </div>
          <p className="mt-1 text-xs text-slate-600">{copy}</p>
          {selection.announced_at && (
            <p className="mt-1 text-[11px] text-slate-500">Diumumkan pada {formatDateTime(selection.announced_at)}.</p>
          )}
          {result === 'passed' && (
            <Link to={`/ppdb/portal/registrations/${registrationId}?langkah=pembayaran`} className="mt-2 inline-block">
              <Button size="sm" variant="outline">
                Cek tagihan daftar ulang
              </Button>
            </Link>
          )}
        </div>
      </div>
    </Card>
  );
}

function StageCard({ entry, index, total }: { entry: PpdbPortalSelection; index: number; total: number }) {
  const { stage, participant } = entry;
  const typeLabel = stageTypeLabel(stage.type);
  const score = participant?.score ?? null;

  return (
    <Card className="p-4">
      <div className="flex items-start gap-3">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">
          {index + 1}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm font-semibold text-slate-900">{stage.name}</p>
            <Badge tone="gray">{typeLabel}</Badge>
            <Badge tone={stageStatusTone(stage.status)}>{stageStatusLabel(stage.status)}</Badge>
          </div>

          <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
            <span className="inline-flex items-center gap-1">
              <CalendarClock className="h-3.5 w-3.5" />
              {stage.scheduled_at ? formatDateTime(stage.scheduled_at) : 'Jadwal menunggu penetapan panitia'}
            </span>
            {stage.location && (
              <span className="inline-flex items-center gap-1">
                <MapPin className="h-3.5 w-3.5" />
                {stage.location}
              </span>
            )}
            <span className="text-slate-400">
              Tahap {index + 1} dari {total}
            </span>
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-2">
            {participant ? (
              <>
                <Badge tone={participantStatusTone(participant.status)}>
                  {participantStatusLabel(participant.status)}
                </Badge>
                {score !== null ? (
                  <span className="inline-flex items-center gap-1 rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-700">
                    Nilai
                    <span className="font-semibold text-slate-900">{String(score)}</span>
                    {stage.passing_score !== null && (
                      <span className="text-slate-400">/ ambang {String(stage.passing_score)}</span>
                    )}
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 text-xs text-slate-400">
                    <MinusCircle className="h-3.5 w-3.5" />
                    Nilai belum diinput
                  </span>
                )}
                {participant.result && (
                  <Badge tone={SELECTION_RESULT_TONES[participant.result]}>
                    {SELECTION_RESULT_LABELS[participant.result]}
                  </Badge>
                )}
              </>
            ) : (
              <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                <Clock3 className="h-3.5 w-3.5" />
                Anak Anda belum dipanggil pada tahap ini
              </span>
            )}
          </div>

          {participant?.notes && (
            <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
              Catatan panitia: {participant.notes}
            </p>
          )}
        </div>
      </div>
    </Card>
  );
}

function ReRegistrationPanel({ selection, registrationId }: { selection: PpdbPortalSelectionResult; registrationId: number }) {
  const re = selection.re_registration;
  if (!re) return null;

  const invoice = re.invoice;
  const outstanding = invoice ? Number(invoice.total_amount) - Number(invoice.paid_amount) : 0;

  return (
    <Card className="border-brand-500/30 bg-brand-50/60 p-4">
      <div className="flex items-start gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-600">
          <CheckCircle2 className="h-5 w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-slate-900">
            {re.open ? 'Daftar ulang sedang dibuka' : 'Daftar ulang belum dibuka'}
          </p>
          {re.start || re.end ? (
            <p className="mt-1 text-xs text-slate-600">
              Window: {re.start ? formatDateTime(re.start) : '-'} sampai {re.end ? formatDateTime(re.end) : '-'}.
              {re.end ? ` ${remainingLabel(re.end)} (sisa ${countdownLabel(re.end)}).` : ''}
            </p>
          ) : (
            <p className="mt-1 text-xs text-slate-600">Panitia akan memberi tahu jadwal daftar ulang melalui notifikasi.</p>
          )}

          {invoice ? (
            <div className="mt-2 space-y-1 text-xs text-slate-700">
              <p>
                Tagihan {invoice.number}: {formatRupiah(Number(invoice.total_amount))}
              </p>
              <p>
                Dibayar {formatRupiah(Number(invoice.paid_amount))} · Sisa{' '}
                <span className={outstanding > 0 ? 'font-semibold text-red-600' : 'font-semibold text-green-700'}>
                  {formatRupiah(outstanding)}
                </span>
              </p>
              {invoice.due_date && <p className="text-slate-500">Batas bayar: {formatDateTime(invoice.due_date)}</p>}
              <Link to={`/ppdb/portal/registrations/${registrationId}?langkah=pembayaran`} className="inline-block pt-1">
                <Button size="sm">Bayar & unggah bukti</Button>
              </Link>
            </div>
          ) : (
            <p className="mt-2 text-xs text-slate-600">
              Tagihan daftar ulang belum diterbitkan. Anda akan menerima notifikasi saat tagihan siap dibayar.
            </p>
          )}
        </div>
      </div>
    </Card>
  );
}

export default PpdbPortalSelectionPage;
