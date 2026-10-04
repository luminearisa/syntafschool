import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, FileSignature, History, PackagePlus, Send, XCircle } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { formatDate, formatRupiah } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { purchaseOrdersApi, receivingApi } from '../api/procurementApi';
import { DetailRow, FormField, SectionCard, StatusBadge, useEnumMetadata } from '../components/ProcurementUi';
import { approvalStatus, poStatus, vendorConfirmationStatus } from '../lib/labels';
import type { PurchaseOrder } from '../types';

/** Detail PO: item, penerimaan, persetujuan, versi amandemen, dan konfirmasi vendor. */
export default function PurchaseOrderDetailPage() {
  const { orderId } = useParams();
  const id = Number(orderId);
  const qc = useQueryClient();
  const [bukaTerima, setBukaTerima] = useState(false);
  const [bukaAmandemen, setBukaAmandemen] = useState(false);
  const [bukaKonfirmasi, setBukaKonfirmasi] = useState(false);
  const [bukaBatal, setBukaBatal] = useState(false);

  const { data: po, isLoading } = useQuery({
    queryKey: ['procurement', 'purchase-order', id],
    queryFn: () => purchaseOrdersApi.show(id),
    enabled: Number.isFinite(id),
  });

  const aksi = useMutation({
    mutationFn: (langkah: 'submit' | 'approve' | 'issue' | 'close') => {
      if (langkah === 'submit') return purchaseOrdersApi.submit(id);
      if (langkah === 'approve') return purchaseOrdersApi.approve(id);
      if (langkah === 'issue') return purchaseOrdersApi.issue(id);
      return purchaseOrdersApi.close(id);
    },
    onSuccess: () => {
      toast.success('Status PO diperbarui');
      qc.invalidateQueries({ queryKey: ['procurement'] });
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

  if (!po) return <EmptyStatePO />;

  const pendingApproval = (po.approvals ?? []).some((a) => a.status === 'PENDING');
  const bisaTerima = ['VENDOR_CONFIRMED', 'PARTIALLY_RECEIVED'].includes(po.status);

  return (
    <div className="space-y-4">
      <Link to="/procurement/purchase-orders" className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-brand-600">
        <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar PO
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="font-mono text-lg font-semibold text-slate-800">{po.number}</h1>
            <StatusBadge value={po.status} map={poStatus} />
            {po.versions && po.versions.length > 1 && <Badge tone="amber">v{po.versions.length}</Badge>}
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            {po.vendor?.legal_name ?? '-'} · {formatDate(po.po_date)}
            {po.expected_delivery_date && <> · tiba {formatDate(po.expected_delivery_date)}</>}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Can perm="procurement.create_po">
            {po.status === 'DRAFT' && (
              <Button size="sm" onClick={() => aksi.mutate('submit')} loading={aksi.isPending}>
                <Send className="mr-1 h-4 w-4" /> Ajukan
              </Button>
            )}
          </Can>
          <Can perm="procurement.approve_po">
            {pendingApproval && (
              <Button size="sm" onClick={() => aksi.mutate('approve')} loading={aksi.isPending}>
                <CheckCircle2 className="mr-1 h-4 w-4" /> Setujui
              </Button>
            )}
          </Can>
          <Can perm="procurement.issue_po">
            {po.status === 'APPROVED' && (
              <Button size="sm" onClick={() => aksi.mutate('issue')} loading={aksi.isPending}>
                <Send className="mr-1 h-4 w-4" /> Terbitkan
              </Button>
            )}
          </Can>
          <Can perm="procurement.create_po">
            {po.status === 'ISSUED' && (
              <Button size="sm" variant="outline" onClick={() => setBukaKonfirmasi(true)}>
                <FileSignature className="mr-1 h-4 w-4" /> Konfirmasi vendor
              </Button>
            )}
            {['ISSUED', 'VENDOR_CONFIRMED', 'PARTIALLY_RECEIVED'].includes(po.status) && (
              <Button size="sm" variant="outline" onClick={() => setBukaAmandemen(true)}>
                <History className="mr-1 h-4 w-4" /> Amandemen
              </Button>
            )}
          </Can>
          <Can perm="procurement.receive">
            {bisaTerima && (
              <Button size="sm" onClick={() => setBukaTerima(true)}>
                <PackagePlus className="mr-1 h-4 w-4" /> Catat penerimaan
              </Button>
            )}
          </Can>
          <Can perm="procurement.approve_po">
            {['APPROVED', 'ISSUED', 'VENDOR_CONFIRMED', 'PARTIALLY_RECEIVED', 'FULLY_RECEIVED'].includes(po.status) && (
              <>
                <Button size="sm" variant="ghost" onClick={() => aksi.mutate('close')} loading={aksi.isPending}>
                  Tutup
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setBukaBatal(true)}>
                  <XCircle className="mr-1 h-4 w-4" /> Batalkan
                </Button>
              </>
            )}
          </Can>
        </div>
      </header>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard title="Ringkasan">
          <DetailRow label="Vendor">{po.vendor?.legal_name ?? '-'}</DetailRow>
          <DetailRow label="Tanggal PO">{formatDate(po.po_date)}</DetailRow>
          <DetailRow label="Estimasi tiba">{formatDate(po.expected_delivery_date)}</DetailRow>
          <DetailRow label="Termin bayar">{po.payment_terms ?? '-'}</DetailRow>
          <DetailRow label="Mata uang">{po.currency ?? 'IDR'}</DetailRow>
          <div className="mt-2 border-t border-slate-100 pt-2">
            <DetailRow label="Subtotal">{formatRupiah(po.subtotal ?? 0)}</DetailRow>
            <DetailRow label="Pajak">{formatRupiah(po.tax_amount ?? 0)}</DetailRow>
            {Number(po.discount_amount ?? 0) > 0 && <DetailRow label="Diskon">{formatRupiah(po.discount_amount ?? 0)}</DetailRow>}
            <DetailRow label="Grand total">
              <span className="text-base font-semibold tabular-nums">{formatRupiah(po.grand_total ?? 0)}</span>
            </DetailRow>
          </div>
        </SectionCard>

        <SectionCard title="Item" className="lg:col-span-2">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="py-2 font-medium">Deskripsi</th>
                  <th className="py-2 text-right font-medium">Dipesan</th>
                  <th className="py-2 text-right font-medium">Diterima</th>
                  <th className="py-2 text-right font-medium">Harga</th>
                  <th className="py-2 text-right font-medium">Subtotal</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(po.items ?? []).map((it) => {
                  const ordered = Number(it.quantity);
                  const received = Number(it.received_quantity ?? 0);
                  return (
                    <tr key={it.id}>
                      <td className="py-2 text-slate-800">
                        {it.description}
                        {it.is_asset && (
                          <Badge tone="brand" className="ml-2">
                            Aset
                          </Badge>
                        )}
                      </td>
                      <td className="py-2 text-right tabular-nums text-slate-600">
                        {ordered} {it.unit ?? ''}
                      </td>
                      <td className="py-2 text-right tabular-nums">
                        <span className={received >= ordered ? 'text-green-600' : received > 0 ? 'text-amber-600' : 'text-slate-500'}>
                          {received}
                        </span>
                      </td>
                      <td className="py-2 text-right tabular-nums text-slate-600">{formatRupiah(it.unit_price ?? 0)}</td>
                      <td className="py-2 text-right tabular-nums text-slate-700">
                        {formatRupiah(Number(it.quantity) * Number(it.unit_price ?? 0))}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {po.notes && <p className="mt-3 text-xs text-slate-500">Catatan: {po.notes}</p>}
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard title="Langkah persetujuan">
          {(po.approvals ?? []).length === 0 ? (
            <p className="text-xs text-slate-500">Belum ada langkah.</p>
          ) : (
            <ol className="space-y-2">
              {po.approvals?.map((a) => (
                <li key={a.id} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                  <div>
                    <p className="text-sm text-slate-800">{(a.role ?? 'Approver').replace(/_/g, ' ')}</p>
                    <p className="text-[11px] text-slate-500">{a.approver?.name ?? 'Belum diproses'}</p>
                  </div>
                  <StatusBadge value={a.status} map={approvalStatus} />
                </li>
              ))}
            </ol>
          )}
        </SectionCard>

        <SectionCard title="Riwayat versi amandemen" className="lg:col-span-2">
          {(po.versions ?? []).length === 0 ? (
            <p className="text-xs text-slate-500">Belum ada amandemen.</p>
          ) : (
            <ol className="space-y-2">
              {po.versions?.map((v) => (
                <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2">
                  <div>
                    <p className="text-sm text-slate-800">
                      Versi {v.version}
                      {v.is_material ? <Badge tone="amber" className="ml-2">Material</Badge> : <Badge tone="gray" className="ml-2">Non-material</Badge>}
                    </p>
                    <p className="text-[11px] text-slate-500">
                      {formatDate(v.created_at)}
                      {v.reason && <> · {v.reason}</>}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          )}
        </SectionCard>
      </div>

      {bukaTerima && <ReceiptModal po={po} onClose={() => setBukaTerima(false)} />}
      {bukaAmandemen && <AmendModal id={id} onClose={() => setBukaAmandemen(false)} />}
      {bukaKonfirmasi && <ConfirmVendorModal id={id} onClose={() => setBukaKonfirmasi(false)} />}
      {bukaBatal && <CancelModal id={id} onClose={() => setBukaBatal(false)} />}
    </div>
  );
}

function EmptyStatePO() {
  return (
    <div className="py-24 text-center text-sm text-slate-500">PO tidak ditemukan.</div>
  );
}

function ReceiptModal({ po, onClose }: { po: PurchaseOrder; onClose: () => void }) {
  const qc = useQueryClient();
  const order = po;
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [receiptDate, setReceiptDate] = useState(new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');
  const [rows, setRows] = useState<Record<number, { delivered: string; accepted: string }>>({});

  const simpan = useMutation({
    mutationFn: () =>
      receivingApi.store(order.id, {
        receipt_date: receiptDate,
        notes,
        items: Object.entries(rows)
          .filter(([, v]) => Number(v.delivered) > 0)
          .map(([poItemId, v]) => ({
            purchase_order_item_id: Number(poItemId),
            delivered_quantity: Number(v.delivered),
            accepted_quantity: Number(v.accepted || v.delivered),
          })),
      }),
    onSuccess: () => {
      toast.success('Penerimaan dicatat');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const adaIsian = Object.values(rows).some((v) => Number(v.delivered) > 0);

  return (
    <Modal
      open
      onClose={onClose}
      title={`Catat penerimaan — ${order.number}`}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!adaIsian}>
            Simpan penerimaan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Tanggal penerimaan" error={errors.receipt_date?.[0]}>
          <Input type="date" value={receiptDate} onChange={(e) => setReceiptDate(e.target.value)} />
        </FormField>
        <FormField label="Catatan" error={errors.notes?.[0]}>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>

      <div className="mt-4">
        <h3 className="mb-2 text-sm font-semibold text-slate-700">Kuantitas diterima</h3>
        {errors['items'] && <p className="mb-2 text-[11px] text-red-600">{errors['items'][0]}</p>}
        <div className="space-y-2">
          {(order.items ?? []).map((it) => {
            const sisa = Number(it.quantity) - Number(it.received_quantity ?? 0);
            const row = rows[it.id] ?? { delivered: '', accepted: '' };
            return (
              <div key={it.id} className="grid grid-cols-12 items-center gap-2 rounded-lg border border-slate-200 p-2">
                <div className="col-span-12 sm:col-span-6">
                  <p className="text-sm text-slate-800">{it.description}</p>
                  <p className="text-[11px] text-slate-500">
                    Dipesan {it.quantity} {it.unit ?? ''} · sisa {sisa}
                  </p>
                </div>
                <div className="col-span-6 sm:col-span-3">
                  <Input
                    type="number"
                    placeholder="Dikirim"
                    value={row.delivered}
                    onChange={(e) => setRows((p) => ({ ...p, [it.id]: { ...row, delivered: e.target.value } }))}
                  />
                </div>
                <div className="col-span-6 sm:col-span-3">
                  <Input
                    type="number"
                    placeholder="Diterima"
                    value={row.accepted}
                    onChange={(e) => setRows((p) => ({ ...p, [it.id]: { ...row, accepted: e.target.value } }))}
                  />
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </Modal>
  );
}

function AmendModal({ id, onClose }: { id: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [reason, setReason] = useState('');
  const [isMaterial, setIsMaterial] = useState(false);
  const [expectedDate, setExpectedDate] = useState('');

  const simpan = useMutation({
    mutationFn: () =>
      purchaseOrdersApi.amend(id, {
        reason,
        is_material: isMaterial,
        changes: expectedDate ? { expected_delivery_date: expectedDate } : {},
      }),
    onSuccess: () => {
      toast.success('Amandemen dicatat — versi baru dibuat');
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
      title="Amandemen PO"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!reason}>
            Simpan amandemen
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Estimasi tiba baru" hint="Kosongkan bila tidak mengubah jadwal." error={errors['changes.expected_delivery_date']?.[0]}>
          <Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
        </FormField>
        <FormField label="Material?" hint="Amandemen material mengubah nilai/kuantitas dan umumnya perlu persetujuan ulang.">
          <Select value={isMaterial ? '1' : '0'} onChange={(e) => setIsMaterial(e.target.value === '1')}>
            <option value="0">Non-material</option>
            <option value="1">Material</option>
          </Select>
        </FormField>
        <FormField label="Alasan" required error={errors.reason?.[0]}>
          <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function ConfirmVendorModal({ id, onClose }: { id: number; onClose: () => void }) {
  const qc = useQueryClient();
  const { optionsOf } = useEnumMetadata();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [status, setStatus] = useState('ACCEPTED');
  const [notes, setNotes] = useState('');

  const simpan = useMutation({
    mutationFn: () => purchaseOrdersApi.confirm(id, { status, notes }),
    onSuccess: () => {
      toast.success('Konfirmasi vendor dicatat');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const list = optionsOf('vendor_confirmation_status');
  const options = list.length ? list : vendorConfirmationStatus.options();

  return (
    <Modal
      open
      onClose={onClose}
      title="Konfirmasi vendor"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Status konfirmasi" required error={errors.status?.[0]}>
          <Select value={status} onChange={(e) => setStatus(e.target.value)}>
            {options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Catatan" error={errors.notes?.[0]}>
          <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function CancelModal({ id, onClose }: { id: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [reason, setReason] = useState('');

  const simpan = useMutation({
    mutationFn: () => purchaseOrdersApi.cancel(id, reason),
    onSuccess: () => {
      toast.success('PO dibatalkan');
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
      title="Batalkan PO"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!reason}>
            Batalkan PO
          </Button>
        </div>
      }
    >
      <FormField label="Alasan pembatalan" required error={errors.reason?.[0]}>
        <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
