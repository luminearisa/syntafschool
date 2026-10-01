import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useQuery as useAccountsQuery } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { AccountSelect } from '../components/AccountSelect';
import { financeStandardApi } from '../api/financeStandardApi';
import { formatRupiah } from '../lib/format';

interface DraftRow {
  account_id: number | '';
  jenis_saldo: 'debet' | 'kredit';
  saldo: string;
  keterangan: string;
}

const barisBaru = (): DraftRow => ({ account_id: '', jenis_saldo: 'debet', saldo: '', keterangan: '' });

/**
 * Saldo awal: rancangan bebas diubah, lalu diposting menjadi jurnal pembuka.
 *
 * Jurnal pembuka WAJIB seimbang. Selisihnya ditampilkan apa adanya dan jurnal
 * tidak akan diposting selama selisih itu ada — neraca awal yang tidak dapat
 * dipercaya membuat seluruh laporan sesudahnya dipertanyakan.
 */
export function FinanceOpeningBalancePage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'opening'], queryFn: () => financeStandardApi.openingBalances() });
  const akun = useAccountsQuery({ queryKey: ['finance', 'accounts', 'label'], queryFn: () => financeStandardApi.accounts() });

  const [rows, setRows] = useState<DraftRow[]>([barisBaru()]);
  const [konfirmasiPosting, setKonfirmasiPosting] = useState(false);
  const [hapusId, setHapusId] = useState<number | null>(null);

  const labelAkun = (id: number) => {
    const found = (akun.data ?? []).find((a) => a.id === id);
    return found ? `${found.code} · ${found.name}` : `#${id}`;
  };

  const simpan = useMutation({
    mutationFn: () => financeStandardApi.saveOpeningBalances(
      rows.filter((row) => row.account_id !== '').map((row) => ({
        account_id: Number(row.account_id),
        jenis_saldo: row.jenis_saldo,
        saldo: Number(row.saldo || 0),
        keterangan: row.keterangan || undefined,
      })),
    ),
    onSuccess: () => {
      toast.success('Rancangan saldo awal disimpan.');
      setRows([barisBaru()]);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'opening'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const posting = useMutation({
    mutationFn: () => financeStandardApi.postOpeningBalances(),
    onSuccess: (hasil) => {
      toast.success(`Saldo awal diposting sebagai jurnal ${hasil.transaction.transaction_number}.`);
      setKonfirmasiPosting(false);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'opening'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const buang = useMutation({
    mutationFn: (id: number) => financeStandardApi.deleteOpeningBalance(id),
    onSuccess: () => {
      toast.success('Saldo awal dihapus dari rancangan.');
      setHapusId(null);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'opening'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const balances = data?.balances ?? [];
  const summary = data?.summary;
  const totalDraft = rows.reduce((total, row) => {
    const nilai = Number(row.saldo || 0);
    return row.jenis_saldo === 'debet' ? total + nilai : total - nilai;
  }, 0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Saldo Awal</h1>
          <p className="text-sm text-slate-500">Rancangan → posting jurnal pembuka. Setelah diposting, angkanya terkunci.</p>
        </div>
        <div className="flex items-center gap-2">
          {summary && (
            <Badge tone={summary.posted ? 'info' : summary.balanced ? 'success' : 'warning'}>
              {summary.posted ? 'Sudah diposting' : summary.balanced ? 'Seimbang, siap diposting' : 'Belum seimbang'}
            </Badge>
          )}
          <Can perm="finance.account.manage">
            <Button
              variant="outline"
              disabled={!summary?.balanced || summary?.posted || posting.isPending}
              onClick={() => setKonfirmasiPosting(true)}
            >
              Posting jurnal pembuka
            </Button>
          </Can>
        </div>
      </div>

      {summary && (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Card><CardBody>
            <p className="text-xs uppercase text-slate-600">Total debet</p>
            <p className="text-lg font-semibold">{formatRupiah(Number(summary.total_debit))}</p>
          </CardBody></Card>
          <Card><CardBody>
            <p className="text-xs uppercase text-slate-600">Total kredit</p>
            <p className="text-lg font-semibold">{formatRupiah(Number(summary.total_credit))}</p>
          </CardBody></Card>
          <Card><CardBody>
            <p className="text-xs uppercase text-slate-600">Selisih</p>
            <p className={`text-lg font-semibold ${summary.balanced ? 'text-slate-800' : 'text-red-600'}`}>
              {formatRupiah(Number(summary.difference))}
            </p>
          </CardBody></Card>
          <Card><CardBody>
            <p className="text-xs uppercase text-slate-600">Akun terisi</p>
            <p className="text-lg font-semibold">{summary.accounts}</p>
          </CardBody></Card>
        </div>
      )}

      <Card>
        <CardHeader><CardTitle>Rancangan baru</CardTitle></CardHeader>
        <CardBody className="space-y-3">
          {rows.map((row, index) => (
            <div key={index} className="grid gap-2 md:grid-cols-12">
              <div className="md:col-span-5">
                <AccountSelect
                  value={row.account_id}
                  onChange={(value) => setRows((current) => current.map((item, i) => i === index ? { ...item, account_id: value } : item))}
                  placeholder="Pilih akun rincian"
                />
              </div>
              <div className="md:col-span-2">
                <Select
                  value={row.jenis_saldo}
                  onChange={(e) => setRows((current) => current.map((item, i) => i === index ? { ...item, jenis_saldo: e.target.value as 'debet' | 'kredit' } : item))}
                >
                  <option value="debet">Debet</option>
                  <option value="kredit">Kredit</option>
                </Select>
              </div>
              <div className="md:col-span-3">
                <Input
                  type="number"
                  min={0}
                  step="0.01"
                  placeholder="0.00"
                  value={row.saldo}
                  onChange={(e) => setRows((current) => current.map((item, i) => i === index ? { ...item, saldo: e.target.value } : item))}
                />
              </div>
              <div className="flex md:col-span-2">
                <Button
                  type="button"
                  variant="outline"
                  className="w-full"
                  aria-label={`Hapus baris rancangan ${index + 1}`}
                  onClick={() => setRows((current) => current.length === 1 ? [barisBaru()] : current.filter((_, i) => i !== index))}
                >
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                </Button>
              </div>
            </div>
          ))}

          <div className="flex flex-wrap items-center justify-between gap-2">
            <Button type="button" variant="outline" onClick={() => setRows((current) => [...current, barisBaru()])}>
              <Plus className="mr-2 h-4 w-4" /> Tambah baris
            </Button>
            <p className="text-xs text-slate-500">
              Selisih rancangan saat ini: <span className={totalDraft === 0 ? 'text-slate-700' : 'text-red-600'}>{formatRupiah(totalDraft)}</span>
            </p>
            <Can perm="finance.account.manage">
              <Button
                disabled={summary?.posted || simpan.isPending}
                onClick={() => simpan.mutate()}
              >
                Simpan rancangan
              </Button>
            </Can>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader><CardTitle>Saldo awal tersimpan</CardTitle></CardHeader>
        <CardBody>
          {isLoading ? (
            <div className="flex justify-center py-10"><Spinner /></div>
          ) : balances.length === 0 ? (
            <EmptyState title="Belum ada saldo awal" description="Isi rancangan di atas, lalu simpan." />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                    <th className="px-3 py-2">Akun</th>
                    <th className="px-3 py-2">Sisi</th>
                    <th className="px-3 py-2 text-right">Saldo</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {balances.map((balance) => (
                    <tr key={balance.id} className="border-b border-slate-50 last:border-0">
                      <td className="px-3 py-2">{balance.account ? `${balance.account.code} · ${balance.account.name}` : labelAkun(balance.account_id)}</td>
                      <td className="px-3 py-2 capitalize">{balance.jenis_saldo}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(balance.saldo))}</td>
                      <td className="px-3 py-2">
                        <Badge tone={balance.posted ? 'info' : 'neutral'}>{balance.posted ? 'Diposting' : 'Rancangan'}</Badge>
                      </td>
                      <td className="px-3 py-2 text-right">
                        {!balance.posted && (
                          <Can perm="finance.account.manage">
                            <Button
                              size="sm"
                              variant="outline"
                              aria-label={`Hapus saldo awal akun ${balance.account?.code ?? balance.account_id}`}
                              onClick={() => setHapusId(balance.id)}
                            >
                              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                            </Button>
                          </Can>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <ConfirmDialog
        open={konfirmasiPosting}
        title="Posting saldo awal"
        message="Saldo awal akan menjadi jurnal pembuka yang TIDAK dapat disunting lagi; koreksinya lewat jurnal pembalik. Lanjutkan?"
        confirmLabel="Posting"
        confirmVariant="primary"
        loading={posting.isPending}
        onCancel={() => setKonfirmasiPosting(false)}
        onConfirm={() => posting.mutate()}
      />

      <ConfirmDialog
        open={hapusId !== null}
        title="Hapus saldo awal"
        message="Hapus baris ini dari rancangan saldo awal?"
        loading={buang.isPending}
        onCancel={() => setHapusId(null)}
        onConfirm={() => hapusId && buang.mutate(hapusId)}
      />
    </div>
  );
}
