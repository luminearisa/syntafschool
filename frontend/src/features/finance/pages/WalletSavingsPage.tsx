import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Eye, Plus, RefreshCw, Search, X } from 'lucide-react';
import { toast } from 'sonner';
import { walletApi } from '../api/walletApi';
import { WalletCreateModal } from '../components/WalletCreateModal';
import { financeStatusTone, formatRupiah } from '../lib/format';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { Modal } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import type { WalletAccount, WalletTopUp } from '../types/wallet';

export function WalletSavingsPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [ownerType, setOwnerType] = useState('');
  const [status, setStatus] = useState('');
  const [selectedWallet, setSelectedWallet] = useState<WalletAccount | null>(null);
  const [selectedTopUp, setSelectedTopUp] = useState<WalletTopUp | null>(null);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [createOpen, setCreateOpen] = useState(false);

  const walletsQuery = useQuery({
    queryKey: ['wallet', 'wallets', page, search, ownerType, status],
    queryFn: () => walletApi.wallets({ page, per_page: 15, search, owner_type: ownerType, status }),
  });
  const topupsQuery = useQuery({
    queryKey: ['wallet', 'topups', 'pending'],
    enabled: can('wallet.topup.view'),
    queryFn: () => walletApi.topups({ pending_verification: true, page: 1, per_page: 8 }),
  });
  const ledgerQuery = useQuery({
    queryKey: ['wallet', 'ledger', selectedWallet?.id],
    enabled: !!selectedWallet && can('wallet.ledger.view'),
    queryFn: () => walletApi.ledger(selectedWallet!.id, { page: 1, per_page: 8 }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['wallet', 'wallets'] });
    queryClient.invalidateQueries({ queryKey: ['wallet', 'topups'] });
    queryClient.invalidateQueries({ queryKey: ['wallet', 'ledger'] });
  };
  const verifyMutation = useMutation({
    mutationFn: (id: number) => walletApi.verifyTopUp(id),
    onSuccess: () => { toast.success('Top up disetujui dan saldo wallet bertambah'); setSelectedTopUp(null); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => walletApi.rejectTopUp(id, reason),
    onSuccess: () => { toast.success('Pengajuan top up ditolak'); setSelectedTopUp(null); setRejectOpen(false); setRejectReason(''); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const createWalletMutation = useMutation({
    mutationFn: (payload: { student_id: number; notes?: string }) => walletApi.createWallet(payload),
    onSuccess: (wallet) => { toast.success(`Wallet ${wallet.number} berhasil dibuka`); setCreateOpen(false); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const submitSearch = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  };
  const wallets = walletsQuery.data?.data ?? [];
  const topups = topupsQuery.data?.data ?? [];
  const ledger = ledgerQuery.data?.data ?? [];

  const submitReject = (event: FormEvent) => {
    event.preventDefault();
    if (!selectedTopUp || !rejectReason.trim()) return;
    rejectMutation.mutate({ id: selectedTopUp.id, reason: rejectReason.trim() });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Tabungan & Wallet</h1>
          <p className="text-sm text-slate-500">Pantau saldo, mutasi, dan persetujuan top up siswa serta keluarga.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => walletsQuery.refetch()}>
            <RefreshCw className={walletsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="wallet.create">
            <Button onClick={() => setCreateOpen(true)}><Plus className="h-4 w-4" /> Buka Wallet Siswa</Button>
          </Can>
        </div>
      </div>

      {can('wallet.topup.view') && (
        <Card>
          <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <div><h2 className="text-sm font-semibold text-slate-800">Antrean Top Up</h2><p className="text-xs text-slate-500">Saldo hanya bertambah setelah pembayaran diverifikasi.</p></div>
            {topupsQuery.isFetching && <Spinner />}
          </div>
          {topupsQuery.isError ? (
            <EmptyState title="Gagal memuat antrean top up" description={getErrorMessage(topupsQuery.error)} />
          ) : topups.length === 0 ? (
            <div className="px-4 py-5 text-sm text-slate-500">Tidak ada pengajuan top up yang menunggu verifikasi.</div>
          ) : (
            <div className="overflow-x-auto"><table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase text-slate-500"><th className="px-4 py-2">Nomor</th><th className="px-4 py-2">Pemilik</th><th className="px-4 py-2">Metode</th><th className="px-4 py-2 text-right">Nominal</th><th className="px-4 py-2">Status</th><th className="px-4 py-2 text-right">Aksi</th></tr></thead>
              <tbody>{topups.map((topup) => <tr key={topup.id} className="border-b border-slate-50"><td className="px-4 py-2 font-mono text-xs">{topup.number}</td><td className="px-4 py-2"><div className="font-medium">{topup.owner_name}</div><div className="text-xs text-slate-400">{topup.wallet_number}</div></td><td className="px-4 py-2">{topup.method_label}</td><td className="px-4 py-2 text-right font-medium">{formatRupiah(topup.amount)}</td><td className="px-4 py-2"><Badge tone={financeStatusTone(topup.status)}>{topup.status.replace(/_/g, ' ')}</Badge></td><td className="px-4 py-2"><div className="flex justify-end gap-1"><Can perm="wallet.topup.verify"><Button size="icon" variant="ghost" title="Setujui top up" onClick={() => verifyMutation.mutate(topup.id)}><Check className="h-4 w-4 text-green-600" /></Button></Can><Can perm="wallet.topup.reject"><Button size="icon" variant="ghost" title="Tolak top up" onClick={() => { setSelectedTopUp(topup); setRejectOpen(true); }}><X className="h-4 w-4 text-red-500" /></Button></Can></div></td></tr>)}</tbody>
            </table></div>
          )}
        </Card>
      )}

      <Card className="p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <form onSubmit={submitSearch} className="flex flex-1 items-center gap-2">
            <div className="relative w-full max-w-sm"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari nomor wallet, nama, NIS/NIP..." className="pl-9" /></div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <div className="grid w-full grid-cols-2 gap-2 sm:w-auto">
            <Select value={ownerType} onChange={(event) => { setOwnerType(event.target.value); setPage(1); }}><option value="">Semua pemilik</option><option value="student">Siswa</option><option value="employee">Pegawai</option><option value="guardian">Wali</option><option value="user">Pengguna</option></Select>
            <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="">Semua status</option><option value="active">Aktif</option><option value="blocked">Diblokir</option><option value="closed">Ditutup</option></Select>
          </div>
        </div>
      </Card>

      <Card>
        {walletsQuery.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat saldo wallet...</div>
          : walletsQuery.isError ? <EmptyState title="Gagal memuat wallet" description={getErrorMessage(walletsQuery.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => walletsQuery.refetch()}>Coba lagi</Button>} />
            : wallets.length === 0 ? <EmptyState title="Belum ada wallet" description="Wallet siswa dan wali yang aktif akan tampil di sini." />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Nomor Wallet</th><th className="px-4 py-3">Pemilik</th><th className="px-4 py-3">Jenis</th><th className="px-4 py-3 text-right">Saldo</th><th className="px-4 py-3 text-right">Tersedia</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{wallets.map((wallet) => <tr key={wallet.id} className="border-b border-slate-50 hover:bg-slate-50/60"><td className="px-4 py-3 font-mono text-xs font-semibold">{wallet.number}</td><td className="px-4 py-3"><div className="font-medium text-slate-800">{wallet.owner_name}</div><div className="text-xs text-slate-400">{wallet.owner_identifier ?? '—'}</div></td><td className="px-4 py-3 text-slate-600">{wallet.owner_type_label ?? wallet.owner_type}</td><td className="px-4 py-3 text-right font-semibold">{formatRupiah(wallet.balance)}</td><td className="px-4 py-3 text-right text-slate-600">{formatRupiah(wallet.available_balance)}</td><td className="px-4 py-3"><Badge tone={wallet.status === 'active' ? 'green' : wallet.status === 'blocked' ? 'amber' : 'gray'}>{wallet.status_label}</Badge></td><td className="px-4 py-3 text-right"><Button size="icon" variant="ghost" title="Lihat mutasi" onClick={() => setSelectedWallet(wallet)}><Eye className="h-4 w-4" /></Button></td></tr>)}</tbody>
              </table></div>}
        {walletsQuery.data?.meta && <Pagination meta={walletsQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <Modal open={!!selectedWallet} onClose={() => setSelectedWallet(null)} title={`Mutasi Wallet ${selectedWallet?.number ?? ''}`} size="xl">
        {selectedWallet && <div className="space-y-4">
          <div className="grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-3"><div><div className="text-xs text-slate-500">Pemilik</div><div className="font-semibold text-slate-800">{selectedWallet.owner_name}</div></div><div><div className="text-xs text-slate-500">Saldo</div><div className="font-semibold">{formatRupiah(selectedWallet.balance)}</div></div><div><div className="text-xs text-slate-500">Saldo tersedia</div><div className="font-semibold">{formatRupiah(selectedWallet.available_balance)}</div></div></div>
          {!can('wallet.ledger.view') ? <p className="text-sm text-slate-500">Akun Anda tidak memiliki izin untuk melihat buku besar wallet.</p> : ledgerQuery.isLoading ? <div className="flex justify-center py-8"><Spinner /></div> : ledgerQuery.isError ? <EmptyState title="Gagal memuat mutasi" description={getErrorMessage(ledgerQuery.error)} /> : ledger.length === 0 ? <EmptyState title="Belum ada mutasi" /> : <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b text-left text-xs uppercase text-slate-500"><th className="px-3 py-2">Tanggal</th><th className="px-3 py-2">Transaksi</th><th className="px-3 py-2">Keterangan</th><th className="px-3 py-2 text-right">Jumlah</th><th className="px-3 py-2 text-right">Saldo</th></tr></thead><tbody>{ledger.map((entry) => <tr key={entry.id} className="border-b border-slate-50"><td className="px-3 py-2 text-xs">{entry.posted_at ? new Date(entry.posted_at).toLocaleDateString('id-ID') : '—'}</td><td className="px-3 py-2"><div className="font-mono text-xs">{entry.transaction_number}</div><div className="text-xs text-slate-500">{entry.type_label}</div></td><td className="px-3 py-2 text-slate-600">{entry.description ?? '—'}</td><td className={`px-3 py-2 text-right font-medium ${entry.direction === 'credit' ? 'text-green-700' : 'text-red-600'}`}>{entry.direction === 'credit' ? '+' : '−'}{formatRupiah(entry.amount)}</td><td className="px-3 py-2 text-right">{formatRupiah(entry.balance_after)}</td></tr>)}</tbody></table></div>}
        </div>}
      </Modal>

      <WalletCreateModal
        open={createOpen}
        submitting={createWalletMutation.isPending}
        onClose={() => setCreateOpen(false)}
        onSubmit={(payload) => createWalletMutation.mutate(payload)}
      />
      <Modal open={rejectOpen} onClose={() => { setRejectOpen(false); setSelectedTopUp(null); }} title="Tolak Pengajuan Top Up" footer={<><Button variant="outline" onClick={() => { setRejectOpen(false); setSelectedTopUp(null); }}>Batal</Button><Button type="submit" form="wallet-reject-topup" variant="danger" loading={rejectMutation.isPending}>Tolak</Button></>}>
        <form id="wallet-reject-topup" onSubmit={submitReject} className="space-y-3"><p className="text-sm text-slate-600">Saldo wallet tidak akan berubah.</p><Textarea value={rejectReason} onChange={(event) => setRejectReason(event.target.value)} placeholder="Alasan penolakan wajib diisi" /></form>
      </Modal>
    </div>
  );
}
