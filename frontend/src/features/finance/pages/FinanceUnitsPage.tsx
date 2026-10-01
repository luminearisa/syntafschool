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
import { financeStandardApi } from '../api/financeStandardApi';

/**
 * Unit bisnis (pusat laba) & unit bawaan per modul jurnal.
 *
 * Unit menentukan laba rugi dan arus kas per satuan usaha. Baris NERACA tidak
 * mengikuti unit dokumen: seluruh liabilitas & rekening kas/bank memakai unit
 * penampung (diatur di Pengaturan Keuangan), karena neraca hanya bermakna di
 * tingkat yayasan.
 */
export function FinanceUnitsPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'units'], queryFn: () => financeStandardApi.units() });

  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ code: '', name: '', parent_code: '', description: '' });

  const simpan = useMutation({
    mutationFn: () => financeStandardApi.createUnit({
      code: form.code,
      name: form.name,
      parent_code: form.parent_code || null,
      description: form.description || null,
      is_active: true,
    }),
    onSuccess: () => {
      toast.success('Unit bisnis dibuat.');
      setOpen(false);
      setForm({ code: '', name: '', parent_code: '', description: '' });
      void queryClient.invalidateQueries({ queryKey: ['finance', 'units'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const toggle = useMutation({
    mutationFn: ({ code, active }: { code: string; active: boolean }) => financeStandardApi.toggleUnit(code, active),
    onSuccess: () => {
      toast.success('Status unit diperbarui.');
      void queryClient.invalidateQueries({ queryKey: ['finance', 'units'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const setDefault = useMutation({
    mutationFn: ({ modul, unit }: { modul: string; unit: string | null }) => financeStandardApi.setUnitDefault(modul, unit),
    onSuccess: () => {
      toast.success('Unit bawaan modul disimpan.');
      void queryClient.invalidateQueries({ queryKey: ['finance', 'units'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const units = data?.units ?? [];
  const defaults = data?.defaults ?? {};
  const modules = data?.modules ?? [];

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!form.code.trim() || !form.name.trim()) {
      toast.error('Kode dan nama unit wajib diisi.');
      return;
    }
    simpan.mutate();
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Unit Bisnis</h1>
          <p className="text-sm text-slate-500">
            Pusat laba untuk memisahkan laba rugi &amp; arus kas — neraca tetap dipusatkan pada unit penampung.
          </p>
        </div>
        <Can perm="finance.account.manage">
          <Button onClick={() => setOpen(true)}>
            <Plus className="mr-2 h-4 w-4" /> Unit baru
          </Button>
        </Can>
      </div>

      <Card>
        <CardBody>
          {isLoading ? (
            <div className="flex justify-center py-10"><Spinner /></div>
          ) : units.length === 0 ? (
            <EmptyState title="Belum ada unit bisnis" description="Tambahkan unit agar laporan per unit dapat disusun." />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-600">
                    <th className="px-3 py-2">Kode</th>
                    <th className="px-3 py-2">Nama</th>
                    <th className="px-3 py-2">Induk</th>
                    <th className="px-3 py-2">Level</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {units.map((unit) => (
                    <tr key={unit.code} className="border-b border-slate-50 last:border-0">
                      <td className="px-3 py-2 font-mono text-xs">{unit.code}</td>
                      <td className="px-3 py-2">{unit.name}</td>
                      <td className="px-3 py-2 font-mono text-xs text-slate-500">{unit.parent_code ?? '—'}</td>
                      <td className="px-3 py-2">{unit.level}</td>
                      <td className="px-3 py-2">
                        <Badge tone={unit.is_active ? 'success' : 'neutral'}>{unit.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                      </td>
                      <td className="px-3 py-2 text-right">
                        <Can perm="finance.account.manage">
                          <Button
                            size="sm"
                            variant="outline"
                            loading={toggle.isPending}
                            onClick={() => toggle.mutate({ code: unit.code, active: !unit.is_active })}
                          >
                            {unit.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                          </Button>
                        </Can>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Unit bawaan per modul</CardTitle>
        </CardHeader>
        <CardBody>
          <p className="mb-3 text-xs text-slate-500">
            Dipakai HANYA bila dokumen tidak menentukan unitnya sendiri. Baris kas/bank &amp; liabilitas selalu
            memakai unit penampung, bukan unit di bawah ini.
          </p>
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {modules.map((modul) => (
              <div key={modul.value} className="space-y-1">
                <Label htmlFor={`modul-${modul.value}`}>{modul.label}</Label>
                <Select
                  id={`modul-${modul.value}`}
                  value={defaults[modul.value] ?? ''}
                  disabled={setDefault.isPending}
                  onChange={(event) => setDefault.mutate({ modul: modul.value, unit: event.target.value || null })}
                >
                  <option value="">(tidak dipetakan)</option>
                  {units.filter((u) => u.is_active).map((unit) => (
                    <option key={unit.code} value={unit.code}>{unit.code} · {unit.name}</option>
                  ))}
                </Select>
              </div>
            ))}
          </div>
        </CardBody>
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="Unit bisnis baru">
        <form className="space-y-3" onSubmit={submit}>
          <div>
            <Label htmlFor="unit-code">Kode</Label>
            <Input id="unit-code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="ASRAMA" />
          </div>
          <div>
            <Label htmlFor="unit-name">Nama</Label>
            <Input id="unit-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Asrama Putra" />
          </div>
          <div>
            <Label htmlFor="unit-parent">Induk</Label>
            <Select id="unit-parent" value={form.parent_code} onChange={(e) => setForm({ ...form, parent_code: e.target.value })}>
              <option value="">(unit utama)</option>
              {units.map((unit) => (
                <option key={unit.code} value={unit.code}>{unit.code} · {unit.name}</option>
              ))}
            </Select>
            <p className="mt-1 text-xs text-slate-500">Level diturunkan dari induknya, tidak diketik manual.</p>
          </div>
          <div>
            <Label htmlFor="unit-desc">Keterangan</Label>
            <Input id="unit-desc" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Batal</Button>
            <Button type="submit" loading={simpan.isPending}>Simpan</Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
