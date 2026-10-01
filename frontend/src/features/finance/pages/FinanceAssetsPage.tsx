import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { AccountSelect } from '../components/AccountSelect';
import { financeStandardApi } from '../api/financeStandardApi';
import { formatRupiah } from '../lib/format';
import type { FixedAsset } from '../types/standard';

const periodeKini = () => new Date().toISOString().slice(0, 7);

/**
 * Aset tetap & penyusutan.
 *
 * Penyusutan berjalan PER PERIODE untuk seluruh aset — bukan per aset — supaya
 * tidak ada aset yang terlewat. Kunci `unique(asset_id, periode)` di basis data
 * membuatnya aman dijalankan berkali-kali.
 */
export function FinanceAssetsPage() {
  const queryClient = useQueryClient();
  const assets = useQuery({ queryKey: ['finance', 'assets'], queryFn: () => financeStandardApi.assets() });

  const [open, setOpen] = useState(false);
  const [periode, setPeriode] = useState(periodeKini());
  const [dispose, setDispose] = useState<FixedAsset | null>(null);
  const [disposeForm, setDisposeForm] = useState({ hasil_pelepasan: '0', tanggal_pelepasan: new Date().toISOString().slice(0, 10), akun_penerimaan_id: '' as number | '', akun_laba_id: '' as number | '', akun_rugi_id: '' as number | '' });
  const [form, setForm] = useState({
    kode_aset: '', nama: '', kategori: '', tanggal_perolehan: new Date().toISOString().slice(0, 10),
    harga_perolehan: '', nilai_residu: '0', umur_bulan: '48', metode: 'garis_lurus',
    akun_aset_id: '' as number | '', akun_akumulasi_id: '' as number | '', akun_beban_depresiasi_id: '' as number | '',
  });

  const pratinjau = useQuery({
    queryKey: ['finance', 'depreciation', 'preview', periode],
    enabled: false,
    queryFn: () => financeStandardApi.depreciationPreview(periode),
  });

  const simpan = useMutation({
    mutationFn: () => financeStandardApi.createAsset({
      kode_aset: form.kode_aset,
      nama: form.nama,
      kategori: form.kategori || null,
      tanggal_perolehan: form.tanggal_perolehan,
      harga_perolehan: Number(form.harga_perolehan || 0),
      nilai_residu: Number(form.nilai_residu || 0),
      umur_bulan: Number(form.umur_bulan || 0),
      metode: form.metode,
      akun_aset_id: Number(form.akun_aset_id || 0),
      akun_akumulasi_id: Number(form.akun_akumulasi_id || 0),
      akun_beban_depresiasi_id: Number(form.akun_beban_depresiasi_id || 0),
    }),
    onSuccess: () => {
      toast.success('Aset tetap didaftarkan.');
      setOpen(false);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'assets'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const jalankan = useMutation({
    mutationFn: () => financeStandardApi.runDepreciation(periode),
    onSuccess: (hasil) => {
      toast.success(hasil.diposting > 0
        ? `Penyusutan diposting untuk ${hasil.diposting} aset (total ${formatRupiah(Number(hasil.total))}).`
        : 'Tidak ada aset yang perlu disusutkan pada periode ini.');
      void queryClient.invalidateQueries({ queryKey: ['finance', 'assets'] });
      void pratinjau.refetch();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const lepas = useMutation({
    mutationFn: () => financeStandardApi.disposeAsset(dispose!.id, {
      tanggal_pelepasan: disposeForm.tanggal_pelepasan,
      hasil_pelepasan: Number(disposeForm.hasil_pelepasan || 0),
      akun_penerimaan_id: disposeForm.akun_penerimaan_id === '' ? undefined : Number(disposeForm.akun_penerimaan_id),
      akun_laba_id: disposeForm.akun_laba_id === '' ? undefined : Number(disposeForm.akun_laba_id),
      akun_rugi_id: disposeForm.akun_rugi_id === '' ? undefined : Number(disposeForm.akun_rugi_id),
    }),
    onSuccess: (hasil) => {
      toast.success(`Aset dilepas; jurnal ${hasil.transaction.transaction_number} diposting.`);
      setDispose(null);
      void queryClient.invalidateQueries({ queryKey: ['finance', 'assets'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!form.kode_aset.trim() || !form.nama.trim() || Number(form.harga_perolehan) <= 0 || Number(form.umur_bulan) <= 0) {
      toast.error('Kode, nama, harga perolehan (> 0) dan umur (bulan) wajib diisi.');
      return;
    }
    if (!form.akun_aset_id || !form.akun_akumulasi_id || !form.akun_beban_depresiasi_id) {
      toast.error('Akun aset, akumulasi penyusutan, dan beban penyusutan wajib dipilih.');
      return;
    }
    simpan.mutate();
  };

  const daftar = assets.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Aset Tetap</h1>
          <p className="text-sm text-slate-500">Register aset, penyusutan per periode, dan pelepasan beserta jurnalnya.</p>
        </div>
        <Can perm="finance.asset.manage">
          <Button onClick={() => setOpen(true)}>
            <Plus className="mr-2 h-4 w-4" /> Aset baru
          </Button>
        </Can>
      </div>

      <Card>
        <CardHeader><CardTitle>Penyusutan periode</CardTitle></CardHeader>
        <CardBody className="space-y-3">
          <div className="flex flex-wrap items-end gap-2">
            <div>
              <Label htmlFor="periode">Periode (YYYY-MM)</Label>
              <Input id="periode" value={periode} onChange={(e) => setPeriode(e.target.value)} placeholder="2026-07" className="w-40" />
            </div>
            <Button variant="outline" loading={pratinjau.isFetching} onClick={() => void pratinjau.refetch()}>
              Pratinjau
            </Button>
            <Can perm="finance.depreciation.post">
              <Button loading={jalankan.isPending} onClick={() => jalankan.mutate()}>Jalankan penyusutan</Button>
            </Can>
          </div>

          {pratinjau.data && (
            <div className="rounded-lg border border-slate-100">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase text-slate-600">
                    <th className="px-3 py-2">Aset</th>
                    <th className="px-3 py-2 text-right">Nilai buku</th>
                    <th className="px-3 py-2 text-right">Penyusutan bulan ini</th>
                  </tr>
                </thead>
                <tbody>
                  {pratinjau.data.rows.map((row) => (
                    <tr key={row.asset_id} className="border-b border-slate-50 last:border-0">
                      <td className="px-3 py-2">{row.kode_aset} {row.nama ? `· ${row.nama}` : ''}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.nilai_buku ?? 0))}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(row.nilai))}</td>
                    </tr>
                  ))}
                  <tr className="bg-slate-50 font-semibold">
                    <td className="px-3 py-2">Total</td>
                    <td className="px-3 py-2" />
                    <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(pratinjau.data.total))}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardBody>
          {assets.isLoading ? (
            <div className="flex justify-center py-10"><Spinner /></div>
          ) : daftar.length === 0 ? (
            <EmptyState title="Belum ada aset tetap" description="Daftarkan aset agar penyusutannya masuk ke laporan." />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                    <th className="px-3 py-2">Kode</th>
                    <th className="px-3 py-2">Nama</th>
                    <th className="px-3 py-2">Perolehan</th>
                    <th className="px-3 py-2 text-right">Harga</th>
                    <th className="px-3 py-2 text-right">Akumulasi</th>
                    <th className="px-3 py-2 text-right">Nilai buku</th>
                    <th className="px-3 py-2 text-right">Penyusutan/bln</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {daftar.map((asset) => (
                    <tr key={asset.id} className="border-b border-slate-50 last:border-0">
                      <td className="px-3 py-2 font-mono text-xs">{asset.kode_aset}</td>
                      <td className="px-3 py-2">{asset.nama}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">{asset.tanggal_perolehan?.slice(0, 10)}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(asset.harga_perolehan))}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(asset.akumulasi_penyusutan))}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs font-semibold">{formatRupiah(Number(asset.nilai_buku))}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs">{formatRupiah(Number(asset.penyusutan_bulanan))}</td>
                      <td className="px-3 py-2">
                        <Badge tone={asset.status === 'aktif' ? 'success' : 'neutral'}>{asset.status}</Badge>
                      </td>
                      <td className="px-3 py-2 text-right">
                        {asset.status === 'aktif' && (
                          <Can perm="finance.asset.manage">
                            <Button size="sm" variant="outline" onClick={() => setDispose(asset)}>Lepas</Button>
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

      <Modal open={open} onClose={() => setOpen(false)} title="Aset tetap baru" size="lg">
        <form className="grid gap-3 md:grid-cols-2" onSubmit={submit}>
          <div>
            <Label htmlFor="asset-code">Kode aset</Label>
            <Input id="asset-code" value={form.kode_aset} onChange={(e) => setForm({ ...form, kode_aset: e.target.value.toUpperCase() })} />
          </div>
          <div>
            <Label htmlFor="asset-name">Nama</Label>
            <Input id="asset-name" value={form.nama} onChange={(e) => setForm({ ...form, nama: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="asset-date">Tanggal perolehan</Label>
            <Input id="asset-date" type="date" value={form.tanggal_perolehan} onChange={(e) => setForm({ ...form, tanggal_perolehan: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="asset-price">Harga perolehan</Label>
            <Input id="asset-price" type="number" min={0} value={form.harga_perolehan} onChange={(e) => setForm({ ...form, harga_perolehan: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="asset-residu">Nilai residu</Label>
            <Input id="asset-residu" type="number" min={0} value={form.nilai_residu} onChange={(e) => setForm({ ...form, nilai_residu: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="asset-life">Umur (bulan)</Label>
            <Input id="asset-life" type="number" min={1} value={form.umur_bulan} onChange={(e) => setForm({ ...form, umur_bulan: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="asset-method">Metode</Label>
            <Select id="asset-method" value={form.metode} onChange={(e) => setForm({ ...form, metode: e.target.value })}>
              <option value="garis_lurus">Garis lurus</option>
              <option value="saldo_menurun">Saldo menurun</option>
            </Select>
          </div>
          <div>
            <Label htmlFor="asset-kategori">Kategori</Label>
            <Input id="asset-kategori" value={form.kategori} onChange={(e) => setForm({ ...form, kategori: e.target.value })} />
          </div>
          <div>
            <Label>Akun aset (kelompok 1)</Label>
            <AccountSelect value={form.akun_aset_id} root="1" onChange={(value) => setForm({ ...form, akun_aset_id: value })} />
          </div>
          <div>
            <Label>Akun akumulasi penyusutan</Label>
            <AccountSelect value={form.akun_akumulasi_id} root="1" onChange={(value) => setForm({ ...form, akun_akumulasi_id: value })} />
          </div>
          <div>
            <Label>Akun beban penyusutan (kelompok 5)</Label>
            <AccountSelect value={form.akun_beban_depresiasi_id} root="5" onChange={(value) => setForm({ ...form, akun_beban_depresiasi_id: value })} />
          </div>
          <div className="md:col-span-2 flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Batal</Button>
            <Button type="submit" loading={simpan.isPending}>Simpan</Button>
          </div>
        </form>
      </Modal>

      <Modal open={dispose !== null} onClose={() => setDispose(null)} title={`Pelepasan aset ${dispose?.kode_aset ?? ''}`} size="lg">
        <div className="grid gap-3 md:grid-cols-2">
          <div>
            <Label htmlFor="dispose-date">Tanggal pelepasan</Label>
            <Input id="dispose-date" type="date" value={disposeForm.tanggal_pelepasan} onChange={(e) => setDisposeForm({ ...disposeForm, tanggal_pelepasan: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="dispose-amount">Hasil pelepasan</Label>
            <Input id="dispose-amount" type="number" min={0} value={disposeForm.hasil_pelepasan} onChange={(e) => setDisposeForm({ ...disposeForm, hasil_pelepasan: e.target.value })} />
          </div>
          <div>
            <Label>Akun penerimaan (kas/bank)</Label>
            <AccountSelect value={disposeForm.akun_penerimaan_id} root="1" onChange={(value) => setDisposeForm({ ...disposeForm, akun_penerimaan_id: value })} />
          </div>
          <div>
            <Label>Akun laba pelepasan</Label>
            <AccountSelect value={disposeForm.akun_laba_id} root="4" onChange={(value) => setDisposeForm({ ...disposeForm, akun_laba_id: value })} />
          </div>
          <div>
            <Label>Akun kerugian pelepasan</Label>
            <AccountSelect value={disposeForm.akun_rugi_id} root="5" onChange={(value) => setDisposeForm({ ...disposeForm, akun_rugi_id: value })} />
          </div>
          <div className="md:col-span-2 text-xs text-slate-500">
            Satu jurnal akan menutup seluruh perjalanan aset: uang yang diterima, akumulasi penyusutan yang dibuang,
            harga perolehan yang dikeluarkan dari neraca, dan selisihnya sebagai laba/rugi.
          </div>
          <div className="md:col-span-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setDispose(null)}>Batal</Button>
            <Button type="button" loading={lepas.isPending} onClick={() => lepas.mutate()}>Lepas aset</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
