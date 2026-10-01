import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import { financeStandardApi } from '../api/financeStandardApi';

const toneStatus = (status: string) => status === 'approved' ? 'success' : status === 'rejected' ? 'danger' : 'warning';

/**
 * Permohonan yang mengubah laporan orang lain: membuka periode & membatalkan jurnal.
 *
 * Keduanya butuh dua orang — pengaju beserta alasannya, lalu pemutus yang berbeda.
 * Tanpa itu, angka yang sudah disampaikan ke yayasan dapat berubah oleh satu orang
 * tanpa jejak.
 */
export function FinanceRequestsPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [tab, setTab] = useState<'periode' | 'void'>('periode');

  const periods = useQuery({ queryKey: ['finance', 'periods'], queryFn: () => financeStandardApi.periods() });
  const reopen = useQuery({ queryKey: ['finance', 'reopen-requests'], queryFn: () => financeStandardApi.reopenRequests() });
  const voids = useQuery({ queryKey: ['finance', 'void-requests'], queryFn: () => financeStandardApi.voidRequests() });
  const journals = useQuery({ queryKey: ['finance', 'journals', 'void'], queryFn: () => financeStandardApi.journals() });

  const [periodeForm, setPeriodeForm] = useState({ period_id: '', reason: '' });
  const [voidForm, setVoidForm] = useState<{ transaction_id: number | ''; reason: string }>({ transaction_id: '', reason: '' });

  const buatReopen = useMutation({
    mutationFn: () => financeStandardApi.requestReopen(Number(periodeForm.period_id), periodeForm.reason),
    onSuccess: () => {
      toast.success('Permohonan diajukan dan menunggu keputusan orang lain.');
      setPeriodeForm({ period_id: '', reason: '' });
      void queryClient.invalidateQueries({ queryKey: ['finance', 'reopen-requests'] });
      void queryClient.invalidateQueries({ queryKey: ['finance', 'periods'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const putusReopen = useMutation({
    mutationFn: ({ id, approved }: { id: number; approved: boolean }) =>
      financeStandardApi.decideReopen(id, approved, approved ? 'Disetujui.' : 'Ditolak.'),
    onSuccess: (hasil) => {
      toast.success(`Permohonan ${hasil.status === 'approved' ? 'disetujui' : 'ditolak'}.`);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'reopen-requests'] });
      void queryClient.invalidateQueries({ queryKey: ['finance', 'periods'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const buatVoid = useMutation({
    mutationFn: () => financeStandardApi.requestVoid(Number(voidForm.transaction_id), voidForm.reason),
    onSuccess: () => {
      toast.success('Permohonan pembatalan diajukan.');
      setVoidForm({ transaction_id: '', reason: '' });
      void queryClient.invalidateQueries({ queryKey: ['finance', 'void-requests'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const putusVoid = useMutation({
    mutationFn: ({ id, approved }: { id: number; approved: boolean }) =>
      financeStandardApi.decideVoid(id, approved, approved ? 'Disetujui.' : 'Ditolak.'),
    onSuccess: (hasil) => {
      toast.success(hasil.status === 'approved' ? 'Jurnal dibatalkan lewat jurnal pembalik.' : 'Permohonan ditolak.');
      void queryClient.invalidateQueries({ queryKey: ['finance', 'void-requests'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const periodeTertutup = (periods.data ?? []).filter((period) => period.status !== 'open');

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-800">Permohonan Akuntansi</h1>
        <p className="text-sm text-slate-500">Buka periode tertutup &amp; batalkan jurnal — keduanya lewat persetujuan orang lain.</p>
      </div>

      <div className="flex gap-2">
        <Button variant={tab === 'periode' ? 'primary' : 'outline'} onClick={() => setTab('periode')}>Buka periode</Button>
        <Button variant={tab === 'void' ? 'primary' : 'outline'} onClick={() => setTab('void')}>Pembatalan jurnal</Button>
      </div>

      {tab === 'periode' ? (
        <>
          <Card>
            <CardHeader><CardTitle>Ajukan pembukaan periode</CardTitle></CardHeader>
            <CardBody className="space-y-3">
              <div>
                <Label htmlFor="reopen-period">Periode tertutup</Label>
                <Select id="reopen-period" value={periodeForm.period_id} onChange={(e) => setPeriodeForm({ ...periodeForm, period_id: e.target.value })}>
                  <option value="">Pilih periode</option>
                  {periodeTertutup.map((period) => (
                    <option key={period.id} value={period.id}>{period.code} · {period.name}</option>
                  ))}
                </Select>
                {periodeTertutup.length === 0 && (
                  <p className="mt-1 text-xs text-slate-500">Tidak ada periode tertutup saat ini.</p>
                )}
              </div>
              <div>
                <Label htmlFor="reopen-reason">Alasan</Label>
                <Textarea
                  id="reopen-reason"
                  rows={2}
                  value={periodeForm.reason}
                  onChange={(e) => setPeriodeForm({ ...periodeForm, reason: e.target.value })}
                  placeholder="Beban bulan lalu belum tercatat…"
                />
              </div>
              <Can perm="finance.closing.manage">
                <Button
                  disabled={!periodeForm.period_id || periodeForm.reason.trim().length < 5 || buatReopen.isPending}
                  onClick={() => buatReopen.mutate()}
                >
                  Ajukan permohonan
                </Button>
              </Can>
            </CardBody>
          </Card>

          <Card>
            <CardHeader><CardTitle>Riwayat permohonan</CardTitle></CardHeader>
            <CardBody>
              {reopen.isLoading ? (
                <div className="flex justify-center py-10"><Spinner /></div>
              ) : (reopen.data ?? []).length === 0 ? (
                <EmptyState title="Belum ada permohonan" />
              ) : (
                <ul className="divide-y divide-slate-100">
                  {(reopen.data ?? []).map((request) => (
                    <li key={request.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                      <div>
                        <p className="text-sm font-medium text-slate-800">
                          {request.period?.code ?? `Periode #${request.period_id}`} · <span className="text-slate-500">{request.reason}</span>
                        </p>
                        <p className="text-xs text-slate-500">
                          Diajukan oleh #{request.requested_by ?? '—'}
                          {request.decided_at ? ` · diputuskan ${new Date(request.decided_at).toLocaleString('id-ID')}` : ''}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge tone={toneStatus(request.status)}>{request.status}</Badge>
                        {request.status === 'pending' && can('finance.closing.approve') && (
                          <>
                            <Button size="sm" variant="outline" loading={putusReopen.isPending} onClick={() => putusReopen.mutate({ id: request.id, approved: false })}>
                              Tolak
                            </Button>
                            <Button size="sm" loading={putusReopen.isPending} onClick={() => putusReopen.mutate({ id: request.id, approved: true })}>
                              Setujui
                            </Button>
                          </>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </>
      ) : (
        <>
          <Card>
            <CardHeader><CardTitle>Ajukan pembatalan jurnal</CardTitle></CardHeader>
            <CardBody className="space-y-3">
              <div>
                <Label htmlFor="void-journal">Jurnal</Label>
                <SearchSelect
                  value={voidForm.transaction_id}
                  options={(journals.data ?? []).map((journal) => ({
                    value: journal.id,
                    label: `${journal.transaction_number} · ${journal.description ?? ''}`,
                  }))}
                  onChange={(value) => setVoidForm({ ...voidForm, transaction_id: value === '' ? '' : Number(value) })}
                  placeholder="Cari nomor jurnal…"
                />
              </div>
              <div>
                <Label htmlFor="void-reason">Alasan</Label>
                <Input id="void-reason" value={voidForm.reason} onChange={(e) => setVoidForm({ ...voidForm, reason: e.target.value })} />
              </div>
              <Can perm="finance.journal.reverse">
                <Button
                  disabled={!voidForm.transaction_id || voidForm.reason.trim().length < 5 || buatVoid.isPending}
                  onClick={() => buatVoid.mutate()}
                >
                  Ajukan pembatalan
                </Button>
              </Can>
              <p className="text-xs text-slate-500">
                Jurnal yang sudah diposting tidak dihapus: ia dibatalkan lewat jurnal pembalik yang menyalin unit, dana, dan pusat biayanya.
              </p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader><CardTitle>Permohonan pembatalan</CardTitle></CardHeader>
            <CardBody>
              {voids.isLoading ? (
                <div className="flex justify-center py-10"><Spinner /></div>
              ) : (voids.data ?? []).length === 0 ? (
                <EmptyState title="Belum ada permohonan" />
              ) : (
                <ul className="divide-y divide-slate-100">
                  {(voids.data ?? []).map((request) => (
                    <li key={request.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                      <div>
                        <p className="text-sm font-medium text-slate-800">
                          {request.transaction?.transaction_number ?? `#${request.transaction_id}`} · <span className="text-slate-500">{request.reason}</span>
                        </p>
                        <p className="text-xs text-slate-500">
                          {request.reversal_transaction_id
                            ? `Dibalik oleh jurnal #${request.reversal_transaction_id}`
                            : 'Belum diputuskan / ditolak'}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge tone={toneStatus(request.status)}>{request.status}</Badge>
                        {request.status === 'pending' && can('finance.journal.approve') && (
                          <>
                            <Button size="sm" variant="outline" loading={putusVoid.isPending} onClick={() => putusVoid.mutate({ id: request.id, approved: false })}>
                              Tolak
                            </Button>
                            <Button size="sm" loading={putusVoid.isPending} onClick={() => putusVoid.mutate({ id: request.id, approved: true })}>
                              Setujui
                            </Button>
                          </>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}
