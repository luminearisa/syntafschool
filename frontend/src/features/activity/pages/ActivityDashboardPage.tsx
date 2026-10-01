import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { useAuthStore } from '@/stores/authStore';
import { activityApi } from '../api/activityApi';

const REPORT_TYPES = [
  { value: 'participation', label: 'Partisipasi per aktivitas' },
  { value: 'attendance', label: 'Kehadiran per aktivitas' },
  { value: 'membership', label: 'Keanggotaan' },
  { value: 'capacity', label: 'Kapasitas & daftar tunggu' },
  { value: 'waitlist', label: 'Daftar tunggu' },
  { value: 'dropout', label: 'Berhenti & penangguhan' },
  { value: 'completion', label: 'Penyelesaian aktivitas' },
  { value: 'achievement', label: 'Prestasi' },
  { value: 'certificate', label: 'Sertifikat' },
  { value: 'competition', label: 'Kompetisi' },
  { value: 'coach_performance', label: 'Kelengkapan pembinaan' },
];

/**
 * Dasbor Aktivitas.
 *
 * Untuk pengelola: angka yang dipakai mengambil keputusan (kursi, daftar
 * tunggu, kehadiran, prestasi) — bukan sekadar jumlah baris tabel. Untuk
 * pembina, ringkasan program yang dibina. Laporan operasional (13 jenis)
 * tersedia di bagian bawah, dengan penyaring dan tabel yang sama bentuknya.
 */
export default function ActivityDashboardPage() {
  const can = useAuthStore((state) => state.can);
  const [reportType, setReportType] = useState('participation');

  const dashboardQuery = useQuery({ queryKey: ['activity', 'dashboard'], queryFn: () => activityApi.dashboard().then((r) => r.data.data) });
  const healthQuery = useQuery({
    queryKey: ['activity', 'health'],
    queryFn: () => activityApi.health().then((r) => r.data.data),
    enabled: can('activity.settings.view'),
  });
  const reportQuery = useQuery({
    queryKey: ['activity', 'report', reportType],
    queryFn: () => activityApi.reports(reportType).then((r) => r.data.data),
    enabled: can('activity.report.view'),
  });

  if (dashboardQuery.isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  const dashboard = dashboardQuery.data;
  const admin = dashboard?.admin ?? null;
  const coach = dashboard?.coach ?? null;
  const report = reportQuery.data;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">Dasbor Aktivitas</h1>
          <p className="mt-1 text-sm text-slate-500">
            Gambaran kegiatan non-akademik: keikutsertaan, kehadiran, prestasi, dan hal-hal yang butuh tindakan.
          </p>
        </div>
      </div>

      {coach && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Program yang Anda bina</CardTitle>
          </CardHeader>
          <CardBody className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {coach.programs.length === 0 ? (
              <p className="text-sm text-slate-500">{coach.message ?? 'Belum ada program yang ditugaskan.'}</p>
            ) : (
              coach.programs.map((program) => (
                <div key={program.id} className="rounded-lg border border-slate-200 p-3">
                  <p className="text-sm font-medium text-slate-800">{program.name}</p>
                  <p className="mt-1 text-xs text-slate-500">
                    {program.members} anggota · {program.sessions} sesi · {program.assessments} penilaian
                  </p>
                </div>
              ))
            )}
            <div className="rounded-lg border border-slate-200 p-3">
              <p className="text-xs uppercase tracking-wide text-slate-500">Penilaian menunggu</p>
              <p className="mt-1 text-2xl font-semibold text-slate-800">{coach.pending_assessments}</p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3">
              <p className="text-xs uppercase tracking-wide text-slate-500">Kelengkapan catatan kehadiran</p>
              <p className="mt-1 text-2xl font-semibold text-slate-800">
                {coach.attendance_completion === null || coach.attendance_completion === undefined ? '-' : `${coach.attendance_completion}%`}
              </p>
            </div>
          </CardBody>
        </Card>
      )}

      {admin && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[
              ['Program aktif', `${admin.programs.active}/${admin.programs.total}`],
              ['Siswa beraktivitas', admin.students.active_members],
              ['Rata-rata aktivitas/siswa', admin.students.average_activities_per_student],
              ['Pendaftaran menunggu', admin.registrations.pending],
              ['Daftar tunggu', admin.registrations.waitlisted],
              ['Kehadiran tercatat', admin.attendance.percentage === null ? '-' : `${admin.attendance.percentage}%`],
              ['Prestasi terverifikasi', `${admin.achievements.verified}/${admin.achievements.total}`],
              ['Sertifikat terbit', admin.certificates.issued],
            ].map(([label, value]) => (
              <Card key={String(label)}>
                <CardBody>
                  <p className="text-xs uppercase tracking-wide text-slate-500">{label}</p>
                  <p className="mt-1 text-2xl font-semibold text-slate-800">{value as string | number}</p>
                </CardBody>
              </Card>
            ))}
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Aktivitas terpopuler</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm">
                {admin.popular_activities.length === 0 ? (
                  <p className="text-slate-500">Belum ada anggota terdaftar.</p>
                ) : (
                  admin.popular_activities.map((row) => (
                    <div key={row.program_id} className="flex items-center justify-between border-b border-slate-100 pb-2 last:border-0">
                      <span className="text-slate-700">{row.program ?? `#${row.program_id}`}</span>
                      <Badge tone="info">{row.members} anggota</Badge>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Perlu perhatian (kapasitas & daftar tunggu)</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm">
                {admin.capacity_alerts.length === 0 ? (
                  <p className="text-slate-500">Tidak ada aktivitas yang mendekati kapasitas.</p>
                ) : (
                  admin.capacity_alerts.map((row) => (
                    <div key={row.program_id} className="flex items-center justify-between border-b border-slate-100 pb-2 last:border-0">
                      <div>
                        <p className="text-slate-700">{row.activity}</p>
                        <p className="text-xs text-slate-500">
                          {row.active}/{row.capacity} kursi terisi · sisa {row.seats_left}
                        </p>
                      </div>
                      <div className="flex gap-1">
                        <Badge tone={row.fill_rate >= 100 ? 'danger' : 'warning'}>{row.fill_rate}%</Badge>
                        {row.waitlist > 0 && <Badge tone="info">{row.waitlist} antre</Badge>}
                      </div>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>
          </div>
        </>
      )}

      {can('activity.report.view') && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Laporan operasional</CardTitle>
          </CardHeader>
          <CardBody className="space-y-3">
            <div className="w-72">
              <Select value={reportType} onChange={(event) => setReportType(event.target.value)} aria-label="Jenis laporan">
                {REPORT_TYPES.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </Select>
            </div>

            {reportQuery.isLoading ? (
              <Spinner />
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                      {(report?.columns ?? []).map((column) => (
                        <th key={column} className="px-3 py-2">
                          {column}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {(report?.rows ?? []).slice(0, 25).map((row, index) => (
                      <tr key={index} className="border-b border-slate-100">
                        {(report?.columns ?? []).map((column) => (
                          <td key={column} className="px-3 py-2 text-slate-700">
                            {row[column] === null || row[column] === undefined ? '-' : String(row[column])}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
                {(report?.rows ?? []).length === 0 && <p className="py-4 text-sm text-slate-500">Belum ada data untuk laporan ini.</p>}
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {can('activity.settings.view') && healthQuery.data && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Kesehatan modul & integrasi</CardTitle>
          </CardHeader>
          <CardBody className="space-y-3 text-sm">
            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
              {Object.entries(healthQuery.data.data).map(([key, value]) => (
                <div key={key} className="rounded-lg border border-slate-200 p-3">
                  <p className="text-xs uppercase tracking-wide text-slate-500">{key.replace(/_/g, ' ')}</p>
                  <p className="mt-1 text-lg font-semibold text-slate-800">{value}</p>
                </div>
              ))}
            </div>

            <div className="overflow-x-auto">
              <table className="min-w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 text-left uppercase tracking-wide text-slate-500">
                    <th className="px-3 py-2">Waktu</th>
                    <th className="px-3 py-2">Integrasi</th>
                    <th className="px-3 py-2">Aksi</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Durasi</th>
                  </tr>
                </thead>
                <tbody>
                  {healthQuery.data.recent_integration_calls.map((call) => (
                    <tr key={call.id} className="border-b border-slate-100">
                      <td className="px-3 py-1 text-slate-600">{call.created_at?.slice(0, 19).replace('T', ' ')}</td>
                      <td className="px-3 py-1">{call.integration}</td>
                      <td className="px-3 py-1">{call.action}</td>
                      <td className="px-3 py-1">
                        <Badge tone={call.status === 'OK' ? 'success' : call.status === 'SKIPPED' ? 'neutral' : 'danger'}>{call.status}</Badge>
                      </td>
                      <td className="px-3 py-1 text-slate-600">{call.duration_ms ?? '-'} ms</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardBody>
        </Card>
      )}
    </div>
  );
}
