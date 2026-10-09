import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CalendarRange, ChartColumn, ReceiptText, ScrollText } from 'lucide-react';
import { Input } from '@/components/ui/Input';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, Money, PageHeader, Panel, Stat, Weight } from '../components/LaundryUi';

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
    <div className="space-y-5">
      <PageHeader
        icon={<ChartColumn className="h-6 w-6" />}
        title="Laporan Laundry"
        description="Operasional harian, gratis vs berbayar, kuota, wallet, operasional, dan audit."
      />

      <Panel title="Rentang laporan" description="Kosongkan rentang untuk periode berjalan.">
        <div className="mb-4 flex flex-wrap items-end gap-2">
          <div className="flex items-center gap-2">
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="Dari" className="w-40" />
            <span className="text-slate-400">→</span>
            <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="Sampai" className="w-40" />
          </div>
          <Input placeholder="Periode kuota (cth. 2026-10)" value={period} onChange={(e) => setPeriod(e.target.value)} className="w-60" />
          {(from || to || period) ? (
            <button
              type="button"
              onClick={() => { setFrom(''); setTo(''); setPeriod(''); }}
              className="text-xs font-medium text-slate-500 underline hover:text-brand-700"
            >
              reset
            </button>
          ) : null}
          <span className="ml-auto hidden items-center gap-1.5 text-xs text-slate-400 sm:flex">
            <CalendarRange className="h-3.5 w-3.5" /> {from || to ? `${from || '…'} → ${to || '…'}` : 'periode berjalan'}
          </span>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <div>
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Operasional harian</p>
            {daily.isLoading || !daily.data ? <p className="text-sm text-slate-500">Memuat…</p> : (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <Stat label="Order" value={daily.data.total_orders} hint={`${daily.data.completed} selesai · ${daily.data.pending} berjalan`} />
                <Stat label="Total kg" value={<Weight value={daily.data.total_kg} />} tone="info" />
                <Stat label="Gratis" value={<Weight value={daily.data.free_kg} />} tone="good" hint="kg bersubsidi" />
                <Stat label="Berbayar" value={<Weight value={daily.data.paid_kg} />} tone="warn" hint="kg komersial" />
                <Stat label="Pendapatan" value={<Money value={daily.data.paid_revenue} compact />} tone="brand" />
                <Stat label="Insiden" value={daily.data.incidents} tone={daily.data.incidents > 0 ? 'bad' : 'good'} />
              </div>
            )}
          </div>
          <div>
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Gratis vs berbayar — gratis = subsidi, bukan revenue (§50)</p>
            {freeVsPaid.isLoading || !freeVsPaid.data ? <p className="text-sm text-slate-500">Memuat…</p> : (
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Gratis (subsidi)</p>
                  <p className="mt-1.5 text-2xl font-semibold text-emerald-700">{freeVsPaid.data.free.total_orders}<span className="ml-1 text-sm font-normal">order</span></p>
                  <p className="text-sm text-slate-600"><Weight value={freeVsPaid.data.free.total_kg} /></p>
                  <p className="text-xs text-slate-500">estimasi <Money value={freeVsPaid.data.free.estimated_value} /></p>
                </div>
                <div className="rounded-xl border border-brand-200 bg-brand-50/60 p-4">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-brand-700">Berbayar (revenue)</p>
                  <p className="mt-1.5 text-2xl font-semibold text-brand-700">{freeVsPaid.data.paid.total_orders}<span className="ml-1 text-sm font-normal">order</span></p>
                  <p className="text-sm text-slate-600"><Weight value={freeVsPaid.data.paid.total_kg} /></p>
                  <p className="text-xs text-slate-500"><Money value={freeVsPaid.data.paid.revenue} /></p>
                </div>
              </div>
            )}
          </div>
        </div>
      </Panel>

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
            { key: 'remaining', header: 'Sisa', render: (r) => <span className="font-semibold"><Weight value={r.remaining} /></span> },
            { key: 'expired', header: 'Kedaluwarsa', render: (r) => (
              r.expired ? <span className="text-xs font-medium text-rose-600">Ya</span> : <span className="text-slate-400">—</span>
            ) },
          ]}
        />
      </Panel>

      <Panel
        title={`Transaksi wallet (${wallet.data?.length ?? 0})`}
        description="Pembayaran + referensi transaksi wallet (§73)"
        action={<ReceiptText className="h-4 w-4 text-slate-400" />}
      >
        <DataTable
          loading={wallet.isLoading}
          rows={wallet.data ?? []}
          empty="Belum ada transaksi."
          columns={[
            { key: 'pay', header: 'Pembayaran', render: (r) => (
              <div><p className="font-mono text-xs font-semibold">{r.payment_number}</p><p className="text-xs text-slate-500">{r.order_number ?? ''}</p></div>
            ) },
            { key: 'amount', header: 'Nominal', render: (r) => <span className="font-semibold"><Money value={r.amount} /></span> },
            { key: 'method', header: 'Metode', render: (r) => <span className="text-xs">{r.method}</span> },
            { key: 'ref', header: 'Ref wallet', render: (r) => <span className="font-mono text-xs">{r.wallet_transaction ?? '—'}</span> },
            { key: 'balance', header: 'Sebelum → sesudah', render: (r) => (
              <span className="text-xs tabular-nums"><Money value={r.balance_before} /> → <Money value={r.balance_after} /></span>
            ) },
            { key: 'at', header: 'Dibayar', render: (r) => <DateOnly value={r.paid_at} /> },
          ]}
        />
      </Panel>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Operasional" description="Waktu proses, beban operator & mesin, telat, QC, hilang/rusak">
          {operational.isLoading || !operational.data ? <p className="text-sm text-slate-500">Memuat…</p> : (
            <div className="grid grid-cols-2 gap-3">
              <Stat label="Rata-rata proses" value={<>{operational.data.average_processing_hours ?? '—'}<span className="text-sm font-normal"> jam</span></>} tone="info" />
              <Stat label="Order telat" value={operational.data.delayed_orders} tone={operational.data.delayed_orders > 0 ? 'warn' : 'good'} />
              <Stat label="QC gagal" value={operational.data.qc_failures} tone={operational.data.qc_failures > 0 ? 'warn' : 'good'} />
              <Stat label="Hilang / rusak" value={<>{operational.data.lost_items} / {operational.data.damaged_items}</>} tone={operational.data.lost_items + operational.data.damaged_items > 0 ? 'bad' : 'good'} />
            </div>
          )}
        </Panel>

        <Panel title="Audit trail" description="15 aktivitas terakhir modul (§75)" action={<ScrollText className="h-4 w-4 text-slate-400" />}>
          <DataTable
            loading={audit.isLoading}
            rows={audit.data?.rows ?? []}
            empty="Belum ada jejak audit."
            columns={[
              { key: 'action', header: 'Aksi', render: (r) => <span className="font-mono text-xs font-medium">{r.action}</span> },
              { key: 'subject', header: 'Subjek', render: (r) => <span className="text-xs">{r.subject_type}#{r.subject_id ?? '—'}</span> },
              { key: 'actor', header: 'Pelaku', render: (r) => r.actor?.name ?? 'sistem' },
              { key: 'reason', header: 'Alasan', render: (r) => <span className="text-xs text-slate-500">{r.reason ?? '—'}</span> },
            ]}
          />
        </Panel>
      </div>
    </div>
  );
}
