import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, CheckCircle2, Gavel, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { usePermission } from '@/hooks/usePermission';
import { assetsApi } from '../api/assetApi';
import { disposalsApi } from '../api/assetGovernanceApi';
import { FormField, Money } from '../components/AssetUi';
import { fmtDateTime } from '../lib/format';
import { disposalMethod, disposalReason, disposalStatus } from '../lib/labels';
import type { DisposalRequest } from '../types';

/**
 * Penghapusan aset.
 *
 * Baris aset TIDAK PERNAH dihapus dari basis data: penghapusan adalah status
 * akhir yang tetap dapat dicari, karena barang yang "lenyap tanpa jejak" adalah
 * temuan audit yang paling mahal.
 */
export default function DisposalListPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const [bukaAjukan, setBukaAjukan] = useState(false);
  const [nilai, setNilai] = useState<DisposalRequest | null>(null);
  const [laksana, setLaksana] = useState<DisposalRequest | null>(null);
  const [tolak, setTolak] = useState<DisposalRequest | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'disposals'],
    queryFn: () => disposalsApi.list({ per_page: 30 }),
  });

  const aksi = useMutation({
    mutationFn: ({ disposal, langkah }: { disposal: DisposalRequest; langkah: 'inspect' | 'approve' | 'retire' }) => {
      if (langkah === 'inspect') return disposalsApi.inspect(disposal.id, { condition: disposal.condition_at_request ?? 'DAMAGED' });
      if (langkah === 'approve') return disposalsApi.approve(disposal.id, {});
      return disposalsApi.retire(disposal.id, {});
    },
    onSuccess: () => {
      toast.success('Langkah penghapusan dicatat');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Penghapusan Aset</h1>
          <p className="text-xs text-slate-500">
            Diperiksa, dinilai, disetujui, dilaksanakan — lalu diarsipkan. Tidak ada langkah yang bisa dilompati, dan tidak ada baris yang dihapus.
          </p>
        </div>
        <Can perm="asset.disposal.request">
          <Button size="sm" onClick={() => setBukaAjukan(true)}>
            <Plus className="mr-1 h-4 w-4" /> Ajukan penghapusan
          </Button>
        </Can>
      </header>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada pengajuan" description="Barang rusak berat dapat diusulkan penghapusan — dengan inspeksi dan persetujuan." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((disposal) => (
              <li key={disposal.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-500">{disposal.request_number}</span>
                    <Badge tone={disposalStatus.tone(disposal.status)}>{disposalStatus.label(disposal.status)}</Badge>
                    <Badge tone="gray">{disposalReason.label(disposal.reason)}</Badge>
                    {disposal.method && <Badge tone="gray">{disposalMethod.label(disposal.method)}</Badge>}
                  </div>
                  <p className="mt-1 text-sm text-slate-800">{disposal.asset?.name ?? '-'}</p>
                  <p className="text-[11px] text-slate-500">
                    {disposal.asset?.asset_code}
                    {disposal.requested_by && <> · diajukan {disposal.requested_by.name}</>}
                    {disposal.executed_at && <> · dilaksanakan {fmtDateTime(disposal.executed_at)}</>}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-xs text-slate-600">
                    Nilai buku: <Money value={disposal.book_value_at_request} />
                  </span>
                  {disposal.status === 'SUBMITTED' && (
                    <Can perm="asset.disposal.request">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ disposal, langkah: 'inspect' })}>
                        Catat inspeksi
                      </Button>
                    </Can>
                  )}
                  {disposal.status === 'INSPECTED' && can('asset.financial.view') && (
                    <Button size="sm" variant="outline" onClick={() => setNilai(disposal)}>
                      Nilai &amp; nilai jual
                    </Button>
                  )}
                  {disposal.status === 'VALUED' && (
                    <Can perm="asset.disposal.approve">
                      <Button size="sm" onClick={() => aksi.mutate({ disposal, langkah: 'approve' })}>
                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Setujui
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setTolak(disposal)}>
                        <Ban className="mr-1 h-3.5 w-3.5" /> Tolak
                      </Button>
                    </Can>
                  )}
                  {disposal.status === 'APPROVED' && (
                    <Can perm="asset.disposal.execute">
                      <Button size="sm" onClick={() => setLaksana(disposal)}>
                        <Gavel className="mr-1 h-3.5 w-3.5" /> Laksanakan
                      </Button>
                    </Can>
                  )}
                  {disposal.status === 'EXECUTED' && (
                    <Can perm="asset.disposal.execute">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ disposal, langkah: 'retire' })}>
                        <Trash2 className="mr-1 h-3.5 w-3.5" /> Pensiunkan &amp; arsipkan
                      </Button>
                    </Can>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {bukaAjukan && <SubmitModal onClose={() => setBukaAjukan(false)} />}
      {nilai && <ValueModal disposal={nilai} onClose={() => setNilai(null)} />}
      {laksana && <ExecuteModal disposal={laksana} onClose={() => setLaksana(null)} />}
      {tolak && <RejectModal disposal={tolak} onClose={() => setTolak(null)} />}
    </div>
  );
}

function SubmitModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [cari, setCari] = useState('');
  const [form, setForm] = useState({ asset_id: '' as number | '', reason: 'DAMAGED', method: 'SCRAP', description: '', estimated_value: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data: kandidat } = useQuery({
    queryKey: ['asset', 'disposal-candidates', cari],
    queryFn: () => assetsApi.list({ search: cari, per_page: 15, condition: 'DAMAGED' }),
  });

  const simpan = useMutation({
    mutationFn: () => disposalsApi.create({ ...form, estimated_value: form.estimated_value === '' ? undefined : form.estimated_value }),
    onSuccess: () => {
      toast.success('Pengajuan penghapusan dibuat');
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
      title="Ajukan penghapusan"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || form.asset_id === ''}>
            Ajukan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Alasan" required error={errors.reason?.[0]}>
          <Select value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })}>
            {disposalReason.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Cara penghapusan" required error={errors.method?.[0]}>
          <Select value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })}>
            {disposalMethod.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Perkiraan nilai (Rp)" error={errors.estimated_value?.[0]}>
          <Input type="number" value={form.estimated_value} onChange={(e) => setForm({ ...form, estimated_value: e.target.value })} />
        </FormField>
        <FormField label="Keterangan" className="sm:col-span-2" error={errors.description?.[0]}>
          <Textarea rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>
      </div>

      <div className="mt-4">
        <FormField label="Barang yang diusulkan" required error={errors.asset_id?.[0]}>
          <Input placeholder="Cari barang…" value={cari} onChange={(e) => setCari(e.target.value)} />
        </FormField>
        <div className="mt-2 max-h-44 overflow-y-auto rounded-lg border border-slate-200">
          {(kandidat?.data ?? []).map((asset) => (
            <label key={asset.id} className="flex items-center gap-2 px-3 py-2 text-sm">
              <input type="radio" name="asset-disposal" checked={form.asset_id === asset.id} onChange={() => setForm({ ...form, asset_id: asset.id })} />
              {asset.name}
              <span className="font-mono text-[11px] text-slate-500">{asset.asset_code}</span>
            </label>
          ))}
        </div>
      </div>
    </Modal>
  );
}

function ValueModal({ disposal, onClose }: { disposal: DisposalRequest; onClose: () => void }) {
  const qc = useQueryClient();
  const [estimasi, setEstimasi] = useState(disposal.estimated_value ?? '');
  const [proceeds, setProceeds] = useState(disposal.expected_proceeds ?? '');
  const [dokumen, setDokumen] = useState('');

  const simpan = useMutation({
    mutationFn: () => disposalsApi.value(disposal.id, { estimated_value: estimasi, expected_proceeds: proceeds, document_reference: dokumen }),
    onSuccess: () => {
      toast.success('Nilai penghapusan dicatat');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Nilai penghapusan — ${disposal.request_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Perkiraan nilai saat ini (Rp)">
          <Input type="number" value={estimasi} onChange={(e) => setEstimasi(e.target.value)} />
        </FormField>
        <FormField label="Perkiraan hasil penjualan (Rp)" hint="Bila barang dijual; biaya ini menjadi jurnal tersendiri di Keuangan.">
          <Input type="number" value={proceeds} onChange={(e) => setProceeds(e.target.value)} />
        </FormField>
        <FormField label="Dokumen pendukung">
          <Input value={dokumen} onChange={(e) => setDokumen(e.target.value)} placeholder="Misal: berita acara penilaian BA-11/2026" />
        </FormField>
      </div>
    </Modal>
  );
}

function ExecuteModal({ disposal, onClose }: { disposal: DisposalRequest; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({ method: disposal.method ?? 'SCRAP', proceeds: '0', recipient: '', document_reference: '', notes: '' });

  const simpan = useMutation({
    mutationFn: () => disposalsApi.execute(disposal.id, form),
    onSuccess: () => {
      toast.success('Penghapusan dilaksanakan — akibat keuangannya dicatat ke Keuangan');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Laksanakan penghapusan — ${disposal.request_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" onClick={() => simpan.mutate()} disabled={simpan.isPending}>
            Laksanakan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          Setelah dilaksanakan, barang berstatus DIHAPUSBUKUKAN dan tidak dapat dikembalikan ke daftar aktif. Barisnya tetap dapat dicari.
        </p>
        <FormField label="Cara penghapusan">
          <Select value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })}>
            {disposalMethod.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Hasil penjualan diterima (Rp)">
          <Input type="number" value={form.proceeds} onChange={(e) => setForm({ ...form, proceeds: e.target.value })} />
        </FormField>
        <FormField label="Penerima">
          <Input value={form.recipient} onChange={(e) => setForm({ ...form, recipient: e.target.value })} placeholder="Pembeli / penerima hibah" />
        </FormField>
        <FormField label="Dokumen">
          <Input value={form.document_reference} onChange={(e) => setForm({ ...form, document_reference: e.target.value })} />
        </FormField>
        <FormField label="Catatan">
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  );
}

function RejectModal({ disposal, onClose }: { disposal: DisposalRequest; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');

  const simpan = useMutation({
    mutationFn: () => disposalsApi.reject(disposal.id, { reason }),
    onSuccess: () => {
      toast.success('Pengajuan ditolak');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Tolak ${disposal.request_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" onClick={() => simpan.mutate()} disabled={simpan.isPending || !reason.trim()}>
            Tolak
          </Button>
        </div>
      }
    >
      <FormField label="Alasan penolakan" required>
        <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
