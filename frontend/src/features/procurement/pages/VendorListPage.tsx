import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Plus, ShieldCheck, UserCheck, XCircle } from 'lucide-react';
import { Link } from 'react-router-dom';
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
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { vendorsApi } from '../api/procurementApi';
import { FormField, StatusBadge, useEnumMetadata } from '../components/ProcurementUi';
import { vendorClassification, vendorStatus, vendorType } from '../lib/labels';
import type { Vendor } from '../types';

/**
 * Daftar vendor & onboarding.
 *
 * Vendor belum bisa dipakai pada PO sebelum melewati verifikasi → aktif. Tombol
 * yang tampil mengikuti langkah berikutnya, bukan seluruh kemungkinan, agar
 * petugas tidak menebak urutannya.
 */
export default function VendorListPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [bukaBuat, setBukaBuat] = useState(false);
  const [tolak, setTolak] = useState<Vendor | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'vendors', page, search, status],
    queryFn: () => vendorsApi.list({ page, per_page: 20, search, status }),
  });

  const langkah = useMutation({
    mutationFn: ({ id, aksi }: { id: number; aksi: 'submit' | 'verify' | 'approve' | 'deactivate' }) => {
      if (aksi === 'submit') return vendorsApi.submit(id);
      if (aksi === 'verify') return vendorsApi.verify(id);
      if (aksi === 'approve') return vendorsApi.approve(id);
      return vendorsApi.deactivate(id);
    },
    onSuccess: () => {
      toast.success('Status vendor diperbarui');
      qc.invalidateQueries({ queryKey: ['procurement'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Vendor</h1>
          <p className="text-xs text-slate-500">
            Vendor hanya dapat dipakai pada PO setelah berstatus Aktif.
          </p>
        </div>
        <Can perm="procurement.manage_vendor">
          <Button size="sm" onClick={() => setBukaBuat(true)}>
            <Plus className="mr-1 h-4 w-4" /> Daftarkan vendor
          </Button>
        </Can>
      </header>

      <Card>
        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
          <Input
            placeholder="Cari nama / kode / NPWP…"
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
            {vendorStatus.options().map((o) => (
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
          <EmptyState title="Belum ada vendor" description="Daftarkan vendor pertama untuk memulai pengadaan." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((vendor) => (
              <li key={vendor.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <Link
                      to={`/procurement/vendors/${vendor.id}`}
                      className="text-sm font-medium text-slate-800 hover:text-brand-600 hover:underline"
                    >
                      {vendor.legal_name}
                    </Link>
                    <StatusBadge value={vendor.status} map={vendorStatus} />
                    {vendor.classification && <StatusBadge value={vendor.classification} map={vendorClassification} />}
                    {vendor.tier && <Badge tone="gray">{vendor.tier.replace(/_/g, ' ')}</Badge>}
                  </div>
                  <p className="mt-0.5 text-[11px] text-slate-500">
                    <span className="font-mono">{vendor.code}</span>
                    {vendor.vendor_type && <> · {vendorType.label(vendor.vendor_type)}</>}
                    {vendor.city && <> · {vendor.city}</>}
                    {vendor.email && <> · {vendor.email}</>}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <Can perm="procurement.manage_vendor">
                    {['DRAFT', 'REGISTERED'].includes(vendor.status) && (
                      <Button size="sm" variant="outline" onClick={() => langkah.mutate({ id: vendor.id, aksi: 'submit' })}>
                        <UserCheck className="mr-1 h-3.5 w-3.5" /> Ajukan
                      </Button>
                    )}
                    {['SUBMITTED', 'UNDER_VERIFICATION'].includes(vendor.status) && (
                      <Button size="sm" variant="outline" onClick={() => langkah.mutate({ id: vendor.id, aksi: 'verify' })}>
                        <ShieldCheck className="mr-1 h-3.5 w-3.5" /> Verifikasi
                      </Button>
                    )}
                    {vendor.status === 'VERIFIED' && (
                      <Button size="sm" onClick={() => langkah.mutate({ id: vendor.id, aksi: 'approve' })}>
                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Aktifkan
                      </Button>
                    )}
                    {['SUBMITTED', 'UNDER_VERIFICATION', 'VERIFIED'].includes(vendor.status) && (
                      <Button size="sm" variant="ghost" onClick={() => setTolak(vendor)}>
                        <XCircle className="mr-1 h-3.5 w-3.5" /> Tolak
                      </Button>
                    )}
                  </Can>
                </div>
              </li>
            ))}
          </ul>
        )}

        {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      {bukaBuat && <VendorFormModal onClose={() => setBukaBuat(false)} />}
      {tolak && <RejectModal vendor={tolak} onClose={() => setTolak(null)} />}
    </div>
  );
}

function VendorFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const { optionsOf } = useEnumMetadata();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    legal_name: '',
    trading_name: '',
    vendor_type: 'SUPPLIER',
    entity_type: 'COMPANY',
    npwp: '',
    nib: '',
    address: '',
    city: '',
    province: '',
    postal_code: '',
    contact_person: '',
    email: '',
    phone: '',
    currency: 'IDR',
    payment_terms: 'NET 30',
    tax_rate: '',
    notes: '',
  });

  const set = (k: keyof typeof form, v: string) => setForm((p) => ({ ...p, [k]: v }));

  const simpan = useMutation({
    mutationFn: () => vendorsApi.create({ ...form, tax_rate: form.tax_rate === '' ? null : Number(form.tax_rate) }),
    onSuccess: () => {
      toast.success('Vendor didaftarkan');
      qc.invalidateQueries({ queryKey: ['procurement'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const typeOptions = optionsOf('vendor_type');
  const typeList = typeOptions.length ? typeOptions : vendorType.options();

  return (
    <Modal
      open
      onClose={onClose}
      title="Daftarkan vendor"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!form.legal_name}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Nama legal" required error={errors.legal_name?.[0]} className="sm:col-span-2">
          <Input value={form.legal_name} onChange={(e) => set('legal_name', e.target.value)} />
        </FormField>
        <FormField label="Nama dagang" error={errors.trading_name?.[0]}>
          <Input value={form.trading_name} onChange={(e) => set('trading_name', e.target.value)} />
        </FormField>
        <FormField label="Jenis vendor" error={errors.vendor_type?.[0]}>
          <Select value={form.vendor_type} onChange={(e) => set('vendor_type', e.target.value)}>
            {typeList.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Bentuk badan">
          <Select value={form.entity_type} onChange={(e) => set('entity_type', e.target.value)}>
            <option value="COMPANY">Perusahaan</option>
            <option value="INDIVIDUAL">Perorangan</option>
          </Select>
        </FormField>
        <FormField label="NPWP" error={errors.npwp?.[0]}>
          <Input value={form.npwp} onChange={(e) => set('npwp', e.target.value)} />
        </FormField>
        <FormField label="NIB" error={errors.nib?.[0]}>
          <Input value={form.nib} onChange={(e) => set('nib', e.target.value)} />
        </FormField>
        <FormField label="Kontak person" error={errors.contact_person?.[0]}>
          <Input value={form.contact_person} onChange={(e) => set('contact_person', e.target.value)} />
        </FormField>
        <FormField label="Email" error={errors.email?.[0]}>
          <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
        </FormField>
        <FormField label="Telepon" error={errors.phone?.[0]}>
          <Input value={form.phone} onChange={(e) => set('phone', e.target.value)} />
        </FormField>
        <FormField label="Alamat" error={errors.address?.[0]} className="sm:col-span-2">
          <Input value={form.address} onChange={(e) => set('address', e.target.value)} />
        </FormField>
        <FormField label="Kota" error={errors.city?.[0]}>
          <Input value={form.city} onChange={(e) => set('city', e.target.value)} />
        </FormField>
        <FormField label="Provinsi" error={errors.province?.[0]}>
          <Input value={form.province} onChange={(e) => set('province', e.target.value)} />
        </FormField>
        <FormField label="Kode pos" error={errors.postal_code?.[0]}>
          <Input value={form.postal_code} onChange={(e) => set('postal_code', e.target.value)} />
        </FormField>
        <FormField label="Mata uang">
          <Input value={form.currency} maxLength={3} onChange={(e) => set('currency', e.target.value.toUpperCase())} />
        </FormField>
        <FormField label="Termin pembayaran" error={errors.payment_terms?.[0]}>
          <Input value={form.payment_terms} onChange={(e) => set('payment_terms', e.target.value)} />
        </FormField>
        <FormField label="Tarif pajak (%)" error={errors.tax_rate?.[0]}>
          <Input type="number" value={form.tax_rate} onChange={(e) => set('tax_rate', e.target.value)} />
        </FormField>
        <FormField label="Catatan" error={errors.notes?.[0]} className="sm:col-span-2">
          <Textarea rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function RejectModal({ vendor, onClose }: { vendor: Vendor; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () => vendorsApi.reject(vendor.id, reason),
    onSuccess: () => {
      toast.success('Vendor ditolak');
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
      title={`Tolak vendor — ${vendor.legal_name}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" onClick={() => simpan.mutate()} loading={simpan.isPending} disabled={!reason}>
            Tolak
          </Button>
        </div>
      }
    >
      <FormField label="Alasan penolakan" required error={errors.reason?.[0]}>
        <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
