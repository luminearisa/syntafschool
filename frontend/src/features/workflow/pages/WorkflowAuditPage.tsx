import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Fingerprint, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import { formatDateTime } from '../components/SlaBadge';

/**
 * Jejak audit & verifikasi rantai hash (§48).
 *
 * Auditor dapat melihat seluruh peristiwa lintas instance dan memverifikasi
 * bahwa rantai hash utuh — bukti bahwa tidak ada baris riwayat yang disunting
 * atau dihapus, bahkan lewat akses langsung ke basis data.
 */
export default function WorkflowAuditPage() {
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const [instanceRef, setInstanceRef] = useState('');

  const audit = useQuery({
    queryKey: ['workflow', 'audit', page, action, instanceRef],
    queryFn: () => workflowApi.auditLog({ page, action: action || undefined, instance: instanceRef || undefined, per_page: 50 }),
  });

  const verify = useMutation({
    mutationFn: () => workflowApi.verifyAudit(),
    onSuccess: (result) =>
      result.valid
        ? toast.success(`Rantai audit utuh (${result.checked} baris diperiksa).`)
        : toast.error(`Rantai terputus pada baris #${result.broken_at}.`),
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Audit Workflow</h1>
          <p className="text-sm text-slate-500">
            Riwayat lengkap keputusan, delegasi, eskalasi, dan perubahan konfigurasi — dengan rantai hash yang dapat diverifikasi.
          </p>
        </div>
        <Button variant="outline" loading={verify.isPending} onClick={() => verify.mutate()}>
          <Fingerprint className="mr-1 h-4 w-4" /> Verifikasi rantai
        </Button>
      </div>

      <Card>
        <CardHeader className="flex flex-wrap items-end gap-3">
          <div>
            <Label htmlFor="audit-action">Aksi</Label>
            <Input id="audit-action" value={action} onChange={(event) => setAction(event.target.value.toUpperCase())} placeholder="DECISION_RECORDED" />
          </div>
          <div>
            <Label htmlFor="audit-instance">Kode request</Label>
            <Input id="audit-instance" value={instanceRef} onChange={(event) => setInstanceRef(event.target.value)} placeholder="WF-2026-000123" />
          </div>
          <div className="flex-1" />
          <div className="flex flex-wrap gap-2">
            {audit.data?.summary.slice(0, 6).map((row) => (
              <Badge key={row.action} tone="neutral">
                {row.action}: {row.total}
              </Badge>
            ))}
          </div>
        </CardHeader>

        <CardBody className="p-0">
          {audit.isLoading ? <Spinner /> : null}
          {audit.data && audit.data.logs.data.length === 0 ? <EmptyState title="Tidak ada baris audit" description="Coba ubah penyaring." /> : null}

          <ul className="divide-y divide-slate-100">
            {audit.data?.logs.data.map((log) => (
              <li key={log.id} className="px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone="info">{log.action}</Badge>
                    <span className="text-sm text-slate-700">{log.instance ?? '— konfigurasi —'}</span>
                    {log.title ? <span className="text-xs text-slate-500">{log.title}</span> : null}
                  </div>
                  <span className="text-xs text-slate-500">{formatDateTime(log.at)}</span>
                </div>

                <p className="mt-0.5 text-xs text-slate-500">
                  {log.actor ?? 'sistem'} · IP {log.ip_address ?? '—'}
                  {log.reason ? ` · alasan: ${log.reason}` : ''}
                </p>

                {log.after ? (
                  <pre className="mt-1 max-h-32 overflow-auto rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-600">
                    {JSON.stringify(log.after, null, 2)}
                  </pre>
                ) : null}

                <p className="mt-1 flex items-center gap-1 text-[11px] text-slate-500">
                  <ShieldCheck className="h-3 w-3" /> {log.hash.slice(0, 24)}… ← {log.previous_hash?.slice(0, 12) ?? 'genesis'}…
                </p>
              </li>
            ))}
          </ul>

          {audit.data ? <Pagination meta={audit.data.logs.meta} onPageChange={setPage} /> : null}
        </CardBody>
      </Card>
    </div>
  );
}
