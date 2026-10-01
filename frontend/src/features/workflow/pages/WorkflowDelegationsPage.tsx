import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, Plus, UserCheck } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import { formatDateTime } from '../components/SlaBadge';

/**
 * Delegasi kewenangan (§8, §39).
 *
 * Approver yang akan tidak berada di tempat membuat delegasi bercakupan &
 * berbatas waktu. Engine tetap mencatat siapa pemilik kewenangan aslinya,
 * sehingga jejak persetujuan tetap dapat dipertanggungjawabkan.
 */
export default function WorkflowDelegationsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [status, setStatus] = useState('');

  const delegations = useQuery({
    queryKey: ['workflow', 'delegations', page, status],
    queryFn: () => workflowApi.delegations({ page, status: status || undefined }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['workflow', 'delegations'] });

  const create = useMutation({
    mutationFn: (payload: Record<string, unknown>) => workflowApi.createDelegation(payload),
    onSuccess: () => {
      toast.success('Delegasi dibuat.');
      setCreating(false);
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const revoke = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => workflowApi.revokeDelegation(id, reason),
    onSuccess: () => {
      toast.success('Delegasi dicabut.');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Delegasi Kewenangan</h1>
          <p className="text-sm text-slate-500">
            Pemindahan kewenangan approval yang terbatas waktu dan bercakupan — jejak pemilik kewenangan tetap tercatat.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Select value={status} onChange={(event) => setStatus(event.target.value)} className="w-40">
            <option value="">Semua status</option>
            <option value="ACTIVE">Aktif</option>
            <option value="PENDING">Terjadwal</option>
            <option value="REVOKED">Dicabut</option>
            <option value="EXPIRED">Berakhir</option>
          </Select>
          <Button onClick={() => setCreating(true)}>
            <Plus className="mr-1 h-4 w-4" /> Delegasi baru
          </Button>
        </div>
      </div>

      <Card>
        <CardBody className="p-0">
          {delegations.isLoading ? <Spinner /> : null}
          {delegations.data && delegations.data.data.length === 0 ? (
            <EmptyState title="Belum ada delegasi" description="Buat delegasi sebelum cuti/dinas agar persetujuan tidak tertahan." />
          ) : null}

          <ul className="divide-y divide-slate-100">
            {delegations.data?.data.map((delegation) => (
              <li key={delegation.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium text-slate-800">
                      {delegation.delegator} → {delegation.delegate}
                    </p>
                    <Badge tone={delegation.is_active_now ? 'success' : delegation.status === 'PENDING' ? 'info' : 'neutral'}>
                      {delegation.status_label}
                    </Badge>
                  </div>
                  <p className="mt-0.5 text-xs text-slate-500">
                    <CalendarClock className="mr-1 inline h-3 w-3" />
                    {formatDateTime(delegation.start_at)} — {formatDateTime(delegation.end_at)}
                  </p>
                  {delegation.reason ? <p className="text-xs text-slate-500">Alasan: {delegation.reason}</p> : null}
                  {delegation.scope && Object.keys(delegation.scope).length > 0 ? (
                    <p className="text-xs text-slate-500">Cakupan: {JSON.stringify(delegation.scope)}</p>
                  ) : (
                    <p className="text-xs text-slate-500">Cakupan: seluruh penugasan</p>
                  )}
                </div>

                {delegation.status !== 'REVOKED' && delegation.status !== 'EXPIRED' ? (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      const reason = window.prompt('Alasan pencabutan delegasi:') ?? '';

                      revoke.mutate({ id: delegation.id, reason });
                    }}
                  >
                    Cabut
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>

          {delegations.data ? <Pagination meta={delegations.data.meta} onPageChange={setPage} /> : null}
        </CardBody>
      </Card>

      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title="Delegasi baru"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setCreating(false)}>
              Batal
            </Button>
          </div>
        }
      >
        <CreateDelegationForm loading={create.isPending} onSubmit={(payload) => create.mutate(payload)} />
      </Modal>
    </div>
  );
}

function CreateDelegationForm({ loading, onSubmit }: { loading: boolean; onSubmit: (payload: Record<string, unknown>) => void }) {
  const [form, setForm] = useState({
    delegate_id: '',
    start_at: new Date().toISOString().slice(0, 16),
    end_at: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 16),
    reason: '',
    definitions: '',
    amount_max: '',
  });

  return (
    <div className="space-y-3">
      <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
        <UserCheck className="mr-1 inline h-3.5 w-3.5" />
        Delegasi memindahkan <span className="font-medium">penugasan</span>, bukan identitas: keputusan penerima delegasi akan tercatat atas nama
        Anda sebagai pemilik kewenangan.
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <Label required>ID penerima delegasi</Label>
          <Input type="number" value={form.delegate_id} onChange={(e) => setForm({ ...form, delegate_id: e.target.value })} placeholder="id pengguna" />
        </div>
        <div>
          <Label>Batas nominal (opsional)</Label>
          <Input type="number" value={form.amount_max} onChange={(e) => setForm({ ...form, amount_max: e.target.value })} placeholder="5000000" />
        </div>
        <div>
          <Label required>Mulai</Label>
          <Input type="datetime-local" value={form.start_at} onChange={(e) => setForm({ ...form, start_at: e.target.value })} />
        </div>
        <div>
          <Label required>Berakhir</Label>
          <Input type="datetime-local" value={form.end_at} onChange={(e) => setForm({ ...form, end_at: e.target.value })} />
        </div>
      </div>

      <div>
        <Label>Batasi cakupan ke kode definisi (pisahkan dengan koma)</Label>
        <Input
          value={form.definitions}
          onChange={(e) => setForm({ ...form, definitions: e.target.value })}
          placeholder="PURCHASE_REQUEST_APPROVAL, EXPENSE_APPROVAL"
        />
      </div>

      <div>
        <Label>Alasan</Label>
        <Textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} rows={2} placeholder="mis. Dinas luar kota 1–7 Oktober" />
      </div>

      <div className="flex justify-end">
        <Button
          loading={loading}
          disabled={!form.delegate_id}
          onClick={() =>
            onSubmit({
              delegate_id: Number(form.delegate_id),
              start_at: new Date(form.start_at).toISOString(),
              end_at: new Date(form.end_at).toISOString(),
              reason: form.reason || undefined,
              scope: {
                ...(form.definitions
                  ? {
                      definitions: form.definitions
                        .split(',')
                        .map((item) => item.trim().toUpperCase())
                        .filter(Boolean),
                    }
                  : {}),
                ...(form.amount_max ? { amount_max: Number(form.amount_max) } : {}),
              },
            })
          }
        >
          Buat delegasi
        </Button>
      </div>
    </div>
  );
}
