import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CalendarRange, ExternalLink, RefreshCw } from 'lucide-react';
import { ppdbDashboardApi } from '../../api/ppdbApi';
import { PpdbScopeFilters } from '../../components/ops/PpdbOpsFilters';
import {
  BarChart,
  PageHeader,
  ReloadButton,
  SectionCard,
  StatCard,
  useUrlFilters,
} from '../../components/ops/PpdbOpsUi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { cn, formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import {
  ACCOUNT_STATUS_LABELS,
  DOCUMENT_STATUS_LABELS,
  PAYMENT_STATUS_LABELS,
  REGISTRATION_STATUS_HINTS,
  REGISTRATION_STATUS_LABELS,
  money,
  registrationStatusTone,
} from '../../components/ops/labels';
import type {
  PpdbAccountStatus,
  PpdbDashboard,
  PpdbDocumentStatus,
  PpdbPaymentStatus,
  PpdbRegistrationStatus,
} from '@/types/ppdb';

const REGISTRATION_STATUSES = Object.keys(REGISTRATION_STATUS_LABELS) as PpdbRegistrationStatus[];
const PAYMENT_STATUSES = Object.keys(PAYMENT_STATUS_LABELS) as PpdbPaymentStatus[];
const DOCUMENT_STATUSES = Object.keys(DOCUMENT_STATUS_LABELS) as PpdbDocumentStatus[];

/**
 * `route_key`/`key` pada agenda tertunda dipeta ke halaman operasi; tanpa ini
 * kartu dashboard hanya jadi hiasan.
 */
function actionPath(key: string): string {
  const needle = key.toLowerCase();
  if (/re.?reg|daftar.?ulang/.test(needle)) return '/ppdb/registrations?status=re_registration';
  if (/convert|student|siswa/.test(needle)) return '/ppdb/registrations?status=completed';
  if (/result|hasil|announce|pengumuman/.test(needle)) return '/ppdb/announcements';
  if (/select|seleksi|score|nilai/.test(needle)) return '/ppdb/selection';
  if (/payment|invoice|tagihan|bayar/.test(needle)) return '/ppdb/payments';
  if (/account|parent|orang.?tua|wali/.test(needle)) return '/ppdb/parent-accounts';
  if (/document|verif|review|berkas/.test(needle)) return '/ppdb/verification';
  if (/revision|revisi/.test(needle)) return '/ppdb/registrations?status=need_revision';
  return '/ppdb/registrations';
}

function DistributionPanels({ data }: { data: PpdbDashboard }) {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard title="Distribusi per Program" description="Bar = jumlah pendaftar.">
        <BarChart
          items={(data.distribution.by_program ?? []).map((program) => ({
            label: program.name,
            value: program.applications,
            hint: program.has_quota ? `${program.filled_percent}% kuota` : undefined,
            to: `/ppdb/registrations?program_id=${program.id}`,
          }))}
        />
      </SectionCard>
      <SectionCard title="Distribusi per Gelombang" description="Bar = jumlah pendaftar.">
        <BarChart
          items={(data.distribution.by_wave ?? []).map((wave) => ({
            label: wave.name,
            value: wave.applications,
            hint: wave.has_quota ? `${wave.filled_percent}% kuota` : undefined,
            to: `/ppdb/registrations?wave_id=${wave.id}`,
          }))}
        />
      </SectionCard>
      <SectionCard
        title="Distribusi Jenis Kelamin"
        description={data.distribution.by_gender?.length ? 'Bar = jumlah calon.' : 'Tidak dikirim pada lingkup ini.'}
      >
        <BarChart
          items={(data.distribution.by_gender ?? []).map((gender) => ({
            label: gender.name || 'Belum diisi',
            value: gender.applicants,
          }))}
        />
      </SectionCard>
    </div>
  );
}

export function PpdbDashboardPage() {
  const filters = useUrlFilters([
    'period_id',
    'wave_id',
    'program_id',
    'date_from',
    'date_to',
  ]);

  const query = useQuery({
    queryKey: ['ppdb', 'dashboard', filters.values],
    refetchOnWindowFocus: false,
    queryFn: () => ppdbDashboardApi.get(filters.apiParams()),
  });

  const data = query.data;
  const byStatus = data?.statistics?.by_status ?? ({} as Record<PpdbRegistrationStatus, number>);
  const byPayment = data?.statistics?.by_payment_status ?? ({} as Record<PpdbPaymentStatus, number>);
  const byDocument = data?.statistics?.by_document_status ?? ({} as Record<PpdbDocumentStatus, number>);
  const byAccount = data?.statistics?.by_account_status ?? {};
  const totalRegistrations = Number(data?.statistics?.total_registrations ?? 0);
  const totalApplicants = Number(data?.statistics?.total_applicants ?? 0);

  const hasAnyData = totalRegistrations > 0 || totalApplicants > 0 || Boolean(data?.period);

  const scopeQuery = useMemo(() => {
    const params = new URLSearchParams();
    if (filters.get('period_id')) params.set('period_id', filters.get('period_id'));
    if (filters.get('wave_id')) params.set('wave_id', filters.get('wave_id'));
    if (filters.get('program_id')) params.set('program_id', filters.get('program_id'));
    const query = params.toString();
    return query ? `?${query}` : '';
  }, [filters]);

  if (query.isLoading) {
    return (
      <div className="space-y-4">
        <PageHeader title="Dashboard PPDB" description="Ringkasan operasional penerimaan peserta didik baru." />
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat ringkasan PPDB...
          </div>
        </Card>
      </div>
    );
  }

  if (query.isError || !data) {
    return (
      <div className="space-y-4">
        <PageHeader
          title="Dashboard PPDB"
          actions={
            <Button variant="outline" size="sm" onClick={() => query.refetch()}>
              <RefreshCw className="h-4 w-4" /> Coba lagi
            </Button>
          }
        />
        <Card>
          <EmptyState
            title="Dashboard gagal dimuat"
            description={query.error ? getErrorMessage(query.error) : 'Respons kosong dari server.'}
          />
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title="Dashboard PPDB"
        description={
          data.scope === 'period'
            ? 'Lingkup satu periode seleksi berjalan.'
            : 'Lingkup seluruh periode (belum ada periode terseleksi).'
        }
        badge={
          data.period ? (
            <Badge tone="brand">{data.period.name ?? 'Periode'}</Badge>
          ) : (
            <Badge tone="gray">Semua periode</Badge>
          )
        }
        actions={
          <>
            {data.period?.registration_start && (
              <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                <CalendarRange className="h-3.5 w-3.5" />
                {formatDate(data.period.registration_start)} – {formatDate(data.period.registration_end)}
              </span>
            )}
            <span className="text-xs text-slate-400">
              Diperbarui {data.updated_at ? formatDate(data.updated_at) : '—'}
            </span>
            <ReloadButton onClick={() => query.refetch()} busy={query.isFetching} />
          </>
        }
      />

      <PpdbScopeFilters filters={filters} showSearch={false} showDates />

      {!hasAnyData ? (
        <Card>
          <EmptyState
            title="Belum ada data PPDB"
            description="Buat dan publikasikan periode PPDB lebih dulu, lalu pendaftaran calon siswa akan muncul di sini."
            action={
              <Can perm="ppdb.period.manage">
                <Link
                  to="/ppdb/periods"
                  className="mt-2 inline-flex h-9 items-center gap-2 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white hover:bg-brand-700"
                >
                  <ExternalLink className="h-4 w-4" /> Kelola periode PPDB
                </Link>
              </Can>
            }
          />
        </Card>
      ) : (
        <>
          <SectionCard
            title="Status Pendaftaran"
            description="Tiga belas status alur. Klik kartu untuk membuka register pada status tersebut."
          >
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
              {REGISTRATION_STATUSES.map((status) => (
                <StatCard
                  key={status}
                  label={REGISTRATION_STATUS_LABELS[status]}
                  value={Number(byStatus[status] ?? 0)}
                  tone={registrationStatusTone(status)}
                  hint={REGISTRATION_STATUS_HINTS[status]}
                  to={`/ppdb/registrations${mergeQuery(scopeQuery, { status })}`}
                />
              ))}
            </div>
          </SectionCard>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <SectionCard
              title="Corong Seleksi"
              description="Persentase dihitung server terhadap pendaftar awal."
            >
              <BarChart
                items={(data.funnel?.steps ?? []).map((step) => ({
                  label: step.label,
                  value: step.count,
                  hint: `${step.percent_of_start}%`,
                  to: REGISTRATION_STATUSES.includes(step.key as PpdbRegistrationStatus)
                    ? `/ppdb/registrations${mergeQuery(scopeQuery, { status: step.key })}`
                    : undefined,
                }))}
                emptyLabel="Corong belum tersedia — biasanya terisi setelah ada berkas terkirim."
              />
            </SectionCard>

            <SectionCard title="Agenda Tertunda" description="Antrean yang menunggu tindakan petugas.">
              {data.pending_actions.length === 0 ? (
                <p className="text-sm text-slate-400">Tidak ada antrean tertunda.</p>
              ) : (
                <ul className="space-y-2">
                  {data.pending_actions.map((action) => (
                    <li key={action.key}>
                      <Link
                        to={mergeQuery(actionPath(action.route_key ?? action.key), {
                          period_id: filters.get('period_id'),
                        })}
                        className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2 hover:border-brand-300 hover:bg-brand-50/40"
                      >
                        <span className="min-w-0">
                          <span className="block truncate text-sm text-slate-700">{action.label}</span>
                          <span className="text-xs text-slate-400">
                            menuju {actionPath(action.route_key ?? action.key).split('?')[0]}
                          </span>
                        </span>
                        <span
                          className={cn(
                            'shrink-0 rounded-full px-2.5 py-0.5 text-sm font-semibold',
                            action.count > 0 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500',
                          )}
                        >
                          {action.count}
                        </span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </SectionCard>
          </div>

          <SectionCard
            title="Ringkasan Keuangan"
            description="Tagihan terdaftar vs pembayaran terverifikasi pada lingkup ini."
            action={
              <Link
                to={`/ppdb/payments${scopeQuery}`}
                className="text-xs font-medium text-brand-700 hover:underline"
              >
                Buka tagihan &amp; pembayaran
              </Link>
            }
          >
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
              <StatCard label="Total tertagih" value={money(data.financial.net_billed_amount)} tone="brand" />
              <StatCard label="Terkumpul" value={money(data.financial.collected_amount)} tone="green" />
              <StatCard
                label="Tunggakan"
                value={money(data.financial.outstanding_amount)}
                tone={data.financial.outstanding_amount > 0 ? 'red' : 'green'}
              />
              <StatCard label="Invoice lunas" value={data.financial.paid_invoice_count} tone="green" />
              <StatCard label="Invoice belum lunas" value={data.financial.open_invoice_count} tone="amber" />
              <StatCard
                label="Pendaftar"
                value={totalRegistrations}
                hint={`${totalApplicants} calon`}
              />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Status pembayaran tagihan
                </p>
                <BarChart
                  items={PAYMENT_STATUSES.filter((status) => Number(byPayment[status] ?? 0) > 0).map(
                    (status) => ({
                      label: PAYMENT_STATUS_LABELS[status],
                      value: Number(byPayment[status] ?? 0),
                      to: `/ppdb/registrations${mergeQuery(scopeQuery, { payment_status: status })}`,
                    }),
                  )}
                  barClass="bg-green-500"
                  emptyLabel="Belum ada tagihan pada lingkup ini."
                />
              </div>
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Terkumpul per metode
                </p>
                <BarChart
                  items={(data.financial.by_payment_method ?? []).map((method) => ({
                    label: method.label,
                    value: Number(method.amount ?? 0),
                    hint: `${method.payments} transaksi`,
                  }))}
                  formatValue={money}
                  barClass="bg-blue-500"
                  emptyLabel="Belum ada pembayaran terverifikasi."
                />
              </div>
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Akun orang tua
                </p>
                <BarChart
                  items={(Object.keys(ACCOUNT_STATUS_LABELS) as PpdbAccountStatus[]).map((status) => ({
                    label: ACCOUNT_STATUS_LABELS[status],
                    value: Number(byAccount[status] ?? 0),
                    to: `/ppdb/parent-accounts${mergeQuery(scopeQuery, { account_status: status })}`,
                  }))}
                  barClass="bg-amber-500"
                />
              </div>
            </div>
          </SectionCard>

          <DistributionPanels data={data} />

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <SectionCard
              title="Tren Pendaftaran"
              description={data.trend?.basis ?? 'Jumlah pendaftaran per periode.'}
              action={
                <Badge tone="gray">{data.trend?.granularity === 'weekly' ? 'Mingguan' : 'Bulanan'}</Badge>
              }
            >
              <BarChart
                items={(data.trend?.points ?? []).map((point) => ({
                  label: point.label,
                  value: point.registrations,
                  hint: `${point.submitted} terkirim · ${point.paid} lunas`,
                }))}
                emptyLabel="Belum ada pendaftaran pada rentang tren."
              />
            </SectionCard>

            <SectionCard
              title="Sorotan Lainnya"
              description="Dikirim server sebagai daftar label + jumlah (mis. sumber informasi teratas)."
            >
              <BarChart
                items={(data.top ?? []).map((item) => ({ label: item.label, value: item.total }))}
                barClass="bg-brand-500"
                emptyLabel="Tidak ada sorotan tambahan."
              />
            </SectionCard>
          </div>

          <SectionCard
            title="Sumbu Dokumen"
            description="Status kelengkapan dokumen dihitung terpisah dari status pendaftaran dan pembayaran."
          >
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {DOCUMENT_STATUSES.map((status) => (
                <StatCard
                  key={status}
                  label={DOCUMENT_STATUS_LABELS[status]}
                  value={Number(byDocument[status] ?? 0)}
                  tone="blue"
                  to={`/ppdb/registrations${mergeQuery(scopeQuery, { document_status: status })}`}
                />
              ))}
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-3 text-xs">
              <Link to="/ppdb/verification" className="font-medium text-brand-700 hover:underline">
                Buka antrean verifikasi
              </Link>
              <Link to="/ppdb/selection" className="font-medium text-brand-700 hover:underline">
                Kelola tahap seleksi
              </Link>
              <Link to="/ppdb/applicants" className="font-medium text-brand-700 hover:underline">
                Data calon siswa
              </Link>
              <Link to="/ppdb/reports" className="font-medium text-brand-700 hover:underline">
                Laporan &amp; ekspor
              </Link>
            </div>
          </SectionCard>
        </>
      )}
    </div>
  );
}

/** Tambah/ubah parameter query pada string path yang sudah mungkin berisi query. */
function mergeQuery(path: string, patch: Record<string, string | undefined>): string {
  const [base, existing] = path.split('?');
  const params = new URLSearchParams(existing ?? '');
  for (const [key, value] of Object.entries(patch)) {
    if (value) params.set(key, value);
  }
  const query = params.toString();
  return `${base}${query ? `?${query}` : ''}`;
}
