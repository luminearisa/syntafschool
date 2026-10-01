import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BarChart3, ShieldAlert } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { workflowApi } from '../api/workflowApi';

interface AnalyticsPayload {
  duration_by_definition: Array<{ definition: string; module: string; samples: number; average_hours: number | null }>;
  sla_compliance_by_definition: Array<{ definition: string; total: number; breached: number; compliance_percent: number | null }>;
  volume: { by_module: Array<{ module: string; total: number }>; by_department: Array<{ department: string; total: number }> };
  workload: Array<{ approver_id: number; pending: number; overdue: number }>;
  decisions: Array<{ decision: string; label: string; total: number; percent: number }>;
}

/**
 * Analitik operasional (§53).
 *
 * Fokus pada **proses**, bukan orang: durasi per definisi, kepatuhan SLA,
 * volume per modul/unit, dan komposisi keputusan. Tidak ada skor individu —
 * beban kerja ditampilkan hanya sebagai jumlah pekerjaan tertunda.
 */
export default function WorkflowAnalyticsPage() {
  const [days, setDays] = useState(90);

  const analytics = useQuery({
    queryKey: ['workflow', 'analytics', days],
    queryFn: () => workflowApi.analytics({ days }) as unknown as Promise<AnalyticsPayload>,
  });
  const escalations = useQuery({ queryKey: ['workflow', 'escalations', days], queryFn: () => workflowApi.escalations({ days, per_page: 10 }) });

  if (analytics.isLoading) return <Spinner />;

  const data = analytics.data;

  if (!data) return <p className="text-sm text-slate-500">Data analitik tidak tersedia.</p>;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Analitik Workflow</h1>
          <p className="text-sm text-slate-500">Pemantauan proses persetujuan lintas modul.</p>
        </div>
        <Select value={String(days)} onChange={(event) => setDays(Number(event.target.value))} className="w-40">
          <option value="30">30 hari</option>
          <option value="90">90 hari</option>
          <option value="180">180 hari</option>
          <option value="365">1 tahun</option>
        </Select>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <BarChart3 className="h-4 w-4" /> Durasi rata-rata per definisi
            </CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-4 py-2">Definisi</th>
                  <th className="px-4 py-2">Modul</th>
                  <th className="px-4 py-2 text-right">Sampel</th>
                  <th className="px-4 py-2 text-right">Rata-rata (jam)</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.duration_by_definition.map((row) => (
                  <tr key={`${row.definition}-${row.module}`}>
                    <td className="px-4 py-2 text-slate-700">{row.definition}</td>
                    <td className="px-4 py-2 text-slate-500">{row.module}</td>
                    <td className="px-4 py-2 text-right text-slate-600">{row.samples}</td>
                    <td className="px-4 py-2 text-right font-medium text-slate-800">{row.average_hours ?? '—'}</td>
                  </tr>
                ))}
                {data.duration_by_definition.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-3 text-slate-500">
                      Belum ada request selesai pada periode ini.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Kepatuhan SLA per definisi</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-4 py-2">Definisi</th>
                  <th className="px-4 py-2 text-right">Selesai</th>
                  <th className="px-4 py-2 text-right">Lewat SLA</th>
                  <th className="px-4 py-2 text-right">Kepatuhan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.sla_compliance_by_definition.map((row) => (
                  <tr key={row.definition}>
                    <td className="px-4 py-2 text-slate-700">{row.definition}</td>
                    <td className="px-4 py-2 text-right text-slate-600">{row.total}</td>
                    <td className="px-4 py-2 text-right text-slate-600">{row.breached}</td>
                    <td className="px-4 py-2 text-right">
                      <Badge tone={(row.compliance_percent ?? 100) >= 90 ? 'success' : (row.compliance_percent ?? 0) >= 70 ? 'warning' : 'danger'}>
                        {row.compliance_percent === null ? '—' : `${row.compliance_percent}%`}
                      </Badge>
                    </td>
                  </tr>
                ))}
                {data.sla_compliance_by_definition.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-3 text-slate-500">
                      Belum ada SLA selesai untuk dinilai.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Volume per modul & unit</CardTitle>
          </CardHeader>
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-500">Modul</p>
              <ul className="mt-1 space-y-1 text-sm">
                {data.volume.by_module.map((row) => (
                  <li key={row.module} className="flex items-center justify-between">
                    <span className="text-slate-600">{row.module}</span>
                    <span className="font-medium text-slate-800">{row.total}</span>
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-500">Unit pemohon</p>
              <ul className="mt-1 space-y-1 text-sm">
                {data.volume.by_department.map((row) => (
                  <li key={row.department} className="flex items-center justify-between">
                    <span className="text-slate-600">{row.department}</span>
                    <span className="font-medium text-slate-800">{row.total}</span>
                  </li>
                ))}
                {data.volume.by_department.length === 0 ? <li className="text-slate-500">Belum ada data unit.</li> : null}
              </ul>
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <ShieldAlert className="h-4 w-4" /> Eskalasi SLA
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-3">
            <p className="text-sm text-slate-600">
              Total {escalations.data?.summary.total ?? 0} eskalasi pada periode ini.
            </p>
            <ul className="flex flex-wrap gap-2">
              {(escalations.data?.summary.by_level ?? []).map((row) => (
                <li key={row.level}>
                  <Badge tone="warning">
                    Level {row.level}: {row.total}
                  </Badge>
                </li>
              ))}
            </ul>
            <ul className="divide-y divide-slate-100">
              {(escalations.data?.rows.data ?? []).map((row, index) => (
                <li key={index} className="py-2 text-xs">
                  <p className="font-medium text-slate-700">{String(row.instance ?? '—')}</p>
                  <p className="text-slate-500">
                    level {String(row.level ?? '')} · {String(row.action ?? '')} · {String(row.reason ?? '')}
                  </p>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
