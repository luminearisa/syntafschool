import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { bkApi } from '../api/bkApi';

/**
 * Laporan agregat BK.
 *
 * Semua laporan bebas dari isi catatan konseling: yang ditampilkan hanya angka
 * dan sebaran, sehingga layar ini aman dibuka kepala sekolah maupun auditor.
 */
export default function BkReportsPage() {
  const [type, setType] = useState('caseload');
  const [range, setRange] = useState({ from: '', to: '' });

  const metadata = useQuery({ queryKey: ['bk', 'metadata'], queryFn: () => bkApi.metadata().then((r) => r.data.data) });
  const report = useQuery({
    queryKey: ['bk', 'report', type, range],
    queryFn: () => bkApi.reports(type, { from: range.from || undefined, to: range.to || undefined }).then((r) => r.data.data),
  });

  const rows = report.data?.rows ?? [];
  const columns = rows.length > 0 ? Object.keys(rows[0]) : [];

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Laporan BK</h1>
        <p className="mt-1 text-sm text-slate-500">Rekap operasional tanpa isi catatan konseling — aman untuk rapat manajemen.</p>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-end gap-2">
            <div className="w-56">
              <Select value={type} onChange={(event) => setType(event.target.value)} aria-label="Jenis laporan">
                {(metadata.data?.report_types ?? ['caseload']).map((item) => <option key={item} value={item}>{item}</option>)}
              </Select>
            </div>
            <div className="w-40"><Input type="date" value={range.from} onChange={(event) => setRange({ ...range, from: event.target.value })} aria-label="Dari" /></div>
            <div className="w-40"><Input type="date" value={range.to} onChange={(event) => setRange({ ...range, to: event.target.value })} aria-label="Sampai" /></div>
          </div>
        </CardHeader>
        <CardBody>
          {report.isLoading ? <Spinner /> : rows.length === 0 ? (
            <EmptyState title="Belum ada data" description="Rentang ini belum memiliki catatan untuk dilaporkan." />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-600">
                    {columns.map((column) => <th key={column} className="px-3 py-2">{column}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row, index) => (
                    <tr key={index} className="border-b border-slate-100">
                      {columns.map((column) => (
                        <td key={column} className="px-3 py-2 text-slate-700">
                          {typeof row[column] === 'string' || typeof row[column] === 'number' ? String(row[column]) : '—'}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <p className="mt-3 text-xs text-slate-500">
            Rentang: {report.data?.range.from ?? '—'} s.d. {report.data?.range.to ?? '—'}
            {report.data?.meta.scope ? ` · cakupan ${String(report.data.meta.scope)}` : ''}
          </p>
        </CardBody>
      </Card>
    </div>
  );
}
