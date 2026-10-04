import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowLeft, CheckCircle2, RotateCcw, Send, XCircle } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { formatDate, formatRupiah } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { purchaseRequestsApi } from '../api/procurementApi';
import { DetailRow, FormField, SectionCard, StatusBadge } from '../components/ProcurementUi';
import { approvalStatus, itemType, prPriority, prStatus, procurementMethod } from '../lib/labels';

/** Detail PR: rincian, temuan validasi, langkah persetujuan, dan linimasa. */
export default function PurchaseRequestDetailPage() {
  const { requestId } = useParams();
  const id = Number(requestId);
  const qc = useQueryClient();
  const [tolak, setTolak] = useState<null | 'reject' | 'return'>(null);

  const { data: pr, isLoading } = useQuery({
    queryKey: ['procurement', 'purchase-request', id],
    queryFn: () => purchaseRequestsApi.show(id),
    enabled: Number.isFinite(id),
  });

  const { data: timeline } = useQuery({
    queryKey: ['procurement', 'purchase-request', id, 'timeline'],
    queryFn: () => purchaseRequestsApi.timeline(id),
    enabled: Number.isFinite(id),
    retry: false,
  });

  const [temuan, setTemuan] = useState<{ code: string; severity: string; message: string }[] | null>(null);

  const aksi = useMutation({
    mutationFn: (langkah: 'submit' | 'approve') =>
      langkah === 'submit' ? purchaseRequestsApi.submit(id) : purchaseRequestsApi.approve(id),
    onSuccess: () => {
      toast.success('Status PR diperbarui');
      qc.invalidateQueries({ queryKey: ['procurement'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const validasi = useMutation({
    mutationFn: () => purchaseRequestsApi.validate(id),
    onSuccess: (res) => {
      setTemuan(res.issues ?? []);
      toast.success('Validasi selesai');
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (isLoading) {
    return (
      <div className="flex justify-center py-24">
        <Spinner />
      </div>
    );
  }

  if (!pr) return <EmptyState title="PR tidak ditemukan" />;

  const pendingApproval = (pr.approvals ?? []).some((a) => a.status === 'PENDING');

  return (
    <div className="space-y-4">
      <Link to="/procurement/purchase-requests" className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-brand-600">
        <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar PR
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="font-mono text-lg font-semibold text-slate-800">{pr.number}</h1>
            <StatusBadge value={pr.status} map={prStatus} />
            <StatusBadge value={pr.priority} map={prPriority} />
            <StatusBadge value={pr.method} map={procurementMethod} />
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            {pr.requester?.name ?? '-'} · perlu {formatDate(pr.required_date)}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={() => validasi.mutate()} loading={validasi.isPending}>
            <AlertTriangle className="mr-1 h-4 w-4" /> Validasi
          </Button>
          <Can perm="procurement.create_pr">
            {['DRAFT', 'RETURNED'].includes(pr.status) && (
              <Button size="sm" onClick={() => aksi.mutate('submit')} loading={aksi.isPending}>
                <Send className="mr-1 h-4 w-4" /> Ajukan
              </Button>
            )}
          </Can>
          <Can perm="procurement.approve_pr">
            {pendingApproval && (
              <>
                <Button size="sm" onClick={() => aksi.mutate('approve')} loading={aksi.isPending}>
                  <CheckCircle2 className="mr-1 h-4 w-4" /> Setujui
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setTolak('return')}>
                  <RotateCcw className="mr-1 h-4 w-4" /> Kembalikan
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setTolak('reject')}>
                  <XCircle className="mr-1 h-4 w-4" /> Tolak
                </Button>
              </>
            )}
          </Can>
        </div>
      </header>

      {temuan && (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
          <h3 className="text-sm font-semibold text-slate-700">Hasil validasi</h3>
          {temuan.length === 0 ? (
            <p className="mt-1 text-xs text-green-700">Tidak ada temuan. PR siap diajukan.</p>
          ) : (
            <ul className="mt-2 space-y-1">
              {temuan.map((t, i) => (
                <li key={i} className="flex items-start gap-2 text-xs">
                  <Badge tone={t.severity === 'HIGH' || t.severity === 'ERROR' ? 'red' : 'amber'}>{t.severity}</Badge>
                  <span className="text-slate-700">{t.message}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard title="Ringkasan" className="lg:col-span-1">
          <DetailRow label="Pemohon">{pr.requester?.name ?? '-'}</DetailRow>
          <DetailRow label="Perlu tanggal">{formatDate(pr.required_date)}</DetailRow>
          <DetailRow label="Metode pengadaan">{procurementMethod.label(pr.method)}</DetailRow>
          <DetailRow label="Mata uang">{pr.currency ?? 'IDR'}</DetailRow>
          <DetailRow label="Estimasi total">
            <span className="font-semibold tabular-nums">{formatRupiah(pr.estimated_total ?? 0)}</span>
          </DetailRow>
          <DetailRow label="Tujuan">{pr.purpose ?? '-'}</DetailRow>
        </SectionCard>

        <SectionCard title="Rincian kebutuhan" className="lg:col-span-2">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="py-2 font-medium">Deskripsi</th>
                  <th className="py-2 font-medium">Jenis</th>
                  <th className="py-2 text-right font-medium">Jumlah</th>
                  <th className="py-2 text-right font-medium">Harga</th>
                  <th className="py-2 text-right font-medium">Subtotal</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(pr.items ?? []).map((it) => (
                  <tr key={it.id}>
                    <td className="py-2 text-slate-800">
                      {it.description}
                      {it.is_asset && (
                        <Badge tone="brand" className="ml-2">
                          Aset
                        </Badge>
                      )}
                    </td>
                    <td className="py-2">
                      <StatusBadge value={it.item_type} map={itemType} />
                    </td>
                    <td className="py-2 text-right tabular-nums text-slate-600">
                      {it.quantity} {it.unit ?? ''}
                    </td>
                    <td className="py-2 text-right tabular-nums text-slate-600">
                      {formatRupiah(it.estimated_unit_price ?? 0)}
                    </td>
                    <td className="py-2 text-right tabular-nums text-slate-700">
                      {formatRupiah(Number(it.quantity) * Number(it.estimated_unit_price ?? 0))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SectionCard title="Langkah persetujuan">
          {(pr.approvals ?? []).length === 0 ? (
            <EmptyState title="Belum ada langkah" description="Langkah muncul setelah PR diajukan." />
          ) : (
            <ol className="space-y-2">
              {pr.approvals?.map((a) => (
                <li key={a.id} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                  <div>
                    <p className="text-sm text-slate-800">
                      {a.step != null && <span className="text-slate-400">#{a.step} </span>}
                      {(a.role ?? 'Approver').replace(/_/g, ' ')}
                    </p>
                    <p className="text-[11px] text-slate-500">
                      {a.approver?.name ?? 'Belum diproses'}
                      {a.acted_at && <> · {formatDate(a.acted_at)}</>}
                    </p>
                  </div>
                  <StatusBadge value={a.status} map={approvalStatus} />
                </li>
              ))}
            </ol>
          )}
        </SectionCard>

        <SectionCard title="Linimasa">
          {(timeline ?? []).length === 0 ? (
            <EmptyState title="Belum ada aktivitas" />
          ) : (
            <ol className="relative space-y-3 border-l border-slate-200 pl-4">
              {timeline?.map((t, i) => (
                <li key={i} className="relative">
                  <span className="absolute -left-[21px] top-1 h-2 w-2 rounded-full bg-brand-500" />
                  <p className="text-sm text-slate-800">{t.action.replace(/_/g, ' ')}</p>
                  <p className="text-[11px] text-slate-500">
                    {formatDate(t.at)}
                    {t.actor && <> · {t.actor}</>}
                    {t.notes && <> · {t.notes}</>}
                  </p>
                </li>
              ))}
            </ol>
          )}
        </SectionCard>
      </div>

      {tolak && <ReasonModal id={id} mode={tolak} onClose={() => setTolak(null)} />}
    </div>
  );
}

function ReasonModal({ id, mode, onClose }: { id: number; mode: 'reject' | 'return'; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () =>
      mode === 'reject' ? purchaseRequestsApi.reject(id, reason) : purchaseRequestsApi.returnRequest(id, reason),
    onSuccess: () => {
      toast.success(mode === 'reject' ? 'PR ditolak' : 'PR dikembalikan');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={mode === 'reject' ? 'Tolak PR' : 'Kembalikan PR'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant={mode === 'reject' ? 'danger' : 'primary'} onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!reason}>
            {mode === 'reject' ? 'Tolak' : 'Kembalikan'}
          </Button>
        </div>
      }
    >
      <FormField label="Alasan" required error={errors.reason?.[0]}>
        <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
