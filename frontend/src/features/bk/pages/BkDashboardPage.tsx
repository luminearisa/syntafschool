import { useQuery } from '@tanstack/react-query';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { bkApi } from '../api/bkApi';

/**
 * Dasbor BK.
 *
 * Angka yang tampil mengikuti wewenang pemakainya: konselor melihat beban
 * kerjanya sendiri, koordinator melihat seluruh modul. Semua widget berisi
 * **agregat** — tidak ada daftar kasus atau isi penanganan di sini.
 */
const WIDGETS: { key: keyof Awaited<ReturnType<typeof bkApi.dashboard>>['data']['data']; label: string; tone?: string }[] = [
  { key: 'active_cases', label: 'Kasus aktif' },
  { key: 'new_referrals', label: 'Referral hari ini' },
  { key: 'pending_screening', label: 'Perlu screening' },
  { key: 'unassigned_cases', label: 'Belum ada konselor' },
  { key: 'today_sessions', label: 'Sesi hari ini' },
  { key: 'upcoming_sessions', label: 'Sesi mendatang' },
  { key: 'overdue_follow_ups', label: 'Tindak lanjut terlambat' },
  { key: 'open_interventions', label: 'Intervensi berjalan' },
  { key: 'pending_support_requests', label: 'Permintaan portal' },
  { key: 'review_due_soon', label: 'Tinjauan ≤ 7 hari' },
];

export default function BkDashboardPage() {
  const dashboard = useQuery({ queryKey: ['bk', 'dashboard'], queryFn: () => bkApi.dashboard().then((r) => r.data.data) });
  const today = useQuery({ queryKey: ['bk', 'today'], queryFn: () => bkApi.today().then((r) => r.data.data) });

  if (dashboard.isLoading) return <Spinner />;

  const data = dashboard.data;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Bimbingan &amp; Konseling</h1>
        <p className="mt-1 text-sm text-slate-500">
          Ringkasan operasional BK. Catatan konseling tidak pernah ditampilkan di dasbor — hanya jumlah dan sebaran.
        </p>
      </div>

      {data && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {WIDGETS.map((widget) => {
              const value = data[widget.key] as number;
              return (
                <Card key={widget.key}>
                  <CardBody>
                    <p className="text-xs uppercase tracking-wide text-slate-600">{widget.label}</p>
                    <p className={`mt-1 text-2xl font-semibold ${value > 0 && widget.key === 'overdue_follow_ups' ? 'text-red-600' : 'text-slate-800'}`}>
                      {value}
                    </p>
                  </CardBody>
                </Card>
              );
            })}
          </div>

          <div className="grid gap-4 lg:grid-cols-3">
            <Card>
              <CardHeader><CardTitle className="text-sm">Kasus per kategori</CardTitle></CardHeader>
              <CardBody className="space-y-1 text-sm">
                {data.cases_by_category.length === 0 ? <p className="text-slate-500">Belum ada kasus.</p> : data.cases_by_category.map((row) => (
                  <div key={row.key} className="flex justify-between border-b border-slate-100 pb-1 last:border-0">
                    <span className="text-slate-600">{row.key}</span>
                    <span className="font-medium text-slate-800">{row.total}</span>
                  </div>
                ))}
              </CardBody>
            </Card>

            <Card>
              <CardHeader><CardTitle className="text-sm">Kasus per prioritas</CardTitle></CardHeader>
              <CardBody className="space-y-1 text-sm">
                {data.cases_by_priority.length === 0 ? <p className="text-slate-500">Belum ada kasus.</p> : data.cases_by_priority.map((row) => (
                  <div key={row.key} className="flex justify-between border-b border-slate-100 pb-1 last:border-0">
                    <span className="text-slate-600">{row.key}</span>
                    <span className="font-medium text-slate-800">{row.total}</span>
                  </div>
                ))}
              </CardBody>
            </Card>

            <Card>
              <CardHeader><CardTitle className="text-sm">Beban konselor</CardTitle></CardHeader>
              <CardBody className="space-y-1 text-sm">
                {data.cases_by_counselor.length === 0 ? <p className="text-slate-500">Belum ada penugasan.</p> : data.cases_by_counselor.map((row) => (
                  <div key={row.counselor} className="flex justify-between border-b border-slate-100 pb-1 last:border-0">
                    <span className="text-slate-600">{row.counselor}</span>
                    <span className="font-medium text-slate-800">{row.total}</span>
                  </div>
                ))}
                <p className="pt-2 text-xs text-slate-500">
                  Rata-rata durasi kasus selesai: {data.average_case_duration_days ?? '—'} hari · sesi pekan ini: {data.sessions_this_week}
                </p>
              </CardBody>
            </Card>
          </div>

          <Card>
            <CardHeader><CardTitle className="text-sm">Antrean hari ini</CardTitle></CardHeader>
            <CardBody className="grid gap-4 lg:grid-cols-2">
              <div>
                <p className="mb-2 text-xs uppercase tracking-wide text-slate-600">Janji temu</p>
                {today.isLoading ? <Spinner /> : (today.data?.appointments ?? []).length === 0 ? (
                  <EmptyState title="Tidak ada janji hari ini" description="Jadwal konseling hari ini kosong." />
                ) : (
                  <ul className="space-y-1 text-sm">
                    {(today.data?.appointments ?? []).map((row) => (
                      <li key={String(row.id)} className="flex justify-between border-b border-slate-100 pb-1 last:border-0">
                        <span className="text-slate-700">{String(row.time)} · {String(row.student)}</span>
                        <span className="text-slate-500">{String(row.status)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <div>
                <p className="mb-2 text-xs uppercase tracking-wide text-slate-600">Tindak lanjut jatuh tempo</p>
                {(today.data?.follow_ups ?? []).length === 0 ? (
                  <p className="text-sm text-slate-500">Tidak ada tindak lanjut yang menunggu.</p>
                ) : (
                  <ul className="space-y-1 text-sm">
                    {(today.data?.follow_ups ?? []).map((row) => (
                      <li key={String(row.id)} className="flex justify-between border-b border-slate-100 pb-1 last:border-0">
                        <span className="text-slate-700">{String(row.title)}</span>
                        <span className={row.is_overdue ? 'text-red-600' : 'text-slate-500'}>{String(row.due_date)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}
