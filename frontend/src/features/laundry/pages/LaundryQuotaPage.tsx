import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { GitBranch, Scale, SlidersHorizontal, UserRound } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { SideModal } from '@/components/ui/SideModal';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, DetailRow, Field, FilterBar, PageHeader, Panel, Progress, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { EntitlementRow } from '../types';

/**
 * Jatah gratis santri (§6–§8, §13, §14, §51, §52).
 *
 * Daftar entitlement per periode + ledger transaksi + adjustment beralasan
 * (Rule 15/24). Detail santri & form penyesuaian hidup di drawer samping.
 */
export default function LaundryQuotaPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [studentId, setStudentId] = useState('');
  const [periodKey, setPeriodKey] = useState('');
  const [adjustForm, setAdjustForm] = useState({ student_id: '', amount: '', reason: '' });
  const [detailStudentId, setDetailStudentId] = useState<number | null>(null);
  const [adjustModalOpen, setAdjustModalOpen] = useState(false);

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
      setAdjustModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ['laundry', 'entitlements'] });
      queryClient.invalidateQueries({ queryKey: ['laundry', 'entitlement'] });
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menyesuaikan jatah')),
  });

  const openAdjust = (row: EntitlementRow) => {
    setAdjustForm({ student_id: String(row.student_id ?? ''), amount: '', reason: '' });
    setAdjustModalOpen(true);
  };

  const detailRow = (rows.data?.rows ?? []).find((r) => r.student_id === detailStudentId);

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<Scale className="h-6 w-6" />}
        title="Jatah Laundry Santri"
        description="Alokasi per periode, ledger pemakaian, carry-over, dan penyesuaian beralasan."
        actions={can('laundry.quota.adjust') ? (
          <Button onClick={() => { setAdjustForm({ student_id: '', amount: '', reason: '' }); setAdjustModalOpen(true); }}>
            <SlidersHorizontal className="h-4 w-4" /> Sesuaikan Jatah
          </Button>
        ) : undefined}
      />

      <Panel title="Filter" description="Cari berdasarkan ID santri atau kunci periode (cth. 2026-10).">
        <FilterBar>
          <Input placeholder="ID santri…" value={studentId} onChange={(e) => setStudentId(e.target.value)} className="w-44" />
          <Input placeholder="Kunci periode (cth. 2026-10)…" value={periodKey} onChange={(e) => setPeriodKey(e.target.value)} className="w-60" />
        </FilterBar>
        <DataTable<EntitlementRow>
          loading={rows.isLoading}
          rows={rows.data?.rows ?? []}
          empty="Belum ada entitlement pada filter ini."
          columns={[
            { key: 'student', header: 'Santri', render: (row) => (
              <button type="button" className="group flex items-center gap-2 text-left" onClick={() => setDetailStudentId(row.student_id ?? null)}>
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                  <UserRound className="h-4 w-4" />
                </span>
                <span>
                  <span className="block font-medium text-slate-800 group-hover:text-brand-700 group-hover:underline">{row.student_name ?? `ID ${row.student_id}`}</span>
                  <span className="block text-xs text-slate-500">{row.nis ?? ''} · {row.period?.key ?? ''}</span>
                </span>
              </button>
            ) },
            { key: 'quota', header: 'Kuota', render: (row) => (
              <span><Weight value={row.allocated} /> <span className="text-xs text-slate-400">{row.quota_type ?? ''}</span></span>
            ) },
            { key: 'used', header: 'Terpakai', render: (row) => <Progress value={row.used} max={row.allocated + row.carry_in + row.adjusted} /> },
            { key: 'remaining', header: 'Sisa', render: (row) => <span className="font-semibold"><Weight value={row.remaining} /></span> },
            { key: 'carry', header: 'Bawaan', render: (row) => <Weight value={row.carry_in} /> },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status ?? 'ACTIVE'} /> },
            { key: 'aksi', header: 'Aksi', render: (row) => (
              can('laundry.quota.adjust') ? (
                <Button size="sm" variant="outline" onClick={() => openAdjust(row)}>Sesuaikan</Button>
              ) : null
            ) },
          ]}
        />
      </Panel>

      {/* ── Drawer: detail jatah santri ─────────────────────────────── */}
      <SideModal
        open={detailStudentId !== null}
        onClose={() => setDetailStudentId(null)}
        title={`Detail Jatah — ${detailRow?.student_name ?? `Santri #${detailStudentId}`}`}
        description={`NIS ${detailRow?.nis ?? '—'} · periode ${detail.data?.current.period?.key ?? '—'}`}
        icon={<UserRound className="h-5 w-5" />}
        footer={<Button variant="secondary" onClick={() => setDetailStudentId(null)}>Tutup</Button>}
      >
        {detail.data ? (
          <>
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Alokasi</p>
                <p className="mt-1 text-xl font-semibold"><Weight value={detail.data.current.allocated} /></p>
              </div>
              <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Terpakai</p>
                <p className="mt-1 text-xl font-semibold"><Weight value={detail.data.current.used} /></p>
              </div>
              <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3.5">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Sisa</p>
                <p className="mt-1 text-xl font-semibold text-emerald-700"><Weight value={detail.data.current.remaining} /></p>
              </div>
              <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Bawaan periode lalu</p>
                <p className="mt-1 text-xl font-semibold"><Weight value={detail.data.current.carry_in} /></p>
              </div>
            </div>

            <div className="mt-5">
              <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                <GitBranch className="h-3.5 w-3.5" /> Ledger jatah (§13)
              </p>
              {ledger.isLoading ? (
                <p className="text-sm text-slate-500">Memuat ledger…</p>
              ) : (
                <div className="overflow-hidden rounded-xl border border-slate-200">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="bg-slate-50/80 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <th className="px-3 py-2 font-semibold">Jenis</th>
                        <th className="px-3 py-2 font-semibold">Jumlah</th>
                        <th className="px-3 py-2 font-semibold">Sisa</th>
                        <th className="px-3 py-2 font-semibold">Waktu</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(ledger.data ?? []).map((r) => (
                        <tr key={r.id} className="border-t border-slate-100">
                          <td className="px-3 py-2"><StatusPill status={r.type} /></td>
                          <td className="px-3 py-2 tabular-nums"><Weight value={r.amount} /></td>
                          <td className="px-3 py-2 tabular-nums"><Weight value={r.balance_after} /></td>
                          <td className="px-3 py-2 text-xs text-slate-500"><DateOnly value={r.occurred_at} /></td>
                        </tr>
                      ))}
                      {(ledger.data ?? []).length === 0 ? (
                        <tr><td colSpan={4} className="px-3 py-6 text-center text-sm text-slate-400">Belum ada transaksi jatah.</td></tr>
                      ) : null}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            {detail.data.history.length > 0 ? (
              <div className="mt-5">
                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Riwayat periode</p>
                <dl className="rounded-xl border border-slate-200 px-4 py-1">
                  {detail.data.history.map((h, i) => (
                    <DetailRow key={i} label={h.period?.key ?? '—'}>
                      <Weight value={h.remaining} /> <span className="text-xs text-slate-400">sisa</span>
                    </DetailRow>
                  ))}
                </dl>
              </div>
            ) : null}
          </>
        ) : (
          <p className="text-sm text-slate-500">Memuat detail…</p>
        )}
      </SideModal>

      {/* ── Drawer: penyesuaian jatah ───────────────────────────────── */}
      <SideModal
        open={adjustModalOpen}
        onClose={() => setAdjustModalOpen(false)}
        title="Penyesuaian Jatah"
        description="Alasan wajib & teraudit (Rule 15/24). Gunakan angka negatif untuk mengurangi."
        icon={<SlidersHorizontal className="h-5 w-5" />}
        tone="amber"
        footer={
          <>
            <Button variant="ghost" onClick={() => setAdjustModalOpen(false)}>Batal</Button>
            <Button
              onClick={() => adjust.mutate()}
              loading={adjust.isPending}
              disabled={!adjustForm.student_id || !adjustForm.amount || !adjustForm.reason.trim()}
            >
              Simpan Penyesuaian
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="ID santri" required>
            <Input value={adjustForm.student_id} onChange={(e) => setAdjustForm({ ...adjustForm, student_id: e.target.value })} placeholder="cth. 123" className="font-mono" />
          </Field>
          <Field label="Jumlah (kg, +/-)" required hint="cth. 2 untuk menambah 2 kg, -1 untuk mengurangi 1 kg.">
            <Input type="number" step="0.001" value={adjustForm.amount} onChange={(e) => setAdjustForm({ ...adjustForm, amount: e.target.value })} placeholder="cth. 2 atau -1" />
          </Field>
          <Field label="Alasan" required hint="Tercatat di audit trail — hindari alasan generik.">
            <Input value={adjustForm.reason} onChange={(e) => setAdjustForm({ ...adjustForm, reason: e.target.value })} placeholder="cth. Special boarding policy" />
          </Field>
        </div>
      </SideModal>
    </div>
  );
}
