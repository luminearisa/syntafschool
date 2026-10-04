import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { laundryApi } from '../api/laundryApi';
import { DateTime, Money, Panel, StatusPill, Weight } from '../components/LaundryUi';

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
    <div className="space-y-4">
      <div>
        <Link to="/laundry/portal" className="text-xs text-slate-500 underline">← Kembali ke laundry anak</Link>
        <h1 className="mt-1 text-lg font-semibold text-slate-900">Order {data.order_number}</h1>
        <div className="mt-1 flex gap-2"><StatusPill status={data.status} /><StatusPill status={data.payment_status} /></div>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Rincian laundry">
          <dl className="space-y-1 text-sm">
            <div className="flex justify-between"><dt className="text-slate-500">Berat netto</dt><dd><Weight value={data.net_weight} /></dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Gratis (jatah)</dt><dd><Weight value={data.free_quantity} /></dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Berbayar</dt><dd><Weight value={data.paid_quantity} /></dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Tagihan</dt><dd className="font-semibold"><Money value={data.total_amount} /></dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Item</dt><dd>{data.item_count} potong</dd></div>
          </dl>
        </Panel>

        <Panel title="Pembayaran wallet" description="Transparan: nominal + referensi + saldo (§26)">
          {wallet.amount != null ? (
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-slate-500">Dibayar</dt><dd className="font-semibold"><Money value={wallet.amount} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Referensi</dt><dd className="font-mono text-xs">{wallet.wallet_transaction ?? '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Saldo sebelum</dt><dd><Money value={wallet.balance_before} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Saldo sesudah</dt><dd><Money value={wallet.balance_after} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Waktu</dt><dd><DateTime value={wallet.paid_at} /></dd></div>
            </dl>
          ) : (
            <p className="text-sm text-slate-500">Sepenuhnya gratis — tidak ada pembayaran wallet.</p>
          )}
        </Panel>
      </div>
    </div>
  );
}
