import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, PackageCheck, Plus, Truck, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { assetsApi } from '../api/assetApi';
import { transfersApi } from '../api/assetOpsApi';
import { FormField, useLocationOptions } from '../components/AssetUi';
import { assetCondition, transferStatus } from '../lib/labels';
import { fmtDateTime } from '../lib/format';
import type { AssetTransfer } from '../types';

/**
 * Pemindahan aset.
 *
 * Tiga langkah yang sengaja tidak digabung: setujui → serah terima → penerima
 * mengonfirmasi. Menggabungkannya membuat barang “berpindah” di sistem padahal
 * belum tentu sampai di tangan siapa pun.
 */
export default function AssetTransferListPage() {
  const qc = useQueryClient();
  const [bukaBuat, setBukaBuat] = useState(false);
  const [terima, setTerima] = useState<AssetTransfer | null>(null);
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'transfers', page],
    queryFn: () => transfersApi.list({ page, per_page: 20 }),
  });

  const aksi = useMutation({
    mutationFn: ({ id, langkah }: { id: number; langkah: 'approve' | 'handover' | 'cancel' }) => {
      if (langkah === 'approve') return transfersApi.approve(id);
      if (langkah === 'handover') return transfersApi.handover(id, {});
      return transfersApi.cancel(id, { reason: 'Dibatalkan pengelola aset' });
    },
    onSuccess: () => {
      toast.success('Langkah pemindahan dicatat');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Pemindahan Aset</h1>
          <p className="text-xs text-slate-500">
            Penerima mengonfirmasi penerimaan barang — inilah yang membuat perpindahan sah, bukan tanda tangan pemberi.
          </p>
        </div>
        <Can perm="asset.transfer.request">
          <Button size="sm" onClick={() => setBukaBuat(true)}>
            <Plus className="mr-1 h-4 w-4" /> Ajukan pemindahan
          </Button>
        </Can>
      </header>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada pemindahan" />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((transfer) => (
              <li key={transfer.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-500">{transfer.transfer_number}</span>
                    <Badge tone={transferStatus.tone(transfer.status)}>{transferStatus.label(transfer.status)}</Badge>
                    <Badge tone="gray">{transfer.type.replace(/_/g, ' ')}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-slate-800">
                    {transfer.from_location?.name ?? '-'} → {transfer.to_location?.name ?? '-'}
                  </p>
                  <p className="text-[11px] text-slate-500">
                    {transfer.reason ?? '-'}
                    {transfer.requested_by && <> · diajukan {transfer.requested_by.name}</>}
                    {transfer.handed_over_at && <> · diserahkan {fmtDateTime(transfer.handed_over_at)}</>}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  {transfer.status === 'REQUESTED' && (
                    <Can perm="asset.transfer.approve">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: transfer.id, langkah: 'approve' })}>
                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Setujui
                      </Button>
                    </Can>
                  )}
                  {transfer.status === 'APPROVED' && (
                    <Can perm="asset.transfer.approve">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: transfer.id, langkah: 'handover' })}>
                        <Truck className="mr-1 h-3.5 w-3.5" /> Serah terima
                      </Button>
                    </Can>
                  )}
                  {transfer.status === 'HANDED_OVER' && (
                    <Can perm="asset.transfer.view">
                      <Button size="sm" onClick={() => setTerima(transfer)}>
                        <PackageCheck className="mr-1 h-3.5 w-3.5" /> Konfirmasi terima
                      </Button>
                    </Can>
                  )}
                  {['REQUESTED', 'APPROVED'].includes(transfer.status) && (
                    <Can perm="asset.transfer.approve">
                      <Button size="sm" variant="ghost" onClick={() => aksi.mutate({ id: transfer.id, langkah: 'cancel' })}>
                        <XCircle className="mr-1 h-3.5 w-3.5" /> Batalkan
                      </Button>
                    </Can>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      {bukaBuat && <TransferFormModal onClose={() => setBukaBuat(false)} />}
      {terima && <ReceiveModal transfer={terima} onClose={() => setTerima(null)} />}
    </div>
  );
}

function TransferFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const { options: locationOptions } = useLocationOptions();
  const [assetIds, setAssetIds] = useState<number[]>([]);
  const [cari, setCari] = useState('');
  const [form, setForm] = useState({ to_location_id: '' as number | '', type: 'ROOM_TRANSFER', reason: '', document_reference: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data: kandidat } = useQuery({
    queryKey: ['asset', 'transfer-candidates', cari],
    queryFn: () => assetsApi.list({ search: cari, per_page: 15, status: 'ACTIVE' }),
  });

  const simpan = useMutation({
    mutationFn: () => transfersApi.create({ ...form, asset_ids: assetIds }),
    onSuccess: () => {
      toast.success('Pemindahan diajukan');
      qc.invalidateQueries({ queryKey: ['asset'] });
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
      title="Ajukan pemindahan"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || assetIds.length === 0 || form.to_location_id === ''}>
            Ajukan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Tujuan" required error={errors.to_location_id?.[0]}>
          <SearchSelect
            options={locationOptions}
            value={form.to_location_id}
            onChange={(v) => setForm({ ...form, to_location_id: v === '' ? '' : Number(v) })}
          />
        </FormField>
        <FormField label="Jenis">
          <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
            {['ROOM_TRANSFER', 'UNIT_TRANSFER', 'CAMPUS_TRANSFER', 'PERMANENT_TRANSFER'].map((t) => (
              <option key={t} value={t}>
                {t.replace(/_/g, ' ')}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Alasan" required error={errors.reason?.[0]} className="sm:col-span-2">
          <Textarea rows={2} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
        </FormField>
        <FormField label="Nomor dokumen" error={errors.document_reference?.[0]}>
          <Input value={form.document_reference} onChange={(e) => setForm({ ...form, document_reference: e.target.value })} />
        </FormField>
      </div>

      <div className="mt-4">
        <FormField label="Barang yang dipindahkan" required error={errors.asset_ids?.[0]}>
          <Input placeholder="Cari barang…" value={cari} onChange={(e) => setCari(e.target.value)} />
        </FormField>
        <div className="mt-2 max-h-48 overflow-y-auto rounded-lg border border-slate-200">
          {(kandidat?.data ?? []).map((asset) => (
            <label key={asset.id} className="flex items-center justify-between px-3 py-2 text-sm">
              <span className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={assetIds.includes(asset.id)}
                  onChange={(e) => setAssetIds((prev) => (e.target.checked ? [...prev, asset.id] : prev.filter((v) => v !== asset.id)))}
                />
                {asset.name}
                <span className="font-mono text-[11px] text-slate-500">{asset.asset_code}</span>
              </span>
              <span className="text-[11px] text-slate-500">{asset.location?.name ?? '-'}</span>
            </label>
          ))}
        </div>
      </div>
    </Modal>
  );
}

function ReceiveModal({ transfer, onClose }: { transfer: AssetTransfer; onClose: () => void }) {
  const qc = useQueryClient();
  const [condition, setCondition] = useState('GOOD');
  const [notes, setNotes] = useState('');

  const simpan = useMutation({
    mutationFn: () => transfersApi.receive(transfer.id, { recipient_condition: condition, recipient_notes: notes }),
    onSuccess: () => {
      toast.success('Penerimaan dikonfirmasi — pemindahan selesai');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Konfirmasi penerimaan — ${transfer.transfer_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending}>
            Konfirmasi
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <p className="text-sm text-slate-700">
          {transfer.from_location?.name ?? '-'} → {transfer.to_location?.name ?? '-'}
        </p>
        <FormField label="Kondisi saat diterima" required>
          <Select value={condition} onChange={(e) => setCondition(e.target.value)}>
            {['GOOD', 'FAIR', 'POOR', 'DAMAGED'].map((c) => (
              <option key={c} value={c}>
                {assetCondition.label(c)}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Catatan penerima">
          <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}
