import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { AlertTriangle, Pill, Plus } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi, INVENTORY_RESULT_LABEL } from '../api/clinicApi';

/**
 * Farmasi klinik: antrean resep, penyerahan obat, dan master obat.
 *
 * Layar ini menegaskan satu aturan penting: **saldo stok bukan milik klinik**.
 * Kolom stok di sini hanya pembacaan dari Inventaris, dan tombol serahkan obat
 * memanggil backend yang mengurangi stok lewat InventoryProviderInterface —
 * bila stok kurang, kedaluwarsa, atau obat belum dipetakan, hasilnya dilaporkan
 * apa adanya, bukan dianggap berhasil.
 */
export default function ClinicPharmacyPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  /** Hasil penyerahan terakhir — apa adanya dari backend, termasuk kegagalan. */
  const [results, setResults] = useState<
    { medication_name: string | null; inventory_result: string; notes: string | null; quantity: number }[]
  >([]);
  const [batchForm, setBatchForm] = useState({ medication_id: '', batch_number: '', expiry_date: '', quantity_received: '' });

  const pending = useQuery({
    queryKey: ['clinic', 'prescriptions', 'pending'],
    queryFn: () => clinicApi.pendingPrescriptions(),
    refetchInterval: 30_000,
  });

  const medications = useQuery({
    queryKey: ['clinic', 'medications', search],
    queryFn: () => clinicApi.medications({ search }),
  });

  const metadata = useQuery({ queryKey: ['clinic', 'metadata'], queryFn: () => clinicApi.metadata() });

  const dispense = useMutation({
    mutationFn: (prescriptionId: number) => clinicApi.dispense(prescriptionId, {}),
    onSuccess: (result) => {
      const rows = result.results;
      setResults(rows);

      const failed = rows.filter((row) => row.inventory_result !== 'SUCCESS');
      if (failed.length === 0) {
        toast.success('Seluruh obat terserahkan dan stok Inventaris berkurang.');
      } else {
        toast.warning(`${failed.length} obat tidak dapat diserahkan — lihat laporan hasil di bawah.`);
      }

      void queryClient.invalidateQueries({ queryKey: ['clinic'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveBatch = useMutation({
    mutationFn: () =>
      clinicApi.createBatch(Number(batchForm.medication_id), {
        batch_number: batchForm.batch_number,
        expiry_date: batchForm.expiry_date || null,
        quantity_received: Number(batchForm.quantity_received || 0),
      }),
    onSuccess: () => {
      toast.success('Batch obat dicatat — dipakai untuk FEFO dan peringatan kedaluwarsa.');
      setBatchForm({ medication_id: '', batch_number: '', expiry_date: '', quantity_received: '' });
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'medications'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows = pending.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Farmasi Klinik</h1>
        <p className="text-sm text-slate-500">
          Resep yang menunggu diserahkan, master obat, dan batch kedaluwarsa. Stok tetap milik modul Inventaris.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Resep menunggu penyerahan</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            {pending.isLoading ? (
              <div className="flex justify-center p-6">
                <Spinner />
              </div>
            ) : rows.length === 0 ? (
              <div className="p-4">
                <EmptyState title="Tidak ada resep menunggu" description="Semua resep sudah diserahkan atau dibatalkan." />
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <div key={row.id} className="flex flex-wrap items-start gap-3 p-3">
                    <div className="min-w-[12rem] flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-slate-900">{row.prescription_number}</span>
                        <Badge tone={row.status === 'PARTIALLY_DISPENSED' ? 'warning' : 'info'}>{row.status}</Badge>
                        {row.allergy_override ? <Badge tone="danger">override alergi</Badge> : null}
                      </div>
                      <p className="text-xs text-slate-500">
                        {row.patient?.student?.full_name ?? 'Pasien'} ·{' '}
                        {new Date(row.prescribed_at).toLocaleString('id-ID')}
                      </p>
                      <ul className="mt-1 space-y-0.5 text-sm text-slate-700">
                        {(row.items ?? []).map((item) => (
                          <li key={item.id}>
                            {item.medication_name} · sisa {Number(item.quantity) - Number(item.dispensed_quantity)}{' '}
                            {item.unit ?? ''}
                          </li>
                        ))}
                      </ul>
                      {row.override_reason ? (
                        <p className="mt-1 rounded bg-rose-50 p-1 text-xs text-rose-700">
                          Alasan override: {row.override_reason}
                        </p>
                      ) : null}
                    </div>

                    <div className="flex flex-col gap-2">
                      <Button size="sm" onClick={() => dispense.mutate(row.id)} disabled={dispense.isPending}>
                        Serahkan obat
                      </Button>
                      {row.visit ? (
                        <Link to={`/clinic/visits/${row.visit.id}`} className="text-center text-xs text-sky-600 underline">
                          Buka kunjungan
                        </Link>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <div className="space-y-4">
          {results.length > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle>Hasil penyerahan terakhir</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm">
                {results.map((row, index) => (
                  <div
                    key={`${row.medication_name}-${index}`}
                    className={`rounded-md p-2 ${row.inventory_result === 'SUCCESS' ? 'bg-emerald-50' : 'bg-rose-50'}`}
                  >
                    <p className="font-medium text-slate-800">{row.medication_name ?? 'Obat'}</p>
                    <p className="text-xs text-slate-600">
                      {INVENTORY_RESULT_LABEL[row.inventory_result] ?? row.inventory_result} · jumlah {row.quantity}
                    </p>
                    {row.notes ? <p className="text-xs text-slate-700">{row.notes}</p> : null}
                  </div>
                ))}
              </CardBody>
            </Card>
          ) : null}

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Plus className="h-4 w-4" /> Catat batch obat
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-2">
              <div>
                <Label>Obat</Label>
                <Select
                  value={batchForm.medication_id}
                  onChange={(event) => setBatchForm({ ...batchForm, medication_id: event.target.value })}
                >
                  <option value="">— pilih obat —</option>
                  {(medications.data ?? []).map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.name}
                    </option>
                  ))}
                </Select>
              </div>
              <Input
                placeholder="Nomor batch"
                value={batchForm.batch_number}
                onChange={(event) => setBatchForm({ ...batchForm, batch_number: event.target.value })}
              />
              <div>
                <Label>Tanggal kedaluwarsa</Label>
                <Input
                  type="date"
                  value={batchForm.expiry_date}
                  onChange={(event) => setBatchForm({ ...batchForm, expiry_date: event.target.value })}
                />
              </div>
              <Input
                type="number"
                placeholder="Jumlah diterima"
                value={batchForm.quantity_received}
                onChange={(event) => setBatchForm({ ...batchForm, quantity_received: event.target.value })}
              />
              <Button
                variant="secondary"
                className="w-full"
                onClick={() => saveBatch.mutate()}
                disabled={batchForm.medication_id === '' || batchForm.batch_number.trim() === '' || saveBatch.isPending}
              >
                Simpan batch
              </Button>
              <p className="text-xs text-slate-500">
                Batch dipakai untuk FEFO (kedaluwarsa terdekat keluar lebih dahulu). Bila seluruh batch kedaluwarsa,
                obat tidak dapat diserahkan.
              </p>
            </CardBody>
          </Card>
        </div>
      </div>

      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle className="flex items-center gap-2">
            <Pill className="h-4 w-4" /> Master obat
          </CardTitle>
          <div className="w-64">
            <Input
              placeholder="Cari nama / kode obat"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
        </CardHeader>
        <CardBody className="overflow-x-auto p-0">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="p-2">Kode</th>
                <th className="p-2">Nama</th>
                <th className="p-2">Bentuk</th>
                <th className="p-2">Rute</th>
                <th className="p-2">Resep?</th>
                <th className="p-2">Stok (Inventaris)</th>
                <th className="p-2">Sumber</th>
              </tr>
            </thead>
            <tbody>
              {(medications.data ?? []).map((row) => (
                <tr key={row.id} className="border-t border-slate-100">
                  <td className="p-2 font-mono text-xs">{row.code}</td>
                  <td className="p-2">
                    {row.name}
                    {row.strength ? <span className="ml-1 text-xs text-slate-500">({row.strength})</span> : null}
                  </td>
                  <td className="p-2">{row.dosage_form}</td>
                  <td className="p-2">{row.route}</td>
                  <td className="p-2">{row.requires_prescription ? 'Ya' : 'Bebas'}</td>
                  <td className="p-2">
                    {row.stock_available === null ? (
                      <span className="text-xs text-slate-400">—</span>
                    ) : (
                      <span className={row.stock_available <= 5 ? 'font-medium text-rose-600' : ''}>
                        {row.stock_available} {row.unit}
                      </span>
                    )}
                  </td>
                  <td className="p-2">
                    {row.stock_source === 'inventory' ? (
                      <Badge tone="success">Inventaris</Badge>
                    ) : (
                      <span className="flex items-center gap-1 text-xs text-amber-600">
                        <AlertTriangle className="h-3 w-3" /> belum dipetakan
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardBody>
      </Card>

      {metadata.data?.equipment && metadata.data.equipment.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle>Alat kesehatan (dari Inventaris)</CardTitle>
          </CardHeader>
          <CardBody className="flex flex-wrap gap-2 text-sm">
            {metadata.data.equipment.map((item) => (
              <span key={item.id} className="rounded-md border p-2">
                {item.name}
                {item.serial_number ? <span className="ml-1 text-xs text-slate-500">#{item.serial_number}</span> : null}
              </span>
            ))}
          </CardBody>
        </Card>
      ) : null}
    </div>
  );
}
