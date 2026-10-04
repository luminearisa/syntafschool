import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Search, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
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
import { invoicesApi } from '../api/procurementApi';
import { FormField, StatusBadge } from '../components/ProcurementUi';
import { exceptionStatus, exceptionType } from '../lib/labels';
import type { InvoiceException } from '../types';

/** Pengecualian pencocokan invoice (§43). */
export default function InvoiceExceptionListPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [aksi, setAksi] = useState<{ row: InvoiceException; mode: 'investigate' | 'resolve' } | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'invoice-exceptions', page, status, type],
    queryFn: () => invoicesApi.exceptions({ page, per_page: 20, status, type }),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Pengecualian Invoice</h1>
        <p className="text-xs text-slate-500">
          Setiap selisih yang tidak lolos toleransi muncul di sini dan harus diselesaikan sebelum invoice disetujui.
        </p>
      </header>

      <Card>
        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="max-w-[200px]"
          >
            <option value="">Semua status</option>
            {exceptionStatus.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select
            value={type}
            onChange={(e) => {
              setType(e.target.value);
              setPage(1);
            }}
            className="max-w-[220px]"
          >
            <option value="">Semua jenis</option>
            {exceptionType.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Tidak ada pengecualian" description="Semua invoice dalam toleransi." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((ex) => (
              <li key={ex.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge value={ex.type} map={exceptionType} />
                    <StatusBadge value={ex.status} map={exceptionStatus} />
                    {ex.invoice?.number && <span className="font-mono text-xs text-slate-500">{ex.invoice.number}</span>}
                  </div>
                  <p className="mt-0.5 text-[11px] text-slate-500">
                    {ex.detail ?? '-'}
                    {ex.detected_at && <> · terdeteksi {formatDate(ex.detected_at)}</>}
                  </p>
                  {ex.resolution && <p className="text-[11px] text-green-700">Penyelesaian: {ex.resolution}</p>}
                </div>
                <Can perm="procurement.match_invoice">
                  <div className="flex gap-1">
                    {['DETECTED'].includes(ex.status) && (
                      <Button size="sm" variant="outline" onClick={() => setAksi({ row: ex, mode: 'investigate' })}>
                        <Search className="mr-1 h-3.5 w-3.5" /> Selidiki
                      </Button>
                    )}
                    {['DETECTED', 'INVESTIGATION'].includes(ex.status) && (
                      <Button size="sm" onClick={() => setAksi({ row: ex, mode: 'resolve' })}>
                        <ShieldCheck className="mr-1 h-3.5 w-3.5" /> Selesaikan
                      </Button>
                    )}
                  </div>
                </Can>
              </li>
            ))}
          </ul>
        )}

        {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      {aksi && <ActionModal row={aksi.row} mode={aksi.mode} onClose={() => setAksi(null)} />}
    </div>
  );
}

function ActionModal({
  row,
  mode,
  onClose,
}: {
  row: InvoiceException;
  mode: 'investigate' | 'resolve';
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [text, setText] = useState('');
  const [status, setStatus] = useState('RESOLVED');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () =>
      mode === 'investigate'
        ? invoicesApi.investigateException(row.id, { notes: text })
        : invoicesApi.resolveException(row.id, { resolution: text, status }),
    onSuccess: () => {
      toast.success(mode === 'investigate' ? 'Penyelidikan dicatat' : 'Pengecualian diselesaikan');
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
      title={mode === 'investigate' ? 'Selidiki pengecualian' : 'Selesaikan pengecualian'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!text}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="mb-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
        <p className="font-medium text-slate-800">{exceptionType.label(row.type)}</p>
        <p className="text-xs text-slate-500">{row.detail ?? '-'}</p>
      </div>
      {mode === 'resolve' && (
        <FormField label="Keputusan" error={errors.status?.[0]}>
          <Select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="RESOLVED">Selesai (perbaiki & lanjut)</option>
            <option value="APPROVED">Disetujui (terima selisih)</option>
            <option value="REJECTED">Ditolak</option>
            <option value="CLOSED">Ditutup</option>
          </Select>
        </FormField>
      )}
      <FormField
        label={mode === 'investigate' ? 'Catatan penyelidikan' : 'Uraian penyelesaian'}
        required
        error={errors.notes?.[0] ?? errors.resolution?.[0]}
        className="mt-3"
      >
        <Textarea rows={3} value={text} onChange={(e) => setText(e.target.value)} />
      </FormField>
    </Modal>
  );
}
