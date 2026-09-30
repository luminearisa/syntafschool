import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';
import { uksApi } from '../api/uksApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

/** Persediaan obat UKS: stok, ambang minimum, kedaluwarsa, dan mutasi. */
export default function UksMedicinesPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [lowOnly, setLowOnly] = useState(false);
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ code: '', name: '', unit: 'tablet', min_stock: '5', initial_stock: '0', expiry_date: '' });
  const [moving, setMoving] = useState<number | null>(null);
  const [movement, setMovement] = useState({ direction: 'MASUK', quantity: '1', reason: 'PENGADAAN' });

  const medicines = useQuery({
    queryKey: ['uks', 'medicines', { search, lowOnly }],
    queryFn: () => uksApi.medicines({ search: search || undefined, low_stock: lowOnly ? 1 : undefined, per_page: 100 }),
  });

  const create = useMutation({
    mutationFn: () => uksApi.createMedicine({
      ...form,
      min_stock: Number(form.min_stock),
      initial_stock: Number(form.initial_stock),
      expiry_date: form.expiry_date || undefined,
    }),
    onSuccess: () => {
      toast.success('Obat ditambahkan');
      setCreating(false);
      queryClient.invalidateQueries({ queryKey: ['uks', 'medicines'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const move = useMutation({
    mutationFn: () => uksApi.stockMovement(moving as number, { ...movement, quantity: Number(movement.quantity) }),
    onSuccess: () => {
      toast.success('Mutasi stok dicatat');
      setMoving(null);
      queryClient.invalidateQueries({ queryKey: ['uks', 'medicines'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const today = new Date().toISOString().slice(0, 10);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-900">Obat & Persediaan</h1>
        <Can perm="uks.medicine.manage">
          <Button onClick={() => setCreating(true)}><Plus className="mr-1 h-4 w-4" /> Tambah Obat</Button>
        </Can>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-64">
          <Label>Cari</Label>
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nama atau kode obat" />
        </div>
        <label className="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" checked={lowOnly} onChange={(e) => setLowOnly(e.target.checked)} />
          Hanya stok menipis
        </label>
      </div>

      <Card>
        <CardBody className="p-0">
          {medicines.isLoading ? <Spinner /> : null}
          {medicines.data && medicines.data.data.length === 0 ? (
            <EmptyState title="Belum ada obat" description="Tambahkan obat yang tersedia di ruang UKS." />
          ) : null}
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-slate-400">
              <tr>
                <th className="px-4 py-2">Obat</th>
                <th className="px-4 py-2">Stok</th>
                <th className="px-4 py-2">Kedaluwarsa</th>
                <th className="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {medicines.data?.data.map((medicine) => {
                const low = Number(medicine.stock) <= Number(medicine.min_stock);
                const expired = medicine.expiry_date !== null && medicine.expiry_date < today;
                return (
                  <tr key={medicine.id}>
                    <td className="px-4 py-2">
                      <p className="font-medium">{medicine.name}</p>
                      <p className="text-xs text-slate-400">{medicine.code} · {medicine.category ?? '-'}</p>
                    </td>
                    <td className="px-4 py-2">
                      <span className={low ? 'font-medium text-amber-600' : ''}>{medicine.stock} {medicine.unit}</span>
                      {low ? <Badge tone="amber" className="ml-2">menipis</Badge> : null}
                    </td>
                    <td className="px-4 py-2">
                      {medicine.expiry_date ?? '-'}
                      {expired ? <Badge tone="red" className="ml-2">kedaluwarsa</Badge> : null}
                    </td>
                    <td className="px-4 py-2 text-right">
                      <Can perm="uks.medicine.manage">
                        <Button variant="ghost" size="sm" onClick={() => setMoving(medicine.id)}>Mutasi</Button>
                      </Can>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </CardBody>
      </Card>

      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title="Tambah Obat"
        footer={<><Button variant="ghost" onClick={() => setCreating(false)}>Batal</Button><Button loading={create.isPending} onClick={() => create.mutate()}>Simpan</Button></>}
      >
        <div className="grid grid-cols-2 gap-3">
          <div><Label>Kode</Label><Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} /></div>
          <div><Label>Satuan</Label><Input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} /></div>
          <div className="col-span-2"><Label>Nama obat</Label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
          <div><Label>Stok minimum</Label><Input type="number" value={form.min_stock} onChange={(e) => setForm({ ...form, min_stock: e.target.value })} /></div>
          <div><Label>Stok awal</Label><Input type="number" value={form.initial_stock} onChange={(e) => setForm({ ...form, initial_stock: e.target.value })} /></div>
          <div className="col-span-2"><Label>Tanggal kedaluwarsa</Label><Input type="date" value={form.expiry_date} onChange={(e) => setForm({ ...form, expiry_date: e.target.value })} /></div>
        </div>
      </Modal>

      <Modal
        open={moving !== null}
        onClose={() => setMoving(null)}
        title="Mutasi Stok"
        footer={<><Button variant="ghost" onClick={() => setMoving(null)}>Batal</Button><Button loading={move.isPending} onClick={() => move.mutate()}>Catat</Button></>}
      >
        <div className="space-y-3">
          <div>
            <Label>Arah</Label>
            <Select value={movement.direction} onChange={(e) => setMovement({ ...movement, direction: e.target.value })}>
              <option value="MASUK">Masuk (pengadaan)</option>
              <option value="KELUAR">Keluar (pemakaian/buang)</option>
            </Select>
          </div>
          <div>
            <Label>Alasan</Label>
            <Select value={movement.reason} onChange={(e) => setMovement({ ...movement, reason: e.target.value })}>
              <option value="PENGADAAN">Pengadaan</option>
              <option value="PEMAKAIAN">Pemakaian</option>
              <option value="PENYESUAIAN">Penyesuaian (stok opname)</option>
              <option value="KEDALUWARSA">Kedaluwarsa</option>
              <option value="RUSAK">Rusak/hilang</option>
            </Select>
          </div>
          <div>
            <Label>Jumlah</Label>
            <Input type="number" min="0.5" step="0.5" value={movement.quantity} onChange={(e) => setMovement({ ...movement, quantity: e.target.value })} />
          </div>
          <p className="text-xs text-slate-400">Setiap mutasi tercatat di buku persediaan beserta saldo sesudahnya. Stok tidak bisa menjadi minus.</p>
        </div>
      </Modal>
    </div>
  );
}
