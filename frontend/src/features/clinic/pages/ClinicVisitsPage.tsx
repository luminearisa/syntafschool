import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Filter } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';

/**
 * Daftar kunjungan dengan filter.
 *
 * Berguna untuk menelusuri riwayat: kunjungan terbuka muncul lebih dahulu,
 * lalu yang sudah ditutup, diurutkan waktu. Cakupan baris tetap mengikuti peran
 * pengguna (petugas klinik: sekolah; orang tua/siswa: memakai portal).
 */
export default function ClinicVisitsPage() {
  const [filters, setFilters] = useState({ date: '', status: '', visit_type: '', priority: '', search: '' });
  const [page, setPage] = useState(1);

  const visits = useQuery({
    queryKey: ['clinic', 'visits', filters, page],
    queryFn: () => clinicApi.visits({ ...filters, page, per_page: 20 }),
  });

  const metadata = useQuery({ queryKey: ['clinic', 'metadata'], queryFn: () => clinicApi.metadata() });

  if (visits.isLoading) {
    return (
      <div className="flex justify-center py-20">
        <Spinner />
      </div>
    );
  }

  if (visits.isError) {
    return <EmptyState title="Daftar kunjungan tidak dapat dimuat" description={getErrorMessage(visits.error)} />;
  }

  const rows = visits.data?.data ?? [];
  const meta = visits.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Kunjungan</h1>
          <p className="text-sm text-slate-500">Riwayat layanan klinis beserta status penanganannya.</p>
        </div>
        <Link to="/clinic/patients">
          <Button size="sm">Kunjungan baru</Button>
        </Link>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Filter className="h-4 w-4" /> Filter
          </CardTitle>
        </CardHeader>
        <CardBody className="grid gap-3 md:grid-cols-5">
          <div>
            <Label>Tanggal</Label>
            <Input type="date" value={filters.date} onChange={(event) => setFilters({ ...filters, date: event.target.value })} />
          </div>
          <div>
            <Label>Status</Label>
            <Select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">Semua</option>
              <option value="REGISTERED">Terdaftar</option>
              <option value="TRIAGED">Sudah triase</option>
              <option value="IN_PROGRESS">Dalam penanganan</option>
              <option value="OBSERVATION">Observasi</option>
              <option value="REFERRED">Dirujuk</option>
              <option value="DISCHARGED">Dipulangkan</option>
              <option value="CANCELLED">Dibatalkan</option>
            </Select>
          </div>
          <div>
            <Label>Jenis</Label>
            <Select value={filters.visit_type} onChange={(event) => setFilters({ ...filters, visit_type: event.target.value })}>
              <option value="">Semua</option>
              {(metadata.data?.options.visit_types ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Prioritas</Label>
            <Select value={filters.priority} onChange={(event) => setFilters({ ...filters, priority: event.target.value })}>
              <option value="">Semua</option>
              {(metadata.data?.options.priorities ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Cari</Label>
            <Input
              placeholder="Nomor kunjungan / nama / NIS"
              value={filters.search}
              onChange={(event) => setFilters({ ...filters, search: event.target.value })}
            />
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardBody className="p-0">
          {rows.length === 0 ? (
            <div className="p-4">
              <EmptyState title="Tidak ada kunjungan" description="Ubah filter atau daftarkan kunjungan baru." />
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
                  <tr>
                    <th className="p-2">Waktu</th>
                    <th className="p-2">Nomor</th>
                    <th className="p-2">Pasien</th>
                    <th className="p-2">Keluhan</th>
                    <th className="p-2">Prioritas</th>
                    <th className="p-2">Status</th>
                    <th className="p-2">Pulang</th>
                    <th className="p-2" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-t border-slate-100">
                      <td className="p-2 text-xs text-slate-600">
                        {new Date(row.visit_at).toLocaleString('id-ID', {
                          day: '2-digit',
                          month: 'short',
                          hour: '2-digit',
                          minute: '2-digit',
                        })}
                      </td>
                      <td className="p-2 font-mono text-xs">{row.visit_number}</td>
                      <td className="p-2">
                        {row.patient.name ?? 'Pasien'}
                        <span className="ml-1 text-xs text-slate-600">{row.patient.nis ?? ''}</span>
                      </td>
                      <td className="p-2 text-xs text-slate-700">{row.chief_complaint ?? '—'}</td>
                      <td className="p-2">
                        <Badge tone={row.priority === 'EMERGENCY' ? 'danger' : row.priority === 'URGENT' ? 'warning' : 'neutral'}>
                          {row.priority_label}
                        </Badge>
                      </td>
                      <td className="p-2">
                        <Badge tone={row.status === 'DISCHARGED' ? 'success' : row.status === 'CANCELLED' ? 'neutral' : 'info'}>
                          {row.status_label}
                        </Badge>
                      </td>
                      <td className="p-2 text-xs text-slate-700">{row.discharge_status ?? '—'}</td>
                      <td className="p-2">
                        <Link to={`/clinic/visits/${row.id}`}>
                          <Button size="sm" variant="secondary">
                            Buka
                          </Button>
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      {meta && meta.last_page > 1 ? (
        <div className="flex items-center justify-between text-sm text-slate-600">
          <span>
            Halaman {meta.current_page} dari {meta.last_page} · {meta.total} kunjungan
          </span>
          <div className="flex gap-2">
            <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Sebelumnya
            </Button>
            <Button size="sm" variant="secondary" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>
              Berikutnya
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
