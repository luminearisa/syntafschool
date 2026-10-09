import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import {
  BadgeCheck, Boxes, Layers, PackageCheck, PlayCircle, Sparkles, SquareStack, Timer, WashingMachine,
} from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SideModal } from '@/components/ui/SideModal';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { Field, Panel, PageHeader, StatusPill, Weight, readError } from '../components/LaundryUi';
import { cn } from '@/lib/utils';
import type { KanbanCard, KanbanColumn } from '../types';

const COLUMNS: Array<{ key: KanbanColumn; label: string; hint: string; accent: string }> = [
  { key: 'WAITING', label: 'Mengantre', hint: 'menunggu masuk tahap', accent: 'border-t-slate-400' },
  { key: 'WASHING', label: 'Mencuci', hint: 'tahap cuci', accent: 'border-t-sky-500' },
  { key: 'DRYING', label: 'Mengeringkan', hint: 'tahap kering', accent: 'border-t-amber-500' },
  { key: 'IRONING', label: 'Menyetrika', hint: 'tahap setrika', accent: 'border-t-orange-500' },
  { key: 'FOLDING', label: 'Melipat', hint: 'tahap lipat', accent: 'border-violet-500' },
  { key: 'QUALITY_CHECK', label: 'QC', hint: 'pemeriksaan mutu', accent: 'border-t-indigo-500' },
  { key: 'PACKAGING', label: 'Pengemasan', hint: 'siap dikemas', accent: 'border-t-teal-500' },
  { key: 'READY', label: 'Siap Diambil', hint: 'menunggu serah terima', accent: 'border-t-emerald-500' },
];
const STAGES = ['WASHING', 'DRYING', 'IRONING', 'FOLDING'];

/**
 * Papan proses kanban (§32) + batch per tahap (§33) + langkah individual
 * (§35–§37) + QC (§38) + kemasan (§42). Semua aksi pencatatan berada di
 * drawer samping sehingga papan tetap terlihat.
 */
export default function LaundryProcessingPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [batchForm, setBatchForm] = useState({ stage: 'WASHING', laundry_machine_id: '', order_ids: '' });
  const [stepForm, setStepForm] = useState({ order_id: '', step_type: 'WASHING', action: 'start' as 'start' | 'complete', notes: '' });
  const [qcForm, setQcForm] = useState({ order_id: '', result: 'PASSED', notes: '' });
  const [batchModalOpen, setBatchModalOpen] = useState(false);
  const [stepModalOpen, setStepModalOpen] = useState(false);
  const [qcModalOpen, setQcModalOpen] = useState(false);

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
    onSuccess: (batch) => { toast.success(`Batch ${batch.batch_number} dibuat`); setBatchForm({ ...batchForm, order_ids: '' }); setBatchModalOpen(false); refresh(); },
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
    onSuccess: () => { toast.success('Langkah tercatat'); setStepModalOpen(false); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mencatat langkah')),
  });

  const qualityCheck = useMutation({
    mutationFn: () => laundryApi.qualityCheck(Number(qcForm.order_id), {
      result: qcForm.result,
      notes: qcForm.notes || undefined,
    }),
    onSuccess: () => { toast.success(qcForm.result === 'PASSED' ? 'QC lulus — siap dikemas' : 'QC gagal — order kembali ke tahap perbaikan'); setQcModalOpen(false); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menyimpan QC')),
  });

  const packageOrder = useMutation({
    mutationFn: (id: number) => laundryApi.packageOrder(id),
    onSuccess: () => { toast.success('Order dikemas'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mengemas — pastikan QC lulus')),
  });

  const openQc = (card: KanbanCard) => {
    setQcForm({ order_id: String(card.id), result: 'PASSED', notes: '' });
    setQcModalOpen(true);
  };

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<WashingMachine className="h-6 w-6" />}
        title="Papan Proses"
        description="Kanban antrean → batch per tahap → QC → kemasan. Order belum lunas ditolak (Rule 27)."
        actions={
          <>
            {can('laundry.batch.manage') ? (
              <Button onClick={() => setBatchModalOpen(true)}>
                <Boxes className="h-4 w-4" /> Buat Batch
              </Button>
            ) : null}
            {can('laundry.process') ? (
              <Button variant="outline" onClick={() => setStepModalOpen(true)}>
                <Layers className="h-4 w-4" /> Catat Langkah
              </Button>
            ) : null}
            {can('laundry.qc') ? (
              <Button variant="outline" onClick={() => { setQcForm({ order_id: '', result: 'PASSED', notes: '' }); setQcModalOpen(true); }}>
                <BadgeCheck className="h-4 w-4" /> QC Order
              </Button>
            ) : null}
          </>
        }
      />

      <Panel title="Kanban antrean" description="Klik nomor order untuk detail — papan menyegarkan otomatis tiap 30 detik">
        {board.isLoading || !board.data ? (
          <p className="text-sm text-slate-500">Memuat papan…</p>
        ) : (
          <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-4">
            {COLUMNS.map((column) => {
              const cards = board.data[column.key] ?? [];
              return (
                <div key={column.key} className={cn('rounded-xl border border-slate-200 border-t-4 bg-slate-50/70 p-2.5', column.accent)}>
                  <div className="flex items-center justify-between px-1 pb-2">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-600">{column.label}</p>
                  <p className="text-[10px] text-slate-400">{column.hint}</p>
                </div>
                <span className={cn('ml-2 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums', cards.length > 0 ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-500')}>
                  {cards.length}
                </span>
              </div>
                  <ul className="space-y-2">
                    {cards.map((card) => (
                      <li key={card.id} className={cn('rounded-lg border bg-white p-2.5 text-sm shadow-sm transition-shadow hover:shadow-soft', card.delayed ? 'border-rose-300' : 'border-slate-200')}>
                        <div className="flex items-center justify-between gap-1">
                          <Link to={`/laundry/orders/${card.id}`} className="font-medium text-brand-700 hover:underline">{card.order_number}</Link>
                          {card.delayed ? (
                            <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-600">
                              <Timer className="h-3 w-3" /> TELAT
                            </span>
                          ) : null}
                        </div>
                        <p className="mt-0.5 text-xs text-slate-500">{card.student_name ?? '—'}</p>
                        <div className="mt-1 flex items-center gap-2 text-xs text-slate-500">
                          <Weight value={card.net_weight} />
                          <StatusPill status={card.payment_status} />
                        </div>
                        {can('laundry.process') && column.key === 'QUALITY_CHECK' ? (
                          <Button size="sm" variant="outline" className="mt-2 w-full" onClick={() => openQc(card)}>
                            <BadgeCheck className="h-3.5 w-3.5" /> QC order ini
                          </Button>
                        ) : null}
                        {can('laundry.process') && column.key === 'PACKAGING' ? (
                          <Button size="sm" variant="outline" className="mt-2 w-full" onClick={() => packageOrder.mutate(card.id)} loading={packageOrder.isPending}>
                            <PackageCheck className="h-3.5 w-3.5" /> Kemas
                          </Button>
                        ) : null}
                      </li>
                    ))}
                    {cards.length === 0 ? (
                      <li className="rounded-lg border border-dashed border-slate-200 px-2 py-3 text-center text-xs text-slate-400">Kosong</li>
                    ) : null}
                  </ul>
                </div>
              );
            })}
          </div>
        )}
      </Panel>

      <Panel title="Batch terbaru" description="Mulai → order masuk tahap · selesai → order maju">
        <ul className="space-y-2 text-sm">
          {(batches.data?.rows ?? []).map((batch) => (
            <li key={batch.id} className="flex flex-wrap items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5">
              <SquareStack className="h-4 w-4 shrink-0 text-brand-600" />
              <span className="font-mono text-xs font-semibold text-slate-900">{batch.batch_number}</span>
              <StatusPill status={batch.stage} />
              <StatusPill status={batch.status} />
              <span className="text-xs text-slate-500">{batch.total_orders ?? 0} order · <Weight value={batch.total_weight} /></span>
              <span className="ml-auto flex items-center gap-2">
                {can('laundry.process') && batch.status === 'PLANNED' ? (
                  <Button size="sm" onClick={() => startBatch.mutate(batch.id)} loading={startBatch.isPending}>
                    <PlayCircle className="h-3.5 w-3.5" /> Mulai
                  </Button>
                ) : null}
                {can('laundry.process') && batch.status === 'IN_PROGRESS' ? (
                  <Button size="sm" variant="secondary" onClick={() => completeBatch.mutate(batch.id)} loading={completeBatch.isPending}>Selesaikan</Button>
                ) : null}
              </span>
            </li>
          ))}
          {(batches.data?.rows ?? []).length === 0 ? <li className="py-6 text-center text-sm text-slate-400">Belum ada batch.</li> : null}
        </ul>
      </Panel>

      {/* ── Drawer: buat batch ──────────────────────────────────────── */}
      <SideModal
        open={batchModalOpen}
        onClose={() => setBatchModalOpen(false)}
        title="Buat Batch Baru"
        description="Banyak order, satu mesin/operator, satu tahap (§33). Batch PLANNED dimulai dari daftar batch."
        icon={<Boxes className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setBatchModalOpen(false)}>Batal</Button>
            <Button onClick={() => createBatch.mutate()} loading={createBatch.isPending} disabled={!batchForm.order_ids.trim()}>
              Buat Batch
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Tahap" required>
            <Select value={batchForm.stage} onChange={(e) => setBatchForm({ ...batchForm, stage: e.target.value })}>
              {STAGES.map((s) => <option key={s} value={s}>{s}</option>)}
            </Select>
          </Field>
          <Field label="Mesin (opsional)" hint="Hanya mesin berstatus OPERASIONAL yang ditampilkan.">
            <Select value={batchForm.laundry_machine_id} onChange={(e) => setBatchForm({ ...batchForm, laundry_machine_id: e.target.value })}>
              <option value="">— tanpa mesin —</option>
              {(machines.data ?? []).filter((m) => m.status === 'OPERATIONAL').map((m) => (
                <option key={m.id} value={m.id}>{m.code} — {m.name}</option>
              ))}
            </Select>
          </Field>
          <Field label="ID order (pisahkan koma)" required hint="cth. 12, 13, 14 — ID tertera pada halaman order.">
            <Input value={batchForm.order_ids} onChange={(e) => setBatchForm({ ...batchForm, order_ids: e.target.value })} placeholder="cth. 12, 13, 14" className="font-mono" />
          </Field>
        </div>
      </SideModal>

      {/* ── Drawer: langkah individual ──────────────────────────────── */}
      <SideModal
        open={stepModalOpen}
        onClose={() => setStepModalOpen(false)}
        title="Catat Langkah Individual"
        description="Tanpa batch — mesin tunggal / order satuan (§35–§37)."
        icon={<Layers className="h-5 w-5" />}
        tone="slate"
        footer={
          <>
            <Button variant="ghost" onClick={() => setStepModalOpen(false)}>Batal</Button>
            <Button onClick={() => recordStep.mutate()} loading={recordStep.isPending} disabled={!stepForm.order_id}>
              Catat Langkah
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="ID order" required>
            <Input value={stepForm.order_id} onChange={(e) => setStepForm({ ...stepForm, order_id: e.target.value })} className="font-mono" />
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
            <Input value={stepForm.notes} onChange={(e) => setStepForm({ ...stepForm, notes: e.target.value })} placeholder="opsional" />
          </Field>
        </div>
      </SideModal>

      {/* ── Drawer: QC ──────────────────────────────────────────────── */}
      <SideModal
        open={qcModalOpen}
        onClose={() => setQcModalOpen(false)}
        title="Quality Control"
        description="PASSED → siap dikemas · FAILED → cuci ulang · REWORK → perbaiki setrika (§38)."
        icon={<BadgeCheck className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setQcModalOpen(false)}>Batal</Button>
            <Button onClick={() => qualityCheck.mutate()} loading={qualityCheck.isPending} disabled={!qcForm.order_id}>
              <Sparkles className="h-4 w-4" /> Simpan QC
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="ID order" required>
            <Input value={qcForm.order_id} onChange={(e) => setQcForm({ ...qcForm, order_id: e.target.value })} className="font-mono" />
          </Field>
          <Field label="Hasil QC" required>
            <Select value={qcForm.result} onChange={(e) => setQcForm({ ...qcForm, result: e.target.value })}>
              <option value="PASSED">PASSED — lulus</option>
              <option value="FAILED">FAILED — cuci ulang</option>
              <option value="REWORK">REWORK — perbaiki setrika</option>
            </Select>
          </Field>
          <Field label="Catatan QC">
            <Textarea value={qcForm.notes} onChange={(e) => setQcForm({ ...qcForm, notes: e.target.value })} rows={3} placeholder="cth. satu kemeja masih lembap…" />
          </Field>
          <p className="text-xs leading-relaxed text-slate-500">
            QC lulus otomatis melanjutkan order ke tahap pengemasan; kegagalan QC tercatat di timeline & laporan operasional.
          </p>
        </div>
      </SideModal>
    </div>
  );
}
