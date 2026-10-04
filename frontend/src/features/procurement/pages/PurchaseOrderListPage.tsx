import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { formatDate, formatRupiah } from '@/lib/utils';
import { purchaseOrdersApi } from '../api/procurementApi';
import { StatusBadge } from '../components/ProcurementUi';
import { poStatus } from '../lib/labels';

/** Daftar Purchase Order (§21–§30). */
export default function PurchaseOrderListPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'purchase-orders', page, search, status],
    queryFn: () => purchaseOrdersApi.list({ page, per_page: 20, search, status }),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Purchase Order</h1>
        <p className="text-xs text-slate-500">
          PO adalah komitmen resmi ke vendor. Amandemen tidak menimpa histori — versi lama tetap tersimpan.
        </p>
      </header>

      <Card>
        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
          <Input
            placeholder="Cari nomor PO / vendor…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="max-w-xs"
          />
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="max-w-[200px]"
          >
            <option value="">Semua status</option>
            {poStatus.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada PO" description="PO dibuat dari pemilihan vendor yang telah disetujui." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-2 font-medium">Nomor</th>
                  <th className="px-3 py-2 font-medium">Vendor</th>
                  <th className="px-3 py-2 font-medium">Tanggal</th>
                  <th className="px-3 py-2 font-medium">Estimasi tiba</th>
                  <th className="px-3 py-2 text-right font-medium">Nilai</th>
                  <th className="px-5 py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((po) => (
                  <tr key={po.id} className="hover:bg-slate-50">
                    <td className="px-5 py-2">
                      <Link to={`/procurement/purchase-orders/${po.id}`} className="font-mono text-xs text-brand-600 hover:underline">
                        {po.number}
                      </Link>
                    </td>
                    <td className="px-3 py-2 text-slate-700">{po.vendor?.legal_name ?? '-'}</td>
                    <td className="px-3 py-2 text-slate-600">{formatDate(po.po_date)}</td>
                    <td className="px-3 py-2 text-slate-600">{formatDate(po.expected_delivery_date)}</td>
                    <td className="px-3 py-2 text-right tabular-nums text-slate-700">
                      {formatRupiah(po.grand_total ?? 0)}
                    </td>
                    <td className="px-5 py-2">
                      <StatusBadge value={po.status} map={poStatus} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
