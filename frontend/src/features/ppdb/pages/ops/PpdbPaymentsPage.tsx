import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { BellRing, ExternalLink, Plus, Receipt } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbInvoicesApi, ppdbPaymentsApi } from '../../api/ppdbApi';
import { RegistrationPicker, PpdbScopeFilters } from '../../components/ops/PpdbOpsFilters';
import {
  ErrorLine,
  FieldList,
  FieldRow,
  FilterField,
  LoadingLine,
  Notice,
  NoteModal,
  OpsTable,
  PageHeader,
  PaymentStatusBadge,
  ReloadButton,
  SectionCard,
  SidePanel,
  StatCard,
  TabsNav,
  useUrlFilters,
  type OpsColumn,
  type UrlFilters,
} from '../../components/ops/PpdbOpsUi';
import {
  INVOICE_TYPE_LABELS,
  PAYMENT_STATUS_LABELS,
  PAYMENT_STATUS_OPTIONS,
  formatDateTime,
  fileUrl,
  money,
  paymentKindTone,
  statusLabelOf,
  toNumber,
} from '../../components/ops/labels';
import { usePpdbReferenceOptions } from '../../hooks/usePpdbReferenceOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Pagination } from '@/components/common/Pagination';
import { Select } from '@/components/ui/Select';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { cn, formatDate } from '@/lib/utils';
import type { PpdbInvoice, PpdbInvoiceType, PpdbPayment } from '@/types/ppdb';

type TabKey = 'invoices' | 'payments';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'invoices', label: 'Tagihan' },
  { key: 'payments', label: 'Pembayaran' },
];

/** Status pembayaran yang masih menuntut tindakan petugas. */
const NEEDS_ACTION = new Set(['pending']);

function ListHeading({
  title,
  description,
  onReload,
  busy,
}: {
  title: string;
  description: string;
  onReload: () => void;
  busy?: boolean;
}) {
  return (
    <div className="flex items-end justify-between gap-2">
      <div>
        <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
        <p className="text-xs text-slate-500">{description}</p>
      </div>
      <ReloadButton onClick={onReload} busy={busy} />
    </div>
  );
}

export function PpdbPaymentsPage() {
  const filters = useUrlFilters([
    'search',
    'period_id',
    'wave_id',
    'program_id',
    'status',
    'type',
    'date_from',
    'date_to',
  ]);
  const [searchParams, setSearchParams] = useSearchParams();
  const tab: TabKey = searchParams.get('tab') === 'payments' ? 'payments' : 'invoices';

  const selectTab = (next: TabKey) => {
    const nextParams = new URLSearchParams(searchParams);
    nextParams.set('tab', next);
    nextParams.delete('page');
    nextParams.set('status', next === 'payments' ? 'pending' : '');
    nextParams.delete('type');
    setSearchParams(nextParams, { replace: true });
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="Pembayaran PPDB"
        description="Tagihan pendaftar dan bukti bayar yang masuk — dua hal berbeda: satu invoice dapat punya beberapa pembayaran."
      />

      <TabsNav tabs={TABS} active={tab} onChange={selectTab} />

      <PpdbScopeFilters filters={filters} showDates searchPlaceholder="Nomor tagihan, nomor pendaftaran, nama calon...">
        <FilterField label="Status">
          <Select
            value={filters.get('status')}
            onChange={(event) => filters.set({ status: event.target.value })}
          >
            <option value="">Semua status bayar</option>
            {PAYMENT_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </FilterField>
        {tab === 'invoices' && (
          <FilterField label="Jenis tagihan">
            <Select
              value={filters.get('type')}
              onChange={(event) => filters.set({ type: event.target.value })}
            >
              <option value="">Semua jenis</option>
              {(Object.keys(INVOICE_TYPE_LABELS) as PpdbInvoiceType[]).map((type) => (
                <option key={type} value={type}>
                  {INVOICE_TYPE_LABELS[type]}
                </option>
              ))}
            </Select>
          </FilterField>
        )}
      </PpdbScopeFilters>

      {tab === 'invoices' ? <InvoiceTab filters={filters} /> : <PaymentTab filters={filters} />}
    </div>
  );
}

/* ============================ TAB TAGIHAN ============================ */

function InvoiceTab({ filters }: { filters: UrlFilters }) {
  const queryClient = useQueryClient();
  const [creating, setCreating] = useState(false);
  const [detail, setDetail] = useState<PpdbInvoice | null>(null);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'invoices', filters.values],
    placeholderData: (previous) => previous,
    queryFn: () => ppdbInvoicesApi.list({ ...filters.apiParams(), per_page: 15 }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const totals = useMemo(() => {
    let billed = 0;
    let collected = 0;
    let outstanding = 0;
    for (const invoice of rows) {
      billed += toNumber(invoice.total_amount);
      collected += toNumber(invoice.paid_amount);
      outstanding +=
        invoice.outstanding_amount !== undefined
          ? toNumber(invoice.outstanding_amount)
          : toNumber(invoice.total_amount) - toNumber(invoice.paid_amount);
    }
    return { billed, collected, outstanding };
  }, [rows]);

  const remindMutation = useMutation({
    mutationFn: (id: number) => ppdbInvoicesApi.remind(id),
    onSuccess: (result) => {
      if (result.sent) toast.success(result.message || 'Reminder tagihan dikirim.');
      else toast.warning(result.message || 'Reminder tidak terkirim — periksa kanal notifikasi akun.');
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const columns = useMemo<OpsColumn<PpdbInvoice>[]>(
    () => [
      {
        key: 'number',
        label: 'Nomor tagihan',
        render: (row) => (
          <div>
            <p className="font-mono text-xs font-medium text-slate-700">{row.number}</p>
            <p className="text-xs text-slate-400">{INVOICE_TYPE_LABELS[row.type] ?? row.type}</p>
          </div>
        ),
      },
      {
        key: 'registration',
        label: 'Pendaftaran',
        render: (row) => (
          <div className="text-xs">
            {row.registration ? (
              <>
                <Link
                  to={`/ppdb/registrations/${row.registration.id ?? row.registration_id}`}
                  className="text-sm font-medium text-brand-700 hover:underline"
                >
                  {row.registration.applicant?.full_name ?? 'Lihat berkas'}
                </Link>
                <p className="font-mono text-slate-400">
                  {row.registration.registration_number ?? `#${row.registration_id}`}
                </p>
              </>
            ) : (
              <Link
                to={`/ppdb/registrations/${row.registration_id}`}
                className="text-brand-700 hover:underline"
              >
                Pendaftaran #{row.registration_id}
              </Link>
            )}
          </div>
        ),
      },
      {
        key: 'amount',
        label: 'Total',
        align: 'right',
        render: (row) => (
          <div className="text-xs">
            <p className="text-sm font-medium text-slate-700">{money(row.total_amount)}</p>
            {toNumber(row.discount_amount) > 0 && (
              <p className="text-emerald-600">diskon {money(row.discount_amount)}</p>
            )}
          </div>
        ),
      },
      {
        key: 'paid',
        label: 'Dibayar',
        align: 'right',
        render: (row) => <span className="text-sm text-slate-600">{money(row.paid_amount)}</span>,
      },
      {
        key: 'outstanding',
        label: 'Kurang bayar',
        align: 'right',
        render: (row) => {
          const remaining =
            row.outstanding_amount !== undefined
              ? toNumber(row.outstanding_amount)
              : toNumber(row.total_amount) - toNumber(row.paid_amount);
          return (
            <span className={cn('text-sm', remaining > 0 ? 'text-amber-600' : 'text-slate-400')}>
              {money(remaining)}
            </span>
          );
        },
      },
      {
        key: 'status',
        label: 'Status',
        render: (row) => <PaymentStatusBadge status={row.status} />,
      },
      {
        key: 'due_date',
        label: 'Jatuh tempo',
        quiet: true,
        render: (row) => (
          <span className="text-xs text-slate-500">
            {row.due_date ? formatDate(row.due_date) : 'tanpa batas'}
            {row.paid_at && <span className="block text-emerald-600">lunas {formatDate(row.paid_at)}</span>}
          </span>
        ),
      },
      {
        key: 'actions',
        label: 'Aksi',
        align: 'right',
        render: (row) => (
          <div className="flex flex-wrap items-center justify-end gap-1.5">
            <Button size="sm" variant="outline" onClick={() => setDetail(row)}>
              <Receipt className="h-3.5 w-3.5" /> Rincian
            </Button>
            <Can perm={['ppdb.payment.verify', 'ppdb.registration.manage']}>
              <Button
                size="sm"
                variant="ghost"
                disabled={row.status === 'paid' || row.status === 'cancelled'}
                loading={remindMutation.isPending && remindMutation.variables === row.id}
                onClick={() => remindMutation.mutate(row.id)}
              >
                <BellRing className="h-3.5 w-3.5" /> Ingatkan
              </Button>
            </Can>
          </div>
        ),
      },
    ],
    [remindMutation],
  );

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Tagihan terpantau" value={meta?.total ?? 0} hint="Total baris pada filter ini." />
        <StatCard label="Ditagihkan (halaman ini)" value={money(totals.billed)} tone="brand" />
        <StatCard label="Diterima (halaman ini)" value={money(totals.collected)} tone="green" />
        <StatCard
          label="Kurang bayar (halaman ini)"
          value={money(totals.outstanding)}
          tone={totals.outstanding > 0 ? 'amber' : 'green'}
          hint="Nominal dihitung dari baris yang tampil."
        />
      </div>

      <Can perm="ppdb.fee.manage">
        <Card className="flex flex-wrap items-center justify-between gap-2 p-4">
          <div>
            <p className="text-sm font-medium text-slate-800">Terbitkan tagihan manual</p>
            <p className="text-xs text-slate-500">
              Untuk pendaftaran yang tagihannya belum terbentuk otomatis, atau biaya lain di luar
              biaya pendaftaran/daftar ulang.
            </p>
          </div>
          <Button size="sm" onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" /> Buat tagihan
          </Button>
        </Card>
      </Can>

      <ListHeading
        title="Daftar tagihan"
        description="Termasuk tagihan biaya pendaftaran dan biaya daftar ulang."
        onReload={() => listQuery.refetch()}
        busy={listQuery.isFetching}
      />

      <OpsTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        loading={listQuery.isLoading}
        error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
        emptyTitle="Belum ada tagihan"
        emptyDescription="Tagihan pendaftaran biasanya terbit otomatis saat berkas dikirim. Buat manual bila perlu."
        footer={meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
      />

      <CreateInvoiceModal
        open={creating}
        onClose={() => setCreating(false)}
        onCreated={() => {
          setCreating(false);
          listQuery.refetch();
        }}
      />

      <SidePanel
        open={Boolean(detail)}
        title={detail ? `Tagihan ${detail.number}` : 'Tagihan'}
        description={detail ? INVOICE_TYPE_LABELS[detail.type] ?? detail.type : undefined}
        onClose={() => setDetail(null)}
      >
        {detail ? <InvoiceDetail invoiceId={detail.id} /> : <LoadingLine />}
      </SidePanel>
    </div>
  );
}

function InvoiceDetail({ invoiceId }: { invoiceId: number }) {
  const query = useQuery({
    queryKey: ['ppdb', 'invoice', invoiceId],
    queryFn: () => ppdbInvoicesApi.show(invoiceId),
  });

  if (query.isLoading) return <LoadingLine label="Memuat rincian tagihan..." />;
  if (query.isError) return <ErrorLine message={getErrorMessage(query.error)} onRetry={() => query.refetch()} />;

  const invoice = query.data;
  if (!invoice) {
    return <ErrorLine message="Rincian tagihan tidak dikembalikan server." onRetry={() => query.refetch()} />;
  }

  const outstanding =
    invoice.outstanding_amount !== undefined
      ? toNumber(invoice.outstanding_amount)
      : toNumber(invoice.total_amount) - toNumber(invoice.paid_amount);

  return (
    <div className="space-y-4">
      <SectionCard title="Ringkasan">
        <FieldList>
          <FieldRow label="Nomor">{invoice.number}</FieldRow>
          <FieldRow label="Jenis">{INVOICE_TYPE_LABELS[invoice.type] ?? invoice.type}</FieldRow>
          <FieldRow label="Pendaftaran">
            <Link
              to={`/ppdb/registrations/${invoice.registration_id}`}
              className="text-brand-700 hover:underline"
            >
              {invoice.registration?.registration_number ?? `#${invoice.registration_id}`}
            </Link>
          </FieldRow>
          <FieldRow label="Status">
            <PaymentStatusBadge status={invoice.status} />
          </FieldRow>
          <FieldRow label="Terbit / jatuh tempo">
            {invoice.issued_at ? formatDate(invoice.issued_at) : '—'} /{' '}
            {invoice.due_date ? formatDate(invoice.due_date) : 'tanpa batas'}
          </FieldRow>
        </FieldList>
      </SectionCard>

      <SectionCard title="Rincian biaya">
        {invoice.items && invoice.items.length > 0 ? (
          <ul className="divide-y divide-slate-50 text-sm">
            {invoice.items.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-2 py-1.5">
                <span className="text-slate-600">{item.name}</span>
                <span className="font-medium text-slate-800">{money(item.amount)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-slate-400">Rincian biaya tidak dikembalikan server.</p>
        )}
        <dl className="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
          <div className="flex justify-between">
            <dt className="text-slate-500">Subtotal</dt>
            <dd>{money(invoice.amount)}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-slate-500">Diskon</dt>
            <dd>-{money(invoice.discount_amount)}</dd>
          </div>
          <div className="flex justify-between font-medium">
            <dt className="text-slate-600">Total tagihan</dt>
            <dd>{money(invoice.total_amount)}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-slate-500">Dibayar</dt>
            <dd className="text-emerald-600">{money(invoice.paid_amount)}</dd>
          </div>
          <div className="flex justify-between font-medium">
            <dt className="text-slate-600">Kurang bayar</dt>
            <dd className={outstanding > 0 ? 'text-amber-600' : 'text-slate-500'}>{money(outstanding)}</dd>
          </div>
        </dl>
        {invoice.notes && <p className="mt-3 text-xs text-slate-500">Catatan: {invoice.notes}</p>}
      </SectionCard>

      {invoice.instructions && invoice.instructions.length > 0 && (
        <SectionCard title="Rekening tujuan">
          <ul className="space-y-2 text-sm">
            {invoice.instructions.map((instruction) => (
              <li key={instruction.id} className="rounded-lg bg-slate-50 px-3 py-2">
                <p className="font-medium text-slate-700">{instruction.name}</p>
                <p className="text-xs text-slate-500">
                  {instruction.account_number ?? '—'} a.n. {instruction.account_name ?? instruction.bank ?? '—'}
                </p>
              </li>
            ))}
          </ul>
        </SectionCard>
      )}

      <SectionCard title="Pembayaran pada tagihan ini">
        {invoice.payments === undefined ? (
          <p className="text-sm text-slate-400">
            Riwayat pembayaran tidak disertakan pada rincian ini — lihat tab Pembayaran.
          </p>
        ) : invoice.payments.length === 0 ? (
          <p className="text-sm text-slate-400">Belum ada pembayaran masuk.</p>
        ) : (
          <ul className="divide-y divide-slate-50 text-sm">
            {invoice.payments.map((payment) => (
              <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <div>
                  <p className="font-medium text-slate-700">{money(payment.amount)}</p>
                  <p className="text-xs text-slate-400">
                    {formatDateTime(payment.paid_at ?? payment.created_at)} ·{' '}
                    {payment.provider_reference ?? payment.provider ?? 'tanpa referensi'}
                  </p>
                </div>
                <div className="flex items-center gap-1.5">
                  <Badge tone={paymentKindTone(payment.method)}>{payment.method}</Badge>
                  <Badge tone="gray">{statusLabelOf(PAYMENT_STATUS_LABELS, payment.status)}</Badge>
                </div>
              </li>
            ))}
          </ul>
        )}
      </SectionCard>
    </div>
  );
}

function CreateInvoiceModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [registrationId, setRegistrationId] = useState<number | ''>('');
  const [type, setType] = useState<PpdbInvoiceType>('registration');

  const mutation = useMutation({
    mutationFn: () =>
      ppdbInvoicesApi.create({
        registration_id: registrationId as number,
        type: type === 'registration' ? undefined : type,
      }),
    onSuccess: (invoice) => {
      toast.success(`Tagihan ${invoice.number} dibuat.`);
      setRegistrationId('');
      setType('registration');
      onCreated();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const errors = getValidationErrors(mutation.error);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Buat tagihan manual"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={mutation.isPending}>
            Batal
          </Button>
          <Button
            loading={mutation.isPending}
            disabled={registrationId === ''}
            onClick={() => mutation.mutate()}
          >
            Terbitkan tagihan
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Notice tone="gray">
          Nominal diambil dari biaya yang dikonfigurasi untuk periode pendaftaran ini — tagihan
          manual tidak dapat diisi nilainya secara bebas.
        </Notice>
        <RegistrationPicker
          required
          value={registrationId}
          onChange={setRegistrationId}
          error={errors?.registration_id?.[0]}
        />
        <div>
          <Label>Jenis tagihan</Label>
          <Select value={type} onChange={(event) => setType(event.target.value as PpdbInvoiceType)}>
            {(Object.keys(INVOICE_TYPE_LABELS) as PpdbInvoiceType[]).map((key) => (
              <option key={key} value={key}>
                {INVOICE_TYPE_LABELS[key]}
              </option>
            ))}
          </Select>
          {errors?.type && <p className="mt-1 text-xs text-red-500">{errors.type[0]}</p>}
        </div>
      </div>
    </Modal>
  );
}

/* ============================ TAB PEMBAYARAN ============================ */

function PaymentTab({ filters }: { filters: UrlFilters }) {
  const queryClient = useQueryClient();
  const [decision, setDecision] = useState<{ payment: PpdbPayment; kind: 'verify' | 'reject' } | null>(
    null,
  );
  const { options: methods } = usePpdbReferenceOptions('payment_methods');
  const methodLabels = useMemo(
    () => new Map(methods.map((method) => [String(method.value), method.label])),
    [methods],
  );

  const listQuery = useQuery({
    queryKey: ['ppdb', 'payments', filters.values],
    placeholderData: (previous) => previous,
    queryFn: () => ppdbPaymentsApi.list({ ...filters.apiParams(), per_page: 15 }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const pendingCount = rows.filter((row) => NEEDS_ACTION.has(row.status)).length;

  const decideMutation = useMutation({
    mutationFn: (args: { payment: PpdbPayment; kind: 'verify' | 'reject'; note: string }) =>
      args.kind === 'verify'
        ? ppdbPaymentsApi.verify(args.payment.id, args.note || undefined)
        : ppdbPaymentsApi.reject(args.payment.id, args.note),
    onSuccess: (_data, args) => {
      toast.success(args.kind === 'verify' ? 'Pembayaran disahkan.' : 'Pembayaran ditolak.');
      setDecision(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const columns = useMemo<OpsColumn<PpdbPayment>[]>(
    () => [
      {
        key: 'when',
        label: 'Waktu',
        render: (row) => (
          <div className="text-xs text-slate-500">
            <p className="text-sm text-slate-700">{formatDateTime(row.paid_at ?? row.created_at)}</p>
            <p>masuk {formatDateTime(row.created_at)}</p>
          </div>
        ),
      },
      {
        key: 'invoice',
        label: 'Tagihan induk',
        render: (row) => (
          <span className="font-mono text-xs text-slate-500" title="Buka tab Tagihan untuk melihat nomor tagihannya">
            Invoice #{row.invoice_id}
          </span>
        ),
      },
      {
        key: 'amount',
        label: 'Nominal',
        align: 'right',
        render: (row) => <span className="text-sm font-medium text-slate-700">{money(row.amount)}</span>,
      },
      {
        key: 'method',
        label: 'Cara bayar',
        render: (row) => (
          <div className="space-y-1">
            <Badge tone={paymentKindTone(row.method)}>
              {row.method === 'manual' ? 'Transfer manual' : 'Midtrans'}
            </Badge>
            <p className="text-xs text-slate-500">
              {row.payment_method_id
                ? methodLabels.get(String(row.payment_method_id)) ?? `metode #${row.payment_method_id}`
                : row.provider ?? 'metode tidak tercatat'}
            </p>
            {row.provider_reference && (
              <p className="font-mono text-[11px] text-slate-400">{row.provider_reference}</p>
            )}
          </div>
        ),
      },
      {
        key: 'proof',
        label: 'Bukti bayar',
        render: (row) => {
          const url = fileUrl(row.proof_url, row.proof_path);
          return url ? (
            <a
              href={url}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline"
            >
              <ExternalLink className="h-3.5 w-3.5" /> Lihat bukti
            </a>
          ) : (
            <span className="text-xs text-slate-400">
              {row.method === 'midtrans' ? 'otomatis dari sistem pembayaran' : 'belum dilampirkan'}
            </span>
          );
        },
      },
      {
        key: 'status',
        label: 'Status',
        render: (row) => (
          <div className="space-y-1">
            <PaymentStatusBadge status={row.status} />
            {row.verified_at && (
              <p className="text-[11px] text-slate-400">
                disahkan {formatDateTime(row.verified_at)}
                {row.verified_by_name ? ` oleh ${row.verified_by_name}` : ''}
              </p>
            )}
          </div>
        ),
      },
      {
        key: 'actions',
        label: 'Aksi',
        align: 'right',
        render: (row) => (
          <Can perm="ppdb.payment.verify">
            <div className="flex flex-wrap items-center justify-end gap-1.5">
              <Button
                size="sm"
                disabled={row.status !== 'pending'}
                onClick={() => setDecision({ payment: row, kind: 'verify' })}
              >
                Sahkan
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={row.status !== 'pending'}
                onClick={() => setDecision({ payment: row, kind: 'reject' })}
              >
                Tolak
              </Button>
            </div>
          </Can>
        ),
      },
    ],
    [methodLabels],
  );

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Menunggu pengesahan" value={pendingCount} tone="amber" hint="Baris berstatus Pending pada halaman ini." />
        <StatCard label="Total pembayaran" value={meta?.total ?? 0} hint="Sesuai filter yang aktif." />
        <StatCard
          label="Diterima (halaman ini)"
          value={money(rows.filter((row) => row.status === 'paid').reduce((sum, row) => sum + toNumber(row.amount), 0))}
          tone="green"
        />
      </div>

      {filters.get('status') === '' && (
        <Notice tone="blue">
          Belum ada filter status. Petugas biasanya bekerja pada daftar <strong>Pending</strong> —{' '}
          <button type="button" className="font-medium underline" onClick={() => filters.set({ status: 'pending' })}>
            saring sekarang
          </button>
          .
        </Notice>
      )}

      <ListHeading
        title="Bukti pembayaran masuk"
        description="Setiap baris adalah satu transaksi; pengesahan menambah catatan pada tagihan induknya."
        onReload={() => listQuery.refetch()}
        busy={listQuery.isFetching}
      />

      <OpsTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        loading={listQuery.isLoading}
        error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
        emptyTitle="Tidak ada pembayaran"
        emptyDescription="Bukti bayar muncul di sini setelah orang tua mengunggah pembayaran manual atau webhook masuk."
        footer={meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
      />

      <NoteModal
        open={Boolean(decision)}
        title={
          decision?.kind === 'verify'
            ? `Sahkan pembayaran ${decision ? money(decision.payment.amount) : ''}`
            : 'Tolak pembayaran'
        }
        description={
          decision?.kind === 'verify'
            ? 'Nominal akan diperhitungkan pada tagihannya. Bila lunas, status pembayaran pendaftaran berpindah menjadi Lunas.'
            : 'Alasan wajib diisi — orang tua melihat catatan ini saat mengunggah ulang bukti bayar.'
        }
        noteLabel={decision?.kind === 'verify' ? 'Catatan pengesahan (opsional)' : 'Alasan penolakan'}
        noteRequired={decision?.kind === 'reject'}
        confirmLabel={decision?.kind === 'verify' ? 'Sahkan pembayaran' : 'Tolak pembayaran'}
        tone={decision?.kind === 'reject' ? 'danger' : 'primary'}
        busy={decideMutation.isPending}
        errors={getValidationErrors(decideMutation.error)}
        onClose={() => setDecision(null)}
        onSubmit={(note) => {
          if (!decision) return;
          decideMutation.mutate({ payment: decision.payment, kind: decision.kind, note });
        }}
      />
    </div>
  );
}
