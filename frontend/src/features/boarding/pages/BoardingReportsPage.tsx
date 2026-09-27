import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, FileSpreadsheet } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { FormField, Tabs, fmtDateTime } from '../components/BoardingUi';
import { auditApi, reportsApi } from '../api/boardingApi';
import { useDormitoryOptions } from '../hooks/useBoardingOptions';
import { getErrorMessage } from '@/lib/axios';
import { toast } from 'sonner';
import type { BoardingReport } from '@/types/boarding';

const MONTH_START = new Date().toISOString().slice(0, 8) + '01';
const TODAY = new Date().toISOString().slice(0, 10);

function ReportsTab() {
  const [kind, setKind] = useState('');
  const [from, setFrom] = useState(MONTH_START);
  const [to, setTo] = useState(TODAY);
  const [dormitoryId, setDormitoryId] = useState('');

  const { options: dormitoryOptions } = useDormitoryOptions();

  const kindsQuery = useQuery({
    queryKey: ['boarding', 'reports', 'kinds'],
    queryFn: () => reportsApi.kinds(),
    staleTime: 30 * 60 * 1000,
  });

  const kinds = (kindsQuery.data ?? []) as Array<{ kind: string; label: string }>;
  const activeKind = kind || kinds[0]?.kind || '';

  const reportQuery = useQuery({
    queryKey: ['boarding', 'reports', activeKind, from, to, dormitoryId],
    queryFn: () =>
      reportsApi.show(activeKind, {
        ...(from ? { from } : {}),
        ...(to ? { to } : {}),
        ...(dormitoryId ? { dormitory_id: dormitoryId } : {}),
      }),
    enabled: !!activeKind,
  });

  const report = reportQuery.data as BoardingReport | undefined;
  const headerEntries = report ? Object.entries(report.headers) : [];

  const exportCsv = () => {
    if (!activeKind) return;
    reportsApi
      .exportCsv(activeKind, {
        ...(from ? { from } : {}),
        ...(to ? { to } : {}),
        ...(dormitoryId ? { dormitory_id: dormitoryId } : {}),
      })
      .catch((err) => toast.error(getErrorMessage(err)));
  };

  return (
    <div className="space-y-4">
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Jenis laporan">
            <Select value={activeKind} onChange={(e) => setKind(e.target.value)}>
              {kinds.map((k) => (
                <option key={k.kind} value={k.kind}>{k.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Dari tanggal">
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </FormField>
          <FormField label="Sampai tanggal">
            <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </FormField>
          <FormField label="Asrama">
            <Select value={dormitoryId} onChange={(e) => setDormitoryId(e.target.value)}>
              <option value="">Semua asrama</option>
              {dormitoryOptions.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
        </div>
      </Card>

      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-500">
          {report ? `${report.label} — ${report.total} baris` : 'Memilih laporan...'}
        </p>
        <Can perm="boarding.report.export">
          <Button variant="outline" size="sm" onClick={exportCsv} disabled={!activeKind}>
            <Download className="h-4 w-4" /> Unduh CSV
          </Button>
        </Can>
      </div>

      {reportQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Menyusun laporan...</div></Card>
      ) : !report || report.rows.length === 0 ? (
        <Card><EmptyState title="Tidak ada baris untuk filter ini" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  {headerEntries.map(([key, label]) => (
                    <th key={key} className="whitespace-nowrap px-4 py-2 font-medium">{label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.rows.map((row, i) => (
                  <tr key={i} className="border-b border-slate-50 last:border-0">
                    {headerEntries.map(([key]) => {
                      const value = row[key];
                      return (
                        <td key={key} className="whitespace-nowrap px-4 py-2 text-slate-600">
                          {typeof value === 'boolean' ? (value ? 'Ya' : 'Tidak') : value === null || value === undefined || value === '' ? '—' : String(value)}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex items-center gap-2 px-4 py-2 text-xs text-slate-400">
            <FileSpreadsheet className="h-3.5 w-3.5" /> Angka layar dan ekspor CSV berasal dari query yang sama (§44).
          </div>
        </Card>
      )}
    </div>
  );
}

function AuditTab() {
  const [page, setPage] = useState(1);
  const [event, setEvent] = useState('');
  const [search, setSearch] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const auditQuery = useQuery({
    queryKey: ['boarding', 'audit', page, event, search, from, to],
    queryFn: () =>
      auditApi.list({
        page,
        per_page: 25,
        search: search || undefined,
        filter: {
          ...(event ? { event } : {}),
          ...(from ? { from } : {}),
          ...(to ? { to } : {}),
        },
      }),
  });

  const rows = auditQuery.data?.rows ?? [];
  const meta = auditQuery.data?.meta;
  const events = Array.from(new Set(rows.map((r) => r.event))).sort();

  return (
    <div className="space-y-4">
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Event">
            <Select value={event} onChange={(e) => { setEvent(e.target.value); setPage(1); }}>
              <option value="">Semua event</option>
              {events.map((ev) => (
                <option key={ev} value={ev}>{ev}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Cari deskripsi">
            <Input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="kata kunci..." />
          </FormField>
          <FormField label="Dari">
            <Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
          </FormField>
          <FormField label="Sampai">
            <Input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
          </FormField>
        </div>
      </Card>

      {auditQuery.isLoading ? (
        <Card><div className="flex justify-center py-14"><Spinner /></div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada jejak audit boarding" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Waktu</th>
                  <th className="px-4 py-2 font-medium">Event</th>
                  <th className="px-4 py-2 font-medium">Subjek</th>
                  <th className="px-4 py-2 font-medium">Oleh</th>
                  <th className="px-4 py-2 font-medium">Deskripsi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => (
                  <tr key={a.id} className="border-b border-slate-50 last:border-0">
                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500">{fmtDateTime(a.created_at)}</td>
                    <td className="px-4 py-2"><Badge tone="blue">{a.event}</Badge></td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {a.subject_type ? String(a.subject_type).split('\\').pop() : '—'}
                      {a.subject_id !== null && ` #${a.subject_id}`}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{a.causer ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-500">{a.description ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}
    </div>
  );
}

export function BoardingReportsPage() {
  const [tab, setTab] = useState('reports');

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Laporan & Audit Asrama</h1>
        <p className="text-sm text-slate-500">Laporan harian/bulanan dengan ekspor CSV (§44) dan jejak audit aktivitas boarding.</p>
      </div>
      <Tabs
        tabs={[
          { key: 'reports', label: 'Laporan' },
          { key: 'audit', label: 'Jejak Audit' },
        ]}
        active={tab}
        onChange={setTab}
      />
      {tab === 'reports' && <ReportsTab />}
      {tab === 'audit' && (
        <Can perm="boarding.audit.view">
          <AuditTab />
        </Can>
      )}
    </div>
  );
}
