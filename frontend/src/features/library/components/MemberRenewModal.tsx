import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, Download, Printer, QrCode, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { libraryDocumentsApi, libraryMemberCardsApi } from '../api/libraryApi';
import { QrImage } from './CodeImages';
import { orDash } from '../lib/labels';
import { Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { LibraryMember, LibraryMemberCard } from '@/types/libraryCatalog';

/** Tanggal hari ini (zona waktu lokal) dalam format `YYYY-MM-DD`. */
function todayISO(): string {
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
}

function formatDate(value?: string | null): string {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(date);
}

/* ============================ PERPANJANG ============================ */

interface RenewProps {
  open: boolean;
  member: LibraryMember | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (validUntil: string, validFrom?: string) => void;
}

/**
 * Perpanjang masa berlaku keanggotaan.
 *
 * Perpanjangan tidak merusak data: status anggota yang ditangguhkan atau
 * kedaluwarsa akan diaktifkan kembali. Anggota yang **diblokir** tidak dapat
 * diperpanjang dan akan ditolak oleh API.
 */
export function MemberRenewModal({
  open,
  member,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: RenewProps) {
  const [validFrom, setValidFrom] = useState(todayISO());
  const [validUntil, setValidUntil] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setValidFrom(todayISO());
    setValidUntil('');
    setError('');
  }, [open, member?.id]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    if (!validUntil) {
      setError('Tanggal berlaku sampai wajib diisi');
      return;
    }
    if (validUntil <= todayISO()) {
      setError('Tanggal berlaku sampai harus setelah hari ini');
      return;
    }

    setError('');
    onSubmit(validUntil, validFrom || undefined);
  };

  const err = error || serverErrors?.valid_until?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Perpanjang Keanggotaan"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-member-renew-form" loading={submitting}>
            Perpanjang
          </Button>
        </>
      }
    >
      <form id="library-member-renew-form" onSubmit={handleSubmit} className="space-y-4">
        {member && (
          <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-sm">
            <div className="font-medium text-slate-800">{member.display_name ?? member.name ?? '—'}</div>
            <div className="mt-0.5 font-mono text-xs text-slate-500">{member.member_number}</div>
            <div className="mt-2 text-xs text-slate-500">
              Masa berlaku saat ini: {formatDate(member.valid_from)} – {formatDate(member.valid_until)}
            </div>
          </div>
        )}

        <div>
          <Label>Berlaku Dari</Label>
          <input
            type="date"
            value={validFrom}
            onChange={(e) => setValidFrom(e.target.value)}
            className="h-9 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-brand-500/40"
          />
        </div>

        <div>
          <Label required>Berlaku Sampai</Label>
          <input
            type="date"
            value={validUntil}
            min={todayISO()}
            onChange={(e) => setValidUntil(e.target.value)}
            className="h-9 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-brand-500/40"
          />
          {err && <p className="mt-1 text-xs text-red-500">{err}</p>}
        </div>

        <p className="text-xs text-slate-500">
          Perpanjangan juga mengaktifkan kembali keanggotaan yang ditangguhkan atau kedaluwarsa.
          Anggota yang diblokir tidak dapat diperpanjang — statusnya harus diubah terlebih dahulu.
        </p>
      </form>
    </Modal>
  );
}

/* ============================ PANEL KARTU ============================ */

const CARD_STATUS_LABELS: Record<LibraryMemberCard['status'], string> = {
  active: 'Aktif',
  revoked: 'Dicabut',
  expired: 'Kedaluwarsa',
};

const CARD_STATUS_TONES: Record<LibraryMemberCard['status'], 'green' | 'red' | 'amber'> = {
  active: 'green',
  revoked: 'red',
  expired: 'amber',
};

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-slate-50 py-2 last:border-0 sm:flex-row sm:items-start sm:gap-3">
      <span className="w-36 shrink-0 text-xs font-medium text-slate-500">{label}</span>
      <span className="text-sm text-slate-800">{children}</span>
    </div>
  );
}

/**
 * Panel kartu anggota: terbitkan / terbitkan ulang / catat cetak / cabut.
 *
 * Mengambil sendiri kartu aktif anggota (`GET members/{id}/card`) agar selalu
 * sinkron setelah mutasi.
 */
export function MemberCardPanel({ memberId }: { memberId: number }) {
  const queryClient = useQueryClient();

  const [revokeOpen, setRevokeOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [revokeErrors, setRevokeErrors] = useState<Record<string, string[]>>({});
  const [downloadingPdf, setDownloadingPdf] = useState(false);

  const cardQuery = useQuery({
    queryKey: ['library', 'member-cards', 'member', memberId],
    enabled: Number.isFinite(memberId),
    queryFn: () => libraryMemberCardsApi.ofMember(memberId),
  });

  const card = cardQuery.data ?? null;

  const handleDownloadPdf = async () => {
    setDownloadingPdf(true);
    try {
      await libraryDocumentsApi.memberCardPdf(memberId);
      toast.success('Kartu PDF diunduh');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setDownloadingPdf(false);
    }
  };

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['library', 'member-cards'] });
    queryClient.invalidateQueries({ queryKey: ['library', 'members'] });
  };

  const generateMutation = useMutation({
    mutationFn: () => libraryMemberCardsApi.generate(memberId),
    onSuccess: () => {
      toast.success('Kartu anggota diterbitkan');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const printedMutation = useMutation({
    mutationFn: (cardId: number) => libraryMemberCardsApi.markPrinted(cardId),
    onSuccess: () => {
      toast.success('Pencetakan dicatat');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const revokeMutation = useMutation({
    mutationFn: ({ cardId, reason: value }: { cardId: number; reason: string }) =>
      libraryMemberCardsApi.revoke(cardId, value),
    onSuccess: () => {
      toast.success('Kartu dicabut');
      setRevokeOpen(false);
      setReason('');
      setRevokeErrors({});
      invalidate();
    },
    onError: (err) => {
      setRevokeErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Card className="p-4">
      <div className="mb-2 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <QrCode className="h-4 w-4 text-slate-400" />
          <h2 className="text-sm font-semibold text-slate-900">Kartu Anggota</h2>
        </div>
        <Button
          variant="ghost"
          size="icon"
          title="Muat ulang"
          onClick={() => cardQuery.refetch()}
        >
          <RefreshCw className={cardQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      {cardQuery.isLoading ? (
        <div className="flex items-center justify-center gap-2 py-8 text-slate-400">
          <Spinner /> Memuat kartu...
        </div>
      ) : cardQuery.isError ? (
        <EmptyState
          title="Gagal memuat kartu"
          description={getErrorMessage(cardQuery.error)}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={() => cardQuery.refetch()}>
              Coba lagi
            </Button>
          }
        />
      ) : !card ? (
        <div className="space-y-2">
          <EmptyState
            title="Belum ada kartu"
            description="Terbitkan kartu untuk menghasilkan nomor kartu dan token QR anggota."
          />
          <Can perm="library.member_card.generate">
            <div className="flex justify-center">
              <Button
                size="sm"
                loading={generateMutation.isPending}
                onClick={() => generateMutation.mutate()}
              >
                <QrCode className="h-3.5 w-3.5" /> Terbitkan Kartu
              </Button>
            </div>
          </Can>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex items-center gap-3">
            <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-slate-400">
              <QrCode className="h-10 w-10" />
            </div>
            <div className="min-w-0">
              <div className="font-mono text-sm font-medium text-slate-900">{card.card_number}</div>
              <div className="mt-1 flex items-center gap-2">
                <Badge tone={CARD_STATUS_TONES[card.status]}>
                  {CARD_STATUS_LABELS[card.status] ?? card.status}
                </Badge>
                {card.is_valid && <Badge tone="green">Valid</Badge>}
              </div>
            </div>
          </div>

          <div>
            <InfoRow label="Token QR">
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <QrImage value={card.qr_token} size={140} />
                <span className="break-all font-mono text-xs">{card.qr_token}</span>
              </div>
            </InfoRow>
            <InfoRow label="Barcode">
              <span className="font-mono text-xs">{orDash(card.barcode)}</span>
            </InfoRow>
            <InfoRow label="Diterbitkan">{formatDate(card.issued_at)}</InfoRow>
            <InfoRow label="Berlaku Sampai">{formatDate(card.valid_until)}</InfoRow>
            <InfoRow label="Jumlah Cetak">{card.printed_count}×</InfoRow>
            <InfoRow label="Terakhir Dicetak">{formatDate(card.last_printed_at)}</InfoRow>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              loading={downloadingPdf}
              onClick={handleDownloadPdf}
            >
              <Download className="h-3.5 w-3.5" /> Unduh PDF Kartu
            </Button>
          </div>

          <Can perm="library.member_card.generate">
            <div className="flex flex-wrap gap-2 pt-1">
              <Button
                size="sm"
                variant="outline"
                loading={generateMutation.isPending}
                onClick={() => generateMutation.mutate()}
              >
                <QrCode className="h-3.5 w-3.5" /> Terbitkan Ulang
              </Button>
              {card.status === 'active' && (
                <>
                  <Button
                    size="sm"
                    variant="outline"
                    loading={printedMutation.isPending}
                    onClick={() => printedMutation.mutate(card.id)}
                  >
                    <Printer className="h-3.5 w-3.5" /> Catat Cetak
                  </Button>
                  <Button
                    size="sm"
                    variant="danger"
                    onClick={() => {
                      setRevokeErrors({});
                      setReason('');
                      setRevokeOpen(true);
                    }}
                  >
                    <Ban className="h-3.5 w-3.5" /> Cabut
                  </Button>
                </>
              )}
            </div>
          </Can>
        </div>
      )}

      <Modal
        open={revokeOpen}
        onClose={() => setRevokeOpen(false)}
        title="Cabut Kartu"
        size="sm"
        footer={
          <>
            <Button variant="outline" onClick={() => setRevokeOpen(false)} disabled={revokeMutation.isPending}>
              Batal
            </Button>
            <Button
              variant="danger"
              loading={revokeMutation.isPending}
              onClick={() => {
                if (!card) return;
                if (!reason.trim()) {
                  setRevokeErrors({ reason: ['Alasan pencabutan wajib diisi'] });
                  return;
                }
                revokeMutation.mutate({ cardId: card.id, reason: reason.trim() });
              }}
            >
              Cabut
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          <p className="text-sm text-slate-600">
            Kartu yang dicabut tidak lagi valid untuk sirkulasi. Tindakan ini tidak dapat dibatalkan.
          </p>
          <div>
            <Label required>Alasan</Label>
            <Textarea
              value={reason}
              rows={3}
              error={revokeErrors.reason?.[0]}
              placeholder="mis. kartu hilang"
              onChange={(e) => setReason(e.target.value)}
            />
          </div>
        </div>
      </Modal>
    </Card>
  );
}