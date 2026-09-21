import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { BellRing, RefreshCw, CalendarClock, AlertTriangle } from 'lucide-react';
import { toast } from 'sonner';
import { libraryCirculationApi } from '../api/libraryApi';
import { LoanDetailModal, LOAN_STATUS_LABELS, loanStatusTone } from '../components/LoanDetailModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage } from '@/lib/axios';

function returnLink(itemCode: string | null | undefined, loanCode: string): string {
  return itemCode
    ? `/library/circulation/return?code=${encodeURIComponent(itemCode)}`
    : `/library/circulation/return?loan_code=${encodeURIComponent(loanCode)}`;
}

/**
 * Daftar keterlambatan (§7, §10).
 *
 * Menampilkan pinjaman terbuka yang melewati jatuh tempo, diurutkan dari yang
 * paling lama terlambat. Tombol sinkronisasi menyetel ulang status keterlambatan;
 * pengingat harian dikirim otomatis oleh penjadwal.
 */
export function LibraryOverduePage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions } = useLibraryOptions();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [detailId, setDetailId] = useState<number | null>(null);

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (libraryId !== '') out.library_id = libraryId;
    return out;
  }, [libraryId]);

  const listQuery = useQuery({
    queryKey: ['library', 'loans', 'overdue', page, search, libraryId],
    queryFn: () =>
      libraryCirculationApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        overdue: true,
        open: true,
        sort_by: 'due_at',
        sort_dir: 'asc',
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const detailQuery = useQuery({
    queryKey: ['library', 'loans', detailId],
    enabled: detailId != null,
    queryFn: () => libraryCirculationApi.show(detailId as number),
  });

  const markMutation = useMutation({
    mutationFn: () => libraryCirculationApi.markOverdue(libraryId),
    onSuccess: (res) => {
      toast.success(`${res.message} (${res.data.marked} pinjaman)`);
      queryClient.invalidateQueries({ queryKey: ['library'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Keterlambatan</h1>
          <p className="text-sm text-slate-500">
            Pinjaman terbuka yang melewati jatuh tempo, diurutkan dari yang paling lama.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Can perm="library.circulation.manage">
            <Button
              variant="outline"
              loading={markMutation.isPending}
              onClick={() => markMutation.mutate()}
            >
              <RefreshCw className="h-4 w-4" /> Sinkronkan Keterlambatan
            </Button>
          </Can>
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
        </div>
      </div>

      <Card className="flex items-start gap-2 border-amber-200 bg-amber-50/60 p-3">
        <BellRing className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
        <p className="text-xs text-amber-800">
          Pengingat keterlambatan dikirim otomatis setiap hari oleh penjadwal (automation harian).
          Gunakan tombol sinkronisasi untuk memutakhirkan status keterlambatan di luar jadwal.
        </p>
      </Card>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Kode pinjaman / anggota / eksemplar..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
            <SearchSelect
              options={libraryOptions}
              value={libraryId}
              placeholder="-- Semua --"
              onChange={(v) => {
                setLibraryId(v === '' ? '' : Number(v));
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat keterlambatan...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat data"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState title="Tidak ada keterlambatan" description="Semua pinjaman terbuka masih dalam jatuh tempo." />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Anggota</th>
                  <th className="px-4 py-2 font-medium">Eksemplar</th>
                  <th className="px-4 py-2 font-medium">Jatuh Tempo</th>
                  <th className="px-4 py-2 font-medium">Keterlambatan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-32 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2 font-mono text-xs text-slate-700">{row.loan_code}</td>
                    <td className="px-4 py-2">
                      <Link
                        to={`/library/members/${row.member_id}`}
                        className="text-brand-700 hover:underline"
                      >
                        {row.member?.name ?? row.member?.member_number ?? '—'}
                      </Link>
                      {row.member?.member_number && (
                        <div className="font-mono text-xs text-slate-400">{row.member.member_number}</div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <div className="font-mono text-xs text-slate-700">{orDash(row.item?.item_code)}</div>
                      {row.bibliography && (
                        <Link
                          to={`/library/catalog/${row.bibliography.id}`}
                          className="text-xs text-brand-700 hover:underline"
                        >
                          {row.bibliography.title}
                        </Link>
                      )}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{orDash(row.due_at)}</td>
                    <td className="px-4 py-2">
                      <span className="inline-flex items-center gap-1 text-sm font-semibold text-red-600">
                        <AlertTriangle className="h-3.5 w-3.5" />
                        {row.overdue_days} hari
                      </span>
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={loanStatusTone(row.status)}>
                        {LOAN_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap items-center gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          title="Detail"
                          onClick={() => setDetailId(row.id)}
                        >
                          <AlertTriangle className="h-3.5 w-3.5" />
                        </Button>
                        <Link
                          to={returnLink(row.item?.item_code, row.loan_code)}
                          className="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
                        >
                          <CalendarClock className="h-3.5 w-3.5" /> Kembalikan
                        </Link>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <LoanDetailModal
        open={detailId != null}
        loan={detailQuery.data ?? null}
        loading={detailQuery.isLoading}
        onClose={() => setDetailId(null)}
      />
    </div>
  );
}