import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardCheck } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { formatDate } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { receivingApi } from '../api/procurementApi';
import { FormField, StatusBadge } from '../components/ProcurementUi';
import { receiptStatus } from '../lib/labels';

/** Kolom bersama yang dipakai daftar, apa pun jenis penerimaannya. */
interface ReceiptRow {
  id: number;
  number: string;
  status: string;
  receipt_date?: string | null;
  notes?: string | null;
  purchase_order?: { number?: string | null } | null;
}

/** Penerimaan barang & jasa (§31–§36). */
export default function ReceivingListPage() {
  const [page, setPage] = useState(1);
  const [tab, setTab] = useState<'goods' | 'service'>('goods');
  const [inspeksi, setInspeksi] = useState<ReceiptRow | null>(null);

  const goods = useQuery({
    queryKey: ['procurement', 'goods-receipts', page],
    queryFn: () => receivingApi.list({ page, per_page: 20 }),
    enabled: tab === 'goods',
  });

  const services = useQuery({
    queryKey: ['procurement', 'service-receipts', page],
    queryFn: () => receivingApi.serviceReceipts({ page, per_page: 20 }),
    enabled: tab === 'service',
  });

  // Dua sumber (barang/jasa) punya bentuk item berbeda; baris daftar hanya
  // memakai kolom bersama, jadi disempitkan ke bentuk ringkas yang sama.
  const rows = ((tab === 'goods' ? goods.data?.data : services.data?.data) ?? []) as unknown as ReceiptRow[];
  const meta = tab === 'goods' ? goods.data?.meta : services.data?.meta;
  const isLoading = tab === 'goods' ? goods.isLoading : services.isLoading;

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Penerimaan</h1>
        <p className="text-xs text-slate-500">
          Penerimaan menggerakkan stok/aset lewat kontrak modul lain — pengadaan tidak menulis kartu stok sendiri.
        </p>
      </header>

      <div className="flex gap-1 border-b border-slate-200">
        <button
          type="button"
          onClick={() => setTab('goods')}
          className={
            tab === 'goods'
              ? 'border-b-2 border-brand-600 px-4 py-2 text-sm font-medium text-brand-600'
              : 'border-b-2 border-transparent px-4 py-2 text-sm text-slate-500 hover:text-slate-700'
          }
        >
          Barang
        </button>
        <button
          type="button"
          onClick={() => setTab('service')}
          className={
            tab === 'service'
              ? 'border-b-2 border-brand-600 px-4 py-2 text-sm font-medium text-brand-600'
              : 'border-b-2 border-transparent px-4 py-2 text-sm text-slate-500 hover:text-slate-700'
          }
        >
          Jasa
        </button>
      </div>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            title={tab === 'goods' ? 'Belum ada penerimaan barang' : 'Belum ada penerimaan jasa'}
            description="Penerimaan dicatat dari halaman PO yang berstatus terbit/dikonfirmasi."
          />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((row) => (
              <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-500">{row.number}</span>
                    <StatusBadge value={row.status} map={receiptStatus} />
                    {row.purchase_order?.number && <Badge tone="gray">PO {row.purchase_order.number}</Badge>}
                  </div>
                  <p className="mt-0.5 text-[11px] text-slate-500">
                    {formatDate(row.receipt_date)}
                    {row.notes && <> · {row.notes}</>}
                  </p>
                </div>
                <Can perm="procurement.inspect">
                  {tab === 'goods' && row.status === 'RECEIVED' && (
                    <Button size="sm" variant="outline" onClick={() => setInspeksi(row)}>
                      <ClipboardCheck className="mr-1 h-3.5 w-3.5" /> Inspeksi
                    </Button>
                  )}
                </Can>
              </li>
            ))}
          </ul>
        )}

        {meta && <Pagination meta={meta} onPageChange={setPage} />}
      </Card>

      {inspeksi && <InspectModal receipt={inspeksi} onClose={() => setInspeksi(null)} />}
    </div>
  );
}

function InspectModal({ receipt, onClose }: { receipt: { id: number; number: string }; onClose: () => void }) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [status, setStatus] = useState('ACCEPTED');
  const [notes, setNotes] = useState('');

  const simpan = useMutation({
    mutationFn: () => receivingApi.inspect(receipt.id, { status, notes }),
    onSuccess: () => {
      toast.success('Hasil inspeksi dicatat');
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
      title={`Inspeksi — ${receipt.number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending}>
            Simpan inspeksi
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Hasil inspeksi" required error={errors.status?.[0]}>
          <Select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="ACCEPTED">Diterima baik</option>
            <option value="PARTIALLY_ACCEPTED">Diterima sebagian</option>
            <option value="REJECTED">Ditolak</option>
          </Select>
        </FormField>
        <FormField label="Catatan" error={errors.notes?.[0]}>
          <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}
