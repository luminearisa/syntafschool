import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, Clock, Filter, Inbox, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import { DecisionDialog } from '../components/DecisionDialog';
import { SlaBadge, formatAmount, priorityTone } from '../components/SlaBadge';
import type { WorkflowApprovalRow } from '../types';

/**
 * Kotak masuk approver (§50).
 *
 * Satu tempat untuk seluruh persetujuan lintas modul, dengan penyaring yang
 * dipakai sehari-hari: menunggu, terlambat, segera jatuh tempo, prioritas,
 * unit, modul, nominal, dan tanggal.
 */
export default function WorkflowInboxPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState<Record<string, string>>({ bucket: 'pending', sort: 'due_at' });
  const [dialog, setDialog] = useState<{ approval: WorkflowApprovalRow; decision: 'approve' | 'reject' | 'return' | 'request-changes' } | null>(null);

  const counter = useQuery({ queryKey: ['workflow', 'counter'], queryFn: () => workflowApi.counter() });
  const options = useQuery({ queryKey: ['workflow', 'filter-options'], queryFn: () => workflowApi.filterOptions() });

  const approvals = useQuery({
    queryKey: ['workflow', 'inbox', page, filters],
    queryFn: () => workflowApi.myApprovals({ page, ...filters }),
  });

  const reassign = useMutation({
    mutationFn: ({ id, userId, reason }: { id: number; userId: number; reason: string }) => workflowApi.reassign(id, userId, reason),
    onSuccess: () => {
      toast.success('Penugasan dialihkan dengan catatan audit.');
      queryClient.invalidateQueries({ queryKey: ['workflow'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const activeFilters = useMemo(
    () => Object.entries(filters).filter(([, value]) => value !== '' && value !== undefined),
    [filters],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Persetujuan Saya</h1>
          <p className="text-sm text-slate-500">
            Seluruh permintaan dari modul ERP yang menunggu keputusan Anda — diurutkan dari yang paling mendesak.
          </p>
        </div>
        <Button variant="outline" onClick={() => approvals.refetch()} loading={approvals.isFetching}>
          <RefreshCw className="mr-1 h-4 w-4" /> Muat ulang
        </Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-4">
        <CounterCard label="Menunggu" value={counter.data?.pending ?? 0} tone="info" icon={<Inbox className="h-4 w-4" />} />
        <CounterCard label="Terlambat" value={counter.data?.overdue ?? 0} tone="danger" icon={<AlertTriangle className="h-4 w-4" />} />
        <CounterCard label="≤ 24 jam" value={counter.data?.due_soon ?? 0} tone="warning" icon={<Clock className="h-4 w-4" />} />
        <CounterCard label="Selesai hari ini" value={0} tone="success" icon={<CheckCircle2 className="h-4 w-4" />} hint="Lihat riwayat di menu Audit" />
      </div>

      <Card>
        <CardHeader className="flex flex-wrap items-end gap-3">
          <div className="flex items-center gap-2 text-sm font-medium text-slate-700">
            <Filter className="h-4 w-4" /> Penyaring
          </div>

          <div className="grid flex-1 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div>
              <Label htmlFor="filter-bucket">Kelompok</Label>
              <Select id="filter-bucket" value={filters.bucket ?? 'pending'} onChange={(e) => setFilters({ ...filters, bucket: e.target.value })}>
                <option value="pending">Semua menunggu</option>
                <option value="overdue">Terlambat</option>
                <option value="due_soon">Segera jatuh tempo</option>
                <option value="today">Jatuh tempo hari ini</option>
              </Select>
            </div>
            <div>
              <Label htmlFor="filter-module">Modul</Label>
              <Select id="filter-module" value={filters.module ?? ''} onChange={(e) => setFilters({ ...filters, module: e.target.value })}>
                <option value="">Semua modul</option>
                {(options.data?.modules ?? []).map((module) => (
                  <option key={module} value={module}>
                    {module}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="filter-priority">Prioritas</Label>
              <Select id="filter-priority" value={filters.priority ?? ''} onChange={(e) => setFilters({ ...filters, priority: e.target.value })}>
                <option value="">Semua prioritas</option>
                {(options.data?.priorities ?? []).map((priority) => (
                  <option key={priority} value={priority}>
                    {priority}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="filter-amount-min">Nominal min.</Label>
              <Input
                id="filter-amount-min"
                type="number"
                value={filters.amount_min ?? ''}
                onChange={(e) => setFilters({ ...filters, amount_min: e.target.value })}
                placeholder="0"
              />
            </div>
            <div>
              <Label htmlFor="filter-amount-max">Nominal maks.</Label>
              <Input
                id="filter-amount-max"
                type="number"
                value={filters.amount_max ?? ''}
                onChange={(e) => setFilters({ ...filters, amount_max: e.target.value })}
                placeholder="—"
              />
            </div>
            <div>
              <Label htmlFor="filter-sort">Urutkan</Label>
              <Select id="filter-sort" value={filters.sort ?? 'due_at'} onChange={(e) => setFilters({ ...filters, sort: e.target.value })}>
                <option value="due_at">Tenggat terdekat</option>
                <option value="created">Terbaru ditugaskan</option>
                <option value="amount">Nominal terbesar</option>
              </Select>
            </div>
          </div>

          {activeFilters.length > 0 ? (
            <Button variant="ghost" size="sm" onClick={() => setFilters({ bucket: 'pending', sort: 'due_at' })}>
              Bersihkan
            </Button>
          ) : null}
        </CardHeader>

        <CardBody className="p-0">
          {approvals.isLoading ? <Spinner /> : null}

          {approvals.data && approvals.data.data.length === 0 ? (
            <EmptyState title="Tidak ada permintaan menunggu" description="Kotak masuk Anda bersih untuk penyaring ini." />
          ) : null}

          <ul className="divide-y divide-slate-100">
            {approvals.data?.data.map((approval) => (
              <li key={approval.id} className="px-4 py-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link to={`/workflow/instances/${approval.instance_id}`} className="truncate text-sm font-semibold text-brand-700 hover:underline">
                        {approval.title}
                      </Link>
                      <Badge tone={priorityTone(approval.priority)}>{approval.priority}</Badge>
                      {approval.is_delegate ? <Badge tone="warning">Delegasi</Badge> : null}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {approval.instance_code} · {approval.definition_name ?? approval.module} · step {approval.step}
                      {approval.subject_reference ? ` · ${approval.subject_reference}` : ''}
                    </p>
                    <p className="mt-0.5 text-xs text-slate-500">
                      Pemohon {approval.requester}
                      {approval.department ? ` (${approval.department})` : ''}
                      {formatAmount(approval.amount, approval.currency) ? ` · ${formatAmount(approval.amount, approval.currency)}` : ''}
                    </p>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    <SlaBadge status={approval.sla_status} dueAt={approval.due_at} remainingMinutes={approval.remaining_minutes} />
                    <Button size="sm" onClick={() => setDialog({ approval, decision: 'approve' })}>
                      Setujui
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => setDialog({ approval, decision: 'return' })}>
                      Kembalikan
                    </Button>
                    <Button size="sm" variant="danger" onClick={() => setDialog({ approval, decision: 'reject' })}>
                      Tolak
                    </Button>
                  </div>
                </div>

                {approval.original_approver ? (
                  <p className="mt-1 text-xs text-amber-600">
                    Bertindak atas nama {approval.original_approver} (delegasi #{approval.delegation_id})
                  </p>
                ) : null}

                <div className="mt-2 flex items-center gap-3 text-xs text-slate-500">
                  <Link to={`/workflow/instances/${approval.instance_id}`} className="hover:text-brand-600">
                    Lihat detail & dokumen
                  </Link>
                  <button
                    type="button"
                    className="hover:text-brand-600"
                    onClick={() => {
                      const raw = window.prompt('Alihkan penugasan ke user id:');
                      const userId = raw ? Number(raw) : 0;

                      if (!userId) return;

                      const reason = window.prompt('Alasan pengalihan (wajib):') ?? '';

                      if (reason.trim() === '') {
                        toast.error('Alasan pengalihan wajib diisi.');

                        return;
                      }

                      reassign.mutate({ id: approval.id, userId, reason });
                    }}
                  >
                    Alihkan penugasan
                  </button>
                </div>
              </li>
            ))}
          </ul>

          {approvals.data ? <Pagination meta={approvals.data.meta} onPageChange={setPage} /> : null}
        </CardBody>
      </Card>

      <DecisionDialog
        approval={dialog?.approval ?? null}
        decision={dialog?.decision ?? 'approve'}
        onClose={() => setDialog(null)}
        onDecided={() => queryClient.invalidateQueries({ queryKey: ['workflow'] })}
      />
    </div>
  );
}

function CounterCard({
  label,
  value,
  tone,
  icon,
  hint,
}: {
  label: string;
  value: number;
  tone: 'info' | 'warning' | 'danger' | 'success';
  icon: React.ReactNode;
  hint?: string;
}) {
  return (
    <Card>
      <CardBody className="flex items-center gap-3 py-3">
        <span className={`flex h-8 w-8 items-center justify-center rounded-full ${
          tone === 'danger' ? 'bg-red-100 text-red-600' : tone === 'warning' ? 'bg-amber-100 text-amber-600' : tone === 'success' ? 'bg-green-100 text-green-600' : 'bg-blue-100 text-blue-600'
        }`}>
          {icon}
        </span>
        <div>
          <p className="text-xs uppercase tracking-wide text-slate-500">{label}</p>
          <p className="text-lg font-semibold text-slate-900">{value}</p>
          {hint ? <p className="text-[11px] text-slate-500">{hint}</p> : null}
        </div>
      </CardBody>
    </Card>
  );
}
