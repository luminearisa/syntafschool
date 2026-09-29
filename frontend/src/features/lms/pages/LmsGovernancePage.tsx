import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Activity, RefreshCw, ShieldAlert, ScrollText } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { phase4Api } from '../api/phase4Api';

/**
 * Tata kelola LMS: kesehatan sistem, status integrasi, audit trail, dan
 * integritas data (§23, §24, §42).
 */
export default function LmsGovernancePage() {
  const [scope, setScope] = useState('all');
  const [action, setAction] = useState('');

  const health = useQuery({ queryKey: ['lms', 'health'], queryFn: () => phase4Api.lmsHealth() });
  const integration = useQuery({ queryKey: ['lms', 'integration'], queryFn: () => phase4Api.lmsIntegration() });
  const audit = useQuery({
    queryKey: ['lms', 'audit', action],
    queryFn: () => phase4Api.auditLog(action ? { action } : {}),
  });
  const integrity = useQuery({ queryKey: ['lms', 'integrity'], queryFn: () => phase4Api.integrityChecks() });

  const runIntegrity = useMutation({
    mutationFn: () => phase4Api.runIntegrity(scope),
    onSuccess: (result) => {
      const row = result as { status?: string; issues_found?: number };
      toast.success(`Integritas: ${row.status ?? '—'} · ${row.issues_found ?? 0} temuan`);
      void integrity.refetch();
    },
    onError: () => toast.error('Pemeriksaan integritas gagal'),
  });

  const statusTone = (status?: string) =>
    status === 'HEALTHY' ? 'green' : status === 'DEGRADED' ? 'amber' : status === 'UNAVAILABLE' ? 'red' : 'gray';

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <ShieldAlert className="h-5 w-5 text-slate-700" aria-hidden="true" /> Tata kelola LMS
        </h1>
        <p className="text-sm text-slate-500">
          Kesehatan layanan, integrasi, audit trail, dan pemeriksaan integritas data.
        </p>
      </header>

      <Card>
        <CardHeader className="flex items-center justify-between">
          <CardTitle className="flex items-center gap-2">
            <Activity className="h-4 w-4 text-emerald-600" aria-hidden="true" /> Kesehatan layanan
          </CardTitle>
          {health.data && <Badge tone={statusTone(health.data.status) as never}>{health.data.status_label}</Badge>}
        </CardHeader>
        <CardBody>
          {health.isLoading ? (
            <Spinner />
          ) : (
            <ul className="grid gap-2 sm:grid-cols-2">
              {Object.entries(health.data?.checks ?? {}).map(([name, check]) => (
                <li key={name} className="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-2.5">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-700">{name.replace(/_/g, ' ')}</p>
                    {check.detail && <p className="text-xs text-slate-500">{check.detail}</p>}
                  </div>
                  <Badge tone={statusTone(check.status) as never}>{check.status}</Badge>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Status integrasi</CardTitle>
        </CardHeader>
        <CardBody>
          {integration.isLoading ? (
            <Spinner />
          ) : (
            <div className="space-y-3 text-sm">
              <p className="text-slate-600">
                Mode aktif: <Badge tone="blue">{integration.data?.mode ?? '—'}</Badge>
              </p>
              <ul className="grid gap-2 sm:grid-cols-2">
                {Object.entries(integration.data?.providers ?? {}).map(([name, provider]) => {
                  const row = provider as { provider?: string; available?: boolean };
                  return (
                    <li key={name} className="flex items-center justify-between rounded-lg border border-slate-200 p-2.5">
                      <span className="text-slate-700">{name.replace(/_/g, ' ')}</span>
                      <span className="flex items-center gap-2">
                        <span className="text-xs text-slate-500">{row.provider}</span>
                        <Badge tone={row.available ? 'green' : 'gray'}>{row.available ? 'siap' : 'tidak aktif'}</Badge>
                      </span>
                    </li>
                  );
                })}
              </ul>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>Audit trail</CardTitle>
          <div className="flex items-center gap-2">
            <input
              value={action}
              onChange={(event) => setAction(event.target.value.toUpperCase())}
              placeholder="Filter aksi (mis. LMS_GRADE_SYNCED)"
              className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
            />
            <Badge tone="gray">{audit.data?.meta?.total ?? 0} baris</Badge>
          </div>
        </CardHeader>
        <CardBody>
          {audit.isLoading ? (
            <Spinner />
          ) : (audit.data?.data ?? []).length === 0 ? (
            <EmptyState title="Belum ada catatan audit" description="Audit terisi saat course, nilai, atau capaian berubah." />
          ) : (
            <ul className="divide-y divide-slate-100 text-sm">
              {(audit.data?.data ?? []).slice(0, 50).map((entry, index) => {
                const row = entry as {
                  id: number;
                  action: string;
                  actor?: { name?: string | null; role?: string | null };
                  entity_type?: string;
                  entity_id?: number | null;
                  ip_address?: string | null;
                  created_at?: string | null;
                };
                return (
                  <li key={row.id ?? index} className="py-2">
                    <p className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-800">{row.action}</span>
                      <span className="text-xs text-slate-500">{row.actor?.name ?? 'Sistem'}</span>
                      {row.entity_id !== null && row.entity_id !== undefined && (
                        <span className="text-xs text-slate-400">
                          {String(row.entity_type ?? '').split('\\').pop()} #{row.entity_id}
                        </span>
                      )}
                    </p>
                    <p className="text-xs text-slate-400">
                      {row.created_at ? new Date(row.created_at).toLocaleString('id-ID') : ''}
                      {row.ip_address ? ` · ${row.ip_address}` : ''}
                    </p>
                  </li>
                );
              })}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle className="flex items-center gap-2">
            <ScrollText className="h-4 w-4 text-slate-500" aria-hidden="true" /> Integritas data
          </CardTitle>
          <div className="flex items-center gap-2">
            <select
              value={scope}
              onChange={(event) => setScope(event.target.value)}
              className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
            >
              <option value="all">Semua pemeriksaan</option>
              <option value="course">Course</option>
              <option value="enrollment">Pendaftaran</option>
              <option value="grade">Nilai</option>
            </select>
            <Button size="sm" onClick={() => runIntegrity.mutate()} disabled={runIntegrity.isPending}>
              <RefreshCw className="mr-2 h-4 w-4" aria-hidden="true" />
              {runIntegrity.isPending ? 'Memeriksa…' : 'Jalankan'}
            </Button>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          {integrity.isLoading ? (
            <Spinner />
          ) : (
            <>
              <p className="text-sm text-slate-600">
                {(integrity.data?.checks ?? []).length} pemeriksaan tersedia. Perbaikan data tidak
                dijalankan otomatis — setiap temuan menyertakan saran tindakan.
              </p>
              <ul className="grid gap-2 sm:grid-cols-2">
                {(integrity.data?.checks ?? []).map((check, index) => {
                  const row = check as { key: string; label: string; description?: string };
                  return (
                    <li key={row.key ?? index} className="rounded-lg border border-slate-200 p-2.5">
                      <p className="text-sm font-medium text-slate-700">{row.label}</p>
                      <p className="text-xs text-slate-500">{row.description}</p>
                    </li>
                  );
                })}
              </ul>
              {(integrity.data?.history ?? []).length > 0 && (
                <div>
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Riwayat pemeriksaan</p>
                  <ul className="space-y-1 text-sm">
                    {(integrity.data?.history ?? []).map((run, index) => {
                      const row = run as { id: number; status: string; issues_found: number; created_at?: string };
                      return (
                        <li key={row.id ?? index} className="flex items-center justify-between">
                          <span className="text-slate-600">
                            {row.created_at ? new Date(row.created_at).toLocaleString('id-ID') : '-'}
                          </span>
                          <span className="flex items-center gap-2">
                            <Badge tone={row.status === 'OK' ? 'green' : row.status === 'CRITICAL' ? 'red' : 'amber'}>
                              {row.status}
                            </Badge>
                            <span className="text-xs text-slate-500">{row.issues_found} temuan</span>
                          </span>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              )}
            </>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
