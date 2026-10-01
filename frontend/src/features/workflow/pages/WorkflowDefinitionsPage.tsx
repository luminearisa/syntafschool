import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FlaskConical, GitBranch, Layers, Plus, Rocket, Save } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import { WorkflowVisualizer } from '../components/WorkflowVisualizer';
import { formatDateTime, instanceTone } from '../components/SlaBadge';
import type { WorkflowDefinitionRow, WorkflowSimulationResult } from '../types';

/**
 * Pengelolaan definisi & versi workflow (§13–§15, §54, §56).
 *
 * Alur kerja yang dijaga layar ini: buat definisi → susun graf di builder →
 * periksa → simulasi → terbitkan. Versi yang sudah terbit tidak dapat diubah;
 * perbaikan selalu lahir sebagai versi baru, sehingga request yang sedang
 * berjalan tetap memakai alur saat ia dibuat.
 */
export default function WorkflowDefinitionsPage() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [page] = useState(1);
  const [creating, setCreating] = useState(false);
  const [selected, setSelected] = useState<WorkflowDefinitionRow | null>(null);
  const [simulation, setSimulation] = useState<{ definition: WorkflowDefinitionRow; result: WorkflowSimulationResult } | null>(null);
  const [simulateContext] = useState('{\n  "amount": 15000000,\n  "department": "SARANA"\n}');

  const definitions = useQuery({
    queryKey: ['workflow', 'definitions', page],
    queryFn: () => workflowApi.definitions({ page, per_page: 50 }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['workflow', 'definitions'] });

  const create = useMutation({
    mutationFn: (payload: Record<string, unknown>) => workflowApi.createDefinition(payload),
    onSuccess: (definition) => {
      toast.success('Definisi dibuat dengan kerangka awal START → APPROVAL → END.');
      setCreating(false);
      invalidate();
      navigate(`/workflow/definitions/${definition.id}/builder`);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const createVersion = useMutation({
    mutationFn: ({ id, fromVersionId }: { id: number; fromVersionId?: number }) =>
      workflowApi.createVersion(id, { from_version_id: fromVersionId, label: undefined }),
    onSuccess: () => {
      toast.success('Versi draf baru dibuat.');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const publish = useMutation({
    mutationFn: ({ versionId, changelog }: { versionId: number; changelog: string }) =>
      workflowApi.publishVersion(versionId, { changelog, activate: true }),
    onSuccess: () => {
      toast.success('Versi diterbitkan dan menggantikan versi aktif sebelumnya.');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const runSimulation = useMutation({
    mutationFn: ({ definition, context }: { definition: WorkflowDefinitionRow; context: Record<string, unknown> }) =>
      workflowApi.simulate(definition.id, context),
    onSuccess: (result, variables) => setSimulation({ definition: variables.definition, result }),
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Definisi Workflow</h1>
          <p className="text-sm text-slate-500">
            Konfigurasi alur persetujuan lintas modul. Mengubah definisi tidak memberi hak menyetujui request bisnis.
          </p>
        </div>
        <Can perm="workflow.definition.manage">
          <Button onClick={() => setCreating(true)}>
            <Plus className="mr-1 h-4 w-4" /> Definisi baru
          </Button>
        </Can>
      </div>

      <Card>
        <CardBody className="p-0">
          {definitions.isLoading ? <Spinner /> : null}
          {definitions.data && definitions.data.data.length === 0 ? (
            <EmptyState title="Belum ada definisi" description="Mulai dari salah satu alur standar: pengadaan, cuti, pengeluaran, atau dokumen." />
          ) : null}

          <ul className="divide-y divide-slate-100">
            {definitions.data?.data.map((definition) => (
              <li key={definition.id} className="px-4 py-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-sm font-semibold text-slate-800">{definition.name}</p>
                      <Badge tone={definition.status === 'ACTIVE' ? 'success' : definition.status === 'INACTIVE' ? 'neutral' : 'warning'}>
                        {definition.status_label}
                      </Badge>
                      <Badge tone="info">{definition.module}</Badge>
                      {definition.current_version ? <Badge tone="neutral">v{definition.current_version.version} aktif</Badge> : null}
                      {definition.allow_self_approval ? <Badge tone="warning">self-approval diizinkan</Badge> : null}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">{definition.code} · pemicu {definition.trigger_type}</p>
                    {definition.description ? <p className="mt-1 text-sm text-slate-600">{definition.description}</p> : null}
                    <p className="mt-1 text-xs text-slate-500">
                      {definition.instances_count} request total · {definition.instances_open} berjalan · dibuat {formatDateTime(definition.created_at)}
                    </p>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant="outline" onClick={() => setSelected(definition)}>
                      <Layers className="mr-1 h-3.5 w-3.5" /> Versi
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => runSimulation.mutate({ definition, context: JSON.parse(simulateContext) })}>
                      <FlaskConical className="mr-1 h-3.5 w-3.5" /> Simulasi
                    </Button>
                    <Link to={`/workflow/definitions/${definition.id}/builder`}>
                      <Button size="sm" variant="secondary">
                        <GitBranch className="mr-1 h-3.5 w-3.5" /> Builder
                      </Button>
                    </Link>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </CardBody>
      </Card>

      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title="Definisi workflow baru"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setCreating(false)}>
              Batal
            </Button>
          </div>
        }
      >
        <CreateDefinitionForm
          loading={create.isPending}
          onSubmit={(payload) => create.mutate(payload)}
        />
      </Modal>

      <Modal
        open={selected !== null}
        onClose={() => setSelected(null)}
        title={`Versi — ${selected?.name ?? ''}`}
        size="lg"
        footer={
          <div className="flex justify-end gap-2">
            <Can perm="workflow.definition.manage">
              <Button
                variant="outline"
                onClick={() => selected && createVersion.mutate({ id: selected.id, fromVersionId: selected.current_version?.id })}
                loading={createVersion.isPending}
              >
                <Plus className="mr-1 h-4 w-4" /> Klon versi aktif menjadi draf
              </Button>
            </Can>
          </div>
        }
      >
        <VersionList
          definition={selected}
          publishing={publish.isPending}
          onPublish={(versionId, changelog) => publish.mutate({ versionId, changelog })}
        />
      </Modal>

      <Modal
        open={simulation !== null}
        onClose={() => setSimulation(null)}
        title={`Simulasi — ${simulation?.definition.name ?? ''}`}
        size="lg"
      >
        {simulation ? (
          <div className="space-y-3">
            <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
              Jalur yang akan dilalui untuk konteks: {JSON.stringify(simulation.result.context)} · perkiraan{' '}
              {simulation.result.estimated_steps} langkah persetujuan · total SLA {simulation.result.estimated_work_minutes} menit kerja
            </div>

            {simulation.result.warnings.length > 0 ? (
              <ul className="list-disc space-y-1 rounded-lg bg-amber-50 px-5 py-3 text-xs text-amber-700">
                {simulation.result.warnings.map((warning) => (
                  <li key={warning}>{warning}</li>
                ))}
              </ul>
            ) : null}

            <WorkflowVisualizer
              nodes={simulation.result.path.map((entry) => ({
                key: entry.node_key,
                name: entry.name,
                type: entry.type,
                sequence: entry.sequence,
                status: entry.skipped ? 'SKIPPED' : entry.type === 'APPROVAL' || entry.type === 'REVIEW' ? 'WAITING' : 'APPROVED',
                approval_mode: (entry.approval_mode as never) ?? null,
                quorum: (entry.quorum?.policy as never) ?? null,
                sla_minutes: entry.sla_minutes ?? null,
              }))}
            />

            <div className="space-y-2">
              {simulation.result.path
                .filter((entry) => entry.approvers.length > 0)
                .map((entry) => (
                  <div key={entry.node_key} className="rounded-lg border border-slate-200 px-3 py-2 text-xs">
                    <p className="font-medium text-slate-700">{entry.name}</p>
                    <p className="text-slate-500">
                      {entry.approvers
                        .map((approver) => `${approver.name ?? `User #${approver.user_id}`} (${approver.source.toLowerCase()})`)
                        .join(', ')}
                    </p>
                  </div>
                ))}
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  );
}

function CreateDefinitionForm({ loading, onSubmit }: { loading: boolean; onSubmit: (payload: Record<string, unknown>) => void }) {
  const [form, setForm] = useState({
    code: '',
    name: '',
    module: 'general',
    entity_type: 'manual.request',
    event: '',
    trigger_type: 'MANUAL',
    description: '',
    required_context: 'amount',
  });

  return (
    <div className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <Label required>Kode</Label>
          <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="PURCHASE_REQUEST_APPROVAL" />
        </div>
        <div>
          <Label required>Nama</Label>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Persetujuan Permintaan Pengadaan" />
        </div>
        <div>
          <Label>Modul</Label>
          <Input value={form.module} onChange={(e) => setForm({ ...form, module: e.target.value })} placeholder="procurement" />
        </div>
        <div>
          <Label>Jenis subjek (subject_type)</Label>
          <Input value={form.entity_type} onChange={(e) => setForm({ ...form, entity_type: e.target.value })} placeholder="procurement.purchase_request" />
        </div>
        <div>
          <Label>Peristiwa pemicu</Label>
          <Input value={form.event} onChange={(e) => setForm({ ...form, event: e.target.value })} placeholder="PurchaseRequestSubmitted" />
        </div>
        <div>
          <Label>Cara memulai</Label>
          <Select value={form.trigger_type} onChange={(e) => setForm({ ...form, trigger_type: e.target.value })}>
            <option value="MANUAL">Manual (layar pengajuan)</option>
            <option value="API">Panggilan API modul</option>
            <option value="EVENT">Event ERP</option>
            <option value="STATUS_CHANGE">Perubahan status</option>
            <option value="SCHEDULED">Terjadwal</option>
          </Select>
        </div>
      </div>

      <div>
        <Label>Konteks wajib (pisahkan dengan koma)</Label>
        <Input value={form.required_context} onChange={(e) => setForm({ ...form, required_context: e.target.value })} placeholder="amount, department" />
        <p className="mt-1 text-xs text-slate-500">
          Engine menolak submit bila konteks ini tidak tersedia — request yang tidak dapat dinilai tidak boleh berjalan.
        </p>
      </div>

      <div>
        <Label>Deskripsi</Label>
        <Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} rows={2} />
      </div>

      <div className="flex justify-end">
        <Button
          loading={loading}
          onClick={() =>
            onSubmit({
              ...form,
              event: form.event || undefined,
              description: form.description || undefined,
              required_context: form.required_context
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean),
              seed_skeleton: true,
            })
          }
        >
          <Save className="mr-1 h-4 w-4" /> Simpan & buka builder
        </Button>
      </div>
    </div>
  );
}

function VersionList({
  definition,
  publishing,
  onPublish,
}: {
  definition: WorkflowDefinitionRow | null;
  publishing: boolean;
  onPublish: (versionId: number, changelog: string) => void;
}) {
  const detail = useQuery({
    queryKey: ['workflow', 'definition', definition?.id],
    queryFn: () => workflowApi.definition(definition!.id),
    enabled: definition !== null,
  });

  if (!definition) return null;

  if (detail.isLoading) return <Spinner />;

  const versions = detail.data?.versions ?? [];

  return (
    <div className="space-y-3">
      <ul className="divide-y divide-slate-100">
        {versions.map((version) => (
          <li key={version.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div>
              <div className="flex items-center gap-2">
                <p className="text-sm font-medium text-slate-800">v{version.version}</p>
                <Badge tone={instanceTone(version.status === 'ACTIVE' ? 'APPROVED' : version.status === 'INACTIVE' ? 'CANCELLED' : 'DRAFT')}>
                  {version.status}
                </Badge>
                <span className="text-xs text-slate-500">{version.instances_count} request</span>
              </div>
              <p className="text-xs text-slate-500">
                {version.label} · {version.published_at ? `terbit ${formatDateTime(version.published_at)}` : 'belum terbit'}
              </p>
              {version.graph_hash ? <p className="truncate text-[11px] text-slate-500">graf {version.graph_hash.slice(0, 16)}…</p> : null}
            </div>

            <div className="flex items-center gap-2">
              <Link to={`/workflow/versions/${version.id}/builder`}>
                <Button size="sm" variant="outline">
                  Buka builder
                </Button>
              </Link>
              {version.status === 'DRAFT' || version.status === 'TESTING' ? (
                <Can perm="workflow.version.publish">
                  <Button
                    size="sm"
                    loading={publishing}
                    onClick={() => {
                      const changelog = window.prompt('Catatan perubahan versi (opsional):') ?? '';

                      onPublish(version.id, changelog);
                    }}
                  >
                    <Rocket className="mr-1 h-3.5 w-3.5" /> Terbitkan
                  </Button>
                </Can>
              ) : null}
            </div>
          </li>
        ))}
      </ul>

      {versions.length === 0 ? <EmptyState title="Belum ada versi" description="Buat versi draf untuk mulai menyusun alur." /> : null}
    </div>
  );
}
