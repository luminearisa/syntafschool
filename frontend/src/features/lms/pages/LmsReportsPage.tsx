import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, FileSpreadsheet, Play } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { phase4Api } from '../api/phase4Api';

/**
 * Report center (§22).
 *
 * Laporan dibaca dari data yang sama dengan halaman guru/siswa. Laporan besar
 * dibuat di antrean; tombol ekspor akan mengembalikan status antreannya.
 */
export default function LmsReportsPage() {
  const [selected, setSelected] = useState<string | null>(null);
  const [courseId, setCourseId] = useState('');
  const [range, setRange] = useState({ from: '', to: '' });

  const catalog = useQuery({ queryKey: ['lms', 'reports'], queryFn: () => phase4Api.reportCatalog() });
  const exports = useQuery({ queryKey: ['lms', 'report-exports'], queryFn: () => phase4Api.reportExports() });

  const filters = {
    course_id: courseId || undefined,
    from: range.from || undefined,
    to: range.to || undefined,
  };

  const run = useQuery({
    queryKey: ['lms', 'report', selected, filters],
    queryFn: () => phase4Api.runReport(selected as string, filters),
    enabled: selected !== null,
  });

  const exportReport = useMutation({
    mutationFn: (key: string) => phase4Api.exportReport(key, filters, 'CSV'),
    onSuccess: (result) => {
      const row = result as { status?: string; rows_count?: number; queued?: boolean };
      toast.success(
        row.queued ? 'Laporan sedang dibuat di antrean' : `Laporan siap (${row.rows_count ?? 0} baris)`,
      );
      void exports.refetch();
    },
    onError: () => toast.error('Ekspor laporan gagal'),
  });

  const reports: { key: string; label: string; columns: string[] }[] = (
    catalog.data?.reports ?? []
  ) as { key: string; label: string; columns: string[] }[];

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <FileSpreadsheet className="h-5 w-5 text-brand-600" aria-hidden="true" /> Laporan pembelajaran
        </h1>
        <p className="text-sm text-slate-500">
          Laporan course, pendaftaran, ketuntasan, nilai, kehadiran, sertifikat, dan siswa berisiko.
        </p>
      </header>

      <Card>
        <CardBody className="grid gap-3 sm:grid-cols-3">
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">Course (opsional)</span>
            <input
              value={courseId}
              onChange={(event) => setCourseId(event.target.value)}
              inputMode="numeric"
              placeholder="ID course"
              className="w-full rounded-lg border border-slate-300 px-3 py-2"
            />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">Dari tanggal</span>
            <input
              type="date"
              value={range.from}
              onChange={(event) => setRange({ ...range, from: event.target.value })}
              className="w-full rounded-lg border border-slate-300 px-3 py-2"
            />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">Sampai tanggal</span>
            <input
              type="date"
              value={range.to}
              onChange={(event) => setRange({ ...range, to: event.target.value })}
              className="w-full rounded-lg border border-slate-300 px-3 py-2"
            />
          </label>
        </CardBody>
      </Card>

      {catalog.isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : (
        <div className="flex flex-wrap gap-2">
          {reports.map((report) => (
            <Button
              key={report.key}
              size="sm"
              variant={selected === report.key ? 'primary' : 'outline'}
              onClick={() => setSelected(report.key)}
            >
              {report.label}
            </Button>
          ))}
        </div>
      )}

      {selected && (
        <Card>
          <CardHeader className="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>{run.data?.label ?? selected}</CardTitle>
            <div className="flex items-center gap-2">
              <Badge tone="blue">{run.data?.meta?.rows ?? 0} baris</Badge>
              <Button size="sm" variant="outline" onClick={() => exportReport.mutate(selected)} disabled={exportReport.isPending}>
                <Download className="mr-2 h-4 w-4" aria-hidden="true" /> Ekspor CSV
              </Button>
            </div>
          </CardHeader>
          <CardBody>
            {run.isLoading ? (
              <div className="flex justify-center py-6">
                <Spinner />
              </div>
            ) : (run.data?.rows ?? []).length === 0 ? (
              <EmptyState title="Tidak ada data" description="Coba ubah filter course atau rentang tanggal." />
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                      {(run.data?.columns ?? []).map((column) => (
                        <th key={column} className="px-2 py-2">{column.replace(/_/g, ' ')}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {(run.data?.rows ?? []).slice(0, 100).map((row, index) => (
                      <tr key={index} className="border-b border-slate-100">
                        {(run.data?.columns ?? []).map((column) => (
                          <td key={column} className="px-2 py-1.5 text-slate-700">
                            {row[column] === null || row[column] === undefined ? '-' : String(row[column])}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
                {(run.data?.rows ?? []).length > 100 && (
                  <p className="mt-2 text-xs text-slate-500">
                    Menampilkan 100 baris pertama; ekspor CSV memuat seluruh baris.
                  </p>
                )}
              </div>
            )}
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Play className="h-4 w-4 text-slate-500" aria-hidden="true" /> Riwayat ekspor
          </CardTitle>
        </CardHeader>
        <CardBody>
          {(exports.data?.exports ?? []).length === 0 ? (
            <p className="text-sm text-slate-500">Belum ada ekspor laporan.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {(exports.data?.exports ?? []).map((entry, index) => {
                const row = entry as { id: number; report_key: string; status_label?: string; rows_count?: number; created_at?: string };
                return (
                  <li key={row.id ?? index} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span className="text-slate-700">{row.report_key}</span>
                    <span className="flex items-center gap-2">
                      <Badge tone="gray">{row.status_label ?? '—'}</Badge>
                      <span className="text-xs text-slate-400">{row.rows_count ?? 0} baris</span>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={async () => {
                          const download = await phase4Api.downloadReport(row.id);
                          const blob = new Blob([download.contents], { type: 'text/csv;charset=utf-8' });
                          const url = URL.createObjectURL(blob);
                          const anchor = document.createElement('a');
                          anchor.href = url;
                          anchor.download = `laporan-${row.report_key.toLowerCase()}.csv`;
                          anchor.click();
                          URL.revokeObjectURL(url);
                        }}
                      >
                        Unduh
                      </Button>
                    </span>
                  </li>
                );
              })}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
