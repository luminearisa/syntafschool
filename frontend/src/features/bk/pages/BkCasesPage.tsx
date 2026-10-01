import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { bkApi } from '../api/bkApi';

const PRIORITY_TONE: Record<string, 'neutral' | 'warning' | 'danger'> = {
  LOW: 'neutral', NORMAL: 'neutral', HIGH: 'warning', URGENT: 'danger',
};

/**
 * Daftar kasus BK.
 *
 * Penyaring yang tersedia mengikuti pertanyaan kerja sehari-hari: mana yang
 * belum ditangani, mana yang prioritasnya tinggi, dan mana yang tinjauannya
 * sudah lewat. Isi kasus hanya dapat dibuka pada halaman detail (dengan Policy).
 */
export default function BkCasesPage() {
  const [filters, setFilters] = useState({ status: 'open', priority: '', search: '' });

  const cases = useQuery({
    queryKey: ['bk', 'cases', filters],
    queryFn: () => bkApi.cases({
      status: filters.status === 'open' ? undefined : filters.status,
      open: filters.status === 'open' ? 1 : undefined,
      priority: filters.priority || undefined,
      search: filters.search || undefined,
      per_page: 25,
    }).then((r) => r.data.data),
  });

  const rows = cases.data ?? [];

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">Kasus BK</h1>
          <p className="mt-1 text-sm text-slate-500">
            Penanganan yang sedang berjalan. Daftar ini mengikuti cakupan Anda: konselor melihat kasusnya, koordinator melihat semua.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <div className="w-44">
            <Select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })} aria-label="Status">
              <option value="open">Berjalan</option>
              {['OPEN', 'ASSESSMENT', 'INTERVENTION', 'MONITORING', 'ON_HOLD', 'REFERRED', 'RESOLVED', 'CLOSED', 'REOPENED'].map((status) => (
                <option key={status} value={status}>{status}</option>
              ))}
            </Select>
          </div>
          <div className="w-40">
            <Select value={filters.priority} onChange={(event) => setFilters({ ...filters, priority: event.target.value })} aria-label="Prioritas">
              <option value="">Semua prioritas</option>
              {['LOW', 'NORMAL', 'HIGH', 'URGENT'].map((priority) => <option key={priority} value={priority}>{priority}</option>)}
            </Select>
          </div>
          <div className="w-56">
            <Input placeholder="Cari nomor kasus / nama siswa" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} />
          </div>
        </div>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-sm">Daftar kasus</CardTitle></CardHeader>
        <CardBody>
          {cases.isLoading ? <Spinner /> : rows.length === 0 ? (
            <EmptyState title="Tidak ada kasus" description="Belum ada kasus yang cocok dengan penyaring ini." />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-600">
                    <th className="px-3 py-2">Nomor</th>
                    <th className="px-3 py-2">Siswa</th>
                    <th className="px-3 py-2">Jenis</th>
                    <th className="px-3 py-2">Prioritas</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Konselor</th>
                    <th className="px-3 py-2">Tinjauan</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b border-slate-100">
                      <td className="px-3 py-2">
                        <Link className="font-medium text-brand-700 underline" to={`/bk/cases/${row.id}`}>{row.case_number}</Link>
                      </td>
                      <td className="px-3 py-2 text-slate-700">{row.student?.full_name ?? `#${row.student_id}`}</td>
                      <td className="px-3 py-2 text-slate-600">{row.case_type}</td>
                      <td className="px-3 py-2"><Badge tone={PRIORITY_TONE[row.priority] ?? 'neutral'}>{row.priority}</Badge></td>
                      <td className="px-3 py-2 text-slate-600">{row.status}</td>
                      <td className="px-3 py-2 text-slate-600">{row.counselor?.name ?? 'Belum ditugaskan'}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">{row.target_review_date ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
