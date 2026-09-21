import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, ChevronRight, RefreshCw } from 'lucide-react';
import { libraryAuditLogsApi } from '../api/libraryApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage } from '@/lib/axios';
import type { LibraryActivity } from '@/types/library';

/** Nama aksi audit yang dipakai backend pada modul Library. */
const KNOWN_EVENTS = [
  'LIBRARY_CREATED',
  'LIBRARY_UPDATED',
  'LIBRARY_STATUS_CHANGED',
  'LIBRARY_DELETED',
  'LIBRARY_SETTING_UPDATED',
  'LIBRARY_OPERATING_HOURS_UPDATED',
  'LIBRARY_LOCATION_CREATED',
  'LIBRARY_LOCATION_UPDATED',
  'LIBRARY_LOCATION_DELETED',
  'LIBRARY_CLOSURE_CREATED',
  'LIBRARY_CLOSURE_UPDATED',
  'LIBRARY_CLOSURE_DELETED',
  'LIBRARY_NUMBERING_UPDATED',
];

const SUBJECT_TYPES = [
  'Library',
  'LibraryLocation',
  'LibraryClosure',
  'LibraryNumberingConfig',
];

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function stringify(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

/**
 * Jejak audit modul Library (hanya baca).
 *
 * Tidak ada endpoint tulis — catatan dihasilkan otomatis oleh backend.
 * Setiap baris dapat dibuka untuk melihat perubahan nilai lama → baru.
 */
export function LibraryAuditLogPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [event, setEvent] = useState('');
  const [subjectType, setSubjectType] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [expandedId, setExpandedId] = useState<number | null>(null);

  const listQuery = useQuery({
    queryKey: ['library', 'audit-logs', page, search, event, subjectType, from, to],
    queryFn: () =>
      libraryAuditLogsApi.list({
        page,
        per_page: 20,
        ...(search ? { search } : {}),
        ...(event ? { event } : {}),
        ...(subjectType ? { subject_type: subjectType } : {}),
        ...(from ? { from } : {}),
        ...(to ? { to } : {}),
      }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Jejak Audit Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Riwayat perubahan modul perpustakaan. Hanya baca — catatan dibuat otomatis oleh sistem.
          </p>
        </div>
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
          <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari Deskripsi</label>
            <Input
              value={search}
              placeholder="Cari deskripsi..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Peristiwa</label>
            <Select
              value={event}
              onChange={(e) => {
                setEvent(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua peristiwa</option>
              {KNOWN_EVENTS.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis Subjek</label>
            <Select
              value={subjectType}
              onChange={(e) => {
                setSubjectType(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua subjek</option>
              {SUBJECT_TYPES.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari Tanggal</label>
            <Input
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai Tanggal</label>
            <Input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat jejak audit...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat jejak audit"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada jejak audit"
            description="Aktivitas modul perpustakaan akan tampil di sini."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="w-8 px-2 py-2 font-medium" />
                  <th className="px-4 py-2 font-medium">Waktu</th>
                  <th className="px-4 py-2 font-medium">Peristiwa</th>
                  <th className="px-4 py-2 font-medium">Subjek</th>
                  <th className="px-4 py-2 font-medium">Deskripsi</th>
                  <th className="px-4 py-2 font-medium">Pelaku</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const isOpen = expandedId === row.id;
                  const keys = Array.from(
                    new Set([
                      ...Object.keys(row.new_values ?? {}),
                      ...Object.keys(row.old_values ?? {}),
                    ]),
                  );
                  return (
                    <Row
                      key={row.id}
                      row={row}
                      keys={keys}
                      isOpen={isOpen}
                      onToggle={() => setExpandedId(isOpen ? null : row.id)}
                    />
                  );
                })}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}
    </div>
  );
}

function Row({
  row,
  keys,
  isOpen,
  onToggle,
}: {
  row: LibraryActivity;
  keys: string[];
  isOpen: boolean;
  onToggle: () => void;
}) {
  const hasChanges = keys.length > 0;

  return (
    <>
      <tr className="border-b border-slate-50 last:border-0 align-top">
        <td className="px-2 py-2">
          {hasChanges && (
            <button
              type="button"
              onClick={onToggle}
              className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
              aria-label={isOpen ? 'Tutup detail' : 'Buka detail'}
            >
              {isOpen ? (
                <ChevronDown className="h-4 w-4" />
              ) : (
                <ChevronRight className="h-4 w-4" />
              )}
            </button>
          )}
        </td>
        <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500">
          {formatDateTime(row.created_at)}
        </td>
        <td className="px-4 py-2">
          <Badge tone="blue" className="font-mono">
            {row.event ?? '—'}
          </Badge>
        </td>
        <td className="px-4 py-2 text-xs text-slate-600">
          {row.subject_type ?? '—'}
          {row.subject_id !== null && (
            <span className="ml-1 font-mono text-slate-400">#{row.subject_id}</span>
          )}
        </td>
        <td className="max-w-[24rem] px-4 py-2 text-slate-700">{row.description}</td>
        <td className="px-4 py-2 text-xs text-slate-600">{row.causer?.name ?? '—'}</td>
      </tr>

      {hasChanges && isOpen && (
        <tr className="border-b border-slate-50 bg-slate-50/60">
          <td />
          <td colSpan={5} className="px-4 py-3">
            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
              Perubahan Nilai
            </p>
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left text-slate-400">
                  <th className="w-1/4 py-1 font-medium">Kolom</th>
                  <th className="w-3/8 py-1 font-medium">Nilai Lama</th>
                  <th className="w-3/8 py-1 font-medium">Nilai Baru</th>
                </tr>
              </thead>
              <tbody>
                {keys.map((key) => (
                  <tr key={key} className="border-t border-slate-100">
                    <td className="py-1 font-mono text-slate-600">{key}</td>
                    <td className="py-1 break-all text-slate-500">
                      {stringify(row.old_values?.[key])}
                    </td>
                    <td className="py-1 break-all text-slate-700">
                      {stringify(row.new_values?.[key])}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </td>
        </tr>
      )}
    </>
  );
}