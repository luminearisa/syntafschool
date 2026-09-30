import { useCallback, useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { CloudOff, FileDown, RefreshCw, UploadCloud, Wifi, WifiOff } from 'lucide-react';
import { toast } from 'sonner';
import {
  clearQueue,
  isOnline,
  pendingAnswers,
  removeFromQueue,
  type QueuedAnswer,
} from '../offline/offlineQueue';
import { supervisiVisitApi } from '../api/supervisiVisitApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';

interface Props {
  visitId: number;
  visitCode: string;
  /** Antrean berubah → halaman dapat menampilkan penanda di tempat lain. */
  onQueueChange?: (count: number) => void;
}

/**
 * Mode luring: mengamati kelas tanpa jaringan.
 *
 * Alur yang didukung: unduh formulir kosong → isi di kelas dengan tangan →
 * masukkan nilai di aplikasi (tetap tersimpan walau sinyal hilang) → kirim
 * saat jaringan kembali. Semua pengiriman idempoten (`client_uuid`), sehingga
 * menekan "Kirim" dua kali tidak menghasilkan nilai ganda.
 */
export default function OfflineModePanel({ visitId, visitCode, onQueueChange }: Props) {
  const [online, setOnline] = useState<boolean>(isOnline());
  const [queue, setQueue] = useState<QueuedAnswer[]>(() => pendingAnswers(visitId));

  useEffect(() => {
    setQueue(pendingAnswers(visitId));
  }, [visitId]);

  useEffect(() => {
    onQueueChange?.(queue.length);
  }, [queue.length, onQueueChange]);

  useEffect(() => {
    const naik = () => setOnline(true);
    const turun = () => setOnline(false);

    window.addEventListener('online', naik);
    window.addEventListener('offline', turun);

    return () => {
      window.removeEventListener('online', naik);
      window.removeEventListener('offline', turun);
    };
  }, []);

  const download = useMutation({
    mutationFn: () => supervisiVisitApi.blankForm(visitId),
    onSuccess: (blob) => {
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `formulir-luring-${visitCode.split('/').join('-')}.pdf`;
      link.click();
      URL.revokeObjectURL(url);
      toast.success('Formulir kosong diunduh — siap dicetak untuk observasi luring.');
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const sync = useMutation({
    mutationFn: () =>
      supervisiVisitApi.syncOfflineAnswers(visitId, {
        device: navigator.userAgent.slice(0, 120),
        answers: queue.map((row) => ({
          indicator_id: row.indicator_id,
          option_id: row.option_id ?? undefined,
          option_code: row.option_code ?? undefined,
          value_number: row.value_number ?? undefined,
          value_text: row.value_text ?? undefined,
          value_long_text: row.value_long_text ?? undefined,
          is_na: row.is_na ?? undefined,
          comment: row.comment ?? undefined,
          client_uuid: row.client_uuid,
          captured_at: row.captured_at,
        })),
      }),
    onSuccess: (result) => {
      const selesai = [
        ...result.skipped.map((row) => row.indicator_id),
        ...result.rejected.map((row) => row.indicator_id),
        ...result.conflicts.map((row) => row.indicator_id),
      ];

      setQueue(removeFromQueue(visitId, selesai));

      const pesan = `${result.applied} tersimpan · ${result.skipped.length} sudah ada · ${result.conflicts.length} perlu diperiksa · ${result.rejected.length} ditolak`;
      toast.success(pesan);

      if (result.conflicts.length > 0) {
        toast.warning('Ada nilai yang di server lebih baru (diisi lewat aplikasi). Nilai server dipertahankan — periksa lalu kirim ulang bila perlu.');
      }

      if (result.rejected.length > 0) {
        toast.error(`Ditolak: ${result.rejected.map((row) => row.reason).join('; ')}`);
      }

      if (result.visit.is_locked) {
        clearQueue(visitId);
        setQueue([]);
        toast.info('Kegiatan sudah difinalkan — antrean perangkat dibersihkan.');
      }
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const bersihkan = useCallback(() => {
    clearQueue(visitId);
    setQueue([]);
    toast.success('Antrean perangkat dibersihkan.');
  }, [visitId]);

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <CloudOff className="h-4 w-4 text-brand-600" />
            <CardTitle>Mode Luring</CardTitle>
          </div>
          <div className="flex items-center gap-2">
            <Badge tone={online ? 'green' : 'amber'}>
              {online ? <Wifi className="mr-1 h-3 w-3" /> : <WifiOff className="mr-1 h-3 w-3" />}
              {online ? 'jaringan tersedia' : 'tanpa jaringan'}
            </Badge>
            <Badge tone={queue.length > 0 ? 'amber' : 'gray'}>{queue.length} menunggu dikirim</Badge>
          </div>
        </div>
      </CardHeader>
      <CardBody className="space-y-3">
        <p className="text-sm text-slate-600">
          Untuk observasi di ruang tanpa sinyal: unduh formulir, isi di kelas, lalu masukkan nilainya di sini —
          jawaban tersimpan di perangkat sampai berhasil dikirim. Pengiriman aman diulang, tidak ada nilai yang
          terhitung dua kali.
        </p>

        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={() => download.mutate()} disabled={download.isPending}>
            {download.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <FileDown className="mr-2 h-4 w-4" />} Formulir kosong (PDF)
          </Button>
          <Button onClick={() => sync.mutate()} disabled={sync.isPending || queue.length === 0}>
            {sync.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <UploadCloud className="mr-2 h-4 w-4" />} Kirim antrean
          </Button>
          {queue.length > 0 && (
            <Button variant="ghost" onClick={bersihkan}>
              <RefreshCw className="mr-2 h-4 w-4" /> Bersihkan antrean
            </Button>
          )}
        </div>

        {queue.length > 0 && (
          <div className="max-h-48 overflow-auto rounded-lg border border-slate-200">
            <table className="w-full text-xs">
              <thead className="bg-slate-50 text-left uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-2 py-1.5 font-medium">Indikator</th>
                  <th className="px-2 py-1.5 font-medium">Nilai</th>
                  <th className="px-2 py-1.5 font-medium">Direkam</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {queue.map((row) => (
                  <tr key={row.client_uuid}>
                    <td className="px-2 py-1.5 text-slate-700">{row.indicator_label ?? `#${row.indicator_id}`}</td>
                    <td className="px-2 py-1.5">{row.value_label ?? row.value_text ?? row.value_number ?? row.option_code ?? '—'}</td>
                    <td className="px-2 py-1.5 text-slate-500">
                      {new Date(row.captured_at).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardBody>
    </Card>
  );
}
