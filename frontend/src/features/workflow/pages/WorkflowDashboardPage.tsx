import { useQuery } from '@tanstack/react-query';
import { Activity, AlertTriangle, CheckCircle2, Clock, GitBranch, Layers, TrendingUp } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { Button } from '@/components/ui/Button';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import { formatDateTime } from '../components/SlaBadge';

/**
 * Dasbor workflow admin (§52).
 *
 * Menjawab "apakah mesin ini sehat hari ini?": berapa yang berjalan, berapa
 * yang menunggu, berapa yang terlambat, apa yang tersangkut, dan seberapa patuh
 * SLA — tanpa memberi peringkat pada individu.
 */
export default function WorkflowDashboardPage() {
  const dashboard = useQuery({ queryKey: ['workflow', 'dashboard'], queryFn: () => workflowApi.dashboard({ days: 30, limit: 8 }) });
  const sweep = useMutation({
    mutationFn: () => workflowApi.sweepSla(500),
    onSuccess: (result) => {
      toast.success(`Sapuan SLA: ${result.reminded} pengingat, ${result.overdue} terlambat, ${result.escalated} eskalasi.`);
      dashboard.refetch();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  if (dashboard.isLoading) return <Spinner />;

  const data = dashboard.data;

  if (!data) return <p className="text-sm text-slate-500">Dasbor tidak tersedia.</p>;

  const stats = data.dashboard;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Dasbor Workflow</h1>
          <p className="text-sm text-slate-500">Operasional mesin persetujuan 30 hari terakhir.</p>
        </div>
        <Can perm="workflow.sla.manage">
          <Button variant="outline" loading={sweep.isPending} onClick={() => sweep.mutate()}>
            <Activity className="mr-1 h-4 w-4" /> Jalankan sapuan SLA
          </Button>
        </Can>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Definisi aktif" value={stats.definitions_active} icon={<GitBranch className="h-4 w-4" />} />
        <Stat label="Request berjalan" value={stats.instances_open} icon={<Layers className="h-4 w-4" />} />
        <Stat label="Menunggu keputusan" value={stats.pending_approvals} icon={<Clock className="h-4 w-4" />} tone="info" />
        <Stat label="Terlambat" value={stats.overdue_approvals} icon={<AlertTriangle className="h-4 w-4" />} tone="danger" />
        <Stat label="Disetujui (30 hari)" value={stats.approved} icon={<CheckCircle2 className="h-4 w-4" />} tone="success" />
        <Stat label="Ditolak" value={stats.rejected} tone="danger" />
        <Stat label="Dikembalikan revisi" value={stats.returned} tone="warning" />
        <Stat label="Gagal / kedaluwarsa" value={`${stats.failed} / ${stats.expired}`} tone="danger" />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Kepatuhan SLA</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            <p className="text-2xl font-semibold text-slate-900">
              {stats.sla.compliance_percent === null ? '—' : `${stats.sla.compliance_percent}%`}
            </p>
            <p className="text-xs text-slate-500">
              {stats.sla.on_time} tepat waktu · {stats.sla.overdue_open} masih terlambat · {stats.sla.due_soon_open} segera jatuh tempo
            </p>
            <p className="text-xs text-slate-500">
              Rata-rata penyelesaian {stats.average_approval_hours === null ? '—' : `${stats.average_approval_hours} jam`} · tingkat eskalasi{' '}
              {stats.escalation_rate === null ? '—' : `${stats.escalation_rate}%`}
            </p>
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <TrendingUp className="h-4 w-4" /> Volume harian
            </CardTitle>
          </CardHeader>
          <CardBody>
            <VolumeChart rows={data.volume_by_day} />
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Titik tersangkut</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            <ul className="divide-y divide-slate-100">
              {data.bottlenecks.map((row, index) => (
                <li key={index} className="px-4 py-2 text-xs">
                  <p className="font-medium text-slate-700">{String(row.title ?? row.instance ?? '—')}</p>
                  <p className="text-slate-500">
                    {String(row.module ?? '')} · langkah {String(row.step ?? '')} · menunggu {String(row.waiting_hours ?? '—')} jam
                  </p>
                </li>
              ))}
              {data.bottlenecks.length === 0 ? <li className="px-4 py-3 text-sm text-slate-500">Tidak ada yang tersangkut.</li> : null}
            </ul>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Beban kerja tertunda per approver</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            <ul className="divide-y divide-slate-100">
              {data.workload.map((row) => (
                <li key={row.approver_id} className="flex items-center justify-between px-4 py-2 text-sm">
                  <span className="text-slate-700">Pengguna #{row.approver_id}</span>
                  <span className="flex items-center gap-2">
                    <Badge tone="info">{row.pending} menunggu</Badge>
                    {row.overdue > 0 ? <Badge tone="danger">{row.overdue} terlambat</Badge> : null}
                  </span>
                </li>
              ))}
              {data.workload.length === 0 ? <li className="px-4 py-3 text-sm text-slate-500">Tidak ada antrean.</li> : null}
            </ul>
            <p className="px-4 py-2 text-[11px] text-slate-500">
              Angka ini untuk memantau beban operasional — bukan penilaian kinerja individu.
            </p>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Komposisi keputusan</CardTitle>
        </CardHeader>
        <CardBody className="flex flex-wrap gap-3">
          {data.decision_breakdown.map((row) => (
            <div key={row.decision} className="rounded-lg border border-slate-200 px-3 py-2">
              <p className="text-xs text-slate-500">{row.label}</p>
              <p className="text-sm font-semibold text-slate-800">
                {row.total} <span className="text-xs font-normal text-slate-500">({row.percent}%)</span>
              </p>
            </div>
          ))}
        </CardBody>
      </Card>
    </div>
  );
}

function Stat({
  label,
  value,
  icon,
  tone = 'neutral',
}: {
  label: string;
  value: number | string;
  icon?: React.ReactNode;
  tone?: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
}) {
  const toneClass = {
    neutral: 'text-slate-500',
    info: 'text-blue-600',
    success: 'text-green-600',
    warning: 'text-amber-600',
    danger: 'text-red-600',
  }[tone];

  return (
    <Card>
      <CardBody className="py-3">
        <div className="flex items-center justify-between">
          <p className="text-xs uppercase tracking-wide text-slate-500">{label}</p>
          <span className={toneClass}>{icon}</span>
        </div>
        <p className="mt-1 text-xl font-semibold text-slate-900">{value}</p>
      </CardBody>
    </Card>
  );
}

/** Grafik batang sederhana tanpa dependensi tambahan. */
function VolumeChart({ rows }: { rows: Array<{ date: string; created: number; completed: number }> }) {
  const max = Math.max(1, ...rows.map((row) => Math.max(row.created, row.completed)));

  return (
    <div>
      <div className="flex h-32 items-end gap-1">
        {rows.map((row) => (
          <div key={row.date} className="flex flex-1 flex-col items-center gap-0.5" title={`${row.date}: ${row.created} dibuat, ${row.completed} selesai`}>
            <div className="flex w-full flex-1 items-end gap-0.5">
              <div className="flex-1 rounded-t bg-brand-500" style={{ height: `${(row.created / max) * 100}%` }} />
              <div className="flex-1 rounded-t bg-green-400" style={{ height: `${(row.completed / max) * 100}%` }} />
            </div>
          </div>
        ))}
      </div>
      <div className="mt-2 flex items-center gap-4 text-xs text-slate-500">
        <span className="flex items-center gap-1"><span className="h-2 w-3 rounded bg-brand-500" /> dibuat</span>
        <span className="flex items-center gap-1"><span className="h-2 w-3 rounded bg-green-400" /> selesai</span>
        <span className="ml-auto">
          {rows.length > 0 ? `${formatDateTime(rows[0].date)} — ${formatDateTime(rows[rows.length - 1].date)}` : ''}
        </span>
      </div>
    </div>
  );
}
