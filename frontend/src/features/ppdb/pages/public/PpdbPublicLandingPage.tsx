import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  ArrowRight,
  CalendarDays,
  CheckCircle2,
  GraduationCap,
  Layers,
  LogIn,
  School,
  UserPlus,
} from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { DataState } from '../../components/portal/DataState';
import { PublicShell } from '../../components/portal/PublicShell';
import { ppdbPublicApi } from '../../api/ppdbApi';
import { formatDate, remainingLabel } from '../../components/portal/format';
import { PERIOD_STATUS_LABELS, PERIOD_STATUS_TONES } from '../../components/portal/labels';
import type { PpdbPublicPeriodSummary } from '@/types/ppdbPortal';

function PeriodCard({ period }: { period: PpdbPublicPeriodSummary }) {
  const detailPath = `/ppdb/${period.slug}`;
  const title = period.public_title || period.name;

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 className="truncate text-base font-semibold text-slate-900">{title}</h2>
          <p className="mt-0.5 truncate text-xs text-slate-500">
            {period.academic_year?.name ? `${period.academic_year.name} · ` : ''}
            Kode {period.code}
          </p>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <Badge tone={PERIOD_STATUS_TONES[period.status]}>
            {PERIOD_STATUS_LABELS[period.status] ?? period.status}
          </Badge>
          {period.registration_open && <Badge tone="green">pendaftaran dibuka</Badge>}
        </div>
      </div>

      {period.public_description && (
        <p className="mt-2 line-clamp-3 text-sm text-slate-600">{period.public_description}</p>
      )}

      <dl className="mt-3 grid grid-cols-1 gap-2 text-xs text-slate-600 sm:grid-cols-2">
        <div className="flex items-start gap-1.5">
          <CalendarDays className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span>
            <span className="text-slate-400">Pendaftaran:</span> {formatDate(period.registration_start)}
            {' - '}
            {formatDate(period.registration_end)}
            {period.registration_open && (
              <span className="ml-1 font-medium text-brand-600">
                ({remainingLabel(period.registration_end)})
              </span>
            )}
          </span>
        </div>
        <div className="flex items-start gap-1.5">
          <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span>
            <span className="text-slate-400">Pengumuman:</span> {formatDate(period.selection_announcement_date)}
          </span>
        </div>
        <div className="flex items-start gap-1.5">
          <Layers className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span>
            {period.waves.length} gelombang · {period.programs.length} program
            {typeof period.quota.remaining === 'number' && (
              <span className="text-slate-400"> · sisa kuota {period.quota.remaining}</span>
            )}
            {period.quota.registered > 0 && (
              <span className="text-slate-400"> · {period.quota.registered} pendaftar</span>
            )}
          </span>
        </div>
        {(period.re_registration_start || period.re_registration_end) && (
          <div className="flex items-start gap-1.5">
            <School className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
            <span>
              <span className="text-slate-400">Daftar ulang:</span> {formatDate(period.re_registration_start)} -{' '}
              {formatDate(period.re_registration_end)}
            </span>
          </div>
        )}
      </dl>

      <div className="mt-4 flex flex-col gap-2 sm:flex-row">
        <Link to={detailPath} className="flex-1">
          <Button variant={period.registration_open ? 'primary' : 'outline'} size="lg" className="w-full">
            Lihat informasi lengkap
            <ArrowRight className="h-4 w-4" />
          </Button>
        </Link>
        {period.registration_open && (
          <Link to="/ppdb/register" className="flex-1">
            <Button variant="outline" size="lg" className="w-full">
              Daftar sekarang
            </Button>
          </Link>
        )}
      </div>
    </Card>
  );
}

/** Halaman utama publik: daftar periode PPDB yang dapat dilihat umum. */
export function PpdbPublicLandingPage() {
  const periodsQuery = useQuery({
    queryKey: ['ppdb', 'public', 'periods'],
    queryFn: () => ppdbPublicApi.periods(),
    staleTime: 60_000,
  });

  const periods = periodsQuery.data ?? [];
  const openPeriod = periods.find((period) => period.registration_open) ?? null;

  return (
    <PublicShell wide>
      <section className="overflow-hidden rounded-2xl bg-brand-600 px-5 py-7 text-white shadow-sm sm:px-7 sm:py-9">
        <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-brand-100">
          <GraduationCap className="h-4 w-4" />
          Penerimaan Peserta Didik Baru
        </div>
        <h1 className="mt-2 text-2xl font-bold leading-snug sm:text-3xl">
          {openPeriod
            ? openPeriod.public_title || openPeriod.name
            : 'Selamat datang di halaman PPDB sekolah kami'}
        </h1>
        <p className="mt-2 max-w-xl text-sm text-brand-100">
          {openPeriod
            ? openPeriod.public_description ||
              'Pendaftaran sedang dibuka. Buat akun orang tua, lalu lengkapi data anak Anda langkah demi langkah.'
            : 'Belum ada periode pendaftaran yang dibuka saat ini. Anda tetap dapat membuat akun orang tua agar lebih siap ketika pendaftaran dibuka.'}
        </p>
        <div className="mt-5 flex flex-col gap-2 sm:flex-row">
          {openPeriod ? (
            <Link to={`/ppdb/${openPeriod.slug}`} className="flex-1 sm:flex-none">
              <Button size="lg" variant="secondary" className="w-full text-brand-700 sm:w-auto">
                Lihat detail pendaftaran
                <ArrowRight className="h-4 w-4" />
              </Button>
            </Link>
          ) : null}
          <Link to="/ppdb/register" className="flex-1 sm:flex-none">
            <Button size="lg" variant="outline" className="w-full border-white/40 bg-white/10 text-white hover:bg-white/20 sm:w-auto">
              <UserPlus className="h-4 w-4" />
              Buat akun orang tua
            </Button>
          </Link>
          <Link to="/ppdb/login" className="flex-1 sm:flex-none">
            <Button size="lg" variant="ghost" className="w-full text-white hover:bg-white/10 sm:w-auto">
              <LogIn className="h-4 w-4" />
              Masuk portal
            </Button>
          </Link>
        </div>
      </section>

      <section className="mt-6">
        <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
          Periode pendaftaran
        </h2>

        <DataState
          isLoading={periodsQuery.isLoading}
          isError={periodsQuery.isError}
          error={periodsQuery.error}
          onRetry={() => periodsQuery.refetch()}
          isEmpty={periods.length === 0}
          loadingLabel="Memuat daftar periode..."
          errorTitle="Daftar periode belum dapat dimuat"
          emptyTitle="Pendaftaran belum dibuka"
          emptyDescription="Belum ada periode PPDB yang dipublikasikan. Silakan pantau halaman ini atau buat akun orang tua terlebih dahulu."
          emptyAction={
            <Link to="/ppdb/register" className="mt-2">
              <Button size="sm">Buat akun orang tua</Button>
            </Link>
          }
        >
          <div className="space-y-3">
            {periods.map((period) => (
              <PeriodCard key={period.id} period={period} />
            ))}
          </div>
        </DataState>
      </section>

      <section className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        {[
          {
            title: 'Satu akun, banyak anak',
            body: 'Cukup satu akun orang tua untuk mendaftarkan seluruh anak Anda pada periode yang sama.',
          },
          {
            title: 'Draf tersimpan otomatis',
            body: 'Isian Anda disimpan otomatis sebagai draf, jadi bisa dilanjutkan kapan saja.',
          },
          {
            title: 'Pantau status',
            body: 'Status pendaftaran, pembayaran, dan dokumen dapat dipantau terpisah dari portal.',
          },
        ].map((item) => (
          <Card key={item.title} className="p-4">
            <p className="text-sm font-semibold text-slate-800">{item.title}</p>
            <p className="mt-1 text-xs text-slate-500">{item.body}</p>
          </Card>
        ))}
      </section>
    </PublicShell>
  );
}

export default PpdbPublicLandingPage;
