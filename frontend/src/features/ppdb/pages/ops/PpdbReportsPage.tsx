import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { Download, FileText } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbReportsApi } from '../../api/ppdbApi';
import { PpdbScopeFilters } from '../../components/ops/PpdbOpsFilters';
import {
  ErrorLine,
  FieldList,
  FieldRow,
  FilterField,
  Notice,
  OpsTable,
  PageHeader,
  ReloadButton,
  SectionCard,
  StatCard,
  TabsNav,
  useUrlFilters,
  type OpsColumn,
  type OpsSort,
} from '../../components/ops/PpdbOpsUi';
import {
  DOCUMENT_STATUS_OPTIONS,
  PAYMENT_STATUS_OPTIONS,
  REGISTRATION_STATUS_OPTIONS,
  money,
  prettifyKey,
} from '../../components/ops/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { cn, formatDate } from '@/lib/utils';
import type { PpdbReportPayload } from '@/types/ppdb';

type ReportKey = 'registration' | 'payment' | 'selection' | 'document';

const REPORTS: { key: ReportKey; label: string; description: string }[] = [
  {
    key: 'registration',
    label: 'Pendaftaran',
    description: 'Rekap pendaftaran per calon: lingkup, status tiga sumbu, dan waktu pengiriman.',
  },
  {
    key: 'payment',
    label: 'Pembayaran',
    description: 'Tagihan, pembayaran, dan kurang bayar untuk menghitung penerimaan PPDB.',
  },
  {
    key: 'selection',
    label: 'Seleksi',
    description: 'Nilai per tahap seleksi dan hasil akhir setiap pendaftar.',
  },
  {
    key: 'document',
    label: 'Dokumen',
    description: 'Status berkas per syarat dokumen, termasuk yang ditolak petugas.',
  },
];

const MONEY_HINTS = ['amount', 'total', 'tagihan', 'bayar', 'biaya', 'nominal', 'outstanding'];
const DATE_HINTS = ['_at', '_date', 'tanggal'];

/** Kolom laporan mana yang berisi angka -> boleh dijumlahkan pada baris total. */
function numericValue(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))) {
    return Number(value);
  }
  return null;
}

function isMoneyColumn(key: string): boolean {
  return MONEY_HINTS.some((hint) => key.toLowerCase().includes(hint));
}

function isDateColumn(key: string): boolean {
  const lower = key.toLowerCase();
  return DATE_HINTS.some((hint) => lower.endsWith(hint) || lower.includes(`${hint}_`));
}

function renderCell(key: string, value: unknown): { node: string; raw: unknown } {
  if (value === null || value === undefined || value === '') return { node: '—', raw: value };
  if (typeof value === 'boolean') return { node: value ? 'Ya' : 'Tidak', raw: value };
  if (typeof value === 'number') {
    return { node: isMoneyColumn(key) ? money(value) : new Intl.NumberFormat('id-ID').format(value), raw: value };
  }
  const text = String(value);
  if (isMoneyColumn(key) && numericValue(text) !== null) return { node: money(text), raw: text };
  if (isDateColumn(key)) {
    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) return { node: formatDate(text), raw: text };
  }
  return { node: text, raw: text };
}

export function PpdbReportsPage() {
  const filters = useUrlFilters([
    'period_id',
    'wave_id',
    'program_id',
    'date_from',
    'date_to',
    'status',
    'payment_status',
    'document_status',
  ]);
  const [searchParams, setSearchParams] = useSearchParams();
  const type: ReportKey = REPORTS.some((item) => item.key === searchParams.get('type'))
    ? (searchParams.get('type') as ReportKey)
    : 'registration';

  const [sort, setSort] = useState<OpsSort | null>(null);
  const [exporting, setExporting] = useState<'csv' | 'pdf' | null>(null);

  const reportQuery = useQuery({
    queryKey: ['ppdb', 'report', type, filters.values],
    placeholderData: (previous) => previous,
    queryFn: () => ppdbReportsApi.byType(type, filters.apiParams()),
  });

  const report = reportQuery.data;
  const active = REPORTS.find((item) => item.key === type);

  const selectType = (next: ReportKey) => {
    const params = new URLSearchParams(searchParams);
    params.set('type', next);
    setSearchParams(params, { replace: true });
    setSort(null);
  };

  const rows = useMemo(() => {
    const source = (report?.rows ?? []) as Record<string, unknown>[];
    if (!sort) return source;
    const factor = sort.dir === 'asc' ? 1 : -1;
    return [...source].sort((a, b) => {
      const left = a[sort.key];
      const right = b[sort.key];
      const leftNumber = numericValue(left);
      const rightNumber = numericValue(right);
      if (leftNumber !== null && rightNumber !== null) return (leftNumber - rightNumber) * factor;
      return String(left ?? '').localeCompare(String(right ?? '')) * factor;
    });
  }, [report, sort]);

  const columns = useMemo<OpsColumn<Record<string, unknown>>[]>(() => {
    const source = report?.columns ?? [];
    return source.map((column) => ({
      key: column.key,
      label: column.label,
      sortable: true,
      align: numericValueFirst(rows, column.key) !== null || isMoneyColumn(column.key) ? 'right' : 'left',
      render: (row) => {
        const cell = renderCell(column.key, row[column.key]);
        return (
          <span
            className={cn(
              'text-sm',
              sort?.key === column.key ? 'font-medium text-brand-700' : 'text-slate-700',
            )}
            title={cell.raw === undefined ? undefined : String(cell.raw)}
          >
            {cell.node}
          </span>
        );
      },
    }));
  }, [report, rows, sort]);

  const totals = useMemo(() => {
    const source = report?.columns ?? [];
    const out: Record<string, number> = {};
    for (const column of source) {
      let sum = 0;
      let seen = false;
      for (const row of rows) {
        const value = numericValue(row[column.key]);
        if (value !== null) {
          sum += value;
          seen = true;
        }
      }
      if (seen) out[column.key] = sum;
    }
    return out;
  }, [report, rows]);

  const summaryItems = useMemo(() => normaliseSummary(report?.summary), [report]);

  const runExport = async (kind: 'csv' | 'pdf') => {
    setExporting(kind);
    try {
      if (kind === 'csv') await ppdbReportsApi.exportCsv(type, filters.apiParams());
      else await ppdbReportsApi.reportPdf(type, filters.apiParams());
      toast.success(kind === 'csv' ? 'CSV sedang diunduh.' : 'PDF sedang diunduh.');
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setExporting(null);
    }
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="Laporan PPDB"
        description="Empat kelompok laporan dalam satu layar. Angka dihitung dari data pada lingkup filter yang dipilih."
        badge={report ? <Badge tone="gray">{rows.length} baris</Badge> : undefined}
        actions={
          <>
            <ReloadButton onClick={() => reportQuery.refetch()} busy={reportQuery.isFetching} />
            <Can perm="ppdb.report.export">
              <Button
                variant="outline"
                disabled={exporting !== null}
                onClick={() => runExport('csv')}
                title="Unduhan CSV dibuka langsung di aplikasi spreadsheet"
              >
                {exporting === 'csv' ? <Spinner /> : <Download className="h-4 w-4" />} Unduh CSV
              </Button>
            </Can>
            <Can perm="ppdb.report.export">
              <Button disabled={exporting !== null} onClick={() => runExport('pdf')}>
                {exporting === 'pdf' ? <Spinner /> : <FileText className="h-4 w-4" />} Cetak / PDF
              </Button>
            </Can>
          </>
        }
      />

      <TabsNav tabs={REPORTS.map((item) => ({ key: item.key, label: item.label }))} active={type} onChange={selectType} />

      {active && (
        <p className="text-sm text-slate-500">{active.description}</p>
      )}

      <PpdbScopeFilters filters={filters} showSearch={false} showDates>
        <StatusFilter filters={filters} type={type} />
      </PpdbScopeFilters>

      {report && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard label="Judul laporan" value={report.title} hint={`Dibuat ${formatDate(report.generated_at)}`} />
          {summaryItems.slice(0, 3).map((item) => (
            <StatCard
              key={item.label}
              label={item.label}
              value={
                typeof item.value === 'number' && isMoneyColumn(item.key ?? '')
                  ? money(item.value)
                  : String(item.value)
              }
            />
          ))}
        </div>
      )}

      <OpsTable
        columns={columns}
        rows={rows}
        rowKey={(_row, index) => index}
        loading={reportQuery.isLoading}
        error={reportQuery.isError ? getErrorMessage(reportQuery.error) : undefined}
        emptyTitle="Laporan kosong"
        emptyDescription="Tidak ada baris untuk lingkup ini. Perluas rentang tanggal atau ganti periode."
        sort={sort}
        onSort={(key) =>
          setSort((current) =>
            current?.key === key ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' },
          )
        }
        footer={
          Object.keys(totals).length > 0 ? (
            <div className="overflow-x-auto border-t border-slate-100">
              <table className="w-full text-sm">
                <tbody>
                  <tr className="bg-slate-50 text-xs font-semibold text-slate-700">
                    {(report?.columns ?? []).map((column, index) => (
                      <td key={column.key} className={cn('px-4 py-2', totals[column.key] === undefined && index > 0 && 'text-slate-400')}>
                        {index === 0
                          ? 'TOTAL KOLOM ANGKA'
                          : totals[column.key] === undefined
                            ? ''
                            : isMoneyColumn(column.key)
                              ? money(totals[column.key])
                              : new Intl.NumberFormat('id-ID').format(totals[column.key])}
                      </td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>
          ) : null
        }
      />

      {report && Object.keys(report.filters ?? {}).length > 0 && (
        <SectionCard title="Filter yang dipakai server">
          <FieldList>
            {Object.entries(report.filters).map(([key, value]) => (
              <FieldRow key={key} label={key}>
                {value === null || value === undefined || value === '' ? '—' : String(value)}
              </FieldRow>
            ))}
          </FieldList>
        </SectionCard>
      )}

      <Notice tone="gray">
        Baris total menjumlahkan kolom bernilai angka pada halaman ini saja. PDF diunduh sebagai berkas
        terpisah agar hasil cetaknya identik dengan laporan resmi, bukan tampilan layar admin.
      </Notice>

      {reportQuery.isError && <ErrorLine message={getErrorMessage(reportQuery.error)} onRetry={() => reportQuery.refetch()} />}
    </div>
  );
}

/* ============================ BANTU ============================ */

function numericValueFirst(rows: Record<string, unknown>[], key: string): number | null {
  for (const row of rows) {
    const value = numericValue(row[key]);
    if (value !== null) return value;
  }
  return null;
}

function normaliseSummary(
  summary: PpdbReportPayload['summary'] | undefined,
): { key?: string; label: string; value: string | number }[] {
  if (!summary) return [];
  if (Array.isArray(summary)) {
    return summary.map((item) => ({
      key: item.key,
      label: item.label || prettifyKey(item.key ?? ''),
      value: item.value,
    }));
  }
  return Object.entries(summary).map(([key, value]) => ({ key, label: key.replace(/_/g, ' '), value }));
}

function StatusFilter({
  filters,
  type,
}: {
  filters: ReturnType<typeof useUrlFilters>;
  type: ReportKey;
}) {
  if (type === 'payment') {
    return (
      <FilterField label="Status bayar">
        <Select
          value={filters.get('payment_status')}
          onChange={(event) => filters.set({ payment_status: event.target.value })}
        >
          <option value="">Semua status bayar</option>
          {PAYMENT_STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </FilterField>
    );
  }
  if (type === 'document') {
    return (
      <FilterField label="Status dokumen">
        <Select
          value={filters.get('document_status')}
          onChange={(event) => filters.set({ document_status: event.target.value })}
        >
          <option value="">Semua status dokumen</option>
          {DOCUMENT_STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </FilterField>
    );
  }
  return (
    <FilterField label="Status pendaftaran">
      <Select value={filters.get('status')} onChange={(event) => filters.set({ status: event.target.value })}>
        <option value="">Semua status pendaftaran</option>
        {REGISTRATION_STATUS_OPTIONS.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </FilterField>
  );
}
