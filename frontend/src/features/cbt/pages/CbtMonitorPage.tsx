import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Card, CardBody } from '@/components/ui/Card';
import { cbtApi } from '../api/cbtApi';

export default function CbtMonitorPage() {
  const { id } = useParams();
  const sessionId = Number(id);
  const monitor = useQuery({
    queryKey: ['cbt', 'monitor', sessionId],
    queryFn: () => cbtApi.monitor(sessionId),
    refetchInterval: 8000,
    enabled: Number.isFinite(sessionId),
  });
  const report = useQuery({
    queryKey: ['cbt', 'report', sessionId],
    queryFn: () => cbtApi.report(sessionId),
    enabled: Number.isFinite(sessionId),
  });

  const rows = monitor.data?.rows ?? [];
  const summary = report.data?.summary;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">{monitor.data?.session?.title ?? 'Monitor'}</h1>
          <p className="text-sm text-slate-500">
            Status {monitor.data?.session?.status} · token {monitor.data?.session?.access_token ?? '—'}
          </p>
        </div>
        <a className="text-sm text-brand-700 underline" href={`${import.meta.env.VITE_API_BASE_URL}/cbt/sessions/${sessionId}/export`}>
          Unduh CSV
        </a>
      </div>

      {summary && (
        <div className="grid gap-3 sm:grid-cols-4">
          {[
            ['Peserta', summary.participants],
            ['Terkumpul', summary.submitted],
            ['Rata-rata %', summary.mean_percentage ?? '—'],
            ['Lulus', summary.passed],
          ].map(([label, value]) => (
            <Card key={String(label)}>
              <CardBody>
                <p className="text-xs text-slate-500">{label}</p>
                <p className="text-2xl font-semibold">{String(value)}</p>
              </CardBody>
            </Card>
          ))}
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2">Siswa</th>
              <th className="px-3 py-2">Status</th>
              <th className="px-3 py-2">Terjawab</th>
              <th className="px-3 py-2">Integritas</th>
              <th className="px-3 py-2">Terakhir terlihat</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row: { participant_id: number; student: { full_name: string; nis: string }; attempt: { status: string; answered: number; total: number; integrity_score: number; last_seen_at: string } | null }) => (
              <tr key={row.participant_id} className="border-t">
                <td className="px-3 py-2">{row.student?.full_name} <span className="text-slate-400">{row.student?.nis}</span></td>
                <td className="px-3 py-2">{row.attempt?.status ?? 'belum mulai'}</td>
                <td className="px-3 py-2">{row.attempt ? `${row.attempt.answered}/${row.attempt.total}` : '—'}</td>
                <td className="px-3 py-2">{row.attempt?.integrity_score ?? '—'}</td>
                <td className="px-3 py-2">{row.attempt?.last_seen_at ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
