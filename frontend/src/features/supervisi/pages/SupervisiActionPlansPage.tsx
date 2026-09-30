import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CheckCircle2, ClipboardList, Clock, ListChecks, RefreshCw, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiGrowthApi, type ActionItemRow, type ActionPlanRow } from '../api/supervisiGrowthApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';

const STATUS_TONES: Record<string, 'gray' | 'blue' | 'amber' | 'green' | 'red'> = {
  DRAFT: 'gray',
  ONGOING: 'blue',
  DONE: 'amber',
  VERIFIED: 'green',
  OVERDUE: 'red',
  DROPPED: 'gray',
};

const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Belum dimulai',
  ONGOING: 'Berjalan',
  DONE: 'Menunggu verifikasi',
  VERIFIED: 'Terverifikasi',
  OVERDUE: 'Terlambat',
  DROPPED: 'Dihentikan',
};

/**
 * Papan Rencana Tindak Lanjut.
 *
 * Menonjolkan butir yang terlambat dan RTL yang menunggu verifikasi supaya
 * tindak lanjut supervisi tidak berhenti setelah rapat.
 */
export default function SupervisiActionPlansPage() {
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<'all' | 'overdue' | 'waiting'>('all');
  const [search, setSearch] = useState('');
  const [progressItem, setProgressItem] = useState<ActionItemRow | null>(null);
  const [progressForm, setProgressForm] = useState({ progress_percent: 50, note: '' });
  const [verifyPlan, setVerifyPlan] = useState<ActionPlanRow | null>(null);
  const [verifyNote, setVerifyNote] = useState('');

  const params = {
    ...(filter === 'overdue' ? { overdue_only: 1 } : {}),
    ...(filter === 'waiting' ? { waiting_verification: 1 } : {}),
  };

  const boardQuery = useQuery({
    queryKey: ['supervisi', 'action-plans', filter],
    queryFn: () => supervisiGrowthApi.actionPlans(params),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['supervisi', 'action-plans'] });
  };

  const progressMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.reportProgress(progressItem?.id ?? 0, progressForm),
    onSuccess: () => {
      toast.success('Laporan kemajuan tersimpan');
      setProgressItem(null);
      setProgressForm({ progress_percent: 50, note: '' });
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const verifyMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.verifyActionPlan(verifyPlan?.id ?? 0, verifyNote),
    onSuccess: () => {
      toast.success('RTL diverifikasi');
      setVerifyPlan(null);
      setVerifyNote('');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const board = boardQuery.data;
  const rows = (board?.rows ?? []).filter((row) =>
    search === ''
      ? true
      : `${row.title} ${row.supervisee?.full_name ?? ''} ${row.visit?.code ?? ''}`.toLowerCase().includes(search.toLowerCase()),
  );

  const buckets = board?.buckets ?? { overdue: 0, due_soon: 0, ongoing: 0, waiting_verification: 0, verified: 0, draft: 0 };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Rencana Tindak Lanjut (RTL)</h1>
          <p className="text-sm text-slate-500">
            Tindak lanjut temuan supervisi: sasaran, tenggat, bukti, dan verifikasi atasan.
          </p>
        </div>
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => boardQuery.refetch()}>
          <RefreshCw className={boardQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card>
          <CardBody className="flex items-center gap-3">
            <TriangleAlert className="h-8 w-8 text-red-500" />
            <div>
              <div className="text-xs text-slate-500">Terlambat</div>
              <div className="text-xl font-semibold text-slate-900">{buckets.overdue}</div>
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardBody className="flex items-center gap-3">
            <Clock className="h-8 w-8 text-amber-500" />
            <div>
              <div className="text-xs text-slate-500">Jatuh tempo ≤ 7 hari</div>
              <div className="text-xl font-semibold text-slate-900">{buckets.due_soon}</div>
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardBody className="flex items-center gap-3">
            <ClipboardList className="h-8 w-8 text-brand-600" />
            <div>
              <div className="text-xs text-slate-500">Menunggu verifikasi</div>
              <div className="text-xl font-semibold text-slate-900">{buckets.waiting_verification}</div>
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardBody className="flex items-center gap-3">
            <CheckCircle2 className="h-8 w-8 text-green-600" />
            <div>
              <div className="text-xs text-slate-500">Terverifikasi</div>
              <div className="text-xl font-semibold text-slate-900">{buckets.verified}</div>
            </div>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex items-center gap-2">
              <ListChecks className="h-4 w-4 text-brand-600" />
              <CardTitle>Daftar RTL</CardTitle>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <div className="inline-flex rounded-lg border border-slate-200 p-0.5">
                {[
                  { key: 'all' as const, label: 'Semua' },
                  { key: 'overdue' as const, label: 'Terlambat' },
                  { key: 'waiting' as const, label: 'Perlu verifikasi' },
                ].map((item) => (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => setFilter(item.key)}
                    className={`rounded-md px-3 py-1.5 text-sm ${
                      filter === item.key ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-50'
                    }`}
                  >
                    {item.label}
                  </button>
                ))}
              </div>
              <Input
                placeholder="Cari RTL / pegawai / kode kegiatan..."
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                className="sm:w-72"
              />
            </div>
          </div>
        </CardHeader>
        <CardBody className="space-y-4">
          {boardQuery.isLoading && (
            <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
              <Spinner /> Memuat RTL...
            </div>
          )}

          {!boardQuery.isLoading && rows.length === 0 && (
            <EmptyState
              title="Belum ada RTL pada filter ini"
              description="RTL dibuat dari temuan kegiatan yang sudah difinalkan, atau disusun manual oleh atasan."
            />
          )}

          {rows.map((plan) => (
            <div key={plan.id} className="rounded-xl border border-slate-200 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <div className="font-medium text-slate-900">{plan.title}</div>
                  <div className="text-xs text-slate-500">
                    {plan.supervisee?.full_name ?? '-'}
                    {plan.supervisee?.position ? ` • ${plan.supervisee.position}` : ''}
                    {plan.visit?.code ? ` • ${plan.visit.code}` : ''}
                    {plan.period_end ? ` • tenggat ${new Date(plan.period_end).toLocaleDateString('id-ID')}` : ''}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={STATUS_TONES[plan.status] ?? 'gray'}>{STATUS_LABELS[plan.status] ?? plan.status}</Badge>
                  <Badge tone="gray">{plan.progress_percent}%</Badge>
                </div>
              </div>

              <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div className="h-full rounded-full bg-brand-500" style={{ width: `${plan.progress_percent}%` }} />
              </div>

              <ul className="mt-3 space-y-2">
                {plan.items.map((item) => (
                  <li key={item.id} className="rounded-lg bg-slate-50 p-3 text-sm">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div className="flex-1">
                        <div className="text-slate-800">{item.statement}</div>
                        <div className="text-xs text-slate-500">
                          {item.due_date ? `Tenggat ${new Date(item.due_date).toLocaleDateString('id-ID')}` : 'Tanpa tenggat'}
                          {item.owner?.full_name ? ` • penanggung jawab ${item.owner.full_name}` : ''}
                          {item.support_needed ? ` • dukungan: ${item.support_needed}` : ''}
                        </div>
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        {item.is_overdue && <Badge tone="red">terlambat</Badge>}
                        {item.is_due_soon && !item.is_overdue && <Badge tone="amber">segera</Badge>}
                        <Badge tone={STATUS_TONES[item.status] ?? 'gray'}>{item.progress_percent}%</Badge>
                        {plan.status !== 'VERIFIED' && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                              setProgressItem(item);
                              setProgressForm({ progress_percent: item.progress_percent, note: '' });
                            }}
                          >
                            Lapor kemajuan
                          </Button>
                        )}
                      </div>
                    </div>
                    {item.result_note && <p className="mt-1 text-xs text-slate-500">Catatan: {item.result_note}</p>}
                    {item.progress.length > 0 && (
                      <ul className="mt-2 space-y-1 border-t border-slate-200 pt-2 text-xs text-slate-500">
                        {item.progress.slice(0, 3).map((log) => (
                          <li key={log.id}>
                            {log.reported_at ? new Date(log.reported_at).toLocaleDateString('id-ID') : ''} — {log.progress_percent}%{' '}
                            {log.note ? `· ${log.note}` : ''}
                          </li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ul>

              <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                {plan.verified_at ? (
                  <span className="text-xs text-green-700">
                    Diverifikasi {new Date(plan.verified_at).toLocaleDateString('id-ID')}
                  </span>
                ) : (
                  <span className="text-xs text-slate-400">
                    {plan.status === 'DONE' ? 'Seluruh butir selesai — menunggu verifikasi atasan.' : 'Belum diverifikasi.'}
                  </span>
                )}
                {plan.status !== 'VERIFIED' && (
                  <Button size="sm" onClick={() => setVerifyPlan(plan)}>
                    <CheckCircle2 className="mr-2 h-4 w-4" /> Verifikasi
                  </Button>
                )}
              </div>

              {plan.visit && (
                <Link to={`/supervisi/visits/${plan.visit.id}`} className="mt-2 inline-block text-xs text-brand-700 hover:underline">
                  Buka kegiatan {plan.visit.code}
                </Link>
              )}
            </div>
          ))}
        </CardBody>
      </Card>

      <Modal
        open={progressItem !== null}
        onClose={() => setProgressItem(null)}
        title="Lapor Kemajuan Butir RTL"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setProgressItem(null)}>Batal</Button>
            <Button onClick={() => progressMutation.mutate()} disabled={progressMutation.isPending}>
              {progressMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan
            </Button>
          </div>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-600">{progressItem?.statement}</p>
          <div>
            <Label required>Kemajuan (%)</Label>
            <Input
              type="number"
              min={0}
              max={100}
              value={progressForm.progress_percent}
              onChange={(event) => setProgressForm((previous) => ({ ...previous, progress_percent: Number(event.target.value) }))}
            />
            <p className="mt-1 text-xs text-slate-500">
              Nilai 100% menandai butir selesai; atasan dapat memverifikasi setelah seluruh butir selesai.
            </p>
          </div>
          <div>
            <Label>Catatan kemajuan</Label>
            <Textarea
              rows={3}
              value={progressForm.note}
              onChange={(event) => setProgressForm((previous) => ({ ...previous, note: event.target.value }))}
              placeholder="Bukti/aktivitas yang sudah dilakukan"
            />
          </div>
        </div>
      </Modal>

      <Modal
        open={verifyPlan !== null}
        onClose={() => setVerifyPlan(null)}
        title="Verifikasi RTL"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setVerifyPlan(null)}>Batal</Button>
            <Button onClick={() => verifyMutation.mutate()} disabled={verifyMutation.isPending}>
              {verifyMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Verifikasi
            </Button>
          </div>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-600">
            Seluruh butir wajib selesai sebelum RTL dapat diverifikasi. Setelah diverifikasi, RTL terkunci.
          </p>
          <div>
            <Label>Catatan verifikasi</Label>
            <Textarea rows={3} value={verifyNote} onChange={(event) => setVerifyNote(event.target.value)} />
          </div>
        </div>
      </Modal>
    </div>
  );
}
