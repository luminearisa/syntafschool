import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CalendarDays, Heart, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { religiousApi } from '../api/religiousApi';
import { displayStatus, ErrorCard, localDate, LoadingCard, ReligiousPageHeader, statusTone } from '../components/ReligiousUi';
import { getErrorMessage } from '@/lib/axios';

export function ReligiousParentPortalPage() {
  const [date, setDate] = useState(localDate());
  const dashboardQuery = useQuery({
    queryKey: ['religious', 'parent-dashboard', date],
    queryFn: () => religiousApi.parentDashboard(date),
  });
  const dashboard = dashboardQuery.data;
  const children = dashboard?.children ?? [];

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Portal keagamaan wali"
        description="Ringkasan presensi shalat dan prestasi keagamaan anak yang terhubung dengan akun wali. Data dibatasi pada anak yang menjadi tanggung jawab Anda."
        actions={
          <>
            <label className="sr-only" htmlFor="religious-parent-date">Tanggal ringkasan anak</label>
            <Input id="religious-parent-date" type="date" className="w-40" value={date} onChange={(event) => setDate(event.target.value)} />
          </>
        }
      />

      {dashboardQuery.isLoading ? <LoadingCard label="Memuat ringkasan anak..." /> : dashboardQuery.isError ? <ErrorCard message={getErrorMessage(dashboardQuery.error)} onRetry={() => dashboardQuery.refetch()} /> : dashboard?.enabled === false ? (
        <Card><CardBody className="flex items-start gap-3 p-5"><ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-amber-700" /><div><p className="font-medium text-slate-800">Portal belum diaktifkan</p><p className="mt-1 text-sm text-slate-500">{dashboard.reason ?? 'Institusi belum mengaktifkan ringkasan keagamaan wali.'}</p></div></CardBody></Card>
      ) : (
        <>
          <div className="flex items-start gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-950">
            <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-emerald-700" />
            <div><p className="font-semibold">Privasi data anak</p><p className="mt-1 text-xs leading-5">{dashboard?.privacy_note ?? 'Informasi hanya ditampilkan untuk anak yang menjadi tanggung jawab akun wali ini.'}</p></div>
          </div>
          {children.length === 0 ? (
            <Card><CardBody className="py-10 text-center"><Heart className="mx-auto h-7 w-7 text-slate-300" /><p className="mt-3 text-sm font-medium text-slate-700">Belum ada anak yang terhubung</p><p className="mt-1 text-xs text-slate-500">Hubungi administrator sekolah jika hubungan wali–siswa belum diperbarui.</p></CardBody></Card>
          ) : (
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
              {children.map((child) => {
                const attendance = child.prayer_attendance ?? [];
                const achievements = child.achievements ?? [];
                return (
                  <Card key={child.student_id}>
                    <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                      <div><CardTitle className="text-sm">Siswa #{child.student_id}</CardTitle><p className="mt-1 text-xs text-slate-500">Ringkasan {dashboard?.date ?? date}</p></div>
                      <Badge tone="info">{attendance.length} catatan presensi</Badge>
                    </CardHeader>
                    <CardBody className="space-y-4">
                      <div>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Presensi shalat</h3>
                        {attendance.length === 0 ? <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Belum ada catatan pada tanggal ini.</p> : (
                          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {attendance.map((record, index) => (
                              <div key={`${record.prayer_type}-${index}`} className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2">
                                <span className="text-xs font-medium text-slate-700">{record.prayer_type}</span>
                                <Badge tone={statusTone(record.status)}>{displayStatus(record.status)}</Badge>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                      <div>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Prestasi terbaru</h3>
                        {achievements.length === 0 ? <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Belum ada prestasi yang tercatat.</p> : (
                          <div className="space-y-2">
                            {achievements.map((achievement, index) => <div key={`${achievement.title}-${index}`} className="flex items-center justify-between gap-3 rounded-lg border border-slate-100 p-3"><span className="text-sm font-medium text-slate-800">{achievement.title}</span><span className="inline-flex items-center gap-1 text-xs text-slate-500"><CalendarDays className="h-3.5 w-3.5" />{achievement.date}</span></div>)}
                          </div>
                        )}
                      </div>
                    </CardBody>
                  </Card>
                );
              })}
            </div>
          )}
        </>
      )}
    </div>
  );
}
