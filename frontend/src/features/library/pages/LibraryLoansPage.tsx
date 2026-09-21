import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CalendarClock, Eye, RefreshCw, RotateCw } from 'lucide-react';
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
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { LoanStatus } from '@/types/libraryCirculation';

const STATUS_OPTIONS = Object.keys(LOAN_STATUS_LABELS) as LoanStatus[];

/** Pesan validasi pertama — alasan penolakan perpanjangan (mis. 422). */
function firstValidationMessage(err: unknown): string | null {
  const errors = getValidationErrors(err);
  if (!errors) return null;
  const first = Object.values(errors)[0];
  return first && first.length > 0 ? first[0] : null;
}

/** Tautan ke halaman pengembalian dengan kode eksemplar/pinjaman terisi. */
function returnLink(itemCode: string | null | undefined, loanCode: string): string {
  return itemCode
    ? `/library/circulation/return?code=${encodeURIComponent(itemCode)}`
    : `/library/circulation/return?loan_code=${encodeURIComponent(loanCode)}`;
}

/**
 * Daftar peminjaman (§7).
 *
 * Mendukung pencarian, filter status/periode/perpustakaan, detail, serta
 * perpanjangan (dengan alasan penolakan dari server ditampilkan apa adanya).
 */
export function LibraryLoansPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions } = useLibraryOptions();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<LoanStatus | ''>('');
  const [openOnly, setOpenOnly] = useState(false);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [detailId, setDetailId] = useState<number | null>(null);

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (status) out.status = status;
    if (libraryId !== '') out.library_id = libraryId;
    return out;
  }, [status, libraryId]);

  const listQuery = useQuery({
    queryKey: ['library', 'loans', page, search, status, openOnly, from, to, libraryId],
    queryFn: () =>
      libraryCirculationApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        open: openOnly,
        from: from || undefined,
        to: to || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const detailQuery = useQuery({
    queryKey: ['library', 'loans', detailId],
    enabled: detailId != null,
    queryFn: () => libraryCirculationApi.show(detailId as number),
  });

  const renewMutation = useMutation({
    mutationFn: (id: number) => libraryCirculationApi.renew(id),
    onSuccess: (res) => {
      toast.success(res.message);
      queryClient.invalidateQueries({ queryKey: ['library'] });
    },
    onError: (err) => toast.error(firstValidationMessage(err) ?? getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Peminjaman</h1>
          <p className="text-sm text-slate-500">
            Daftar transaksi peminjaman beserta status, jatuh tempo, dan perpanjangannya.
          </p>
        </div>
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
          <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as LoanStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {STATUS_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {LOAN_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
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
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari Tanggal</label>
            <Input
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai Tanggal</label>
            <Input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="flex items-end">
            <label className="inline-flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={openOnly}
                onChange={(e) => {
                  setOpenOnly(e.target.checked);
                  setPage(1);
                }}
              />
              Hanya yang belum dikembalikan
            </label>
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat peminjaman...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat peminjaman"
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
          <EmptyState title="Belum ada peminjaman" description="Tidak ada transaksi yang cocok dengan filter." />
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
                  <th className="px-4 py-2 font-medium">Dipinjam</th>
                  <th className="px-4 py-2 font-medium">Jatuh Tempo</th>
                  <th className="px-4 py-2 font-medium">Dikembalikan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-44 px-4 py-2 font-medium">Aksi</th>
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
                    <td className="px-4 py-2 text-xs text-slate-600">{orDash(row.borrowed_at)}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{orDash(row.due_at)}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{orDash(row.returned_on)}</td>
                    <td className="px-4 py-2">
                      <Badge tone={loanStatusTone(row.status)}>
                        {LOAN_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                      {row.is_overdue && (
                        <div className="mt-0.5 text-xs font-medium text-red-600">
                          {row.overdue_days} hari
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap items-center gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          title="Detail"
                          onClick={() => setDetailId(row.id)}
                        >
                          <Eye className="h-3.5 w-3.5" />
                        </Button>
                        {row.is_open && (
                          <>
                            <Can perm="library.circulation.renew">
                              <Button
                                variant="outline"
                                size="sm"
                                loading={renewMutation.isPending && renewMutation.variables === row.id}
                                onClick={() => renewMutation.mutate(row.id)}
                              >
                                <RotateCw className="h-3.5 w-3.5" /> Perpanjang
                              </Button>
                            </Can>
                            <Link
                              to={returnLink(row.item?.item_code, row.loan_code)}
                              className="inline-flex h-8 items-center gap-1 rounded-lg px-3 text-xs font-medium text-slate-600 hover:bg-slate-100"
                            >
                              <CalendarClock className="h-3.5 w-3.5" /> Kembalikan
                            </Link>
                          </>
                        )}
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