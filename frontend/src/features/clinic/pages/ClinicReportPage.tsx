import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { BarChart3, Download, ShieldCheck } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';
import { downloadCsv } from '../utils/download';

/**
 * Pusat laporan klinik.
 *
 * Semua laporan dihitung dalam cakupan peran pengguna, dan setiap ekspor
 * dicatat di jejak audit (aksi EXPORT) sehingga pengambilan data pasien dapat
 * ditelusuri — persyaratan penting untuk data kesehatan.
 */
export default function ClinicReportPage() {
  const today = new Date().toISOString().slice(0, 10);
  const [from, setFrom] = useState(new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10));
  const [to, setTo] = useState(today);
  const [report, setReport] = useState('daily');

  const overview = useQuery({
    queryKey: ['clinic', 'reports', 'overview', from, to],
    queryFn: () => clinicApi.reportOverview({ from, to }),
  });

  const accessLog = useQuery({
    queryKey: ['clinic', 'reports', 'access-log'],
    queryFn: () => clinicApi.accessLog({ limit: 100 }),
    retry: false,
  });

  const data = overview.data as
    | {
        daily?: { total: number; by_visit_type: Record<string, number>; by_status: Record<string, number>; referred: number; sent_home: number };
        top_complaints?: { complaint: string; total: number }[];
        top_diagnoses?: { diagnosis: string; total: number }[];
        scope?: { patient_level: boolean; can_export: boolean };
      }
    | undefined;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Laporan Klinik</h1>
          <p className="text-sm text-slate-500">
            Ringkasan layanan, pemakaian obat, rujukan, skrining, dan jejak akses rekam medis.
          </p>
        </div>

        <div className="flex flex-wrap items-end gap-2">
          <div>
            <Label>Dari</Label>
            <Input type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          </div>
          <div>
            <Label>Sampai</Label>
            <Input type="date" value={to} onChange={(event) => setTo(event.target.value)} />
          </div>
          <div className="w-48">
            <Label>Jenis ekspor</Label>
            <Select value={report} onChange={(event) => setReport(event.target.value)}>
              <option value="daily">Kunjungan harian</option>
              <option value="medications">Pemakaian obat</option>
              <option value="referrals">Rujukan</option>
              <option value="emergencies">Gawat darurat</option>
              <option value="screenings">Skrining</option>
              <option value="follow-ups">Tindak lanjut</option>
              <option value="staff-activity">Aktivitas petugas</option>
            </Select>
          </div>
          <Button
            onClick={() =>
              downloadCsv(() => clinicApi.exportReport(report, { from, to }))
                .then(() => toast.success('Ekspor dimulai — pengambilan data tercatat di jejak audit.'))
                .catch((error) => toast.error(getErrorMessage(error)))
            }
          >
            <Download className="mr-1 h-4 w-4" /> Ekspor CSV
          </Button>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardBody>
            <p className="text-2xl font-semibold text-slate-900">{data?.daily?.total ?? 0}</p>
            <p className="text-xs text-slate-500">Kunjungan pada rentang ini</p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-2xl font-semibold text-slate-900">{data?.daily?.referred ?? 0}</p>
            <p className="text-xs text-slate-500">Dirujuk</p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-2xl font-semibold text-slate-900">{data?.daily?.sent_home ?? 0}</p>
            <p className="text-xs text-slate-500">Dipulangkan ke rumah</p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-2xl font-semibold text-slate-900">
              {data?.scope?.patient_level ? 'Ya' : 'Tidak'}
            </p>
            <p className="text-xs text-slate-500">Akses laporan tingkat pasien</p>
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <BarChart3 className="h-4 w-4" /> Keluhan tersering
            </CardTitle>
          </CardHeader>
          <CardBody>
            {(data?.top_complaints ?? []).length === 0 ? (
              <p className="text-sm text-slate-500">Belum ada data.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {(data?.top_complaints ?? []).map((row) => (
                  <li key={row.complaint} className="flex justify-between">
                    <span className="text-slate-700">{row.complaint}</span>
                    <span className="font-medium text-slate-900">{row.total}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Diagnosis tersering</CardTitle>
          </CardHeader>
          <CardBody>
            {(data?.top_diagnoses ?? []).length === 0 ? (
              <p className="text-sm text-slate-500">
                Tidak ditampilkan untuk peran Anda (laporan diagnosis termasuk data klinis tingkat pasien).
              </p>
            ) : (
              <ul className="space-y-1 text-sm">
                {(data?.top_diagnoses ?? []).map((row) => (
                  <li key={row.diagnosis} className="flex justify-between">
                    <span className="text-slate-700">{row.diagnosis}</span>
                    <span className="font-medium text-slate-900">{row.total}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ShieldCheck className="h-4 w-4" /> Jejak akses rekam medis
          </CardTitle>
        </CardHeader>
        <CardBody className="p-0">
          {accessLog.isError ? (
            <div className="p-4">
              <EmptyState
                title="Tidak berwenang"
                description="Laporan akses rekam medis hanya untuk pemegang izin audit (mis. kepala sekolah, admin klinik)."
              />
            </div>
          ) : (accessLog.data ?? []).length === 0 ? (
            <div className="p-4">
              <EmptyState title="Belum ada catatan akses" description="Pembukaan rekam medis akan tercatat di sini." />
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                  <tr>
                    <th className="p-2">Waktu</th>
                    <th className="p-2">Aksi</th>
                    <th className="p-2">Pelaku</th>
                    <th className="p-2">Peran</th>
                    <th className="p-2">Pasien</th>
                    <th className="p-2">Alasan akses</th>
                  </tr>
                </thead>
                <tbody>
                  {(accessLog.data ?? []).map((row, index) => (
                    <tr key={index} className="border-t border-slate-100">
                      <td className="p-2 text-xs">{String(row.occurred_at ?? '')}</td>
                      <td className="p-2">{String(row.action ?? '')}</td>
                      <td className="p-2">{String(row.actor ?? '—')}</td>
                      <td className="p-2 text-xs">{String(row.actor_role ?? '—')}</td>
                      <td className="p-2 text-xs">{String(row.patient_id ?? '—')}</td>
                      <td className="p-2 text-xs">{String(row.reason ?? '—')}</td>
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
