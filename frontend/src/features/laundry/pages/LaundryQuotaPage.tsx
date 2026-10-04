import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, Field, FilterBar, Panel, Progress, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { EntitlementRow } from '../types';

/**
 * Jatah gratis santri (§6–§8, §13, §14, §51, §52).
 *
 * Daftar entitlement per periode + ledger transaksi + adjustment beralasan
 * (Rule 15/24) + statement per santri.
 */
export default function LaundryQuotaPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [studentId, setStudentId] = useState('');
  const [periodKey, setPeriodKey] = useState('');
  const [adjustForm, setAdjustForm] = useState({ student_id: '', amount: '', reason: '' });
  const [detailStudentId, setDetailStudentId] = useState<number | null>(null);

  const rows = useQuery({
    queryKey: ['laundry', 'entitlements', { studentId, periodKey }],
    queryFn: () => laundryApi.entitlements({
      student_id: studentId ? Number(studentId) : undefined,
      period_key: periodKey || undefined,
      per_page: 25,
    }),
  });

  const detail = useQuery({
    queryKey: ['laundry', 'entitlement', detailStudentId],
    queryFn: () => laundryApi.entitlement(detailStudentId as number),
    enabled: detailStudentId !== null,
  });

  const ledger = useQuery({
    queryKey: ['laundry', 'entitlement-ledger', detailStudentId],
    queryFn: () => laundryApi.entitlementTransactions(detailStudentId as number),
    enabled: detailStudentId !== null,
  });

  const adjust = useMutation({
    mutationFn: () => laundryApi.adjustEntitlement(Number(adjustForm.student_id), {
      amount: Number(adjustForm.amount),
      reason: adjustForm.reason.trim(),
    }),
    onSuccess: (state) => {
      toast.success(`Jatah disesuaikan — sisa ${state.remaining}`);
      setAdjustForm({ student_id: '', amount: '', reason: '' });
      queryClient.invalidateQueries({ queryKey: ['laundry', 'entitlements'] });
      queryClient.invalidateQueries({ queryKey: ['laundry', 'entitlement'] });
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menyesuaikan jatah')),
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Jatah Laundry Santri</h1>
        <p className="text-sm text-slate-500">Alokasi per periode, ledger pemakaian, carry-over, dan penyesuaian beralasan.</p>
      </div>

      <Panel title="Filter">
        <FilterBar>
          <Input placeholder="ID santri…" value={studentId} onChange={(e) => setStudentId(e.target.value)} className="w-40" />
          <Input placeholder="Kunci periode (cth. 2026-10)…" value={periodKey} onChange={(e) => setPeriodKey(e.target.value)} className="w-56" />
        </FilterBar>
      </Panel>

      <Panel title={`Entitlement (${rows.data?.meta.total ?? 0})`}>
        <DataTable<EntitlementRow>
          loading={rows.isLoading}
          rows={rows.data?.rows ?? []}
          empty="Belum ada entitlement pada filter ini."
          columns={[
            { key: 'student', header: 'Santri', render: (row) => (
              <div>
                <button type="button" className="font-medium text-slate-800 underline" onClick={() => setDetailStudentId(row.student_id ?? null)}>
                  {row.student_name ?? `ID ${row.student_id}`}
                </button>
                <p className="text-xs text-slate-500">{row.nis ?? ''} · {row.period?.key ?? ''}</p>
              </div>
            ) },
            { key: 'quota', header: 'Kuota', render: (row) => (
              <span><Weight value={row.allocated} /> {row.quota_type ?? ''}</span>
            ) },
            { key: 'used', header: 'Terpakai', render: (row) => <Progress value={row.used} max={row.allocated + row.carry_in + row.adjusted} /> },
            { key: 'remaining', header: 'Sisa', render: (row) => <Weight value={row.remaining} /> },
            { key: 'carry', header: 'Bawaan', render: (row) => <Weight value={row.carry_in} /> },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status ?? 'ACTIVE'} /> },
          ]}
        />
      </Panel>

      {detailStudentId !== null && detail.data ? (
        <Panel
          title={`Detail santri #${detailStudentId}`}
          action={<Button size="sm" variant="ghost" onClick={() => setDetailStudentId(null)}>Tutup</Button>}
        >
          <div className="grid gap-3 md:grid-cols-4">
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Alokasi</p>
              <p className="font-semibold"><Weight value={detail.data.current.allocated} /></p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Terpakai</p>
              <p className="font-semibold"><Weight value={detail.data.current.used} /></p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Sisa</p>
              <p className="font-semibold"><Weight value={detail.data.current.remaining} /></p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Periode</p>
              <p className="font-semibold">{detail.data.current.period?.key ?? '—'}</p>
            </div>
          </div>
          <div className="mt-3">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Ledger jatah (§13)</p>
            <DataTable
              loading={ledger.isLoading}
              rows={ledger.data ?? []}
              empty="Belum ada transaksi jatah."
              columns={[
                { key: 'type', header: 'Jenis', render: (r) => <StatusPill status={r.type} /> },
                { key: 'amount', header: 'Jumlah', render: (r) => <Weight value={r.amount} /> },
                { key: 'after', header: 'Sisa sesudah', render: (r) => <Weight value={r.balance_after} /> },
                { key: 'reason', header: 'Alasan', render: (r) => r.reason ?? '—' },
                { key: 'at', header: 'Waktu', render: (r) => <DateOnly value={r.occurred_at} /> },
              ]}
            />
          </div>
        </Panel>
      ) : null}

      {can('laundry.quota.adjust') ? (
        <Panel title="Penyesuaian jatah" description="Alasan wajib & teraudit (Rule 15/24)">
          <div className="grid gap-3 md:grid-cols-4">
            <Field label="ID santri" required>
              <Input value={adjustForm.student_id} onChange={(e) => setAdjustForm({ ...adjustForm, student_id: e.target.value })} />
            </Field>
            <Field label="Jumlah (+/-)" required>
              <Input type="number" step="0.001" value={adjustForm.amount} onChange={(e) => setAdjustForm({ ...adjustForm, amount: e.target.value })} placeholder="cth. 2 atau -1" />
            </Field>
            <Field label="Alasan" required>
              <Input value={adjustForm.reason} onChange={(e) => setAdjustForm({ ...adjustForm, reason: e.target.value })} placeholder="cth. Special boarding policy" />
            </Field>
            <div className="flex items-end">
              <Button
                onClick={() => adjust.mutate()}
                loading={adjust.isPending}
                disabled={!adjustForm.student_id || !adjustForm.amount || !adjustForm.reason.trim()}
              >
                Simpan penyesuaian
              </Button>
            </div>
          </div>
        </Panel>
      ) : null}
    </div>
  );
}
