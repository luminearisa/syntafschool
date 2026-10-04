import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { Field, Money, Panel, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { KanbanColumn } from '../types';

const COLUMNS: KanbanColumn[] = ['WAITING', 'WASHING', 'DRYING', 'IRONING', 'FOLDING', 'QUALITY_CHECK', 'PACKAGING', 'READY'];
const STAGES = ['WASHING', 'DRYING', 'IRONING', 'FOLDING'];

/**
 * Papan proses kanban (§32) + batch per tahap (§33) + langkah individual
 * (§35–§37) + QC (§38) + kemasan (§42).
 */
export default function LaundryProcessingPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [batchForm, setBatchForm] = useState({ stage: 'WASHING', laundry_machine_id: '', order_ids: '' });
  const [stepForm, setStepForm] = useState({ order_id: '', step_type: 'WASHING', action: 'start' as 'start' | 'complete', notes: '' });
  const [qcForm, setQcForm] = useState({ order_id: '', result: 'PASSED', notes: '' });

  const board = useQuery({ queryKey: ['laundry', 'kanban'], queryFn: () => laundryApi.kanban(), refetchInterval: 30_000 });
  const batches = useQuery({ queryKey: ['laundry', 'batches'], queryFn: () => laundryApi.batches({ per_page: 10 }) });
  const machines = useQuery({ queryKey: ['laundry', 'machines'], queryFn: () => laundryApi.machines() });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['laundry', 'kanban'] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'batches'] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'orders'] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'dashboard'] });
  };

  const createBatch = useMutation({
    mutationFn: () => laundryApi.createBatch({
      stage: batchForm.stage,
      laundry_machine_id: batchForm.laundry_machine_id ? Number(batchForm.laundry_machine_id) : undefined,
      order_ids: batchForm.order_ids.split(',').map((s) => Number(s.trim())).filter((n) => Number.isFinite(n) && n > 0),
    }),
    onSuccess: (batch) => { toast.success(`Batch ${batch.batch_number} dibuat`); setBatchForm({ ...batchForm, order_ids: '' }); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal membuat batch')),
  });

  const startBatch = useMutation({
    mutationFn: (id: number) => laundryApi.startBatch(id),
    onSuccess: () => { toast.success('Batch dimulai — order masuk tahap'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal memulai batch')),
  });

  const completeBatch = useMutation({
    mutationFn: (id: number) => laundryApi.completeBatch(id),
    onSuccess: () => { toast.success('Batch selesai — order maju ke tahap berikut'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menyelesaikan batch')),
  });

  const recordStep = useMutation({
    mutationFn: () => laundryApi.recordStep(Number(stepForm.order_id), {
      step_type: stepForm.step_type,
      action: stepForm.action,
      notes: stepForm.notes || undefined,
    }),
    onSuccess: () => { toast.success('Langkah tercatat'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mencatat langkah')),
  });

  const qualityCheck = useMutation({
    mutationFn: () => laundryApi.qualityCheck(Number(qcForm.order_id), {
      result: qcForm.result,
      notes: qcForm.notes || undefined,
    }),
    onSuccess: () => { toast.success(qcForm.result === 'PASSED' ? 'QC lulus — siap dikemas' : 'QC gagal — order kembali ke tahap perbaikan'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menyimpan QC')),
  });

  const packageOrder = useMutation({
    mutationFn: (id: number) => laundryApi.packageOrder(id),
    onSuccess: () => { toast.success('Order dikemas'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mengemas — pastikan QC lulus')),
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Papan Proses</h1>
        <p className="text-sm text-slate-500">Kanban antrean → batch per tahap → QC → kemasan. Order belum lunas ditolak (Rule 27).</p>
      </div>

      <Panel title="Kanban antrean" description="Klik nomor order untuk detail">
        {board.isLoading || !board.data ? (
          <p className="text-sm text-slate-500">Memuat papan…</p>
        ) : (
          <div className="grid gap-3 md:grid-cols-4">
            {COLUMNS.map((column) => (
              <div key={column} className="rounded-xl border border-slate-200 bg-slate-50 p-2">
                <p className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {column} ({board.data[column]?.length ?? 0})
                </p>
                <ul className="mt-2 space-y-2">
                  {(board.data[column] ?? []).map((card) => (
                    <li key={card.id} className={`rounded-lg border bg-white p-2 text-sm ${card.delayed ? 'border-rose-300' : 'border-slate-200'}`}>
                      <Link to={`/laundry/orders/${card.id}`} className="font-medium text-slate-800 underline">{card.order_number}</Link>
                      <p className="text-xs text-slate-500">{card.student_name ?? '—'}{card.delayed ? ' · TELAT' : ''}</p>
                      <p className="text-xs text-slate-500"><Weight value={card.net_weight} /> · <StatusPill status={card.payment_status} /></p>
                      {can('laundry.process') && column === 'QUALITY_CHECK' ? (
                        <Button size="sm" variant="outline" className="mt-1" onClick={() => setQcForm({ ...qcForm, order_id: String(card.id) })}>
                          QC order ini
                        </Button>
                      ) : null}
                      {can('laundry.process') && column === 'PACKAGING' ? (
                        <Button size="sm" variant="outline" className="mt-1" onClick={() => packageOrder.mutate(card.id)} loading={packageOrder.isPending}>
                          Kemas
                        </Button>
                      ) : null}
                    </li>
                  ))}
                  {(board.data[column] ?? []).length === 0 ? (
                    <li className="px-1 text-xs text-slate-400">Kosong</li>
                  ) : null}
                </ul>
              </div>
            ))}
          </div>
        )}
      </Panel>

      {can('laundry.batch.manage') ? (
        <Panel title="Buat batch" description="Banyak order, satu mesin/operator, satu tahap (§33)">
          <div className="grid gap-3 md:grid-cols-4">
            <Field label="Tahap" required>
              <Select value={batchForm.stage} onChange={(e) => setBatchForm({ ...batchForm, stage: e.target.value })}>
                {STAGES.map((s) => <option key={s} value={s}>{s}</option>)}
              </Select>
            </Field>
            <Field label="Mesin (opsional)">
              <Select value={batchForm.laundry_machine_id} onChange={(e) => setBatchForm({ ...batchForm, laundry_machine_id: e.target.value })}>
                <option value="">— tanpa mesin —</option>
                {(machines.data ?? []).filter((m) => m.status === 'OPERATIONAL').map((m) => (
                  <option key={m.id} value={m.id}>{m.code} — {m.name}</option>
                ))}
              </Select>
            </Field>
            <Field label="ID order (koma)" required>
              <Input value={batchForm.order_ids} onChange={(e) => setBatchForm({ ...batchForm, order_ids: e.target.value })} placeholder="cth. 12, 13, 14" />
            </Field>
            <div className="flex items-end">
              <Button onClick={() => createBatch.mutate()} loading={createBatch.isPending} disabled={!batchForm.order_ids.trim()}>
                Buat batch
              </Button>
            </div>
          </div>
        </Panel>
      ) : null}

      <Panel title="Batch terbaru" description="Mulai → order masuk tahap · selesai → order maju">
        <ul className="space-y-2 text-sm">
          {(batches.data?.rows ?? []).map((batch) => (
            <li key={batch.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2">
              <span className="font-medium">{batch.batch_number}</span>
              <StatusPill status={batch.stage} />
              <StatusPill status={batch.status} />
              <span className="text-slate-500">{batch.total_orders ?? 0} order · <Weight value={batch.total_weight} /></span>
              {can('laundry.process') && batch.status === 'PLANNED' ? (
                <Button size="sm" onClick={() => startBatch.mutate(batch.id)} loading={startBatch.isPending}>Mulai</Button>
              ) : null}
              {can('laundry.process') && batch.status === 'IN_PROGRESS' ? (
                <Button size="sm" variant="secondary" onClick={() => completeBatch.mutate(batch.id)} loading={completeBatch.isPending}>Selesaikan</Button>
              ) : null}
            </li>
          ))}
          {(batches.data?.rows ?? []).length === 0 ? <li className="text-slate-500">Belum ada batch.</li> : null}
        </ul>
      </Panel>

      <div className="grid gap-4 lg:grid-cols-2">
        {can('laundry.process') ? (
          <Panel title="Langkah individual" description="Tanpa batch — mesin tunggal / order satuan (§35–§37)">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="ID order" required>
                <Input value={stepForm.order_id} onChange={(e) => setStepForm({ ...stepForm, order_id: e.target.value })} />
              </Field>
              <Field label="Tahap">
                <Select value={stepForm.step_type} onChange={(e) => setStepForm({ ...stepForm, step_type: e.target.value })}>
                  {[...STAGES, 'PACKAGING'].map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </Field>
              <Field label="Aksi">
                <Select value={stepForm.action} onChange={(e) => setStepForm({ ...stepForm, action: e.target.value as 'start' | 'complete' })}>
                  <option value="start">start — mulai</option>
                  <option value="complete">complete — selesai</option>
                </Select>
              </Field>
              <Field label="Catatan">
                <Input value={stepForm.notes} onChange={(e) => setStepForm({ ...stepForm, notes: e.target.value })} />
              </Field>
            </div>
            <div className="mt-3">
              <Button onClick={() => recordStep.mutate()} loading={recordStep.isPending} disabled={!stepForm.order_id}>Catat langkah</Button>
            </div>
          </Panel>
        ) : null}

        {can('laundry.qc') ? (
          <Panel title="Quality control" description="PASSED → kemas · FAILED → cuci ulang · REWORK → setrika (§38)">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="ID order" required>
                <Input value={qcForm.order_id} onChange={(e) => setQcForm({ ...qcForm, order_id: e.target.value })} />
              </Field>
              <Field label="Hasil">
                <Select value={qcForm.result} onChange={(e) => setQcForm({ ...qcForm, result: e.target.value })}>
                  <option value="PASSED">PASSED — lulus</option>
                  <option value="FAILED">FAILED — cuci ulang</option>
                  <option value="REWORK">REWORK — perbaiki setrika</option>
                </Select>
              </Field>
            </div>
            <div className="mt-3">
              <Field label="Catatan QC">
                <Textarea value={qcForm.notes} onChange={(e) => setQcForm({ ...qcForm, notes: e.target.value })} rows={2} />
              </Field>
            </div>
            <div className="mt-3 flex items-center gap-2">
              <Button onClick={() => qualityCheck.mutate()} loading={qualityCheck.isPending} disabled={!qcForm.order_id}>
                Simpan QC
              </Button>
              <span className="text-xs text-slate-500">Biaya QC: <Money value={0} /></span>
            </div>
          </Panel>
        ) : null}
      </div>
    </div>
  );
}
