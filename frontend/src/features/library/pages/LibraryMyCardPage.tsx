import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CreditCard, Download, Printer, QrCode, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { libraryDocumentsApi, libraryMemberCardsApi } from '../api/libraryApi';
import { QrImage } from '../components/CodeImages';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import { formatDate } from '@/lib/utils';

/**
 * "Kartu Saya" (§40).
 *
 * Menampilkan kartu perpustakaan milik pengguna yang sedang login, tanpa
 * permission modul — di luar `PermissionGuard`. Dapat dicetak.
 */
export function LibraryMyCardPage() {
  const { can } = usePermission();
  const [downloadingPdf, setDownloadingPdf] = useState(false);

  const cardQuery = useQuery({
    queryKey: ['library', 'member-cards', 'mine'],
    queryFn: () => libraryMemberCardsApi.mine(),
  });

  const card = cardQuery.data ?? null;

  const handleDownloadPdf = async () => {
    if (!card) return;
    setDownloadingPdf(true);
    try {
      await libraryDocumentsApi.memberCardPdf(card.member_id);
      toast.success('Kartu PDF diunduh');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setDownloadingPdf(false);
    }
  };

  return (
    <div className="space-y-4">
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #member-card-print, #member-card-print * { visibility: visible; }
          #member-card-print { position: absolute; left: 0; top: 0; width: 100%; }
          .no-print { display: none !important; }
        }
      `}</style>

      <div className="no-print flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kartu Saya</h1>
          <p className="text-sm text-slate-500">
            Kartu perpustakaan pribadi Anda. Tunjukkan kartu ini saat meminjam koleksi.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => cardQuery.refetch()}>
            <RefreshCw className={cardQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          {card && (
            <>
              <Button variant="outline" loading={downloadingPdf} onClick={handleDownloadPdf}>
                <Download className="h-4 w-4" /> Unduh PDF
              </Button>
              <Button onClick={() => window.print()}>
                <Printer className="h-4 w-4" /> Cetak
              </Button>
            </>
          )}
        </div>
      </div>

      {cardQuery.isLoading ? (
        <Card className="no-print">
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat kartu...
          </div>
        </Card>
      ) : cardQuery.isError ? (
        <Card className="no-print">
          <EmptyState
            title="Gagal memuat kartu"
            description={getErrorMessage(cardQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => cardQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : !card ? (
        <Card className="no-print">
          <EmptyState
            title="Anda belum menjadi anggota perpustakaan"
            description="Kartu belum diterbitkan untuk akun Anda. Hubungi petugas perpustakaan untuk mendaftar sebagai anggota."
            action={
              can('library.member.view') ? (
                <Link
                  to="/library/members"
                  className="mt-2 text-xs font-medium text-brand-700 hover:underline"
                >
                  Buka halaman Anggota
                </Link>
              ) : undefined
            }
          />
        </Card>
      ) : (
        <div id="member-card-print" className="flex justify-center">
          <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3 border-b border-slate-100 pb-3">
              {card.library?.logo_path ? (
                <img
                  src={card.library.logo_path}
                  alt={card.library.name}
                  className="h-10 w-10 rounded-lg object-contain"
                />
              ) : (
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                  <CreditCard className="h-5 w-5" />
                </div>
              )}
              <div className="min-w-0">
                <div className="truncate text-sm font-semibold text-slate-900">
                  {card.library?.name ?? 'Perpustakaan'}
                </div>
                <div className="text-xs text-slate-500">Kartu Anggota</div>
              </div>
              <div className="ml-auto">
                <Badge tone={card.is_valid ? 'green' : 'red'}>
                  {card.is_valid ? 'Valid' : 'Tidak Valid'}
                </Badge>
              </div>
            </div>

            <div className="flex items-center gap-4 py-4">
              {card.member?.photo_path ? (
                <img
                  src={card.member.photo_path}
                  alt={card.member.display_name ?? ''}
                  className="h-16 w-16 rounded-full object-cover"
                />
              ) : (
                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                  <QrCode className="h-7 w-7" />
                </div>
              )}
              <div className="min-w-0">
                <div className="truncate text-base font-semibold text-slate-900">
                  {card.member?.display_name ?? '—'}
                </div>
                <div className="font-mono text-xs text-slate-500">
                  {card.member?.member_number ?? card.card_number}
                </div>
                {card.member?.membership_type && (
                  <div className="mt-0.5 text-xs text-slate-500">
                    {card.member.membership_type}
                  </div>
                )}
              </div>
            </div>

            <div className="flex items-center justify-between gap-4 border-t border-slate-100 pt-4">
              <div className="inline-flex flex-col items-center gap-2">
                <QrImage value={card.qr_token} size={200} />
                <span className="max-w-[12rem] break-all text-center font-mono text-[10px] text-slate-500">
                  {card.qr_token}
                </span>
              </div>
              <div className="text-right text-xs text-slate-500">
                <div className="font-medium text-slate-600">Nomor Kartu</div>
                <div className="font-mono text-sm text-slate-900">{card.card_number}</div>
                <div className="mt-2 font-medium text-slate-600">Berlaku Sampai</div>
                <div className="text-sm text-slate-900">
                  {formatDate(card.member?.valid_until ?? card.valid_until)}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}