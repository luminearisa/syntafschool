import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  ActivitySquare,
  AlertTriangle,
  Ambulance,
  CalendarClock,
  ClipboardPlus,
  HeartPulse,
  PackageX,
  Pill,
  Stethoscope,
  Users,
} from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi, QUEUE_STAGES } from '../api/clinicApi';

/**
 * Dasbor klinik: ringkasan operasional hari ini dan papan antrean.
 *
 * Angka yang tampil sudah dihitung server-side **dalam cakupan peran** pengguna,
 * jadi petugas klinik melihat seluruh sekolah sementara orang tua hanya melihat
 * anaknya (dan tidak melihat data stok sama sekali).
 */
export default function ClinicDashboardPage() {
  const queryClient = useQueryClient();
  const [stageFilter, setStageFilter] = useState('');

  const dashboard = useQuery({
    queryKey: ['clinic', 'dashboard'],
    queryFn: () => clinicApi.dashboard(),
    refetchInterval: 30_000,
  });

  const metadata = useQuery({
    queryKey: ['clinic', 'metadata'],
    queryFn: () => clinicApi.metadata(),
  });

  const callNext = useMutation({
    mutationFn: () => clinicApi.callNext(metadata.data?.clinics.find((c) => c.is_default)?.id),
    onSuccess: (response) => {
      const data = response?.data as { patient_name?: string; queue_number?: string } | null;
      if (!data?.patient_name) {
        toast.info('Tidak ada pasien dalam antrean.');
      } else {
        toast.success(`Memanggil ${data.queue_number ?? ''} — ${data.patient_name}`);
      }
      void queryClient.invalidateQueries({ queryKey: ['clinic'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const moveStage = useMutation({
    mutationFn: ({ visitId, stage }: { visitId: number; stage: string }) =>
      clinicApi.moveQueue(visitId, stage),
    onSuccess: () => {
      toast.success('Tahap antrean diperbarui.');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'dashboard'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows = useMemo(() => {
    const list = dashboard.data?.queue_board ?? [];
    return stageFilter === '' ? list : list.filter((row) => row.stage === stageFilter);
  }, [dashboard.data?.queue_board, stageFilter]);

  if (dashboard.isLoading) {
    return (
      <div className="flex justify-center py-20">
        <Spinner />
      </div>
    );
  }

  if (dashboard.isError) {
    return <EmptyState title="Dasbor klinik tidak dapat dimuat" description={getErrorMessage(dashboard.error)} />;
  }

  const data = dashboard.data;
  if (!data) return null;

  const cards = [
    { label: 'Kunjungan hari ini', value: data.visits_today, icon: Stethoscope, tone: 'text-sky-600' },
    { label: 'Menunggu antrean', value: data.queue_waiting, icon: Users, tone: 'text-amber-600' },
    { label: 'Belum triase', value: data.pending_triage, icon: ClipboardPlus, tone: 'text-indigo-600' },
    { label: 'Dalam penanganan', value: data.in_treatment, icon: ActivitySquare, tone: 'text-emerald-600' },
    { label: 'Observasi', value: data.in_observation, icon: HeartPulse, tone: 'text-teal-600' },
    { label: 'Gawat darurat aktif', value: data.emergencies_open, icon: Ambulance, tone: 'text-rose-600' },
    { label: 'Rujukan berjalan', value: data.referrals_active, icon: AlertTriangle, tone: 'text-orange-600' },
    { label: 'Tindak lanjut ≤7 hari', value: data.follow_ups_due, icon: CalendarClock, tone: 'text-violet-600' },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Clinic / UKS</h1>
          <p className="text-sm text-slate-500">
            Ruang kerja klinis: antrean, penanganan, obat, rujukan — semuanya satu alur.
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Link to="/clinic/patients">
            <Button size="sm">
              <ClipboardPlus className="mr-1 h-4 w-4" /> Kunjungan Baru
            </Button>
          </Link>
          <Link to="/clinic/emergency">
            <Button size="sm" variant="danger">
              <Ambulance className="mr-1 h-4 w-4" /> Gawat Darurat
            </Button>
          </Link>
          <Button size="sm" variant="secondary" onClick={() => callNext.mutate()} disabled={callNext.isPending}>
            Panggil Berikutnya
          </Button>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {cards.map((card) => (
          <Card key={card.label}>
            <CardBody className="flex items-center gap-3">
              <card.icon className={`h-8 w-8 ${card.tone}`} />
              <div>
                <p className="text-2xl font-semibold text-slate-900">{card.value}</p>
                <p className="text-xs text-slate-500">{card.label}</p>
              </div>
            </CardBody>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>Papan antrean {data.date}</CardTitle>
            <div className="w-48">
              <Select value={stageFilter} onChange={(event) => setStageFilter(event.target.value)}>
                <option value="">Semua tahap</option>
                {QUEUE_STAGES.map((stage) => (
                  <option key={stage.value} value={stage.value}>
                    {stage.label}
                  </option>
                ))}
              </Select>
            </div>
          </CardHeader>
          <CardBody className="p-0">
            {rows.length === 0 ? (
              <div className="p-4">
                <EmptyState title="Antrean kosong" description="Belum ada pasien menunggu pada tahap ini." />
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <div key={row.visit_id} className="flex flex-wrap items-center gap-3 p-3">
                    <div
                      className={`flex h-10 w-14 items-center justify-center rounded-md text-sm font-semibold ${
                        row.is_emergency ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-700'
                      }`}
                    >
                      {row.queue_number ?? '—'}
                    </div>

                    <div className="min-w-[12rem] flex-1">
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-slate-900">{row.patient_name ?? 'Pasien'}</span>
                        <Badge tone={row.is_emergency ? 'danger' : 'neutral'}>{row.priority_label}</Badge>
                      </div>
                      <p className="text-xs text-slate-500">
                        {row.nis ?? '—'} · {row.chief_complaint ?? 'keluhan belum dicatat'}
                        {row.waiting_minutes !== null ? ` · menunggu ${row.waiting_minutes} menit` : ''}
                      </p>
                    </div>

                    <div className="flex items-center gap-2">
                      <div className="w-40">
                        <Select
                          value={row.stage ?? ''}
                          onChange={(event) =>
                            moveStage.mutate({ visitId: row.visit_id, stage: event.target.value })
                          }
                        >
                          <option value="" disabled>
                            Pilih tahap
                          </option>
                          {QUEUE_STAGES.map((stage) => (
                            <option key={stage.value} value={stage.value}>
                              {stage.label}
                            </option>
                          ))}
                        </Select>
                      </div>
                      <Link to={`/clinic/visits/${row.visit_id}`}>
                        <Button size="sm" variant="secondary">
                          Buka
                        </Button>
                      </Link>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Peringatan stok</CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.low_stock.length === 0 && data.expiring_medications.length === 0 ? (
                <p className="text-sm text-slate-500">Tidak ada peringatan stok obat.</p>
              ) : null}

              {data.low_stock.map((item) => (
                <div key={`low-${item.id}`} className="flex items-start gap-2 rounded-md bg-amber-50 p-2">
                  <PackageX className="mt-0.5 h-4 w-4 text-amber-600" />
                  <div className="text-sm">
                    <p className="font-medium text-slate-800">{item.name}</p>
                    <p className="text-xs text-slate-600">
                      Sisa {item.quantity} (minimum {item.minimum_quantity}) — ajukan pengadaan ke Inventaris.
                    </p>
                  </div>
                </div>
              ))}

              {data.expiring_medications.map((batch) => (
                <div key={`exp-${batch.batch_id}`} className="flex items-start gap-2 rounded-md bg-rose-50 p-2">
                  <AlertTriangle className="mt-0.5 h-4 w-4 text-rose-600" />
                  <div className="text-sm">
                    <p className="font-medium text-slate-800">{batch.medication_name ?? 'Obat'}</p>
                    <p className="text-xs text-slate-600">
                      Batch {batch.batch_number ?? '—'}{' '}
                      {batch.expired
                        ? `sudah kedaluwarsa (${batch.expiry_date})`
                        : `kedaluwarsa dalam ${batch.days_left} hari (${batch.expiry_date})`}
                      . Obat kedaluwarsa tidak dapat diserahkan.
                    </p>
                  </div>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Keluhan tersering (30 hari)</CardTitle>
            </CardHeader>
            <CardBody>
              {data.top_complaints.length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada data keluhan.</p>
              ) : (
                <ul className="space-y-1 text-sm">
                  {data.top_complaints.map((row) => (
                    <li key={row.complaint} className="flex items-center justify-between">
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
              <CardTitle>Pintasan</CardTitle>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-2 text-sm">
              <Link className="flex items-center gap-2 rounded-md border p-2 hover:bg-slate-50" to="/clinic/pharmacy">
                <Pill className="h-4 w-4 text-slate-500" /> Farmasi
              </Link>
              <Link className="flex items-center gap-2 rounded-md border p-2 hover:bg-slate-50" to="/clinic/referrals">
                <AlertTriangle className="h-4 w-4 text-slate-500" /> Rujukan
              </Link>
              <Link className="flex items-center gap-2 rounded-md border p-2 hover:bg-slate-50" to="/clinic/screening">
                <HeartPulse className="h-4 w-4 text-slate-500" /> Skrining
              </Link>
              <Link className="flex items-center gap-2 rounded-md border p-2 hover:bg-slate-50" to="/clinic/reports">
                <ActivitySquare className="h-4 w-4 text-slate-500" /> Laporan
              </Link>
            </CardBody>
          </Card>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Tren kunjungan 14 hari</CardTitle>
        </CardHeader>
        <CardBody>
          <div className="flex h-32 items-end gap-1">
            {data.visits_by_day.map((point) => {
              const max = Math.max(...data.visits_by_day.map((p) => p.total), 1);
              return (
                <div key={point.date} className="flex flex-1 flex-col items-center gap-1">
                  <div
                    className="w-full rounded-t bg-sky-500/70"
                    style={{ height: `${Math.max(4, (point.total / max) * 100)}%` }}
                    title={`${point.date}: ${point.total} kunjungan`}
                  />
                  <span className="text-[10px] text-slate-400">{point.date.slice(8)}</span>
                </div>
              );
            })}
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
