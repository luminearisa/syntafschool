import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2, X } from 'lucide-react';
import { toast } from 'sonner';
import { Card, CardBody } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { AccountSelect } from '../components/AccountSelect';
import { financeStandardApi } from '../api/financeStandardApi';
import type { Fund } from '../types/standard';

const JENIS = [
  { value: 'bebas', label: 'Dana Bebas' },
  { value: 'terikat', label: 'Dana Terikat' },
  { value: 'wakaf', label: 'Dana Wakaf' },
  { value: 'zakat', label: 'Dana Zakat' },
  { value: 'infaq', label: 'Dana Infaq' },
  { value: 'sedekah', label: 'Dana Sedekah' },
  { value: 'temporer', label: 'Dana Temporer' },
];

/**
 * Dana & peruntukannya.
 *
 * Daftar akun per dana membatasi baris BEBAN: dana wakaf pembangunan tidak boleh
 * terpakai untuk gaji. Dana tanpa daftar akun berarti tidak membatasi — bukan
 * melarang semuanya.
 */
export function FinanceFundsPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'funds'], queryFn: () => financeStandardApi.funds() });

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Fund | null>(null);
  const [form, setForm] = useState({ kode_dana: '', nama_dana: '', jenis: 'bebas', peruntukan: '', keterangan: '' });
  const [akunDana, setAkunDana] = useState<{ fund: Fund; accounts: number[]; contra: number[] } | null>(null);
  const [pilihAkun, setPilihAkun] = useState<number | ''>('');
  const [pilihKontra, setPilihKontra] = useState<number | ''>('');
  const [hapus, setHapus] = useState<Fund | null>(null);

  const simpan = useMutation({
    mutationFn: () => editing
      ? financeStandardApi.updateFund(editing.id, {
        nama_dana: form.nama_dana,
        jenis: form.jenis,
        peruntukan: form.peruntukan || null,
        keterangan: form.keterangan || null,
      })
      : financeStandardApi.createFund({
        kode_dana: form.kode_dana,
        nama_dana: form.nama_dana,
        jenis: form.jenis,
        peruntukan: form.peruntukan || null,
        keterangan: form.keterangan || null,
      }),
    onSuccess: () => {
      toast.success(editing ? 'Dana diperbarui.' : 'Dana dibuat.');
      setOpen(false);
      setEditing(null);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'funds'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const simpanAkun = useMutation({
    mutationFn: () => financeStandardApi.syncFundAccounts(akunDana!.fund.id, akunDana!.accounts, akunDana!.contra),
    onSuccess: () => {
      toast.success('Daftar akun dana disimpan.');
      setAkunDana(null);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'funds'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const buangDana = useMutation({
    mutationFn: (id: number) => financeStandardApi.deleteFund(id),
    onSuccess: () => {
      toast.success('Dana dihapus.');
      setHapus(null);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'funds'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const funds = data?.funds ?? [];

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!form.nama_dana.trim() || (!editing && !form.kode_dana.trim())) {
      toast.error('Kode dan nama dana wajib diisi.');
      return;
    }
    if (form.jenis !== 'bebas' && !form.peruntukan.trim()) {
      toast.error('Dana terikat wajib mencantumkan peruntukannya.');
      return;
    }
    simpan.mutate();
  };

  const bukaForm = (fund?: Fund) => {
    setEditing(fund ?? null);
    setForm(fund
      ? { kode_dana: fund.kode_dana, nama_dana: fund.nama_dana, jenis: fund.jenis, peruntukan: fund.peruntukan ?? '', keterangan: fund.keterangan ?? '' }
      : { kode_dana: '', nama_dana: '', jenis: 'bebas', peruntukan: '', keterangan: '' });
    setOpen(true);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Dana &amp; Peruntukan</h1>
          <p className="text-sm text-slate-500">
            Dana terikat hanya boleh dibelanjakan sesuai peruntukannya; pelanggarannya ditolak saat posting jurnal.
          </p>
        </div>
        <Can perm="finance.account.manage">
          <Button onClick={() => bukaForm()}>
            <Plus className="mr-2 h-4 w-4" /> Dana baru
          </Button>
        </Can>
      </div>

      <Card>
        <CardBody>
          {isLoading ? (
            <div className="flex justify-center py-10"><Spinner /></div>
          ) : funds.length === 0 ? (
            <EmptyState title="Belum ada dana" description="Tambahkan dana agar penerimaan terikat dapat dilaporkan." />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                    <th className="px-3 py-2">Kode</th>
                    <th className="px-3 py-2">Nama</th>
                    <th className="px-3 py-2">Jenis</th>
                    <th className="px-3 py-2">Peruntukan</th>
                    <th className="px-3 py-2 text-right">Akun</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {funds.map((fund) => (
                    <tr key={fund.id} className="border-b border-slate-50 last:border-0">
                      <td className="px-3 py-2 font-mono text-xs">{fund.kode_dana}</td>
                      <td className="px-3 py-2">{fund.nama_dana}</td>
                      <td className="px-3 py-2">{JENIS.find((j) => j.value === fund.jenis)?.label ?? fund.jenis}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">{fund.peruntukan ?? '—'}</td>
                      <td className="px-3 py-2 text-right">{fund.accounts_count ?? 0}</td>
                      <td className="px-3 py-2">
                        <Badge tone={fund.status === 'aktif' ? 'success' : 'neutral'}>{fund.status}</Badge>
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex justify-end gap-2">
                          <Can perm="finance.account.manage">
                            <Button size="sm" variant="outline" onClick={() => setAkunDana({ fund, accounts: [], contra: [] })}>
                              Akun dana
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => bukaForm(fund)}>Ubah</Button>
                            <Button
                              size="sm"
                              variant="outline"
                              aria-label={`Hapus dana ${fund.kode_dana}`}
                              onClick={() => setHapus(fund)}
                            >
                              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
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
        </CardBody>
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title={editing ? `Ubah dana ${editing.kode_dana}` : 'Dana baru'}>
        <form className="space-y-3" onSubmit={submit}>
          {!editing && (
            <div>
              <Label htmlFor="fund-code">Kode dana</Label>
              <Input id="fund-code" value={form.kode_dana} onChange={(e) => setForm({ ...form, kode_dana: e.target.value.toUpperCase() })} placeholder="WAKAF-GEDUNG" />
            </div>
          )}
          <div>
            <Label htmlFor="fund-name">Nama dana</Label>
            <Input id="fund-name" value={form.nama_dana} onChange={(e) => setForm({ ...form, nama_dana: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="fund-jenis">Jenis</Label>
            <Select id="fund-jenis" value={form.jenis} onChange={(e) => setForm({ ...form, jenis: e.target.value })}>
              {JENIS.map((jenis) => <option key={jenis.value} value={jenis.value}>{jenis.label}</option>)}
            </Select>
          </div>
          <div>
            <Label htmlFor="fund-peruntukan">Peruntukan</Label>
            <Textarea id="fund-peruntukan" rows={2} value={form.peruntukan} onChange={(e) => setForm({ ...form, peruntukan: e.target.value })} />
            <p className="mt-1 text-xs text-slate-500">
              Wajib untuk dana terikat: penolakan jurnal di kemudian hari harus dapat dijelaskan dengan peruntukan ini.
            </p>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Batal</Button>
            <Button type="submit" loading={simpan.isPending}>Simpan</Button>
          </div>
        </form>
      </Modal>

      <Modal
        open={akunDana !== null}
        onClose={() => setAkunDana(null)}
        title={`Akun dana ${akunDana?.fund.kode_dana ?? ''}`}
        size="lg"
      >
        {akunDana && (
          <div className="space-y-4">
            <div>
              <Label>Akun yang boleh dibebani dana ini</Label>
              <div className="flex gap-2">
                <div className="flex-1">
                  <AccountSelect value={pilihAkun} onChange={setPilihAkun} root="5" placeholder="Pilih akun beban" />
                </div>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => {
                    if (pilihAkun === '') return;
                    setAkunDana({ ...akunDana, accounts: [...new Set([...akunDana.accounts, Number(pilihAkun)])] });
                    setPilihAkun('');
                  }}
                >
                  Tambah
                </Button>
              </div>
              <ul className="mt-2 flex flex-wrap gap-2">
                {akunDana.accounts.map((id) => (
                  <li key={id} className="flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-xs">
                    <span className="font-mono">#{id}</span>
                    <button type="button" onClick={() => setAkunDana({ ...akunDana, accounts: akunDana.accounts.filter((a) => a !== id) })}>
                      <X className="h-3 w-3" />
                    </button>
                  </li>
                ))}
                {akunDana.accounts.length === 0 && (
                  <li className="text-xs text-slate-500">Kosong = dana ini tidak membatasi akun apa pun.</li>
                )}
              </ul>
            </div>

            <div>
              <Label>Akun pengurang dana bebas (opsional)</Label>
              <div className="flex gap-2">
                <div className="flex-1">
                  <AccountSelect value={pilihKontra} onChange={setPilihKontra} root="5" placeholder="Pilih akun pengurang" />
                </div>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => {
                    if (pilihKontra === '') return;
                    setAkunDana({ ...akunDana, contra: [...new Set([...akunDana.contra, Number(pilihKontra)])] });
                    setPilihKontra('');
                  }}
                >
                  Tambah
                </Button>
              </div>
              <ul className="mt-2 flex flex-wrap gap-2">
                {akunDana.contra.map((id) => (
                  <li key={id} className="flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-xs">
                    <span className="font-mono">#{id}</span>
                    <button type="button" onClick={() => setAkunDana({ ...akunDana, contra: akunDana.contra.filter((a) => a !== id) })}>
                      <X className="h-3 w-3" />
                    </button>
                  </li>
                ))}
              </ul>
            </div>

            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setAkunDana(null)}>Batal</Button>
              <Button type="button" loading={simpanAkun.isPending} onClick={() => simpanAkun.mutate()}>Simpan daftar akun</Button>
            </div>
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={hapus !== null}
        title="Hapus dana"
        message={`Hapus dana ${hapus?.kode_dana ?? ''}? Dana yang sudah dipakai pada baris jurnal tidak dapat dihapus — nonaktifkan saja.`}
        loading={buangDana.isPending}
        confirmLabel="Hapus"
        onCancel={() => setHapus(null)}
        onConfirm={() => hapus && buangDana.mutate(hapus.id)}
      />
    </div>
  );
}
