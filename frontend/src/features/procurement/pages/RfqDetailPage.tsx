import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Award, CheckCircle2, Plus, Send, Star, XCircle } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { formatDate, formatRupiah } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { quotationsApi, rfqsApi, selectionsApi } from '../api/procurementApi';
import { DetailRow, FormField, SectionCard, StatusBadge, useVendorOptions } from '../components/ProcurementUi';
import { quotationStatus, rfqStatus } from '../lib/labels';
import type { Quotation } from '../types';

/** Detail RFQ: item, vendor, penawaran, perbandingan, evaluasi, dan pemilihan. */
export default function RfqDetailPage() {
  const { rfqId } = useParams();
  const id = Number(rfqId);
  const qc = useQueryClient();
  const [bukaPenawaran, setBukaPenawaran] = useState(false);
  const [bukaKriteria, setBukaKriteria] = useState(false);
  const [evaluasi, setEvaluasi] = useState<Quotation | null>(null);
  const [pilih, setPilih] = useState<Quotation | null>(null);

  const { data: rfq, isLoading } = useQuery({
    queryKey: ['procurement', 'rfq', id],
    queryFn: () => rfqsApi.show(id),
    enabled: Number.isFinite(id),
  });

  const { data: comparison } = useQuery({
    queryKey: ['procurement', 'rfq', id, 'comparison'],
    queryFn: () => quotationsApi.comparison(id),
    enabled: Number.isFinite(id),
    retry: false,
  });

  const { data: criteria } = useQuery({
    queryKey: ['procurement', 'rfq', id, 'criteria'],
    queryFn: () => quotationsApi.criteria(id),
    enabled: Number.isFinite(id),
    retry: false,
  });

  const { data: ranking } = useQuery({
    queryKey: ['procurement', 'rfq', id, 'ranking'],
    queryFn: () => quotationsApi.ranking(id),
    enabled: Number.isFinite(id),
    retry: false,
  });

  const aksi = useMutation({
    mutationFn: (langkah: 'issue' | 'close') => (langkah === 'issue' ? rfqsApi.issue(id) : rfqsApi.close(id)),
    onSuccess: () => {
      toast.success('Status RFQ diperbarui');
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

  if (!rfq) return <EmptyState title="RFQ tidak ditemukan" />;

  const quotations = rfq.quotations ?? [];

  return (
    <div className="space-y-4">
      <Link to="/procurement/rfqs" className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-brand-600">
        <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar RFQ
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-lg font-semibold text-slate-800">{rfq.title}</h1>
            <StatusBadge value={rfq.status} map={rfqStatus} />
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            <span className="font-mono">{rfq.number}</span>
            {rfq.due_date && <> · batas {formatDate(rfq.due_date)}</>}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Can perm="procurement.create_rfq">
            {['DRAFT'].includes(rfq.status) && (
              <Button size="sm" onClick={() => aksi.mutate('issue')} loading={aksi.isPending}>
                <Send className="mr-1 h-4 w-4" /> Terbitkan
              </Button>
            )}
            {['ISSUED', 'OPEN', 'PARTIALLY_RESPONDED'].includes(rfq.status) && (
              <Button size="sm" variant="outline" onClick={() => aksi.mutate('close')} loading={aksi.isPending}>
                Tutup RFQ
              </Button>
            )}
          </Can>
          <Can perm="procurement.manage_quotation">
            <Button size="sm" variant="outline" onClick={() => setBukaPenawaran(true)}>
              <Plus className="mr-1 h-4 w-4" /> Catat penawaran
            </Button>
          </Can>
        </div>
      </header>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard title="Ringkasan">
          <DetailRow label="Mata uang">{rfq.currency ?? 'IDR'}</DetailRow>
          <DetailRow label="Batas penawaran">{formatDate(rfq.due_date)}</DetailRow>
          <DetailRow label="Terbit">{formatDate(rfq.issued_at)}</DetailRow>
          <DetailRow label="Ditutup">{formatDate(rfq.closed_at)}</DetailRow>
        </SectionCard>

        <SectionCard title="Item yang diminta" className="lg:col-span-2">
          {(rfq.items ?? []).length === 0 ? (
            <EmptyState title="Belum ada item" />
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="py-2 font-medium">Deskripsi</th>
                  <th className="py-2 text-right font-medium">Jumlah</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rfq.items?.map((it) => (
                  <tr key={it.id}>
                    <td className="py-2 text-slate-800">{it.description}</td>
                    <td className="py-2 text-right tabular-nums text-slate-600">
                      {it.quantity} {it.unit ?? ''}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </SectionCard>
      </div>

      <SectionCard title="Vendor diundang">
        {(rfq.vendors ?? []).length === 0 ? (
          <EmptyState title="Belum ada vendor diundang" />
        ) : (
          <div className="flex flex-wrap gap-2">
            {rfq.vendors?.map((v) => (
              <Badge key={v.id} tone="blue">
                {v.vendor?.legal_name ?? `Vendor #${v.vendor_id}`}
              </Badge>
            ))}
          </div>
        )}
      </SectionCard>

      <SectionCard
        title="Penawaran masuk"
        action={
          <Can perm="procurement.evaluate_vendor">
            <Button size="sm" variant="outline" onClick={() => setBukaKriteria(true)}>
              Kriteria evaluasi
            </Button>
          </Can>
        }
      >
        {quotations.length === 0 ? (
          <EmptyState title="Belum ada penawaran" description="Catat penawaran vendor yang masuk." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="py-2 font-medium">Vendor</th>
                  <th className="py-2 font-medium">Tanggal</th>
                  <th className="py-2 text-right font-medium">Total</th>
                  <th className="py-2 text-right font-medium">Kirim (hari)</th>
                  <th className="py-2 font-medium">Status</th>
                  <th className="py-2 text-right font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {quotations.map((q) => (
                  <tr key={q.id}>
                    <td className="py-2 text-slate-800">{q.vendor?.legal_name ?? `Vendor #${q.vendor_id}`}</td>
                    <td className="py-2 text-slate-600">{formatDate(q.quotation_date)}</td>
                    <td className="py-2 text-right tabular-nums text-slate-700">{formatRupiah(q.total ?? 0)}</td>
                    <td className="py-2 text-right tabular-nums text-slate-600">{q.delivery_days ?? '-'}</td>
                    <td className="py-2">
                      <StatusBadge value={q.status} map={quotationStatus} />
                    </td>
                    <td className="py-2 text-right">
                      <div className="flex justify-end gap-1">
                        <Can perm="procurement.evaluate_vendor">
                          <Button size="sm" variant="ghost" onClick={() => setEvaluasi(q)}>
                            <Star className="mr-1 h-3.5 w-3.5" /> Evaluasi
                          </Button>
                        </Can>
                        <Can perm="procurement.evaluate_vendor">
                          <Button size="sm" variant="outline" onClick={() => setPilih(q)}>
                            <Award className="mr-1 h-3.5 w-3.5" /> Pilih
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </SectionCard>

      {(criteria ?? []).length > 0 && (
        <SectionCard title="Kriteria evaluasi">
          <div className="flex flex-wrap gap-2">
            {criteria?.map((c) => (
              <Badge key={c.id} tone="gray">
                {c.name} · bobot {c.weight}
              </Badge>
            ))}
          </div>
        </SectionCard>
      )}

      {comparison && (comparison.vendors ?? []).length > 0 && (
        <SectionCard title="Matriks perbandingan" description="Nilai per vendor, tanpa peringkat otomatis — keputusan tetap di rapat pengadaan.">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="py-2 font-medium">Vendor</th>
                  <th className="py-2 text-right font-medium">Total penawaran</th>
                  <th className="py-2 font-medium">Terendah</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {comparison.vendors.map((v) => (
                  <tr key={v.vendor_id}>
                    <td className="py-2 text-slate-800">{v.vendor ?? `Vendor #${v.vendor_id}`}</td>
                    <td className="py-2 text-right tabular-nums text-slate-700">{formatRupiah(v.total ?? 0)}</td>
                    <td className="py-2">
                      {String(v.total ?? '') === String(comparison.lowest_total ?? '') ? (
                        <Badge tone="green">Terendah</Badge>
                      ) : (
                        '-'
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>
      )}

      {(ranking ?? []).length > 0 && (
        <SectionCard title="Peringkat evaluasi">
          <ol className="space-y-2">
            {(ranking ?? []).map((r, i) => {
              const row = r as Record<string, unknown>;
              return (
                <li key={i} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm">
                  <span className="text-slate-700">
                    #{i + 1} {String(row.vendor ?? row.vendor_name ?? `Vendor #${row.vendor_id ?? '?'}`)}
                  </span>
                  <Badge tone="brand">Skor {String(row.weighted_score ?? row.score ?? '-')}</Badge>
                </li>
              );
            })}
          </ol>
        </SectionCard>
      )}

      {bukaPenawaran && <QuotationModal rfqId={id} onClose={() => setBukaPenawaran(false)} />}
      {bukaKriteria && <CriteriaModal rfqId={id} onClose={() => setBukaKriteria(false)} />}
      {evaluasi && <EvaluateModal quotation={evaluasi} onClose={() => setEvaluasi(null)} />}
      {pilih && <SelectVendorModal rfqId={id} quotation={pilih} onClose={() => setPilih(null)} />}
    </div>
  );
}

function QuotationModal({ rfqId, onClose }: { rfqId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: vendorOptions } = useVendorOptions();
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data: rfq } = useQuery({
    queryKey: ['procurement', 'rfq', rfqId],
    queryFn: () => rfqsApi.show(rfqId),
  });

  const [form, setForm] = useState({
    vendor_id: '' as number | '',
    quotation_date: new Date().toISOString().slice(0, 10),
    valid_until: '',
    currency: 'IDR',
    payment_terms: 'NET 30',
    delivery_days: '14',
  });
  const [prices, setPrices] = useState<Record<number, string>>({});

  const total = (rfq?.items ?? []).reduce(
    (acc, it) => acc + Number(it.quantity || 0) * Number(prices[it.id] || 0),
    0,
  );

  const simpan = useMutation({
    mutationFn: () =>
      quotationsApi.create({
        rfq_id: rfqId,
        vendor_id: Number(form.vendor_id),
        quotation_date: form.quotation_date,
        valid_until: form.valid_until || null,
        currency: form.currency,
        payment_terms: form.payment_terms,
        delivery_days: Number(form.delivery_days || 0),
        items: (rfq?.items ?? []).map((it) => ({
          rfq_item_id: it.id,
          description: it.description,
          quantity: Number(it.quantity),
          unit: it.unit,
          unit_price: Number(prices[it.id] || 0),
        })),
      }),
    onSuccess: () => {
      toast.success('Penawaran dicatat');
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
      title="Catat penawaran vendor"
      size="lg"
      footer={
        <div className="flex items-center justify-between gap-2">
          <span className="text-sm text-slate-600">
            Total: <strong className="tabular-nums">{formatRupiah(total)}</strong>
          </span>
          <div className="flex gap-2">
            <Button variant="ghost" onClick={onClose}>
              Batal
            </Button>
            <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={form.vendor_id === ''}>
              Simpan
            </Button>
          </div>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Vendor" required error={errors.vendor_id?.[0]}>
          <Select value={form.vendor_id} onChange={(e) => setForm({ ...form, vendor_id: e.target.value === '' ? '' : Number(e.target.value) })}>
            <option value="">— Pilih vendor —</option>
            {vendorOptions.map((v) => (
              <option key={v.value} value={v.value}>
                {v.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tanggal penawaran" error={errors.quotation_date?.[0]}>
          <Input type="date" value={form.quotation_date} onChange={(e) => setForm({ ...form, quotation_date: e.target.value })} />
        </FormField>
        <FormField label="Berlaku sampai" error={errors.valid_until?.[0]}>
          <Input type="date" value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} />
        </FormField>
        <FormField label="Termin pembayaran" error={errors.payment_terms?.[0]}>
          <Input value={form.payment_terms} onChange={(e) => setForm({ ...form, payment_terms: e.target.value })} />
        </FormField>
        <FormField label="Lama kirim (hari)" error={errors.delivery_days?.[0]}>
          <Input type="number" value={form.delivery_days} onChange={(e) => setForm({ ...form, delivery_days: e.target.value })} />
        </FormField>
        <FormField label="Mata uang">
          <Input value={form.currency} maxLength={3} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} />
        </FormField>
      </div>

      <div className="mt-4">
        <h3 className="mb-2 text-sm font-semibold text-slate-700">Harga satuan per item</h3>
        <div className="space-y-2">
          {(rfq?.items ?? []).map((it) => (
            <div key={it.id} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
              <div className="min-w-0">
                <p className="text-sm text-slate-800">{it.description}</p>
                <p className="text-[11px] text-slate-500">
                  {it.quantity} {it.unit ?? ''}
                </p>
              </div>
              <Input
                type="number"
                className="max-w-[160px]"
                placeholder="Harga satuan"
                value={prices[it.id] ?? ''}
                onChange={(e) => setPrices((p) => ({ ...p, [it.id]: e.target.value }))}
              />
            </div>
          ))}
        </div>
      </div>
    </Modal>
  );
}

function CriteriaModal({ rfqId, onClose }: { rfqId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [rows, setRows] = useState([
    { code: 'PRICE', name: 'Harga', weight: '40' },
    { code: 'TECHNICAL', name: 'Teknis', weight: '25' },
    { code: 'DELIVERY', name: 'Pengiriman', weight: '15' },
    { code: 'QUALITY', name: 'Kualitas', weight: '10' },
    { code: 'WARRANTY', name: 'Garansi', weight: '5' },
    { code: 'PERFORMANCE', name: 'Kinerja', weight: '5' },
  ]);

  const totalBobot = rows.reduce((a, r) => a + Number(r.weight || 0), 0);

  const simpan = useMutation({
    mutationFn: () =>
      quotationsApi.defineCriteria(rfqId, {
        criteria: rows.map((r) => ({ code: r.code, name: r.name, weight: Number(r.weight) })),
      }),
    onSuccess: () => {
      toast.success('Kriteria disimpan');
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
      title="Kriteria evaluasi"
      footer={
        <div className="flex items-center justify-between gap-2">
          <span className={totalBobot === 100 ? 'text-xs text-green-700' : 'text-xs text-amber-700'}>
            Total bobot: {totalBobot}
          </span>
          <div className="flex gap-2">
            <Button variant="ghost" onClick={onClose}>
              Batal
            </Button>
            <Button onClick={() => simpan.mutate()} loading={simpan.isPending}>
              Simpan
            </Button>
          </div>
        </div>
      }
    >
      <div className="space-y-2">
        {rows.map((r, i) => (
          <div key={r.code} className="flex items-center gap-2">
            <Input
              value={r.name}
              onChange={(e) => setRows((p) => p.map((x, idx) => (idx === i ? { ...x, name: e.target.value } : x)))}
            />
            <Input
              type="number"
              className="max-w-[110px]"
              value={r.weight}
              onChange={(e) => setRows((p) => p.map((x, idx) => (idx === i ? { ...x, weight: e.target.value } : x)))}
            />
          </div>
        ))}
        {errors['criteria'] && <p className="text-[11px] text-red-600">{errors['criteria'][0]}</p>}
      </div>
    </Modal>
  );
}

function EvaluateModal({ quotation, onClose }: { quotation: Quotation; onClose: () => void }) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [recommendation, setRecommendation] = useState('');
  const [scores, setScores] = useState<Record<string, string>>({
    PRICE: '80',
    TECHNICAL: '80',
    DELIVERY: '80',
    QUALITY: '80',
    WARRANTY: '80',
    PERFORMANCE: '80',
  });

  const simpan = useMutation({
    mutationFn: () =>
      quotationsApi.evaluate(quotation.id, {
        recommendation,
        scores: Object.entries(scores).map(([code, score]) => ({ code, score: Number(score) })),
      }),
    onSuccess: () => {
      toast.success('Evaluasi tersimpan');
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
      title={`Evaluasi — ${quotation.vendor?.legal_name ?? `Vendor #${quotation.vendor_id}`}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending}>
            Simpan evaluasi
          </Button>
        </div>
      }
    >
      <div className="space-y-2">
        {Object.keys(scores).map((code) => (
          <div key={code} className="flex items-center justify-between gap-3">
            <span className="text-sm text-slate-700">{code}</span>
            <Input
              type="number"
              className="max-w-[140px]"
              value={scores[code]}
              onChange={(e) => setScores((p) => ({ ...p, [code]: e.target.value }))}
            />
          </div>
        ))}
        {errors['scores'] && <p className="text-[11px] text-red-600">{errors['scores'][0]}</p>}
        <FormField label="Rekomendasi" error={errors.recommendation?.[0]}>
          <Textarea rows={2} value={recommendation} onChange={(e) => setRecommendation(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function SelectVendorModal({ rfqId, quotation, onClose }: { rfqId: number; quotation: Quotation; onClose: () => void }) {
  const qc = useQueryClient();
  const [rationale, setRationale] = useState('');
  const [recommendation, setRecommendation] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () => selectionsApi.select(rfqId, { quotation_id: quotation.id, rationale, recommendation }),
    onSuccess: () => {
      toast.success('Pemilihan vendor dibuat — menunggu persetujuan');
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
      title="Pilih vendor pemenang"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!rationale}>
            <CheckCircle2 className="mr-1 h-4 w-4" /> Pilih
          </Button>
        </div>
      }
    >
      <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
        <p className="font-medium text-slate-800">{quotation.vendor?.legal_name ?? `Vendor #${quotation.vendor_id}`}</p>
        <p className="text-xs text-slate-500">Total penawaran: {formatRupiah(quotation.total ?? 0)}</p>
      </div>
      <FormField label="Alasan pemilihan" required error={errors.rationale?.[0]} className="mt-3">
        <Textarea rows={3} value={rationale} onChange={(e) => setRationale(e.target.value)} />
      </FormField>
      <FormField label="Rekomendasi" error={errors.recommendation?.[0]} className="mt-3">
        <Input value={recommendation} onChange={(e) => setRecommendation(e.target.value)} />
      </FormField>
      <p className="mt-3 flex items-center gap-1 text-[11px] text-slate-500">
        <XCircle className="h-3 w-3" /> Pemilihan belum sah sebelum disetujui pemegang izin.
      </p>
    </Modal>
  );
}
