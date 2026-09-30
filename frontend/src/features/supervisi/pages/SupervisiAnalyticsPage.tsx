import { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { BarChart3, CheckCircle2, Copy, Download, Eye, FileSpreadsheet, RefreshCw, Users } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiInsightApi, type ReportPreview } from '../api/supervisiInsightApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';

const number = (value: number | null | undefined, suffix = '') =>
  value === null || value === undefined ? '—' : `${value}${suffix}`;

/** Analitik supervisi + pembuat laporan wajib (CSV/XLSX/PDF) & verifikasi dokumen. */
export default function SupervisiAnalyticsPage() {
  const [window, setWindow] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10),
    to: new Date(new Date().getFullYear(), 11, 31).toISOString().slice(0, 10),
  });
  const [reportKey, setReportKey] = useState('program_supervisi');
  const [preview, setPreview] = useState<ReportPreview | null>(null);

  const analyticsQuery = useQuery({
    queryKey: ['supervisi', 'analytics', window],
    queryFn: () => supervisiInsightApi.analytics(window),
  });

  const catalogQuery = useQuery({
    queryKey: ['supervisi', 'report-catalog'],
    queryFn: () => supervisiInsightApi.reportCatalog(),
    staleTime: 30 * 60 * 1000,
  });

  const historyQuery = useQuery({
    queryKey: ['supervisi', 'report-history'],
    queryFn: () => supervisiInsightApi.reportHistory(),
  });

  const previewMutation = useMutation({
    mutationFn: () => supervisiInsightApi.reportPreview({ report_key: reportKey, ...window }),
    onSuccess: (data) => setPreview(data),
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const generateMutation = useMutation({
    mutationFn: (format: string) => supervisiInsightApi.generateReport({ report_key: reportKey, format, ...window }),
    onSuccess: (data) => {
      toast.success(`Dokumen dibuat. Kode verifikasi ${data.verification_code}.`);
      historyQuery.refetch();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const data = analyticsQuery.data;
  const maxTrend = useMemo(
    () => Math.max(100, ...(data?.trend ?? []).map((row) => row.average ?? 0)),
    [data],
  );

  const kpi = [
    { label: 'Cakupan supervisi', value: number(data?.coverage.percent, '%'), hint: `${data?.coverage.supervised ?? 0} dari ${data?.coverage.total_employees ?? 0} pegawai` },
    { label: 'Rata-rata nilai', value: number(data?.scores.average), hint: `${data?.scores.count ?? 0} kegiatan dinilai` },
    { label: 'RTL tepat waktu', value: number(data?.follow_up.on_time_percent, '%'), hint: `${data?.follow_up.items_overdue ?? 0} butir terlambat` },
    { label: 'Butuh PIP', value: String(data?.appraisals.needs_pip ?? 0), hint: `${data?.appraisals.pip_active ?? 0} PIP berjalan` },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Analitik &amp; Laporan Supervisi</h1>
          <p className="text-sm text-slate-500">
            Cakupan, tren nilai, indikator lemah, disiplin tindak lanjut, dan dokumen wajib siap cetak.
          </p>
        </div>
        <div className="flex items-end gap-2">
          <div>
            <Label>Dari</Label>
            <Input type="date" value={window.from} onChange={(event) => setWindow((previous) => ({ ...previous, from: event.target.value }))} />
          </div>
          <div>
            <Label>Sampai</Label>
            <Input type="date" value={window.to} onChange={(event) => setWindow((previous) => ({ ...previous, to: event.target.value }))} />
          </div>
          <Button variant="outline" onClick={() => analyticsQuery.refetch()}>
            <RefreshCw className={analyticsQuery.isFetching ? 'mr-2 h-4 w-4 animate-spin' : 'mr-2 h-4 w-4'} /> Muat
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {kpi.map((item) => (
          <Card key={item.label}>
            <CardBody>
              <div className="text-xs text-slate-500">{item.label}</div>
              <div className="text-2xl font-semibold text-slate-900">{item.value}</div>
              <div className="text-xs text-slate-500">{item.hint}</div>
            </CardBody>
          </Card>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <BarChart3 className="h-4 w-4 text-brand-600" />
              <CardTitle>Tren Nilai per Bulan</CardTitle>
            </div>
          </CardHeader>
          <CardBody>
            {(data?.trend ?? []).length === 0 ? (
              <EmptyState title="Belum ada kegiatan final pada periode ini" />
            ) : (
              <div className="flex h-48 items-end gap-2">
                {(data?.trend ?? []).map((row) => (
                  <div key={row.period} className="flex flex-1 flex-col items-center gap-1">
                    <span className="text-xs text-slate-500">{row.average ?? '—'}</span>
                    <div
                      className="w-full rounded-t bg-brand-500/80"
                      style={{ height: `${((row.average ?? 0) / maxTrend) * 100}%` }}
                      title={`${row.visits} kegiatan`}
                    />
                    <span className="text-[10px] text-slate-500">{row.period.slice(5)}</span>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader><CardTitle>Sebaran Predikat &amp; Nilai</CardTitle></CardHeader>
          <CardBody className="space-y-2">
            {Object.entries(data?.scores.distribution ?? {}).length === 0 && <EmptyState title="Belum ada nilai" />}
            {Object.entries(data?.scores.distribution ?? {}).map(([band, info]) => (
              <div key={band} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                <span className="text-slate-700">{band.replace(/_/g, ' ').toLowerCase()}</span>
                <span className="flex items-center gap-2">
                  <Badge tone="gray">{info.count} kegiatan</Badge>
                  <span className="font-medium text-slate-900">{info.average}</span>
                </span>
              </div>
            ))}
            {(data?.sections ?? []).map((section) => (
              <div key={section.section} className="flex items-center justify-between text-xs text-slate-600">
                <span>{section.section}</span>
                <span>
                  rata {section.average_percent}% · terendah {section.lowest_percent}% · {section.visits} kegiatan
                </span>
              </div>
            ))}
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>Indikator Terlemah</CardTitle>
            <span className="text-xs text-slate-500">Ambang perlu perbaikan: di bawah 75% skor maksimum</span>
          </div>
        </CardHeader>
        <CardBody>
          {(data?.weak_indicators ?? []).length === 0 ? (
            <EmptyState title="Belum ada jawaban indikator pada periode ini" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">Indikator</th>
                    <th className="px-3 py-2 font-medium">Bagian</th>
                    <th className="px-3 py-2 font-medium">Jawaban</th>
                    <th className="px-3 py-2 font-medium">Rata-rata</th>
                    <th className="px-3 py-2 font-medium">%</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {(data?.weak_indicators ?? []).map((row) => (
                    <tr key={row.indicator_id}>
                      <td className="px-3 py-2 text-slate-800">{row.statement}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">{row.section}</td>
                      <td className="px-3 py-2">{row.answers}</td>
                      <td className="px-3 py-2">{row.average_score} / {row.max_score}</td>
                      <td className="px-3 py-2">
                        <Badge tone={row.is_weak ? 'red' : 'green'}>{row.average_percent}%</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <Users className="h-4 w-4 text-brand-600" />
              <CardTitle>Kinerja &amp; Kelonggaran Penilai</CardTitle>
            </div>
          </CardHeader>
          <CardBody>
            {(data?.supervisors ?? []).length === 0 ? (
              <EmptyState title="Belum ada supervisor yang memfinalkan kegiatan" />
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">Supervisor</th>
                    <th className="px-3 py-2 font-medium">Kegiatan</th>
                    <th className="px-3 py-2 font-medium">Rata-rata</th>
                    <th className="px-3 py-2 font-medium">Selisih dr sekolah</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {(data?.supervisors ?? []).map((row) => (
                    <tr key={row.supervisor_employee_id}>
                      <td className="px-3 py-2 text-slate-800">{row.full_name ?? `#${row.supervisor_employee_id}`}</td>
                      <td className="px-3 py-2">{row.visits}</td>
                      <td className="px-3 py-2">{row.average_score}</td>
                      <td className="px-3 py-2">
                        <Badge tone={row.needs_calibration ? 'red' : 'gray'}>
                          {row.deviation_from_school === null ? '—' : row.deviation_from_school > 0 ? `+${row.deviation_from_school}` : row.deviation_from_school}
                          {row.needs_calibration ? ' · perlu kalibrasi' : ''}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader><CardTitle>Perlu Ditindaklanjuti</CardTitle></CardHeader>
          <CardBody className="space-y-3">
            <div className="rounded-lg bg-slate-50 p-3 text-sm">
              <div className="font-medium text-slate-800">Sudah disupervisi</div>
              <div className="text-xs text-slate-500">
                Guru: {data?.coverage.teacher_supervised ?? 0}/{data?.coverage.teacher_total ?? 0} · Tendik:{' '}
                {data?.coverage.staff_supervised ?? 0}/{data?.coverage.staff_total ?? 0}
              </div>
            </div>

            {(data?.coverage.unsupervised ?? []).length > 0 && (
              <div className="text-sm">
                <div className="mb-1 font-medium text-slate-800">Belum disupervisi</div>
                <div className="flex flex-wrap gap-1">
                  {(data?.coverage.unsupervised ?? []).slice(0, 12).map((row) => (
                    <Badge key={row.id} tone="amber">{row.full_name}</Badge>
                  ))}
                  {(data?.coverage.unsupervised ?? []).length > 12 && (
                    <Badge tone="gray">+{(data?.coverage.unsupervised ?? []).length - 12} lainnya</Badge>
                  )}
                </div>
              </div>
            )}

            {(data?.appraisals.needs_pip_rows ?? []).length > 0 && (
              <div className="text-sm">
                <div className="mb-1 font-medium text-slate-800">Nilai di bawah ambang tanpa PIP</div>
                <ul className="space-y-1 text-xs text-slate-600">
                  {(data?.appraisals.needs_pip_rows ?? []).map((row) => (
                    <li key={row.id}>
                      {row.supervisee} — {row.appraisal_type} {row.final_score} ({row.grade_label})
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="rounded-lg bg-slate-50 p-3 text-sm">
              <div className="font-medium text-slate-800">Pengembangan pegawai</div>
              <div className="text-xs text-slate-500">
                {data?.growth.total_hours ?? 0} jam pada {data?.growth.entries ?? 0} kegiatan · {data?.growth.certified ?? 0} bersertifikat ·{' '}
                {data?.growth.coaching_sessions ?? 0} sesi coaching ({data?.growth.coaching_follow_up_due ?? 0} tindak lanjut jatuh tempo)
              </div>
            </div>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <FileSpreadsheet className="h-4 w-4 text-brand-600" />
              <CardTitle>Laporan Wajib (Permendikbud 15/2018)</CardTitle>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Select value={reportKey} onChange={(event) => setReportKey(event.target.value)} className="sm:w-72">
                {(catalogQuery.data?.reports ?? []).map((report) => (
                  <option key={report.key} value={report.key}>{report.title}</option>
                ))}
              </Select>
              <Button variant="outline" onClick={() => previewMutation.mutate()} disabled={previewMutation.isPending}>
                {previewMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Eye className="mr-2 h-4 w-4" />} Pratinjau
              </Button>
              {['CSV', 'XLSX', 'PDF'].map((format) => (
                <Button key={format} onClick={() => generateMutation.mutate(format)} disabled={generateMutation.isPending}>
                  <Download className="mr-2 h-4 w-4" /> {format}
                </Button>
              ))}
            </div>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          {!preview && <p className="text-sm text-slate-500">Pilih jenis laporan lalu tekan Pratinjau untuk melihat isinya sebelum diunduh.</p>}

          {preview && (
            <>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-sm text-slate-600">
                  <span className="font-medium text-slate-900">{preview.report.title}</span> · periode {preview.report.window.from} s.d.{' '}
                  {preview.report.window.to} · {preview.rows.length} baris
                </div>
                <div className="flex flex-wrap gap-1">
                  {Object.entries(preview.summary)
                    .filter(([, value]) => ['string', 'number'].includes(typeof value))
                    .slice(0, 6)
                    .map(([key, value]) => (
                      <Badge key={key} tone="gray">
                        {key.replace(/_/g, ' ')}: {String(value)}
                      </Badge>
                    ))}
                </div>
              </div>

              <div className="max-h-96 overflow-auto rounded-lg border border-slate-200">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 bg-slate-50 text-left uppercase tracking-wide text-slate-500">
                    <tr>
                      {preview.headers.map((header) => (
                        <th key={header} className="px-2 py-2 font-medium">{header}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {preview.rows.slice(0, 50).map((row, index) => (
                      <tr key={index}>
                        {row.map((cell, cellIndex) => (
                          <td key={cellIndex} className="px-2 py-1.5 text-slate-700">{cell === null ? '—' : cell}</td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {preview.rows.length > 50 && (
                <p className="text-xs text-slate-500">Menampilkan 50 baris pertama; berkas ekspor memuat seluruh baris.</p>
              )}
            </>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>Riwayat Dokumen &amp; Kode Verifikasi</CardTitle>
            <Button
              variant="ghost"
              size="sm"
              aria-label="Muat ulang riwayat dokumen"
              title="Muat ulang riwayat dokumen"
              onClick={() => historyQuery.refetch()}
            >
              <RefreshCw className={historyQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
          </div>
        </CardHeader>
        <CardBody>
          {(historyQuery.data ?? []).length === 0 ? (
            <EmptyState title="Belum ada dokumen yang dihasilkan" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2 font-medium">Dokumen</th>
                    <th className="px-3 py-2 font-medium">Format</th>
                    <th className="px-3 py-2 font-medium">Baris</th>
                    <th className="px-3 py-2 font-medium">Dihasilkan</th>
                    <th className="px-3 py-2 font-medium">Kode verifikasi</th>
                    <th className="px-3 py-2 font-medium">Sidik digital</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {(historyQuery.data ?? []).map((row) => (
                    <tr key={row.id}>
                      <td className="px-3 py-2">
                        <div className="text-slate-800">{row.title}</div>
                        <div className="text-xs text-slate-500">{row.generated_by ?? ''}</div>
                      </td>
                      <td className="px-3 py-2"><Badge tone="gray">{row.format}</Badge></td>
                      <td className="px-3 py-2">{row.rows_count ?? '—'}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">
                        {row.generated_at ? new Date(row.generated_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-2">
                          <Badge tone="brand">{row.verification_code}</Badge>
                          {row.verification_url && (
                            <button
                              type="button"
                              title="Salin tautan verifikasi"
                              className="text-slate-400 hover:text-brand-600"
                              onClick={() => {
                                void navigator.clipboard.writeText(row.verification_url ?? '');
                                toast.success('Tautan verifikasi disalin');
                              }}
                            >
                              <Copy className="h-3.5 w-3.5" />
                            </button>
                          )}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <span className="font-mono text-[10px] text-slate-500">{(row.checksum ?? '').slice(0, 16)}…</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <p className="mt-3 flex items-start gap-2 text-xs text-slate-500">
            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 text-green-600" />
            Setiap dokumen diberi kode acak dan sidik digital SHA-256. Salinan cetak dapat diperiksa di halaman
            verifikasi publik (QR pada PDF); berkas yang diubah tidak akan dinyatakan sah.
          </p>
        </CardBody>
      </Card>
    </div>
  );
}
