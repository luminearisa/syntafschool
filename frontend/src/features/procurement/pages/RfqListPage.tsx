import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Send } from 'lucide-react';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { formatDate } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { purchaseRequestsApi, rfqsApi } from '../api/procurementApi';
import { FormField, StatusBadge, useVendorOptions } from '../components/ProcurementUi';
import { rfqStatus } from '../lib/labels';

/** Daftar RFQ — permintaan penawaran ke vendor (§12–§15). */
export default function RfqListPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [buka, setBuka] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'rfqs', page, search, status],
    queryFn: () => rfqsApi.list({ page, per_page: 20, search, status }),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Permintaan Penawaran (RFQ)</h1>
          <p className="text-xs text-slate-500">
            RFQ mengundang vendor menawar; perbandingan dan evaluasi dilakukan pada dokumen ini.
          </p>
        </div>
        <Can perm="procurement.create_rfq">
          <Button size="sm" onClick={() => setBuka(true)}>
            <Plus className="mr-1 h-4 w-4" /> Buat RFQ
          </Button>
        </Can>
      </header>

      <Card>
        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
          <Input
            placeholder="Cari judul / nomor…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="max-w-xs"
          />
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="max-w-[200px]"
          >
            <option value="">Semua status</option>
            {rfqStatus.options().map((o) => (
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
          <EmptyState title="Belum ada RFQ" description="Buat RFQ dari peristiwa pengadaan atau langsung." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((rfq) => (
              <li key={rfq.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <Link
                      to={`/procurement/rfqs/${rfq.id}`}
                      className="text-sm font-medium text-slate-800 hover:text-brand-600 hover:underline"
                    >
                      {rfq.title}
                    </Link>
                    <StatusBadge value={rfq.status} map={rfqStatus} />
                  </div>
                  <p className="mt-0.5 text-[11px] text-slate-500">
                    <span className="font-mono">{rfq.number}</span>
                    {rfq.due_date && <> · batas {formatDate(rfq.due_date)}</>}
                    {rfq.issued_at && <> · terbit {formatDate(rfq.issued_at)}</>}
                  </p>
                </div>
                <Link to={`/procurement/rfqs/${rfq.id}`}>
                  <Button size="sm" variant="outline">
                    Buka
                  </Button>
                </Link>
              </li>
            ))}
          </ul>
        )}

        {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      {buka && <RfqFormModal onClose={() => setBuka(false)} />}
    </div>
  );
}

function RfqFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const { options: vendorOptions } = useVendorOptions();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({ title: '', currency: 'IDR', due_date: '', notes: '' });
  const [vendorIds, setVendorIds] = useState<number[]>([]);
  const [mode, setMode] = useState<'event' | 'manual'>('event');
  const [eventId, setEventId] = useState<number | ''>('');

  const { data: approvedPr } = useQuery({
    queryKey: ['procurement', 'purchase-requests', 'approved'],
    queryFn: () => purchaseRequestsApi.list({ status: 'APPROVED', per_page: 50 }),
  });

  const simpan = useMutation({
    mutationFn: () =>
      mode === 'event'
        ? rfqsApi.createFromEvent(Number(eventId), { title: form.title, currency: form.currency, due_date: form.due_date || null, vendor_ids: vendorIds })
        : rfqsApi.create({ ...form, due_date: form.due_date || null, vendors: vendorIds }),
    onSuccess: () => {
      toast.success('RFQ dibuat');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const rows = approvedPr?.data ?? [];

  return (
    <Modal
      open
      onClose={onClose}
      title="Buat RFQ"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button
            onClick={() => simpan.mutate()}
            loading={simpan.isPending}
            disabled={!form.title || vendorIds.length === 0 || (mode === 'event' && eventId === '')}
          >
            Simpan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Sumber">
          <Select value={mode} onChange={(e) => setMode(e.target.value as 'event' | 'manual')}>
            <option value="event">Dari PR yang disetujui</option>
            <option value="manual">Buat mandiri</option>
          </Select>
        </FormField>

        {mode === 'event' && (
          <FormField label="PR yang disetujui" required error={errors.event_id?.[0]}>
            <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
              {rows.length === 0 ? (
                <p className="p-2 text-xs text-slate-500">Tidak ada PR berstatus disetujui.</p>
              ) : (
                rows.map((pr) => (
                  <label key={pr.id} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                    <input type="radio" name="pr" checked={eventId === pr.id} onChange={() => setEventId(pr.id)} />
                    <span className="font-mono text-xs text-slate-500">{pr.number}</span>
                    <span className="text-slate-700">{pr.purpose ?? '-'}</span>
                  </label>
                ))
              )}
            </div>
          </FormField>
        )}

        <FormField label="Judul RFQ" required error={errors.title?.[0]}>
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </FormField>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField label="Batas penawaran" error={errors.due_date?.[0]}>
            <Input type="date" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} />
          </FormField>
          <FormField label="Mata uang">
            <Input value={form.currency} maxLength={3} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} />
          </FormField>
        </div>

        <FormField label="Vendor yang diundang" required error={errors.vendor_ids?.[0]}>
          <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
            {vendorOptions.length === 0 ? (
              <p className="p-2 text-xs text-slate-500">Belum ada vendor aktif.</p>
            ) : (
              vendorOptions.map((v) => (
                <label key={v.value} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                  <input
                    type="checkbox"
                    checked={vendorIds.includes(Number(v.value))}
                    onChange={(e) =>
                      setVendorIds((prev) => (e.target.checked ? [...prev, Number(v.value)] : prev.filter((x) => x !== Number(v.value))))
                    }
                  />
                  <span className="text-slate-700">{v.label}</span>
                </label>
              ))
            )}
          </div>
        </FormField>

        <FormField label="Catatan" error={errors.notes?.[0]}>
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>

        <p className="flex items-center gap-1 text-[11px] text-slate-500">
          <Send className="h-3 w-3" /> RFQ dibuat sebagai draf; terbitkan setelah vendor diundang.
        </p>
      </div>
    </Modal>
  );
}
