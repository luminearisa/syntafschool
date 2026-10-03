import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, RefreshCw } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import { religiousApi } from '../api/religiousApi';
import { ErrorCard, FormField, localDate, LoadingCard, MetricCard, ReligiousPageHeader } from '../components/ReligiousUi';

function monthStart(date: string) {
  return date ? `${date.slice(0, 7)}-01` : localDate();
}

export function ReligiousReportsPage() {
  const { can } = usePermission();
  const today = localDate();
  const [from, setFrom] = useState(monthStart(today));
  const [to, setTo] = useState(today);
  const [groupBy, setGroupBy] = useState('DATE');
  const canViewAnalytics = can('religious.analytics.view');
  const canViewReports = can('religious.report.view');

  const analyticsQuery = useQuery({
    queryKey: ['religious', 'analytics', from, to],
    queryFn: () => religiousApi.analytics({ from, to }),
    enabled: canViewAnalytics,
  });
  const prayerReportQuery = useQuery({
    queryKey: ['religious', 'prayer-report', from, to, groupBy],
    queryFn: () => religiousApi.prayerReport({ from, to, group_by: groupBy }),
    enabled: canViewReports,
  });

  const attendance = analyticsQuery.data?.prayer_attendance;
  const activity = analyticsQuery.data?.activity_participation;
  const utilization = analyticsQuery.data?.mosque_utilization;
  const prayerRows = prayerReportQuery.data?.rows ?? [];
  const isBusy = analyticsQuery.isFetching || prayerReportQuery.isFetching;

  const exportJson = () => {
    const report = prayerReportQuery.data;
    if (!report) return;
    const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `laporan-presensi-keagamaan-${from}-${to}.json`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Laporan & analitik"
        description="Pantau tren presensi, partisipasi kegiatan, dan pemanfaatan masjid dalam periode tertentu. Laporan ini bersifat deskriptif."
        actions={
          <>
            <Button variant="outline" size="icon" aria-label="Muat ulang laporan" title="Muat ulang" onClick={() => { analyticsQuery.refetch(); prayerReportQuery.refetch(); }}>
              <RefreshCw className={isBusy ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            {canViewReports && <Button variant="outline" onClick={exportJson} disabled={!prayerReportQuery.data}><Download className="h-4 w-4" /> Ekspor JSON</Button>}
          </>
        }
      />

      <Card>
        <CardBody className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
          <FormField label="Dari tanggal"><Input type="date" value={from} max={to} onChange={(event) => setFrom(event.target.value)} /></FormField>
          <FormField label="Sampai tanggal"><Input type="date" value={to} min={from} onChange={(event) => setTo(event.target.value)} /></FormField>
          <FormField label="Kelompok laporan presensi">
            <Select value={groupBy} onChange={(event) => setGroupBy(event.target.value)}>
              {[
                ['DATE', 'Tanggal'], ['PRAYER', 'Jenis shalat'], ['MOSQUE', 'Masjid'], ['GRADE', 'Tingkat'], ['CLASS', 'Kelas'], ['DORMITORY', 'Asrama'],
              ].map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
          </FormField>
          <Badge tone="info" className="h-9 justify-center px-3">{from} — {to}</Badge>
        </CardBody>
      </Card>

      {canViewAnalytics && (
        <section className="space-y-3" aria-labelledby="religious-analytics-heading">
          <div className="flex items-center justify-between gap-3"><div><h2 id="religious-analytics-heading" className="text-base font-semibold text-slate-900">Ringkasan analitik</h2><p className="mt-1 text-xs text-slate-500">Rentang tanggal terpilih</p></div></div>
          {analyticsQuery.isLoading ? <LoadingCard label="Memuat analitik..." /> : analyticsQuery.isError ? <ErrorCard message={getErrorMessage(analyticsQuery.error)} onRetry={() => analyticsQuery.refetch()} /> : (
            <>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="Catatan presensi" value={attendance?.total_records ?? attendance?.total ?? 0} detail={`${attendance?.present ?? 0} hadir · ${attendance?.late ?? 0} terlambat`} />
                <MetricCard label="Tingkat kehadiran" value={attendance?.attendance_rate == null ? '—' : `${attendance.attendance_rate}%`} detail="Hadir + terlambat" tone="blue" />
                <MetricCard label="Kegiatan dibuat" value={activity?.activities_created ?? 0} detail={`${activity?.participants ?? 0} peserta tercatat`} tone="amber" />
                <MetricCard label="Kehadiran masjid" value={utilization?.total_attendance ?? 0} detail={`${utilization?.entries ?? 0} log pemanfaatan`} tone="green" />
              </div>
              <Card>
                <CardHeader><CardTitle className="text-sm">Partisipasi kegiatan per jenis</CardTitle></CardHeader>
                <CardBody className="flex flex-wrap gap-2">
                  {(activity?.by_type ?? []).length === 0 ? <p className="text-sm text-slate-500">Belum ada data kegiatan dalam periode ini.</p> : (activity?.by_type ?? []).map((row) => <Badge key={row.type} tone="info">{row.type.replace(/_/g, ' ')} · {row.total}</Badge>)}
                </CardBody>
              </Card>
              {analyticsQuery.data?.disclaimer && <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900">{analyticsQuery.data.disclaimer}</p>}
            </>
          )}
        </section>
      )}

      {canViewReports && (
        <section className="space-y-3" aria-labelledby="religious-prayer-report-heading">
          <div><h2 id="religious-prayer-report-heading" className="text-base font-semibold text-slate-900">Laporan presensi shalat</h2><p className="mt-1 text-xs text-slate-500">Dikelompokkan berdasarkan {groupBy.toLowerCase().replace(/_/g, ' ')}.</p></div>
          {prayerReportQuery.isLoading ? <LoadingCard label="Memuat laporan presensi..." /> : prayerReportQuery.isError ? <ErrorCard message={getErrorMessage(prayerReportQuery.error)} onRetry={() => prayerReportQuery.refetch()} /> : (
            <Card>
              {prayerRows.length === 0 ? <CardBody><p className="text-sm text-slate-500">Belum ada catatan pada rentang ini.</p></CardBody> : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[620px] text-sm">
                    <thead><tr className="border-b border-slate-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-5 py-3 font-medium">Kelompok</th><th className="px-3 py-3 text-right font-medium">Total</th><th className="px-3 py-3 text-right font-medium">Hadir</th><th className="px-3 py-3 text-right font-medium">Terlambat</th><th className="px-5 py-3 text-right font-medium">Absen / izin</th>
                    </tr></thead>
                    <tbody>{prayerRows.map((row, index) => (
                      <tr key={`${String(row.bucket)}-${index}`} className="border-b border-slate-50 last:border-0">
                        <td className="px-5 py-3 font-medium text-slate-800">{String(row.bucket ?? '—')}</td><td className="px-3 py-3 text-right">{Number(row.total ?? 0)}</td><td className="px-3 py-3 text-right text-emerald-700">{Number(row.present ?? 0)}</td><td className="px-3 py-3 text-right text-amber-700">{Number(row.late ?? 0)}</td><td className="px-5 py-3 text-right">{Number(row.absent ?? 0) + Number(row.excused ?? 0)}</td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
            </Card>
          )}
          <p className="text-xs leading-5 text-slate-500">Laporan ini menggambarkan catatan operasional. Hindari menggunakannya untuk menilai karakter atau kualitas moral seseorang.</p>
        </section>
      )}

      {!canViewAnalytics && !canViewReports && (
        <Card><CardBody><p className="text-sm text-slate-500">Akun ini belum memiliki izin untuk melihat analitik atau laporan.</p></CardBody></Card>
      )}
    </div>
  );
}
