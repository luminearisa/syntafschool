import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Input } from '@/components/ui/Input';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, Money, Panel, Stat, StatusPill, Weight } from '../components/LaundryUi';

/**
 * Portal wali (§25/§26, §63, §71).
 *
 * Header jatah + wallet + riwayat per anak. Backend menegakkan isolasi:
 * hanya authorized dependent (Rule 20).
 */
export default function ParentLaundryPage() {
  const [studentId, setStudentId] = useState('');

  const children = useQuery({ queryKey: ['laundry', 'parent-children'], queryFn: () => laundryApi.parentChildren() });
  const overview = useQuery({
    queryKey: ['laundry', 'parent-overview', studentId],
    queryFn: () => laundryApi.childOverview(Number(studentId)),
    enabled: !!studentId,
  });
  const statement = useQuery({
    queryKey: ['laundry', 'parent-statement', studentId],
    queryFn: () => laundryApi.childStatement(Number(studentId)),
    enabled: !!studentId,
  });

  const list = children.data ?? [];
  const activeId = studentId || String((list[0] as unknown as { student?: { id?: number } })?.student?.id ?? (list[0] as unknown as { student_id?: number })?.student_id ?? '');
  const context = overview.data;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Laundry Anak</h1>
        <p className="text-sm text-slate-500">Jatah gratis, pemakaian, sisa, saldo wallet, dan riwayat laundry.</p>
      </div>

      <Panel title="Pilih anak">
        {children.isLoading ? <p className="text-sm text-slate-500">Memuat…</p> : (
          <div className="flex flex-wrap gap-2">
            {list.map((child, i) => {
              const student = (child.student ?? {}) as { id?: number; full_name?: string; nis?: string };
              const id = String(student.id ?? (child as unknown as { student_id?: number }).student_id ?? i);
              return (
                <button
                  key={id}
                  type="button"
                  onClick={() => setStudentId(id)}
                  className={`rounded-lg px-3 py-2 text-sm font-medium ${(activeId === id || (!studentId && i === 0)) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}
                >
                  {String(student.full_name ?? `Anak ${i + 1}`)}
                </button>
              );
            })}
            {list.length === 0 ? <p className="text-sm text-slate-500">Belum ada anak tertaut.</p> : null}
          </div>
        )}
        <div className="mt-2 hidden">
          <Input value={studentId} onChange={(e) => setStudentId(e.target.value)} />
        </div>
      </Panel>

      {activeId ? (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Sisa jatah" value={<Weight value={context?.quota.remaining} />} hint={`${context?.quota.quota_type ?? ''} · periode ${context?.quota.period?.key ?? '—'}`} />
            <Stat label="Terpakai" value={<Weight value={context?.quota.used} />} hint={`dari ${context?.quota.allocated ?? 0}`} />
            <Stat label="Saldo wallet" value={<Money value={context?.wallet?.balance} />} hint={context?.wallet?.number ?? '—'} />
            <Stat label="Total dibayar" value={<Money value={context?.paid_total} compact />} hint="periode berjalan" />
          </div>

          <Panel title="Order terakhir" description="Klik untuk detail + referensi transaksi wallet (§26)">
            <DataTable
              loading={overview.isLoading}
              rows={context?.recent_orders ?? []}
              empty="Belum ada order."
              columns={[
                { key: 'order', header: 'Order', render: (r) => (
                  <Link to={`/laundry/portal/${activeId}/orders/${r.id}`} className="font-medium underline">{r.order_number}</Link>
                ) },
                { key: 'date', header: 'Tanggal', render: (r) => <DateOnly value={r.order_date} /> },
                { key: 'split', header: 'Gratis / Bayar', render: (r) => (
                  <span><Weight value={r.free_quantity} /> / <Weight value={r.paid_quantity} /></span>
                ) },
                { key: 'amount', header: 'Tagihan', render: (r) => <Money value={r.total_amount} /> },
                { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
              ]}
            />
          </Panel>

          <Panel title="Statement periode" description="Transaksi + referensi wallet (§53/§72)">
            {statement.isLoading || !statement.data ? <p className="text-sm text-slate-500">Memuat…</p> : (
              <DataTable
                rows={statement.data.transactions}
                empty="Belum ada transaksi periode ini."
                columns={[
                  { key: 'date', header: 'Tanggal', render: (r) => <DateOnly value={r.date} /> },
                  { key: 'order', header: 'Order', render: (r) => <span className="font-mono text-xs">{r.order_number}</span> },
                  { key: 'free', header: 'Gratis', render: (r) => <Weight value={r.free} /> },
                  { key: 'charge', header: 'Bayar', render: (r) => <Money value={r.charge} /> },
                  { key: 'ref', header: 'Ref wallet', render: (r) => <span className="font-mono text-xs">{r.wallet_ref ?? '—'}</span> },
                  { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
                ]}
              />
            )}
          </Panel>
        </>
      ) : null}
    </div>
  );
}
