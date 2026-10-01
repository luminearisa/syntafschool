import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
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
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { AccountSelect } from '../components/AccountSelect';
import { financeStandardApi } from '../api/financeStandardApi';
import { formatRupiah } from '../lib/format';

/**
 * Akrual: mengakui pendapatan/beban di periode yang benar, lalu membalikkannya
 * otomatis pada tanggal baliknya.
 *
 * Sisi akunnya tidak bebas: akrual pendapatan memakai piutang (kelompok 1) &
 * pendapatan (4); akrual beban memakai beban (5) & utang (2).
 */
export function FinanceAccrualsPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'accruals'], queryFn: () => financeStandardApi.accruals() });

  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({
    kode: '', tanggal: new Date().toISOString().slice(0, 10), tanggal_balik: '', jenis: 'beban' as 'beban' | 'pendapatan',
    akun_akrual_id: '' as number | '', akun_lawan_id: '' as number | '', jumlah: '', deskripsi: '',
  });

  const simpan = useMutation({
    mutationFn: () => financeStandardApi.createAccrual({
      kode: form.kode,
      tanggal: form.tanggal,
      tanggal_balik: form.tanggal_balik,
      jenis: form.jenis,
      akun_akrual_id: Number(form.akun_akrual_id || 0),
      akun_lawan_id: Number(form.akun_lawan_id || 0),
      jumlah: Number(form.jumlah || 0),
      deskripsi: form.deskripsi || null,
    }),
    onSuccess: () => {
      toast.success('Akrual dibuat sebagai rancangan.');
      setOpen(false);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'accruals'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const posting = useMutation({
    mutationFn: (id: number) => financeStandardApi.postAccrual(id),
    onSuccess: () => {
      toast.success('Akrual diposting.');
      void queryClient.invalidateQueries({ queryKey: ['finance', 'accruals'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const balik = useMutation({
    mutationFn: () => financeStandardApi.reverseDueAccruals(),
    onSuccess: (hasil) => {
      toast.success(hasil.dibalik > 0 ? `${hasil.dibalik} akrual dibalik (total ${formatRupiah(Number(hasil.total))}).` : 'Tidak ada akrual yang jatuh tempo.');
      void queryClient.invalidateQueries({ queryKey: ['finance', 'accruals'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!form.kode.trim() || !form.tanggal_balik || Number(form.jumlah) <= 0) {
      toast.error('Kode, tanggal balik, dan jumlah (> 0) wajib diisi.');
      return;
    }
    if (form.tanggal_balik <= form.tanggal) {
      toast.error('Tanggal balik harus sesudah tanggal pengakuan.');
      return;
    }
    if (!form.akun_akrual_id || !form.akun_lawan_id) {
      toast.error('Akun akrual dan akun lawannya wajib dipilih.');
      return;
    }
    simpan.mutate();
  };

  const accruals = data?.accruals ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Akrual</h1>
          <p className="text-sm text-slate-500">
            Akrual lahir dengan tanggal balik; pembalikannya otomatis agar pendapatan tidak tercatat dua kali.
          </p>
        </div>
        <div className="flex items-center gap-2">
          {(data?.due_for_reversal ?? 0) > 0 && <Badge tone="warning">{data?.due_for_reversal} jatuh tempo</Badge>}
          <Can perm="finance.accrual.manage">
            <Button variant="outline" loading={balik.isPending} onClick={() => balik.mutate()}>Balik yang jatuh tempo</Button>
            <Button onClick={() => setOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> Akrual baru
            </Button>
          </Can>
        </div>
      </div>

      <Card>
        <CardBody>
          {isLoading ? (
            <div className="flex justify-center py-10"><Spinner /></div>
          ) : accruals.length === 0 ? (
            <EmptyState title="Belum ada akrual" />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                    <th className="px-3 py-2">Kode</th>
                    <th className="px-3 py-2">Tanggal</th>
                    <th className="px-3 py-2">Balik</th>
                    <th className="px-3 py-2">Jenis</th>
                    <th className="px-3 py-2">Akun</th>
                    <th className="px-3 py-2 text-right">Jumlah</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {accruals.map((accrual) => (
                    <tr key={accrual.id} className="border-b border-slate-50 last:border-0">
                      <td className="px-3 py-2 font-mono text-xs">{accrual.kode}</td>
                      <td className="px-3 py-2 text-xs">{accrual.tanggal?.slice(0, 10)}</td>
                      <td className="px-3 py-2 text-xs">{accrual.tanggal_balik?.slice(0, 10)}</td>
                      <td className="px-3 py-2 text-xs capitalize">{accrual.jenis}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">
                        {accrual.akun_akrual?.code ?? accrual.akun_akrual_id} / {accrual.akun_lawan?.code ?? accrual.akun_lawan_id}
                      </td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(accrual.jumlah))}</td>
                      <td className="px-3 py-2">
                        <Badge tone={accrual.status === 'posted' ? 'info' : accrual.status === 'reversed' ? 'success' : 'neutral'}>
                          {accrual.status}
                        </Badge>
                      </td>
                      <td className="px-3 py-2 text-right">
                        {accrual.status === 'draft' && (
                          <Can perm="finance.accrual.manage">
                            <Button size="sm" loading={posting.isPending} onClick={() => posting.mutate(accrual.id)}>Posting</Button>
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

      <Modal open={open} onClose={() => setOpen(false)} title="Akrual baru" size="lg">
        <form className="grid gap-3 md:grid-cols-2" onSubmit={submit}>
          <div>
            <Label htmlFor="accrual-code">Kode</Label>
            <Input id="accrual-code" value={form.kode} onChange={(e) => setForm({ ...form, kode: e.target.value.toUpperCase() })} placeholder="AKR-001" />
          </div>
          <div>
            <Label htmlFor="accrual-jenis">Jenis</Label>
            <Select id="accrual-jenis" value={form.jenis} onChange={(e) => setForm({ ...form, jenis: e.target.value as 'beban' | 'pendapatan', akun_akrual_id: '', akun_lawan_id: '' })}>
              <option value="beban">Beban akrual (beban 5 / utang 2)</option>
              <option value="pendapatan">Pendapatan akrual (piutang 1 / pendapatan 4)</option>
            </Select>
          </div>
          <div>
            <Label htmlFor="accrual-date">Tanggal pengakuan</Label>
            <Input id="accrual-date" type="date" value={form.tanggal} onChange={(e) => setForm({ ...form, tanggal: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="accrual-reverse">Tanggal balik</Label>
            <Input id="accrual-reverse" type="date" value={form.tanggal_balik} onChange={(e) => setForm({ ...form, tanggal_balik: e.target.value })} />
          </div>
          <div>
            <Label>{form.jenis === 'beban' ? 'Akun utang akrual (kelompok 2)' : 'Akun piutang akrual (kelompok 1)'}</Label>
            <AccountSelect value={form.akun_akrual_id} root={form.jenis === 'beban' ? '2' : '1'} onChange={(value) => setForm({ ...form, akun_akrual_id: value })} />
          </div>
          <div>
            <Label>{form.jenis === 'beban' ? 'Akun beban (kelompok 5)' : 'Akun pendapatan (kelompok 4)'}</Label>
            <AccountSelect value={form.akun_lawan_id} root={form.jenis === 'beban' ? '5' : '4'} onChange={(value) => setForm({ ...form, akun_lawan_id: value })} />
          </div>
          <div>
            <Label htmlFor="accrual-amount">Jumlah</Label>
            <Input id="accrual-amount" type="number" min={0} value={form.jumlah} onChange={(e) => setForm({ ...form, jumlah: e.target.value })} />
          </div>
          <div className="md:col-span-2">
            <Label htmlFor="accrual-desc">Deskripsi</Label>
            <Textarea id="accrual-desc" rows={2} value={form.deskripsi} onChange={(e) => setForm({ ...form, deskripsi: e.target.value })} />
          </div>
          <div className="md:col-span-2 flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Batal</Button>
            <Button type="submit" loading={simpan.isPending}>Simpan</Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
