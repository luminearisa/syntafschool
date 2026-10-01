import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, Plus, Rocket, Save, Trash2, Wand2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import { WorkflowVisualizer, nodeTypeLabels } from '../components/WorkflowVisualizer';
import type { ApproverSpec, NodeType, WorkflowEdgeConfig, WorkflowMeta, WorkflowNodeConfig } from '../types';

/**
 * Workflow Builder (§54, §55).
 *
 * Editor berbasis formulir — bukan canvas bebas — dengan alasan yang disengaja:
 * alur persetujuan sekolah hampir selalu berupa rangkaian langkah dan satu-dua
 * percabangan. Bentuk tabel membuat setiap kolom (approver, SLA, kuorum,
 * kondisi) terlihat dan dapat diaudit, dan tetap dapat dioperasikan pada
 * perangkat sentuh tanpa drag-and-drop.
 *
 * Tombol "Periksa" menjalankan validator backend yang sama dengan yang dipakai
 * saat penerbitan: tidak ada definisi setengah jadi yang bisa tayang.
 */
export default function WorkflowBuilderPage() {
  const { versionId } = useParams<{ versionId: string }>();
  const id = Number(versionId);
  const navigate = useNavigate();

  const meta = useQuery({ queryKey: ['workflow', 'meta'], queryFn: () => workflowApi.meta() });
  const version = useQuery({ queryKey: ['workflow', 'version', id], queryFn: () => workflowApi.version(id), enabled: Number.isFinite(id) });

  const [nodes, setNodes] = useState<WorkflowNodeConfig[]>([]);
  const [edges, setEdges] = useState<WorkflowEdgeConfig[]>([]);
  const [config, setConfig] = useState<Record<string, unknown>>({});
  const [report, setReport] = useState<{ valid: boolean; errors: string[]; warnings: string[] } | null>(null);
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    if (version.data) {
      setNodes(version.data.nodes);
      setEdges(version.data.edges);
      setConfig(version.data.config ?? {});
      setDirty(false);
    }
  }, [version.data]);

  const save = useMutation({
    mutationFn: () => workflowApi.saveVersion(id, { nodes, edges, conditions: version.data?.conditions ?? [], actions: version.data?.actions ?? [], config }),
    onSuccess: () => {
      toast.success('Graf workflow disimpan.');
      setDirty(false);
      version.refetch();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const validate = useMutation({
    mutationFn: async () => {
      if (dirty) {
        await workflowApi.saveVersion(id, { nodes, edges, conditions: version.data?.conditions ?? [], actions: version.data?.actions ?? [], config });
      }

      return workflowApi.validateVersion(id);
    },
    onSuccess: (data) => {
      setReport(data);
      setDirty(false);
      toast[data.valid ? 'success' : 'error'](data.valid ? 'Workflow siap diterbitkan.' : 'Periksa kesalahan sebelum menerbitkan.');
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const publish = useMutation({
    mutationFn: () => workflowApi.publishVersion(id, { changelog: 'Diterbitkan dari builder.', activate: true }),
    onSuccess: () => {
      toast.success('Versi diterbitkan.');
      version.refetch();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const addNode = () => {
    const key = `step_${nodes.length + 1}_${Math.random().toString(36).slice(2, 5)}`;

    setNodes([
      ...nodes,
      {
        key,
        type: 'APPROVAL',
        name: 'Langkah ' + (nodes.length + 1),
        sequence: nodes.length + 1,
        approval_mode: 'PARALLEL',
        quorum_policy: 'ALL',
        allow_skip: false,
        allow_reassign: true,
        self_approval_rule: 'INHERIT',
        approvers: [],
      },
    ]);
    setDirty(true);
  };

  const updateNode = (key: string, patch: Partial<WorkflowNodeConfig>) => {
    setNodes(nodes.map((node) => (node.key === key ? { ...node, ...patch } : node)));
    setDirty(true);
  };

  const removeNode = (key: string) => {
    setNodes(nodes.filter((node) => node.key !== key));
    setEdges(edges.filter((edge) => edge.from_key !== key && edge.to_key !== key));
    setDirty(true);
  };

  const addEdge = () => {
    const first = nodes[0]?.key ?? 'start';
    const second = nodes[1]?.key ?? first;

    setEdges([...edges, { from_key: first, to_key: second, priority: edges.length }]);
    setDirty(true);
  };

  const updateEdge = (index: number, patch: Partial<WorkflowEdgeConfig>) => {
    setEdges(edges.map((edge, i) => (i === index ? { ...edge, ...patch } : edge)));
    setDirty(true);
  };

  const preview = useMemo(
    () =>
      nodes.map((node) => ({
        key: node.key,
        name: node.name,
        type: node.type,
        sequence: node.sequence ?? 0,
        status: (node.type === 'START' || node.type === 'END' ? 'APPROVED' : 'PENDING') as never,
        approval_mode: node.approval_mode ?? null,
        quorum: node.quorum_policy ?? null,
        sla_minutes: node.sla_minutes ?? null,
      })),
    [nodes],
  );

  if (version.isLoading || meta.isLoading) return <Spinner />;

  if (!version.data) {
    return <p className="text-sm text-slate-500">Versi workflow tidak ditemukan.</p>;
  }

  const editable = version.data.editable;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">
            Builder — {version.data.definition_name} v{version.data.version}
          </h1>
          <p className="text-sm text-slate-500">
            Status versi <Badge tone={editable ? 'warning' : 'success'}>{version.data.status}</Badge>{' '}
            {editable ? 'Draf ini masih dapat diubah.' : 'Versi terbit bersifat beku: klon menjadi draf untuk memperbaiki.'}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" onClick={() => navigate('/workflow/definitions')}>
            Kembali
          </Button>
          <Can perm="workflow.definition.manage">
            <Button variant="outline" loading={validate.isPending} onClick={() => validate.mutate()}>
              <Wand2 className="mr-1 h-4 w-4" /> Periksa
            </Button>
            <Button variant="secondary" loading={save.isPending} disabled={!editable || !dirty} onClick={() => save.mutate()}>
              <Save className="mr-1 h-4 w-4" /> Simpan
            </Button>
          </Can>
          <Can perm="workflow.version.publish">
            <Button loading={publish.isPending} disabled={!editable} onClick={() => publish.mutate()}>
              <Rocket className="mr-1 h-4 w-4" /> Terbitkan
            </Button>
          </Can>
        </div>
      </div>

      {report ? (
        <Card>
          <CardHeader className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-sm">
              {report.valid ? <CheckCircle2 className="h-4 w-4 text-green-600" /> : <AlertTriangle className="h-4 w-4 text-red-600" />}
              Hasil pemeriksaan
            </CardTitle>
            <Badge tone={report.valid ? 'success' : 'danger'}>{report.valid ? 'Valid' : `${report.errors.length} kesalahan`}</Badge>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            {report.errors.length > 0 ? (
              <ul className="list-disc space-y-1 pl-5 text-red-600">
                {report.errors.map((error) => (
                  <li key={error}>{error}</li>
                ))}
              </ul>
            ) : null}
            {report.warnings.length > 0 ? (
              <ul className="list-disc space-y-1 pl-5 text-amber-600">
                {report.warnings.map((warning) => (
                  <li key={warning}>{warning}</li>
                ))}
              </ul>
            ) : null}
            {report.valid && report.warnings.length === 0 ? <p className="text-green-700">Tidak ada temuan.</p> : null}
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader className="flex items-center justify-between">
          <CardTitle className="text-sm">Langkah (node)</CardTitle>
          <Can perm="workflow.definition.manage">
            <Button size="sm" variant="outline" disabled={!editable} onClick={addNode}>
              <Plus className="mr-1 h-3.5 w-3.5" /> Tambah langkah
            </Button>
          </Can>
        </CardHeader>
        <CardBody className="space-y-3">
          {nodes.map((node) => (
            <NodeEditor
              key={node.key}
              node={node}
              meta={meta.data!}
              editable={editable}
              onChange={(patch) => updateNode(node.key, patch)}
              onRemove={() => removeNode(node.key)}
            />
          ))}
          {nodes.length === 0 ? <p className="text-sm text-slate-500">Belum ada langkah. Tambahkan minimal START, satu persetujuan, dan END.</p> : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader className="flex items-center justify-between">
          <CardTitle className="text-sm">Alur antar langkah (edge)</CardTitle>
          <Can perm="workflow.definition.manage">
            <Button size="sm" variant="outline" disabled={!editable} onClick={addEdge}>
              <Plus className="mr-1 h-3.5 w-3.5" /> Tambah alur
            </Button>
          </Can>
        </CardHeader>
        <CardBody className="space-y-3">
          {edges.map((edge, index) => (
            <div key={`${edge.from_key}-${edge.to_key}-${index}`} className="grid items-end gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12">
              <div className="sm:col-span-3">
                <Label>Dari</Label>
                <Select value={edge.from_key} disabled={!editable} onChange={(e) => updateEdge(index, { from_key: e.target.value })}>
                  {nodes.map((node) => (
                    <option key={node.key} value={node.key}>
                      {node.name}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="sm:col-span-3">
                <Label>Ke</Label>
                <Select value={edge.to_key} disabled={!editable} onChange={(e) => updateEdge(index, { to_key: e.target.value })}>
                  {nodes.map((node) => (
                    <option key={node.key} value={node.key}>
                      {node.name}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="sm:col-span-2">
                <Label>Field kondisi</Label>
                <Input
                  value={edge.condition?.field ?? ''}
                  disabled={!editable}
                  placeholder="amount"
                  onChange={(e) =>
                    updateEdge(index, {
                      condition: e.target.value === '' ? null : { ...(edge.condition ?? {}), field: e.target.value },
                    })
                  }
                />
              </div>
              <div className="sm:col-span-2">
                <Label>Operator</Label>
                <Select
                  value={edge.condition?.operator ?? ''}
                  disabled={!editable || !edge.condition}
                  onChange={(e) => updateEdge(index, { condition: { ...(edge.condition ?? {}), operator: e.target.value } })}
                >
                  <option value="">—</option>
                  {(meta.data?.condition_operators ?? []).map((operator) => (
                    <option key={operator.value} value={operator.value}>
                      {operator.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="sm:col-span-2">
                <Label>Nilai</Label>
                <Input
                  value={String(edge.condition?.value ?? '')}
                  disabled={!editable || !edge.condition}
                  placeholder="10000000"
                  onChange={(e) =>
                    updateEdge(index, {
                      condition: { ...(edge.condition ?? {}), value: coerce(e.target.value) },
                    })
                  }
                />
              </div>
              <div className="flex items-center justify-end gap-2 sm:col-span-12">
                <label className="flex items-center gap-1 text-xs text-slate-600">
                  <input
                    type="checkbox"
                    checked={Boolean(edge.is_default)}
                    disabled={!editable}
                    onChange={(e) => updateEdge(index, { is_default: e.target.checked })}
                  />
                  cabang default
                </label>
                <Button size="sm" variant="ghost" disabled={!editable} onClick={() => { setEdges(edges.filter((_, i) => i !== index)); setDirty(true); }}>
                  <Trash2 className="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
          ))}
          <p className="text-xs text-slate-500">
            Cabang dinilai berurutan: edge pertama yang kondisinya benar ditempuh. Tandai satu cabang sebagai <em>default</em> agar request
            tidak pernah gagal karena tidak ada kondisi yang cocok.
          </p>
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Pratinjau jalur</CardTitle>
          </CardHeader>
          <CardBody>
            <WorkflowVisualizer nodes={preview} />
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Kebijakan versi (JSON)</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <Textarea
              rows={10}
              value={JSON.stringify(config, null, 2)}
              disabled={!editable}
              onChange={(event) => {
                try {
                  setConfig(JSON.parse(event.target.value));
                  setDirty(true);
                } catch {
                  /* biarkan pengguna menyelesaikan ketikan */
                }
              }}
            />
            <p className="text-xs text-slate-500">
              Contoh: <code>{`{"allow_self_approval": false, "empty_approver": "PAUSE", "return_strategy": "RESUME_FROM_PREVIOUS_STEP", "actions": {"approved": [{"handler": "event.publish", "params": {"name": "PurchaseRequestApproved"}}]}}`}</code>
            </p>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

function NodeEditor({
  node,
  meta,
  editable,
  onChange,
  onRemove,
}: {
  node: WorkflowNodeConfig;
  meta: WorkflowMeta;
  editable: boolean;
  onChange: (patch: Partial<WorkflowNodeConfig>) => void;
  onRemove: () => void;
}) {
  const human = node.type === 'APPROVAL' || node.type === 'REVIEW';

  return (
    <div className="rounded-lg border border-slate-200 p-3">
      <div className="grid gap-2 sm:grid-cols-12">
        <div className="sm:col-span-3">
          <Label>Nama langkah</Label>
          <Input value={node.name} disabled={!editable} onChange={(e) => onChange({ name: e.target.value })} />
        </div>
        <div className="sm:col-span-2">
          <Label>Kunci</Label>
          <Input value={node.key} disabled={!editable} onChange={(e) => onChange({ key: e.target.value })} />
        </div>
        <div className="sm:col-span-3">
          <Label>Jenis</Label>
          <Select value={node.type} disabled={!editable} onChange={(e) => onChange({ type: e.target.value as NodeType })}>
            {meta.node_types.map((type) => (
              <option key={type.value} value={type.value}>
                {nodeTypeLabels[type.value] ?? type.label}
              </option>
            ))}
          </Select>
        </div>
        <div className="sm:col-span-2">
          <Label>Urutan</Label>
          <Input type="number" value={node.sequence} disabled={!editable} onChange={(e) => onChange({ sequence: Number(e.target.value) })} />
        </div>
        <div className="sm:col-span-2">
          <Label>SLA (menit)</Label>
          <Input
            type="number"
            value={node.sla_minutes ?? ''}
            disabled={!editable}
            onChange={(e) => onChange({ sla_minutes: e.target.value === '' ? null : Number(e.target.value) })}
          />
        </div>
      </div>

      {human ? (
        <div className="mt-3 space-y-3">
          <div className="grid gap-2 sm:grid-cols-4">
            <div>
              <Label>Mode persetujuan</Label>
              <Select
                value={node.approval_mode ?? 'PARALLEL'}
                disabled={!editable}
                onChange={(e) => onChange({ approval_mode: e.target.value as never })}
              >
                {meta.approval_modes.map((mode) => (
                  <option key={mode.value} value={mode.value}>
                    {mode.label}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Kuorum</Label>
              <Select
                value={node.quorum_policy ?? 'ALL'}
                disabled={!editable}
                onChange={(e) => onChange({ quorum_policy: e.target.value as never })}
              >
                {meta.quorum_policies.map((policy) => (
                  <option key={policy.value} value={policy.value}>
                    {policy.label}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Nilai kuorum</Label>
              <Input
                type="number"
                value={node.quorum_value ?? ''}
                disabled={!editable || (node.quorum_policy !== 'MINIMUM_N' && node.quorum_policy !== 'PERCENTAGE')}
                onChange={(e) => onChange({ quorum_value: e.target.value === '' ? null : Number(e.target.value) })}
                placeholder="mis. 2"
              />
            </div>
            <div>
              <Label>Self-approval</Label>
              <Select
                value={node.self_approval_rule ?? 'INHERIT'}
                disabled={!editable}
                onChange={(e) => onChange({ self_approval_rule: e.target.value as never })}
              >
                <option value="INHERIT">Ikuti definisi</option>
                <option value="FORBID">Larang</option>
                <option value="ALLOW">Izinkan</option>
              </Select>
            </div>
          </div>

          <ResolverEditor
            specs={node.approvers ?? []}
            meta={meta}
            editable={editable}
            onChange={(approvers) => onChange({ approvers })}
          />

          <label className="flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" checked={Boolean(node.allow_skip)} disabled={!editable} onChange={(e) => onChange({ allow_skip: e.target.checked })} />
            Izinkan langkah ini dilewati oleh pengelola workflow (dengan alasan wajib)
          </label>
        </div>
      ) : null}

      <div className="mt-3 flex justify-end">
        <Button size="sm" variant="ghost" disabled={!editable} onClick={onRemove}>
          <Trash2 className="mr-1 h-3.5 w-3.5" /> Hapus langkah
        </Button>
      </div>
    </div>
  );
}

/** Editor daftar approver: resolver + field wajibnya (dari meta backend). */
function ResolverEditor({
  specs,
  meta,
  editable,
  onChange,
}: {
  specs: ApproverSpec[];
  meta: WorkflowMeta;
  editable: boolean;
  onChange: (specs: ApproverSpec[]) => void;
}) {
  const update = (index: number, patch: Record<string, unknown>) => {
    onChange(specs.map((spec, i) => (i === index ? { ...spec, ...patch } : spec)));
  };

  return (
    <div className="space-y-2 rounded-lg bg-slate-50 p-3">
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Approver</p>
        <Button size="sm" variant="ghost" disabled={!editable} onClick={() => onChange([...specs, { resolver: 'role', role: '' }])}>
          <Plus className="mr-1 h-3.5 w-3.5" /> Tambah
        </Button>
      </div>

      {specs.length === 0 ? (
        <p className="text-xs text-amber-600">Belum ada approver — workflow tidak dapat diterbitkan tanpa resolver.</p>
      ) : null}

      {specs.map((spec, index) => {
        const definition = meta.resolvers.find((resolver) => resolver.alias === spec.resolver);

        return (
          <div key={index} className="grid items-end gap-2 sm:grid-cols-12">
            <div className="sm:col-span-4">
              <Label>Jenis resolver</Label>
              <Select value={spec.resolver} disabled={!editable} onChange={(e) => update(index, { resolver: e.target.value })}>
                {meta.resolvers.map((resolver) => (
                  <option key={resolver.alias} value={resolver.alias}>
                    {resolver.alias} — {resolver.description}
                  </option>
                ))}
              </Select>
            </div>

            {(Object.keys(definition?.required_spec ?? {}) as string[]).map((field) => (
              <div key={field} className="sm:col-span-5">
                <Label>{field}</Label>
                <Input
                  value={String(spec[field] ?? '')}
                  disabled={!editable}
                  placeholder={field === 'position' ? 'Kepala Sekolah' : field === 'role' ? 'bendahara' : '1'}
                  onChange={(e) => update(index, { [field]: field === 'user_id' || field === 'levels' ? coerce(e.target.value) : e.target.value })}
                />
              </div>
            ))}

            <div className="flex justify-end sm:col-span-3">
              <Button size="sm" variant="ghost" disabled={!editable} onClick={() => onChange(specs.filter((_, i) => i !== index))}>
                <Trash2 className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        );
      })}

      <p className="text-[11px] text-slate-500">
        Contoh yang sering dipakai: <code>{`{"resolver":"manager"}`}</code> (atasan langsung),{' '}
        <code>{`{"resolver":"role","role":"bendahara"}`}</code>, <code>{`{"resolver":"cost_center"}`}</code> (manajer cost center dari modul Finance).
      </p>
    </div>
  );
}

function coerce(value: string): unknown {
  if (value === '') return '';
  if (!Number.isNaN(Number(value))) return Number(value);

  return value;
}
