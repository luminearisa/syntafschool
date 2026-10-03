import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Input } from '@/components/ui/Input';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, FilterBar, Money, Panel, Weight } from '../components/LaundryUi';

/**
 * Laporan laundry (§48–§51, §73).
 *
 * Prinsip §50: laundry gratis BUKAN cash revenue — dilaporkan terpisah
 * sebagai estimated value (beban subsidi sekolah).
 */
export default function LaundryReportsPage() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [period, setPeriod] = useState('');
  const range = { from: from || undefined, to: to || undefined };

  const daily = useQuery({ queryKey: ['laundry', 'report-daily'], queryFn: () => laundryApi.dailyOperations() });
  const freeVsPaid = useQuery({ queryKey: ['laundry', 'report-fvp', range], queryFn: () => laundryApi.freeVsPaid(range) });
  const quota = useQuery({ queryKey: ['laundry', 'report-quota', period], queryFn: () => laundryApi.quotaReport({ period: period || undefined }) });
  const wallet = useQuery({ queryKey: ['laundry', 'report-wallet', range], queryFn: () => laundryApi.walletReport(range) });
  const operational = useQuery({ queryKey: ['laundry', 'report-ops', range], queryFn: () => laundryApi.operationalReport(range) });
  const audit = useQuery({ queryKey: ['laundry', 'audit'], queryFn: () => laundryApi.auditLogs({ per_page: 15 }) });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Laporan Laundry</h1>
        <p className="text-sm text-slate-500">Operasional harian, gratis vs berbayar, kuota, wallet, operasional, dan audit.</p>
      </div>

      <Panel title="Rentang laporan">
        <FilterBar>
          <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="Dari" />
          <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="Sampai" />
          <Input placeholder="Periode kuota (cth. 2026-10)" value={period} onChange={(e) => setPeriod(e.target.value)} className="w-56" />
        </FilterBar>
      </Panel>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Operasional harian" description={daily.data?.date ?? ''}>
          {daily.isLoading || !daily.data ? <p className="text-sm text-slate-500">Memuat…</p> : (
            <dl className="grid grid-cols-2 gap-2 text-sm">
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Order</dt><dd className="font-semibold">{daily.data.total_orders}</dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Total kg</dt><dd className="font-semibold"><Weight value={daily.data.total_kg} /></dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Gratis</dt><dd className="font-semibold"><Weight value={daily.data.free_kg} /></dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Berbayar</dt><dd className="font-semibold"><Weight value={daily.data.paid_kg} /></dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Pendapatan</dt><dd className="font-semibold"><Money value={daily.data.paid_revenue} /></dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Selesai / pending</dt><dd className="font-semibold">{daily.data.completed} / {daily.data.pending}</dd></div>
            </dl>
          )}
        </Panel>

        <Panel title="Gratis vs berbayar" description="Gratis = subsidi, bukan revenue (§50)">
          {freeVsPaid.isLoading || !freeVsPaid.data ? <p className="text-sm text-slate-500">Memuat…</p> : (
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <p className="text-xs font-medium uppercase text-emerald-700">Gratis (subsidi)</p>
                <p className="text-lg font-semibold">{freeVsPaid.data.free.total_orders} order</p>
                <p><Weight value={freeVsPaid.data.free.total_kg} /></p>
                <p className="text-xs text-slate-500">estimasi <Money value={freeVsPaid.data.free.estimated_value} /></p>
              </div>
              <div className="rounded-lg border border-slate-200 p-3">
                <p className="text-xs font-medium uppercase text-slate-500">Berbayar (revenue)</p>
                <p className="text-lg font-semibold">{freeVsPaid.data.paid.total_orders} order</p>
                <p><Weight value={freeVsPaid.data.paid.total_kg} /></p>
                <p className="text-xs text-slate-500"><Money value={freeVsPaid.data.paid.revenue} /></p>
              </div>
            </div>
          )}
        </Panel>
      </div>

      <Panel title={`Kuota per santri (${quota.data?.length ?? 0})`} description="Allocated / used / remaining / carry / expired (§51)">
        <DataTable
          loading={quota.isLoading}
          rows={quota.data ?? []}
          empty="Belum ada data kuota."
          columns={[
            { key: 'student', header: 'Santri', render: (r) => (
              <div><p className="font-medium">{r.student_name ?? `#${r.student_id}`}</p><p className="text-xs text-slate-500">{r.nis ?? ''} · {r.period_key}</p></div>
            ) },
            { key: 'alloc', header: 'Alokasi', render: (r) => <Weight value={r.allocated} /> },
            { key: 'used', header: 'Terpakai', render: (r) => <Weight value={r.used} /> },
            { key: 'remaining', header: 'Sisa', render: (r) => <Weight value={r.remaining} /> },
            { key: 'expired', header: 'Kedaluwarsa', render: (r) => (r.expired ? 'Ya' : '—') },
          ]}
        />
      </Panel>

      <Panel title={`Transaksi wallet (${wallet.data?.length ?? 0})`} description="Pembayaran + referensi transaksi wallet (§73)">
        <DataTable
          loading={wallet.isLoading}
          rows={wallet.data ?? []}
          empty="Belum ada transaksi."
          columns={[
            { key: 'pay', header: 'Pembayaran', render: (r) => (
              <div><p className="font-medium font-mono text-xs">{r.payment_number}</p><p className="text-xs text-slate-500">{r.order_number ?? ''}</p></div>
            ) },
            { key: 'amount', header: 'Nominal', render: (r) => <Money value={r.amount} /> },
            { key: 'method', header: 'Metode', render: (r) => r.method },
            { key: 'ref', header: 'Ref wallet', render: (r) => <span className="font-mono text-xs">{r.wallet_transaction ?? '—'}</span> },
            { key: 'balance', header: 'Sebelum → sesudah', render: (r) => (
              <span className="text-xs"><Money value={r.balance_before} /> → <Money value={r.balance_after} /></span>
            ) },
            { key: 'at', header: 'Dibayar', render: (r) => <DateOnly value={r.paid_at} /> },
          ]}
        />
      </Panel>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Operasional" description="Waktu proses, beban operator & mesin, telat, QC, hilang/rusak">
          {operational.isLoading || !operational.data ? <p className="text-sm text-slate-500">Memuat…</p> : (
            <dl className="grid grid-cols-2 gap-2 text-sm">
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Rata-rata proses</dt><dd className="font-semibold">{operational.data.average_processing_hours ?? '—'} jam</dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Order telat</dt><dd className="font-semibold">{operational.data.delayed_orders}</dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">QC gagal</dt><dd className="font-semibold">{operational.data.qc_failures}</dd></div>
              <div className="rounded-lg border p-2"><dt className="text-xs text-slate-500">Hilang / rusak</dt><dd className="font-semibold">{operational.data.lost_items} / {operational.data.damaged_items}</dd></div>
            </dl>
          )}
        </Panel>

        <Panel title="Audit trail" description="15 aktivitas terakhir modul (§75)">
          <DataTable
            loading={audit.isLoading}
            rows={audit.data?.rows ?? []}
            empty="Belum ada jejak audit."
            columns={[
              { key: 'action', header: 'Aksi', render: (r) => <span className="font-mono text-xs">{r.action}</span> },
              { key: 'subject', header: 'Subjek', render: (r) => <span className="text-xs">{r.subject_type}#{r.subject_id ?? '—'}</span> },
              { key: 'actor', header: 'Pelaku', render: (r) => r.actor?.name ?? 'sistem' },
              { key: 'reason', header: 'Alasan', render: (r) => r.reason ?? '—' },
            ]}
          />
        </Panel>
      </div>
    </div>
  );
}
