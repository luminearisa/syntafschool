import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CreditCard, Download, Printer, RefreshCw, Tag } from 'lucide-react';
import { toast } from 'sonner';
import { libraryCatalogApi, libraryDocumentsApi, libraryItemsApi } from '../api/libraryApi';
import { BarcodeSvg, QrImage } from '../components/CodeImages';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import type { SelectOption } from '@/constants/masterData';
import type { LibraryItem } from '@/types/libraryCatalog';

export function LibraryLabelPrintPage() {
  const [mode, setMode] = useState<'bibliography' | 'items'>('items');
  const [catalogSearch, setCatalogSearch] = useState('');
  const [bibliographyId, setBibliographyId] = useState<number | ''>('');
  const [itemSearch, setItemSearch] = useState('');

  const [selected, setSelected] = useState<LibraryItem[]>([]);
  const [downloadingPdf, setDownloadingPdf] = useState(false);

  const catalogQuery = useQuery({
    queryKey: ['library', 'labels', 'catalog', catalogSearch],
    enabled: mode === 'bibliography',
    queryFn: () => libraryCatalogApi.list({ search: catalogSearch || undefined, per_page: 50 }),
  });
  const catalogOptions: SelectOption[] = useMemo(
    () =>
      (catalogQuery.data?.data ?? []).map((b) => ({
        value: b.id,
        label: b.control_number ? `${b.control_number} — ${b.title}` : b.title,
      })),
    [catalogQuery.data],
  );

  const copiesQuery = useQuery({
    queryKey: ['library', 'labels', 'copies', bibliographyId],
    enabled: mode === 'bibliography' && bibliographyId !== '',
    queryFn: () => libraryItemsApi.ofBibliography(Number(bibliographyId)),
  });

  const itemsQuery = useQuery({
    queryKey: ['library', 'labels', 'items', itemSearch],
    enabled: mode === 'items',
    queryFn: () => libraryItemsApi.list({ search: itemSearch || undefined, per_page: 25 }),
  });

  const activeList: LibraryItem[] =
    mode === 'bibliography' ? (copiesQuery.data ?? []) : (itemsQuery.data?.data ?? []);

  const isFetching = mode === 'bibliography' ? copiesQuery.isFetching : itemsQuery.isFetching;
  const isLoading = mode === 'bibliography' ? copiesQuery.isLoading : itemsQuery.isLoading;
  const isError = mode === 'bibliography' ? copiesQuery.isError : itemsQuery.isError;
  const error = mode === 'bibliography' ? copiesQuery.error : itemsQuery.error;

  const selectedIds = useMemo(() => new Set(selected.map((s) => s.id)), [selected]);

  const toggle = (item: LibraryItem) => {
    setSelected((prev) =>
      prev.some((s) => s.id === item.id)
        ? prev.filter((s) => s.id !== item.id)
        : [...prev, item],
    );
  };

  const clearSelection = () => setSelected([]);

  const handleDownloadPdf = async () => {
    const itemIds = selected.map((s) => s.id);
    if (itemIds.length === 0 && bibliographyId === '') {
      toast.error('Pilih minimal satu eksemplar atau satu judul.');
      return;
    }
    setDownloadingPdf(true);
    try {
      await libraryDocumentsApi.labelsPdf(
        itemIds.length > 0 ? { item_ids: itemIds } : { bibliography_id: Number(bibliographyId) },
      );
      toast.success('Label PDF diunduh');
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
  #label-print-area, #label-print-area * { visibility: visible; }
  #label-print-area { position: absolute; left: 0; top: 0; width: 100%; }
  .no-print { display: none !important; }
}
      `}</style>

      <div className="no-print flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Cetak Label & Barcode</h1>
          <p className="text-sm text-slate-500">
            Pilih eksemplar lalu cetak label berisi barcode Code 128 dan kode QR yang dapat dipindai.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => {
              if (mode === 'bibliography') copiesQuery.refetch();
              else itemsQuery.refetch();
            }}
          >
            <RefreshCw className={cn('h-4 w-4', isFetching && 'animate-spin')} />
          </Button>
          <Button variant="outline" loading={downloadingPdf} onClick={handleDownloadPdf}>
            <Download className="h-4 w-4" /> Unduh PDF Label
          </Button>
          <Button
            disabled={selected.length === 0}
            onClick={() => window.print()}
          >
            <Printer className="h-4 w-4" /> Cetak {selected.length > 0 ? `(${selected.length})` : ''}
          </Button>
        </div>
      </div>

      <Card className="no-print p-4">
        <div className="flex flex-wrap gap-1.5">
          <button
            type="button"
            onClick={() => setMode('items')}
            className={cn(
              'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
              mode === 'items' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100',
            )}
          >
            Cari Eksemplar
          </button>
          <button
            type="button"
            onClick={() => setMode('bibliography')}
            className={cn(
              'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
              mode === 'bibliography'
                ? 'bg-brand-600 text-white'
                : 'text-slate-600 hover:bg-slate-100',
            )}
          >
            Pilih per Judul
          </button>
        </div>

        {mode === 'items' ? (
          <div className="mt-3">
            <Label>Cari eksemplar</Label>
            <Input
              value={itemSearch}
              placeholder="Kode / nomor inventaris / barcode / judul..."
              onChange={(e) => setItemSearch(e.target.value)}
            />
          </div>
        ) : (
          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label>Cari judul</Label>
              <Input
                value={catalogSearch}
                placeholder="Ketik judul lalu pilih pada daftar di samping..."
                onChange={(e) => setCatalogSearch(e.target.value)}
              />
            </div>
            <div>
              <Label>Judul (bibliografi)</Label>
              <SearchSelect
                options={catalogOptions}
                value={bibliographyId}
                onChange={(v) => setBibliographyId(v === '' ? '' : Number(v))}
                placeholder="-- Pilih judul --"
              />
            </div>
          </div>
        )}

        {selected.length > 0 && (
          <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
            <span className="text-sm text-slate-700">
              <span className="font-semibold">{selected.length}</span> eksemplar dipilih
            </span>
            <Button size="sm" variant="ghost" onClick={clearSelection}>
              Bersihkan
            </Button>
          </div>
        )}
      </Card>

      <Card className="no-print">
        <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
          <Tag className="h-4 w-4 text-slate-400" />
          <h3 className="text-sm font-semibold text-slate-900">
            {mode === 'bibliography' ? 'Eksemplar pada Judul Terpilih' : 'Hasil Pencarian Eksemplar'}
          </h3>
        </div>
        {isLoading ? (
          <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
            <Spinner /> Memuat eksemplar...
          </div>
        ) : isError ? (
          <EmptyState title="Gagal memuat eksemplar" description={getErrorMessage(error)} />
        ) : activeList.length === 0 ? (
          <EmptyState
            title="Tidak ada eksemplar"
            description={
              mode === 'bibliography'
                ? 'Pilih judul yang memiliki eksemplar.'
                : 'Ubah kata kunci pencarian.'
            }
          />
        ) : (
          <ul className="divide-y divide-slate-50">
            {activeList.map((item) => (
              <li key={item.id} className="flex items-center gap-3 px-4 py-2">
                <input
                  type="checkbox"
                  checked={selectedIds.has(item.id)}
                  onChange={() => toggle(item)}
                  aria-label={`Pilih ${item.item_code}`}
                />
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-700">{item.item_code}</span>
                    <Badge tone={item.is_available ? 'green' : 'gray'}>
                      {item.is_available ? 'Tersedia' : 'Tidak tersedia'}
                    </Badge>
                  </div>
                  <p className="truncate text-sm text-slate-600">
                    {item.bibliography?.title ?? '—'}
                  </p>
                </div>
                <span className="shrink-0 font-mono text-xs text-slate-400">
                  {item.barcode ?? 'tanpa barcode'}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card className="no-print border-brand-200 bg-brand-50/40 p-4">
        <div className="flex items-center gap-2">
          <CreditCard className="h-4 w-4 text-brand-600" />
          <h3 className="text-sm font-semibold text-slate-900">Kartu Anggota</h3>
        </div>
        <p className="mt-1 text-sm text-slate-600">
          Cetak kartu anggota per orang melalui{' '}
          <Link to="/library/my-card" className="font-medium text-brand-700 hover:underline">
            Kartu Saya
          </Link>
          . Untuk pencetakan kartu massal, buka halaman{' '}
          <Link to="/library/members" className="font-medium text-brand-700 hover:underline">
            Anggota
          </Link>{' '}
          lalu pilih beberapa anggota dan gunakan aksi "Terbitkan Kartu".
        </p>
      </Card>

      {selected.length === 0 ? (
        <Card className="no-print">
          <EmptyState
            title="Belum ada label"
            description="Pilih eksemplar di atas untuk menampilkan pratinjau label."
          />
        </Card>
      ) : (
        <div id="label-print-area" className="space-y-3">
          <p className="no-print text-xs text-slate-400">
            Pratinjau label — barcode Code 128 dan kode QR di bawah ini dapat dipindai langsung.
          </p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {selected.map((item) => (
              <div
                key={item.id}
                className="break-inside-avoid rounded-lg border border-slate-300 bg-white p-3"
              >
                <p className="truncate text-xs font-medium text-slate-500">
                  {item.library ? `${item.library.name} (${item.library.code})` : 'Perpustakaan'}
                </p>
                <p className="mt-0.5 line-clamp-2 text-sm font-semibold text-slate-900">
                  {item.bibliography?.title ?? '—'}
                </p>
                <dl className="mt-1 grid grid-cols-2 gap-x-2 text-xs text-slate-600">
                  <dt className="text-slate-400">Kode</dt>
                  <dd className="font-mono">{item.item_code}</dd>
                  <dt className="text-slate-400">Inventaris</dt>
                  <dd className="font-mono">{item.accession_number ?? '—'}</dd>
                </dl>
                <div className="mt-2 flex items-end gap-2">
                  <div className="min-w-0 flex-1">
                    <BarcodeSvg value={item.barcode ?? item.item_code} displayValue={false} />
                  </div>
                  <QrImage value={item.barcode ?? item.item_code} size={72} alt={`QR ${item.item_code}`} />
                </div>
                <p className="mt-0.5 text-center font-mono text-xs text-slate-700">
                  {item.barcode ?? item.item_code}
                </p>
                <p className="mt-1 text-center text-[10px] uppercase tracking-wide text-slate-300">
                  Pratinjau label
                </p>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}