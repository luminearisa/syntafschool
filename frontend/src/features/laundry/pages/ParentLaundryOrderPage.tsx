import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Package, Wallet } from 'lucide-react';
import { laundryApi } from '../api/laundryApi';
import { DateTime, Money, PageHeader, Panel, StatusPill, Weight } from '../components/LaundryUi';

/**
 * Detail order untuk wali: split free/paid + pembayaran wallet
 * (saldo sebelum/sesudah + nomor transaksi).
 */
export default function ParentLaundryOrderPage() {
  const { studentId, orderId } = useParams();
  const detail = useQuery({
    queryKey: ['laundry', 'parent-order', studentId, orderId],
    queryFn: () => laundryApi.childOrder(Number(studentId), Number(orderId)),
    enabled: !!studentId && !!orderId,
  });

  if (detail.isLoading || !detail.data) {
    return <p className="text-sm text-slate-500">Memuat detail order…</p>;
  }

  const data = detail.data;
  const wallet = (data.wallet_payment ?? {}) as { amount?: number; wallet_transaction?: string | null; balance_before?: number | null; balance_after?: number | null; paid_at?: string | null; status?: string };

  return (
    <div className="space-y-5">
      <Link to="/laundry/portal" className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition-colors hover:text-brand-700">
        <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke laundry anak
      </Link>

      <PageHeader
        icon={<Package className="h-6 w-6" />}
        title={`Order ${data.order_number}`}
        description={`${data.student_name ?? '—'} · ${data.service ?? '—'}`}
        actions={
          <>
            <StatusPill status={data.status} />
            <StatusPill status={data.payment_status} />
          </>
        }
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Rincian laundry">
          <dl className="space-y-0 text-sm">
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Berat netto</dt><dd className="font-medium"><Weight value={data.net_weight} /></dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Gratis (jatah)</dt><dd className="font-medium"><Weight value={data.free_quantity} /></dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Berbayar</dt><dd className="font-medium"><Weight value={data.paid_quantity} /></dd></div>
            <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Tagihan</dt><dd className="font-semibold text-brand-700"><Money value={data.total_amount} /></dd></div>
            <div className="flex justify-between py-2"><dt className="text-slate-500">Item</dt><dd className="font-medium">{data.item_count} potong</dd></div>
          </dl>
        </Panel>

        <Panel title="Pembayaran wallet" description="Transparan: nominal + referensi + saldo (§26)" action={<Wallet className="h-4 w-4 text-slate-400" />}>
          {wallet.amount != null ? (
            <dl className="space-y-0 text-sm">
              <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Dibayar</dt><dd className="font-semibold text-brand-700"><Money value={wallet.amount} /></dd></div>
              <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Referensi</dt><dd className="font-mono text-xs">{wallet.wallet_transaction ?? '—'}</dd></div>
              <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Saldo sebelum</dt><dd className="font-medium"><Money value={wallet.balance_before} /></dd></div>
              <div className="flex justify-between border-b border-slate-100 py-2"><dt className="text-slate-500">Saldo sesudah</dt><dd className="font-medium"><Money value={wallet.balance_after} /></dd></div>
              <div className="flex justify-between py-2"><dt className="text-slate-500">Waktu</dt><dd className="font-medium"><DateTime value={wallet.paid_at} /></dd></div>
            </dl>
          ) : (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-sm text-emerald-800">
              Sepenuhnya gratis — tidak ada pembayaran wallet.
            </div>
          )}
        </Panel>
      </div>
    </div>
  );
}
