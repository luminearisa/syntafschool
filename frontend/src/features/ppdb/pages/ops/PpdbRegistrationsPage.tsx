import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Download, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbInvoicesApi, ppdbRegistrationsApi, ppdbReportsApi } from '../../api/ppdbApi';
import { PpdbScopeFilters } from '../../components/ops/PpdbOpsFilters';
import {
  OpsTable,
  PageHeader,
  PaymentStatusBadge,
  DocumentStatusBadge,
  RegistrationStatusBadge,
  ReloadButton,
  useUrlFilters,
  type OpsColumn,
  type OpsSort,
} from '../../components/ops/PpdbOpsUi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Pagination } from '@/components/common/Pagination';
import { Select } from '@/components/ui/Select';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { formatDate } from '@/lib/utils';
import {
  DOCUMENT_STATUS_OPTIONS,
  PAYMENT_STATUS_OPTIONS,
  REGISTRATION_STATUS_OPTIONS,
  money,
  toNumber,
} from '../../components/ops/labels';
import type { PpdbRegistration } from '@/types/ppdb';

/** Kolom yang boleh diurutkan di sisi server. */
const SORTABLE = new Set(['registration_number', 'submitted_at', 'status', 'created_at']);

export function PpdbRegistrationsPage() {
  const filters = useUrlFilters([
    'search',
    'period_id',
    'wave_id',
    'program_id',
    'status',
    'payment_status',
    'document_status',
    'sort_by',
    'sort_dir',
  ]);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'registrations', filters.values],
    placeholderData: (previous) => previous,
    queryFn: () => ppdbRegistrationsApi.list({ ...filters.apiParams(), per_page: 15 }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  /*
   * `PpdbRegistration` tidak memuat nominal tagihan, jadi total diambil dari
   * listing invoice pada lingkup yang sama (maks 100 baris pertama).
   */
  const invoiceQuery = useQuery({
    queryKey: ['ppdb', 'invoices', 'totals', filters.numeric('period_id'), filters.get('wave_id'), filters.get('program_id')],
    enabled: rows.length > 0,
    staleTime: 1000 * 60 * 30,
    queryFn: () =>
      ppdbInvoicesApi.list({
        page: 1,
        per_page: 100,
        period_id: filters.numeric('period_id') || undefined,
        wave_id: filters.numeric('wave_id') || undefined,
        program_id: filters.numeric('program_id') || undefined,
      }),
  });

  const invoiceTotals = useMemo(() => {
    const map = new Map<number, number>();
    for (const invoice of invoiceQuery.data?.data ?? []) {
      map.set(
        invoice.registration_id,
        (map.get(invoice.registration_id) ?? 0) + toNumber(invoice.total_amount),
      );
    }
    return map;
  }, [invoiceQuery.data]);

  const invoicePartial = Boolean(
    invoiceQuery.data && (invoiceQuery.data.meta?.total ?? 0) > invoiceQuery.data.data.length,
  );

  const sort: OpsSort | null = filters.get('sort_by')
    ? { key: filters.get('sort_by'), dir: filters.get('sort_dir') === 'asc' ? 'asc' : 'desc' }
    : null;

  const columns = useMemo<OpsColumn<PpdbRegistration>[]>(
    () => [
      {
        key: 'registration_number',
        label: 'Nomor pendaftaran',
        sortable: true,
        render: (row) => (
          <div>
            <Link
              to={`/ppdb/registrations/${row.id}`}
              className="font-mono text-xs font-medium text-brand-700 hover:underline"
            >
              {row.registration_number}
            </Link>
            <p className="text-sm text-slate-700">{row.applicant?.full_name ?? 'Calon belum terisi'}</p>
            {row.applicant?.nisn && (
              <p className="text-xs text-slate-400">NISN {row.applicant.nisn}</p>
            )}
          </div>
        ),
      },
      {
        key: 'scope',
        label: 'Periode / gelombang / program',
        quiet: true,
        render: (row) => (
          <div className="text-xs text-slate-600">
            <p className="text-sm text-slate-700">{row.program?.name ?? 'Program —'}</p>
            <p>{row.period?.name ?? 'Periode —'}</p>
            <p className="text-slate-400">{row.wave?.name ?? 'Tanpa gelombang'}</p>
          </div>
        ),
      },
      {
        key: 'status',
        label: 'Status pendaftaran',
        sortable: true,
        render: (row) => <RegistrationStatusBadge status={row.status} />,
      },
      {
        key: 'payment_status',
        label: 'Pembayaran',
        render: (row) => <PaymentStatusBadge status={row.payment_status} />,
      },
      {
        key: 'document_status',
        label: 'Dokumen',
        render: (row) => <DocumentStatusBadge status={row.document_status} />,
      },
      {
        key: 'submitted_at',
        label: 'Dikirim',
        sortable: true,
        quiet: true,
        align: 'left',
        render: (row) => (
          <span className="text-xs text-slate-500">
            {row.submitted_at ? formatDate(row.submitted_at) : 'belum dikirim'}
            {row.verified_at && (
              <span className="block text-emerald-600">diverifikasi {formatDate(row.verified_at)}</span>
            )}
          </span>
        ),
      },
      {
        key: 'invoice_total',
        label: 'Total tagihan',
        align: 'right',
        quiet: true,
        render: (row) => {
          const total = invoiceTotals.get(row.id);
          return (
            <span className="text-sm text-slate-700">
              {total == null ? <span className="text-xs text-slate-400">tidak tercantum</span> : money(total)}
            </span>
          );
        },
      },
      {
        key: 'actions',
        label: 'Aksi',
        align: 'right',
        render: (row) => (
          <Link
            to={`/ppdb/registrations/${row.id}`}
            className="inline-flex h-8 items-center rounded-lg px-3 text-xs font-medium text-slate-600 hover:bg-slate-100"
          >
            Buka berkas
          </Link>
        ),
      },
    ],
    [invoiceTotals],
  );

  return (
    <div className="space-y-4">
      <PageHeader
        title="Register Pendaftaran"
        description="Seluruh pendaftaran calon siswa baru dengan tiga sumbu status yang berdiri sendiri."
        badge={meta ? <Badge tone="gray">{meta.total} pendaftaran</Badge> : undefined}
        actions={
          <>
            <Can perm="ppdb.registration.verify">
              <Link
                to={filters.link('/ppdb/verification', { status: undefined })}
                className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                <ShieldCheck className="h-4 w-4" /> Antrean verifikasi
              </Link>
            </Can>
            <Can perm="ppdb.report.export">
              <Button
                variant="outline"
                onClick={() =>
                  ppdbReportsApi
                    .exportCsv('registration', filters.apiParams({ page: undefined, per_page: undefined }))
                    .then(() => toast.success('CSV unduhan dimulai.'))
                    .catch((error: unknown) => toast.error(getErrorMessage(error)))
                }
              >
                <Download className="h-4 w-4" /> Unduh CSV
              </Button>
            </Can>
            <ReloadButton onClick={() => listQuery.refetch()} busy={listQuery.isFetching} />
          </>
        }
      />

      <PpdbScopeFilters filters={filters}>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600">Status pendaftaran</label>
          <Select
            value={filters.get('status')}
            onChange={(event) => filters.set({ status: event.target.value })}
          >
            <option value="">Semua status</option>
            {REGISTRATION_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600">Status pembayaran</label>
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
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600">Status dokumen</label>
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
        </div>
      </PpdbScopeFilters>

      {filters.activeCount > 0 && (
        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <span>Filter aktif:</span>
          {Object.entries(filters.values)
            .filter(([key, value]) => value && key !== 'page' && key !== 'per_page')
            .map(([key, value]) => (
              <button
                key={key}
                type="button"
                className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600 hover:bg-slate-200"
                title="Hapus filter ini"
                onClick={() => filters.set({ [key]: '' })}
              >
                {key}: {value}
              </button>
            ))}
          <button
            type="button"
            className="font-medium text-brand-700 hover:underline"
            onClick={() => filters.reset()}
          >
            Reset semua
          </button>
        </div>
      )}

      <OpsTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        loading={listQuery.isLoading}
        error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
        emptyTitle="Tidak ada pendaftaran"
        emptyDescription="Ubah kata kunci atau filter, atau tunggu pendaftar pertama dari portal."
        sort={sort}
        onSort={(key) => {
          if (!SORTABLE.has(key)) return;
          const current = filters.get('sort_by');
          const dir = current === key && filters.get('sort_dir') === 'desc' ? 'asc' : 'desc';
          filters.set({ sort_by: key, sort_dir: dir });
        }}
        footer={
          <>
            {invoicePartial && (
              <p className="border-t border-slate-100 px-4 py-2 text-xs text-amber-600">
                Nominal tagihan hanya dihitung dari 100 invoice pertama pada lingkup ini — sebagian
                baris bisa terlihat kosong.
              </p>
            )}
            {meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
          </>
        }
      />
    </div>
  );
}
