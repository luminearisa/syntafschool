import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Printer, Search } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { assetsApi, labelsApi } from '../api/assetApi';
import { AssetLabelCard, LabelPrintButton } from '../components/AssetLabelCard';
import { AssetScannerInput } from '../components/AssetScannerInput';
import type { AssetLabel } from '../types';

/**
 * Lembar kerja pelabelan.
 *
 * Alur nyata di sekolah: petugas menyiapkan daftar barang (dari saringan daftar
 * aset atau hasil pemindaian), mencentang, lalu mencetak sekali jalan. Karena itu
 * halaman ini bekerja dengan **pilihan**, bukan satu barang per layar.
 */
export default function AssetLabelPrintPage() {
  const [params] = useSearchParams();
  const idAwal = useMemo(
    () =>
      (params.get('ids') ?? '')
        .split(',')
        .map((v) => Number(v))
        .filter((v) => Number.isFinite(v) && v > 0),
    [params],
  );

  const [terpilih, setTerpilih] = useState<number[]>(idAwal);
  const [cari, setCari] = useState('');
  const [labels, setLabels] = useState<AssetLabel[]>([]);

  const { data: kandidat, isLoading } = useQuery({
    queryKey: ['asset', 'label-candidates', cari],
    queryFn: () => assetsApi.list({ search: cari, per_page: 25, tag_status: cari ? undefined : 'UNTAGGED' }),
  });

  const siapkan = useMutation({
    mutationFn: () => assetsApi.bulkPrintLabels({ asset_ids: terpilih }),
    onSuccess: (hasil) => {
      setLabels(hasil.labels ?? []);
      toast.success(`${hasil.labels?.length ?? 0} label siap dicetak`);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const tandaiTercetak = useMutation({
    mutationFn: async () => {
      for (const label of labels) {
        const assetId = terpilih[labels.indexOf(label)];
        if (assetId) await labelsApi.markPrinted(assetId, { copies: 1 });
      }
    },
    onSuccess: () => toast.success('Semua label ditandai tercetak'),
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Label &amp; QR Aset</h1>
          <p className="text-xs text-slate-500">
            Pilih barang, siapkan label, lalu cetak. Barang tanpa label tetap tidak terlihat saat opname memindai.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <LabelPrintButton
            labels={labels}
            disabled={terpilih.length === 0 || siapkan.isPending}
            onPrint={() => tandaiTercetak.mutate()}
          />
          <Button size="sm" onClick={() => siapkan.mutate()} disabled={terpilih.length === 0 || siapkan.isPending}>
            {siapkan.isPending ? <Spinner /> : <Printer className="mr-1 h-4 w-4" />} Siapkan {terpilih.length || ''} label
          </Button>
        </div>
      </header>

      <Card className="p-4 print:hidden">
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          <AssetScannerInput
            onScan={(code) =>
              assetsApi
                .lookup(code)
                .then((asset) => {
                  setTerpilih((prev) => (prev.includes(asset.id) ? prev : [...prev, asset.id]));
                  toast.success(`${asset.name} ditambahkan`);
                })
                .catch(() => toast.error(`Kode ${code} tidak dikenali`))
            }
            hint="Pindai barang yang akan dilabeli — langsung masuk daftar cetak."
            autoFocus={false}
          />
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              className="ui-control h-9 w-full rounded-lg border border-slate-300 pl-9 pr-3 text-sm"
              placeholder="Cari barang untuk dilabeli…"
              value={cari}
              onChange={(e) => setCari(e.target.value)}
            />
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2 print:hidden">
        <Card>
          <header className="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-800">
            Kandidat pelabelan
          </header>
          {isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : (kandidat?.data ?? []).length === 0 ? (
            <EmptyState title="Tidak ada kandidat" description="Semua barang pada saringan ini sudah berlabel." />
          ) : (
            <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto">
              {(kandidat?.data ?? []).map((asset) => (
                <li key={asset.id} className="flex items-center justify-between gap-2 px-5 py-2.5 text-sm">
                  <label className="flex items-center gap-2">
                    <input
                      type="checkbox"
                      checked={terpilih.includes(asset.id)}
                      onChange={(e) =>
                        setTerpilih((prev) => (e.target.checked ? [...prev, asset.id] : prev.filter((v) => v !== asset.id)))
                      }
                    />
                    <span>
                      <span className="font-medium text-slate-800">{asset.name}</span>
                      <span className="ml-2 font-mono text-[11px] text-slate-500">{asset.asset_code}</span>
                    </span>
                  </label>
                  <span className="text-[11px] text-slate-500">{asset.location?.name ?? '-'}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <header className="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-800">
            Daftar cetak ({terpilih.length})
          </header>
          <div className="max-h-96 overflow-y-auto px-5 py-3">
            {terpilih.length === 0 ? (
              <EmptyState title="Belum ada barang dipilih" />
            ) : (
              <ul className="space-y-1 text-xs text-slate-600">
                {terpilih.map((id) => (
                  <li key={id} className="flex items-center justify-between">
                    <span>Aset #{id}</span>
                    <button type="button" className="text-red-600 hover:underline" onClick={() => setTerpilih((p) => p.filter((v) => v !== id))}>
                      hapus
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Card>
      </div>

      {labels.length > 0 && (
        <Card className="p-5">
          <div className="mb-3 flex items-center justify-between print:hidden">
            <h2 className="text-sm font-semibold text-slate-800">Pratinjau label</h2>
            <span className="text-xs text-slate-500">Ukuran cetak mengikuti pengaturan printer; pratinjau hanya untuk memastikan isinya benar.</span>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {labels.map((label) => (
              <AssetLabelCard key={label.asset_code} label={label} />
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
