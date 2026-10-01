import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, PackageCheck, Plus, Undo2, XCircle } from 'lucide-react';
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
import { loansApi } from '../api/assetOpsApi';
import { FormField } from '../components/AssetUi';
import { assetCondition, loanStatus } from '../lib/labels';
import { fmtDateTime, isOverdue, toDateTimeInput } from '../lib/format';
import type { AssetLoan } from '../types';

/**
 * Peminjaman aset — dari pengajuan sampai pengembalian.
 *
 * Satu hal yang sengaja dibuat menonjol: tombol “Terima kembali” SELALU meminta
 * kondisi barang. Sekolah yang mencatat pengembalian tanpa kondisi akan
 * kehilangan jejak kapan sebuah barang rusak.
 */
export default function AssetLoanListPage() {
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [bukaBuat, setBukaBuat] = useState(false);
  const [kembalikan, setKembalikan] = useState<AssetLoan | null>(null);

  const page = Number(params.get('page') ?? 1);
  const status = params.get('status') ?? '';

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'loans', page, status],
    queryFn: () => loansApi.list({ page, per_page: 20, status: status || undefined }),
  });

  const aksi = useMutation({
    mutationFn: ({ id, langkah }: { id: number; langkah: 'approve' | 'reserve' | 'handover' | 'activate' | 'cancel' }) => {
      switch (langkah) {
        case 'approve':
          return loansApi.approve(id);
        case 'reserve':
          return loansApi.reserve(id);
        case 'handover':
          return loansApi.handover(id, {});
        case 'activate':
          return loansApi.activate(id);
        default:
          return loansApi.cancel(id, { reason: 'Dibatalkan dari daftar peminjaman' });
      }
    },
    onSuccess: () => {
      toast.success('Langkah peminjaman dicatat');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Peminjaman Aset</h1>
          <p className="text-xs text-slate-500">
            Barang yang dipinjam terkunci: tidak dapat dipinjam orang lain, dipindahkan, atau diusulkan penghapusan sampai kembali.
          </p>
        </div>
        <Can perm="asset.loan.request">
          <Button size="sm" onClick={() => setBukaBuat(true)}>
            <Plus className="mr-1 h-4 w-4" /> Ajukan peminjaman
          </Button>
        </Can>
      </header>

      <Card className="p-4">
        <Select
          value={status}
          onChange={(e) => {
            const next = new URLSearchParams(params);
            if (e.target.value) next.set('status', e.target.value);
            else next.delete('status');
            next.delete('page');
            setParams(next);
          }}
          className="max-w-xs"
        >
          <option value="">Semua status</option>
          {loanStatus.options().map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      </Card>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada peminjaman" description="Pengajuan peminjaman akan muncul di sini beserta statusnya." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((loan) => (
              <li key={loan.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-500">{loan.loan_number}</span>
                    <Badge tone={loanStatus.tone(loan.status)}>{loanStatus.label(loan.status)}</Badge>
                    {isOverdue(loan.due_at, Boolean(loan.returned_at)) && <Badge tone="red">Terlambat</Badge>}
                    {(loan.items_count ?? 0) > 1 && <Badge tone="blue">{loan.items_count} barang</Badge>}
                  </div>
                  <p className="mt-1 text-sm text-slate-800">{loan.purpose ?? '-'}</p>
                  <p className="text-[11px] text-slate-500">
                    {fmtDateTime(loan.start_at)} → {fmtDateTime(loan.due_at)}
                    {loan.borrower && <> · peminjam: {loan.borrower.name}</>}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  {loan.status === 'REQUESTED' && (
                    <Can perm="asset.loan.approve">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: loan.id, langkah: 'approve' })}>
                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Setujui
                      </Button>
                    </Can>
                  )}
                  {loan.status === 'APPROVED' && (
                    <Can perm="asset.loan.manage">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: loan.id, langkah: 'reserve' })}>
                        Pesan slot
                      </Button>
                    </Can>
                  )}
                  {loan.status === 'RESERVED' && (
                    <Can perm="asset.loan.manage">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: loan.id, langkah: 'handover' })}>
                        <PackageCheck className="mr-1 h-3.5 w-3.5" /> Serah terima
                      </Button>
                    </Can>
                  )}
                  {loan.status === 'HANDED_OVER' && (
                    <Can perm="asset.loan.manage">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: loan.id, langkah: 'activate' })}>
                        Aktifkan
                      </Button>
                    </Can>
                  )}
                  {(loan.status === 'ACTIVE' || loan.status === 'OVERDUE') && (
                    <Can perm="asset.loan.manage">
                      <Button size="sm" onClick={() => setKembalikan(loan)}>
                        <Undo2 className="mr-1 h-3.5 w-3.5" /> Terima kembali
                      </Button>
                    </Can>
                  )}
                  {['REQUESTED', 'APPROVED', 'RESERVED'].includes(loan.status) && (
                    <Can perm="asset.loan.manage">
                      <Button size="sm" variant="ghost" onClick={() => aksi.mutate({ id: loan.id, langkah: 'cancel' })}>
                        <XCircle className="mr-1 h-3.5 w-3.5" /> Batalkan
                      </Button>
                    </Can>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {data?.meta && (
          <Pagination
            meta={data.meta}
            onPageChange={(p) => {
              const next = new URLSearchParams(params);
              next.set('page', String(p));
              setParams(next);
            }}
          />
        )}
      </Card>

      {bukaBuat && <LoanFormModal onClose={() => setBukaBuat(false)} />}
      {kembalikan && <ReturnModal loan={kembalikan} onClose={() => setKembalikan(null)} />}
    </div>
  );
}

function LoanFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [assetIds, setAssetIds] = useState<number[]>([]);
  const [cari, setCari] = useState('');
  const [form, setForm] = useState({
    borrower_id: '' as number | '',
    purpose: '',
    start_at: toDateTimeInput(new Date()),
    due_at: toDateTimeInput(new Date(Date.now() + 86_400_000)),
    notes: '',
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data: kandidat } = useQuery({
    queryKey: ['asset', 'loan-candidates', cari],
    queryFn: () => assetsApi.list({ search: cari, per_page: 15, status: 'ACTIVE' }),
  });

  const { data: peminjam } = useQuery({
    queryKey: ['asset', 'borrowers'],
    queryFn: () => import('../api/assetApi').then((m) => m.metaApi.custodians({ per_page: 300 })),
  });

  const simpan = useMutation({
    mutationFn: () =>
      loansApi.create({
        asset_ids: assetIds,
        borrower_id: form.borrower_id,
        borrower_type: 'CUSTODIAN',
        purpose: form.purpose,
        start_at: form.start_at,
        due_at: form.due_at,
        notes: form.notes,
      }),
    onSuccess: () => {
      toast.success('Peminjaman diajukan');
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
      title="Ajukan peminjaman"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || assetIds.length === 0 || form.borrower_id === '' || !form.purpose}>
            {simpan.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Ajukan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Peminjam" required error={errors.borrower_id?.[0]}>
          <SearchSelect
            options={(peminjam ?? []).map((c) => ({ value: c.id, label: c.name }))}
            value={form.borrower_id}
            onChange={(v) => setForm({ ...form, borrower_id: v === '' ? '' : Number(v) })}
          />
        </FormField>
        <FormField label="Keperluan" required error={errors.purpose?.[0]}>
          <Input value={form.purpose} onChange={(e) => setForm({ ...form, purpose: e.target.value })} />
        </FormField>
        <FormField label="Mulai" required error={errors.start_at?.[0]}>
          <Input type="datetime-local" value={form.start_at} onChange={(e) => setForm({ ...form, start_at: e.target.value })} />
        </FormField>
        <FormField label="Batas kembali" required error={errors.due_at?.[0]}>
          <Input type="datetime-local" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} />
        </FormField>
        <FormField label="Catatan" className="sm:col-span-2">
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </div>

      <div className="mt-4">
        <FormField label="Barang yang dipinjam" required error={errors.asset_ids?.[0]}>
          <Input placeholder="Cari barang…" value={cari} onChange={(e) => setCari(e.target.value)} />
        </FormField>
        <div className="mt-2 max-h-48 overflow-y-auto rounded-lg border border-slate-200">
          {(kandidat?.data ?? []).length === 0 ? (
            <p className="px-3 py-4 text-xs text-slate-500">Tidak ada barang yang cocok.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {(kandidat?.data ?? []).map((asset) => (
                <li key={asset.id} className="flex items-center justify-between px-3 py-2 text-sm">
                  <label className="flex items-center gap-2">
                    <input
                      type="checkbox"
                      checked={assetIds.includes(asset.id)}
                      onChange={(e) => setAssetIds((prev) => (e.target.checked ? [...prev, asset.id] : prev.filter((v) => v !== asset.id)))}
                    />
                    <span>
                      {asset.name}
                      <span className="ml-2 font-mono text-[11px] text-slate-500">{asset.asset_code}</span>
                    </span>
                  </label>
                  <span className="text-[11px] text-slate-500">{asset.location?.name ?? '-'}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
        <p className="mt-1 text-[11px] text-slate-500">{assetIds.length} barang dipilih.</p>
      </div>
    </Modal>
  );
}

/** Pengembalian: kondisi wajib, kerusakan melahirkan laporan + pemeliharaan. */
function ReturnModal({ loan, onClose }: { loan: AssetLoan; onClose: () => void }) {
  const qc = useQueryClient();
  const [condition, setCondition] = useState('GOOD');
  const [damageFound, setDamageFound] = useState(false);
  const [notes, setNotes] = useState('');

  const simpan = useMutation({
    mutationFn: () => loansApi.returnLoan(loan.id, { condition, damage_found: damageFound, notes }),
    onSuccess: () => {
      toast.success(damageFound ? 'Dikembalikan — laporan kerusakan dibuat' : 'Pengembalian dicatat');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Terima kembali — ${loan.loan_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending}>
            Catat pengembalian
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Kondisi barang" required>
          <Select
            value={condition}
            onChange={(e) => {
              setCondition(e.target.value);
              setDamageFound(e.target.value !== 'GOOD');
            }}
          >
            {['GOOD', 'FAIR', 'POOR', 'DAMAGED'].map((c) => (
              <option key={c} value={c}>
                {assetCondition.label(c)}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Catatan" hint="Kerusakan akan melahirkan laporan kerusakan dan permintaan pemeliharaan otomatis.">
          <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}
