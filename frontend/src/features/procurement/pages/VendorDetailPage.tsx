import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, FilePlus2 } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { formatDate } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { vendorsApi } from '../api/procurementApi';
import { DetailRow, FormField, SectionCard, StatusBadge, useEnumMetadata } from '../components/ProcurementUi';
import {
  vendorClassification,
  vendorDocumentStatus,
  vendorDocumentType,
  vendorRisk,
  vendorStatus,
  vendorType,
} from '../lib/labels';
import type { VendorDocument } from '../types';

/** Detail vendor: identitas, kontak, rekening, dokumen, dan kinerja. */
export default function VendorDetailPage() {
  const { vendorId } = useParams();
  const id = Number(vendorId);
  const qc = useQueryClient();
  const [bukaDokumen, setBukaDokumen] = useState(false);
  const [bukaKlasifikasi, setBukaKlasifikasi] = useState(false);
  const [bulan, setBulan] = useState(() => new Date().toISOString().slice(0, 7));

  const { data: vendor, isLoading } = useQuery({
    queryKey: ['procurement', 'vendor', id],
    queryFn: () => vendorsApi.show(id),
    enabled: Number.isFinite(id),
  });

  const { data: scorecard } = useQuery({
    queryKey: ['procurement', 'vendor', id, 'performance', bulan],
    queryFn: () => vendorsApi.performance(id, bulan),
    enabled: Number.isFinite(id),
    retry: false,
  });

  const verifikasiDokumen = useMutation({
    mutationFn: (documentId: number) => vendorsApi.verifyDocument(documentId),
    onSuccess: () => {
      toast.success('Dokumen diverifikasi');
      qc.invalidateQueries({ queryKey: ['procurement', 'vendor', id] });
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

  if (!vendor) {
    return <EmptyState title="Vendor tidak ditemukan" />;
  }

  return (
    <div className="space-y-4">
      <Link to="/procurement/vendors" className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-brand-600">
        <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar vendor
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-lg font-semibold text-slate-800">{vendor.legal_name}</h1>
            <StatusBadge value={vendor.status} map={vendorStatus} />
            {vendor.classification && <StatusBadge value={vendor.classification} map={vendorClassification} />}
            {vendor.risk_level && <StatusBadge value={vendor.risk_level} map={vendorRisk} />}
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            <span className="font-mono">{vendor.code}</span>
            {vendor.vendor_type && <> · {vendorType.label(vendor.vendor_type)}</>}
            {vendor.tier && <> · {vendor.tier.replace(/_/g, ' ')}</>}
          </p>
        </div>
        <Can perm="procurement.manage_vendor">
          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={() => setBukaKlasifikasi(true)}>
              Klasifikasi
            </Button>
            <Button size="sm" onClick={() => setBukaDokumen(true)}>
              <FilePlus2 className="mr-1 h-4 w-4" /> Tambah dokumen
            </Button>
          </div>
        </Can>
      </header>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard title="Identitas & pajak" className="lg:col-span-2">
          <div className="grid grid-cols-2 gap-x-6 sm:grid-cols-3">
            <DetailRow label="Nama dagang">{vendor.trading_name ?? '-'}</DetailRow>
            <DetailRow label="NPWP">{vendor.npwp ?? '-'}</DetailRow>
            <DetailRow label="NIB">{vendor.nib ?? '-'}</DetailRow>
            <DetailRow label="Nama pajak">{vendor.tax_name ?? '-'}</DetailRow>
            <DetailRow label="Tarif pajak">{vendor.tax_rate != null ? `${vendor.tax_rate}%` : '-'}</DetailRow>
            <DetailRow label="Mata uang">{vendor.currency ?? '-'}</DetailRow>
            <DetailRow label="Termin bayar">{vendor.payment_terms ?? '-'}</DetailRow>
            <DetailRow label="Kontak person">{vendor.contact_person ?? '-'}</DetailRow>
            <DetailRow label="Email">{vendor.email ?? '-'}</DetailRow>
            <DetailRow label="Telepon">{vendor.phone ?? '-'}</DetailRow>
            <DetailRow label="Website">{vendor.website ?? '-'}</DetailRow>
            <DetailRow label="Terdaftar">{formatDate(vendor.created_at)}</DetailRow>
          </div>
          <DetailRow label="Alamat" className="mt-2">
            {[vendor.address, vendor.city, vendor.province, vendor.postal_code, vendor.country]
              .filter(Boolean)
              .join(', ') || '-'}
          </DetailRow>
          {vendor.notes && <DetailRow label="Catatan">{vendor.notes}</DetailRow>}
        </SectionCard>

        <SectionCard title="Kinerja vendor" description={`Scorecard periode ${bulan}`}>
          <FormField label="Periode">
            <Input type="month" value={bulan} onChange={(e) => setBulan(e.target.value)} />
          </FormField>
          {scorecard ? (
            <dl className="mt-3 space-y-2 text-sm">
              <div className="flex items-center justify-between">
                <dt className="text-slate-500">Skor keseluruhan</dt>
                <dd className="font-semibold tabular-nums text-slate-800">
                  {String(scorecard.overall_score ?? '-')}
                </dd>
              </div>
              {scorecard.grade && (
                <div className="flex items-center justify-between">
                  <dt className="text-slate-500">Grade</dt>
                  <dd>
                    <Badge tone="brand">{scorecard.grade}</Badge>
                  </dd>
                </div>
              )}
              {Object.entries(scorecard.components ?? {}).map(([k, v]) => (
                <div key={k} className="flex items-center justify-between">
                  <dt className="capitalize text-slate-500">{k.replace(/_/g, ' ')}</dt>
                  <dd className="tabular-nums text-slate-700">{String(v)}</dd>
                </div>
              ))}
            </dl>
          ) : (
            <p className="mt-3 text-xs text-slate-500">Belum ada scorecard untuk periode ini.</p>
          )}
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SectionCard title="Kontak">
          {(vendor.contacts ?? []).length === 0 ? (
            <EmptyState title="Belum ada kontak" />
          ) : (
            <ul className="divide-y divide-slate-100">
              {vendor.contacts?.map((c) => (
                <li key={c.id} className="flex items-center justify-between py-2 text-sm">
                  <span className="text-slate-800">
                    {c.name}
                    {c.position && <span className="text-slate-500"> · {c.position}</span>}
                  </span>
                  <span className="text-[11px] text-slate-500">
                    {c.email ?? ''} {c.phone ? `· ${c.phone}` : ''}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>

        <SectionCard title="Rekening bank">
          {(vendor.bankAccounts ?? []).length === 0 ? (
            <EmptyState title="Belum ada rekening" />
          ) : (
            <ul className="divide-y divide-slate-100">
              {vendor.bankAccounts?.map((b) => (
                <li key={b.id} className="py-2 text-sm">
                  <p className="font-medium text-slate-800">{b.bank_name}</p>
                  <p className="text-[11px] text-slate-500">
                    {b.account_number} · {b.account_name}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      </div>

      <SectionCard title="Dokumen" description="Dokumen wajib harus terverifikasi sebelum vendor dapat dipakai.">
        {(vendor.documents ?? []).length === 0 ? (
          <EmptyState title="Belum ada dokumen" />
        ) : (
          <ul className="divide-y divide-slate-100">
            {vendor.documents?.map((d) => (
              <DocumentRow
                key={d.id}
                document={d}
                onVerify={() => verifikasiDokumen.mutate(d.id)}
                verifying={verifikasiDokumen.isPending}
              />
            ))}
          </ul>
        )}
      </SectionCard>

      {bukaDokumen && <DocumentModal vendorId={id} onClose={() => setBukaDokumen(false)} />}
      {bukaKlasifikasi && <ClassifyModal vendorId={id} onClose={() => setBukaKlasifikasi(false)} />}
    </div>
  );
}

function DocumentRow({
  document: d,
  onVerify,
  verifying,
}: {
  document: VendorDocument;
  onVerify: () => void;
  verifying: boolean;
}) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-2.5">
      <div>
        <div className="flex items-center gap-2">
          <span className="text-sm text-slate-800">{d.name}</span>
          <StatusBadge value={d.status} map={vendorDocumentStatus} />
          {d.is_mandatory && <Badge tone="amber">Wajib</Badge>}
        </div>
        <p className="text-[11px] text-slate-500">
          {vendorDocumentType.label(d.type)}
          {d.number && <> · {d.number}</>}
          {d.expiry_date && <> · berlaku s/d {formatDate(d.expiry_date)}</>}
        </p>
      </div>
      {d.status !== 'VERIFIED' && (
        <Can perm="procurement.manage_vendor">
          <Button size="sm" variant="outline" onClick={onVerify} loading={verifying}>
            Verifikasi
          </Button>
        </Can>
      )}
    </li>
  );
}

function DocumentModal({ vendorId, onClose }: { vendorId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const { optionsOf } = useEnumMetadata();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    type: 'LEGAL',
    name: '',
    number: '',
    issued_date: '',
    expiry_date: '',
    is_mandatory: true,
    notes: '',
  });

  const set = (k: keyof typeof form, v: string | boolean) => setForm((p) => ({ ...p, [k]: v }));

  const simpan = useMutation({
    mutationFn: () =>
      vendorsApi.addDocument(vendorId, {
        ...form,
        issued_date: form.issued_date || null,
        expiry_date: form.expiry_date || null,
      }),
    onSuccess: () => {
      toast.success('Dokumen ditambahkan');
      qc.invalidateQueries({ queryKey: ['procurement', 'vendor', vendorId] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const typeList = optionsOf('vendor_document_type');
  const types = typeList.length ? typeList : vendorDocumentType.options();

  return (
    <Modal
      open
      onClose={onClose}
      title="Tambah dokumen vendor"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!form.name}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Jenis" required error={errors.type?.[0]}>
          <Select value={form.type} onChange={(e) => set('type', e.target.value)}>
            {types.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Nama dokumen" required error={errors.name?.[0]}>
          <Input value={form.name} onChange={(e) => set('name', e.target.value)} />
        </FormField>
        <FormField label="Nomor" error={errors.number?.[0]}>
          <Input value={form.number} onChange={(e) => set('number', e.target.value)} />
        </FormField>
        <FormField label="Tanggal terbit" error={errors.issued_date?.[0]}>
          <Input type="date" value={form.issued_date} onChange={(e) => set('issued_date', e.target.value)} />
        </FormField>
        <FormField label="Berlaku sampai" error={errors.expiry_date?.[0]}>
          <Input type="date" value={form.expiry_date} onChange={(e) => set('expiry_date', e.target.value)} />
        </FormField>
        <FormField label="Wajib?">
          <Select value={form.is_mandatory ? '1' : '0'} onChange={(e) => set('is_mandatory', e.target.value === '1')}>
            <option value="1">Wajib</option>
            <option value="0">Opsional</option>
          </Select>
        </FormField>
      </div>
    </Modal>
  );
}

function ClassifyModal({ vendorId, onClose }: { vendorId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const { optionsOf } = useEnumMetadata();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({ classification: 'NORMAL', tier: 'TIER_2', risk_level: 'LOW' });

  const simpan = useMutation({
    mutationFn: () => vendorsApi.classify(vendorId, form),
    onSuccess: () => {
      toast.success('Klasifikasi diperbarui');
      qc.invalidateQueries({ queryKey: ['procurement', 'vendor', vendorId] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const list = (key: string, fallback: { value: string | number; label: string }[]) => {
    const o = optionsOf(key);
    return o.length ? o : fallback;
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Klasifikasi vendor"
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
        <FormField label="Klasifikasi" required error={errors.classification?.[0]}>
          <Select value={form.classification} onChange={(e) => setForm({ ...form, classification: e.target.value })}>
            {list('vendor_classification', vendorClassification.options()).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tier" error={errors.tier?.[0]}>
          <Select value={form.tier} onChange={(e) => setForm({ ...form, tier: e.target.value })}>
            <option value="">—</option>
            <option value="TIER_1">Tier 1</option>
            <option value="TIER_2">Tier 2</option>
            <option value="TIER_3">Tier 3</option>
          </Select>
        </FormField>
        <FormField label="Tingkat risiko" error={errors.risk_level?.[0]}>
          <Select value={form.risk_level} onChange={(e) => setForm({ ...form, risk_level: e.target.value })}>
            <option value="">—</option>
            <option value="LOW">Rendah</option>
            <option value="MEDIUM">Sedang</option>
            <option value="HIGH">Tinggi</option>
          </Select>
        </FormField>
      </div>
    </Modal>
  );
}
