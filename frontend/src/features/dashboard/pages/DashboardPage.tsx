import { useQuery } from '@tanstack/react-query';
import {
  Activity,
  BookOpenCheck,
  CalendarDays,
  CheckCircle2,
  ClipboardCheck,
  Database,
  GraduationCap,
  Package,
  RefreshCw,
  ShieldCheck,
  Users,
  Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useAuthStore } from '@/stores/authStore';
import { usePermission } from '@/hooks/usePermission';
import { authApi } from '@/features/auth/api/authApi';
import { dashboardApi } from '../api/dashboardApi';
import {
  AttendanceCompositionChart,
  AttendanceTrendChart,
  FinanceTrendChart,
  formatMoney,
  StudentAdmissionsChart,
} from '../components/DashboardCharts';
import type { DashboardOverview } from '../types';

function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  tone = 'green',
}: {
  icon: typeof Database;
  label: string;
  value: string | number;
  hint: string;
  tone?: 'green' | 'amber' | 'blue' | 'violet';
}) {
  return (
    <article className={`dashboard-stat-card dashboard-stat-${tone}`}>
      <span className="dashboard-stat-icon" aria-hidden="true"><Icon /></span>
      <div className="min-w-0">
        <p className="dashboard-stat-value">{value}</p>
        <p className="dashboard-stat-label">
          <span className="dashboard-stat-main-label">{label}</span>
          <span className="dashboard-stat-hint"> · {hint}</span>
        </p>
      </div>
    </article>
  );
}

const quickLinks = [
  { label: 'Data siswa', detail: 'Biodata & wali murid', to: '/students', permission: 'student.view', icon: Users },
  { label: 'Kelas & rombel', detail: 'Struktur belajar', to: '/academics/classes', permission: 'academic.view', icon: GraduationCap },
  { label: 'Presensi siswa', detail: 'Kehadiran harian', to: '/academics/attendance/students', permission: 'academic.view', icon: ClipboardCheck },
  { label: 'Tagihan & invoice', detail: 'Administrasi keuangan', to: '/finance/invoices', permission: 'finance.invoice.view', icon: Wallet },
  { label: 'Penilaian', detail: 'Evaluasi pembelajaran', to: '/assessments', permission: 'assessment.view', icon: BookOpenCheck },
  { label: 'Inventaris', detail: 'Aset & stok sekolah', to: '/inventory', permission: 'inventory.view', icon: Package },
  { label: 'Perpustakaan', detail: 'Koleksi & sirkulasi', to: '/library/libraries', permission: 'library.view', icon: BookOpenCheck },
  { label: 'Data induk', detail: 'Referensi sistem', to: '/master/schools', permission: 'master-data.view', icon: Database },
] as const;

function todayLabel(): string {
  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date());
}

function numberLabel(value: number | undefined): string {
  return value === undefined ? '—' : new Intl.NumberFormat('id-ID').format(value);
}

function LoadingChart({ className = '' }: { className?: string }) {
  return (
    <div className={`dashboard-chart-skeleton ${className}`} aria-label="Memuat grafik" role="status">
      <span className="dashboard-skeleton-line w-1/3" />
      <span className="dashboard-skeleton-line w-1/2" />
      <span className="dashboard-skeleton-plot" />
    </div>
  );
}

function AnalyticsError({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="dashboard-analytics-error" role="alert">
      <span className="dashboard-error-icon"><Activity aria-hidden="true" /></span>
      <div>
        <strong>Grafik belum dapat dimuat</strong>
        <p>Ringkasan sekolah tidak berhasil diambil. Data akun dan akses cepat tetap tersedia.</p>
      </div>
      <button type="button" onClick={onRetry}>
        <RefreshCw aria-hidden="true" /> Coba lagi
      </button>
    </div>
  );
}

function MetricCards({ data }: { data: DashboardOverview | undefined }) {
  const metrics: Array<{
    icon: typeof Database;
    label: string;
    value: string;
    hint: string;
    tone: 'green' | 'amber' | 'blue' | 'violet';
  }> = [];

  if (data?.students) {
    metrics.push({
      icon: Users,
      label: 'Siswa aktif',
      value: numberLabel(data.students.active_total),
      hint: `+${numberLabel(data.students.admitted_this_month)} siswa masuk bulan ini`,
      tone: 'green',
    });
  }
  if (data?.academic) {
    metrics.push({
      icon: GraduationCap,
      label: 'Rombel aktif',
      value: numberLabel(data.academic.active_classes),
      hint: `${numberLabel(data.academic.enrolled_students)} siswa terdaftar${data.academic.academic_year ? ` · ${data.academic.academic_year}` : ''}`,
      tone: 'blue',
    });
  }
  if (data?.attendance) {
    metrics.push({
      icon: CheckCircle2,
      label: 'Kehadiran hari ini',
      value: data.attendance.today.rate === null ? '—' : `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(data.attendance.today.rate)}%`,
      hint: data.attendance.today.total > 0
        ? `${numberLabel(data.attendance.today.total)} catatan presensi`
        : 'Belum ada presensi tercatat',
      tone: 'amber',
    });
  }
  if (data?.finance) {
    const hasReceived = data.finance.received_this_month !== null;
    const currentAmount = hasReceived ? data.finance.received_this_month : data.finance.billed_this_month;
    metrics.push({
      icon: Wallet,
      label: hasReceived ? 'Penerimaan bulan ini' : 'Tagihan bulan ini',
      value: formatMoney(currentAmount),
      hint: data.finance.outstanding_total !== undefined
        ? `${formatMoney(data.finance.outstanding_total)} piutang berjalan`
        : hasReceived ? 'Pembayaran terkonfirmasi' : 'Invoice diterbitkan',
      tone: 'violet',
    });
  }

  if (metrics.length === 0) return null;

  return (
    <section className="dashboard-metric-grid" aria-label="Ringkasan sekolah">
      {metrics.map((metric) => (
        <StatCard key={metric.label} {...metric} />
      ))}
    </section>
  );
}

function DashboardCharts({
  data,
  days,
  onDaysChange,
}: {
  data: DashboardOverview;
  days: number;
  onDaysChange: (days: number) => void;
}) {
  const hasCharts = Boolean(data.attendance || data.finance || data.students);

  if (!hasCharts) {
    return (
      <section className="dashboard-chart-access-note" aria-label="Grafik dashboard">
        <div className="dashboard-chart-access-icon"><ShieldCheck aria-hidden="true" /></div>
        <div>
          <h2>Ringkasan siap digunakan</h2>
          <p>Grafik akademik, kesiswaan, dan keuangan akan tampil sesuai hak akses akun Anda.</p>
        </div>
      </section>
    );
  }

  return (
    <section className="dashboard-visual-grid" aria-label="Grafik kinerja sekolah">
      {data.attendance && (
        <AttendanceTrendChart data={data.attendance} days={days} onDaysChange={onDaysChange} />
      )}
      {data.attendance && <AttendanceCompositionChart data={data.attendance} />}
      {data.finance && <FinanceTrendChart data={data.finance} />}
      {data.students && <StudentAdmissionsChart data={data.students} />}
    </section>
  );
}

export default function DashboardPage() {
  const user = useAuthStore((state) => state.user);
  const { can } = usePermission();
  const [attendanceDays, setAttendanceDays] = useState(14);

  const { data: profile } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: authApi.me,
    staleTime: 1000 * 60 * 5,
  });

  const analyticsQuery = useQuery({
    queryKey: ['dashboard', 'overview', attendanceDays],
    queryFn: () => dashboardApi.overview(attendanceDays),
    staleTime: 1000 * 60,
    refetchOnWindowFocus: false,
  });

  const displayUser = profile ?? user;
  const roles = displayUser?.roles ?? [];
  const quickAccess = quickLinks.filter((link) => can(link.permission));
  const isActive = displayUser?.is_active ?? false;
  const activeYear = analyticsQuery.data?.academic?.academic_year;
  const heroTrend = analyticsQuery.data?.attendance?.daily
    .filter((point) => point.rate !== null)
    .slice(-8) ?? [];

  return (
    <div className="dashboard-page">
      <section className="dashboard-hero" aria-labelledby="dashboard-title">
        <div className="dashboard-hero-content">
          <p className="dashboard-eyebrow"><span className="dashboard-eyebrow-dot" /> PUSAT KENDALI SEKOLAH</p>
          <h1 id="dashboard-title">Selamat datang, {displayUser?.name?.split(' ')[0] ?? 'kembali'}</h1>
          <p>Pantau aktivitas akademik, kehadiran, dan operasional sekolah dalam satu tampilan.</p>
          <span className="dashboard-hero-date"><CalendarDays aria-hidden="true" />{todayLabel()}</span>
        </div>
        <div className="dashboard-hero-aside" aria-label={activeYear ? `Tahun ajaran ${activeYear}` : 'Ringkasan sekolah'}>
          <span className="dashboard-hero-orbit dashboard-hero-orbit-one" />
          <span className="dashboard-hero-orbit dashboard-hero-orbit-two" />
          <div className="dashboard-hero-period-card">
            <span><span className="dashboard-hero-live-dot" /> PERIODE AKTIF</span>
            <strong>{activeYear ?? 'Tahun ajaran berjalan'}</strong>
            <small>Data diperbarui {analyticsQuery.data?.as_of ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${analyticsQuery.data.as_of}T12:00:00`)) : 'hari ini'}</small>
          </div>
          {heroTrend.length > 0 && (
            <div className="dashboard-hero-sparkline" aria-hidden="true">
              {heroTrend.map((point) => (
                <span key={point.date} style={{ height: `${Math.max(9, point.rate ?? 0)}%` }} />
              ))}
            </div>
          )}
        </div>
      </section>

      {analyticsQuery.isLoading ? (
        <section className="dashboard-metric-grid" aria-label="Memuat ringkasan sekolah" aria-busy="true">
          {[0, 1, 2, 3].map((item) => <div key={item} className="dashboard-stat-skeleton" />)}
        </section>
      ) : analyticsQuery.isError ? (
        <AnalyticsError onRetry={() => void analyticsQuery.refetch()} />
      ) : (
        <MetricCards data={analyticsQuery.data} />
      )}

      {analyticsQuery.isLoading ? (
        <section className="dashboard-visual-grid" aria-label="Memuat grafik sekolah" aria-busy="true">
          <LoadingChart className="dashboard-skeleton-wide" />
          <LoadingChart />
          <LoadingChart />
          <LoadingChart />
        </section>
      ) : analyticsQuery.data ? (
        <DashboardCharts
          data={analyticsQuery.data}
          days={attendanceDays}
          onDaysChange={setAttendanceDays}
        />
      ) : null}

      <div className="dashboard-bottom-grid">
        <section aria-labelledby="quick-access-title">
          <div className="dashboard-section-heading">
            <div>
              <h2 id="quick-access-title">Akses cepat</h2>
              <p>Modul yang tersedia untuk akun Anda.</p>
            </div>
          </div>
          <div className="dashboard-quick-grid">
            {quickAccess.map((item) => (
              <Link key={item.to} className="dashboard-quick-link" to={item.to}>
                <span className="dashboard-quick-icon" aria-hidden="true"><item.icon /></span>
                <span className="min-w-0">
                  <span className="dashboard-quick-title block">{item.label}</span>
                  <span className="dashboard-quick-meta block">{item.detail}</span>
                </span>
              </Link>
            ))}
            {quickAccess.length === 0 && (
              <Card className="col-span-full">
                <CardBody>
                  <p className="text-sm font-medium text-slate-700">Belum ada modul yang dapat ditampilkan.</p>
                  <p className="mt-1 text-xs text-slate-500">Hubungi administrator untuk meninjau hak akses akun Anda.</p>
                </CardBody>
              </Card>
            )}
          </div>
        </section>

        <Card>
          <CardHeader>
            <CardTitle>Profil akun</CardTitle>
          </CardHeader>
          <CardBody>
            <dl className="dashboard-profile-list">
              <div className="dashboard-profile-row">
                <dt>Nama</dt>
                <dd>{displayUser?.name ?? '—'}</dd>
              </div>
              <div className="dashboard-profile-row">
                <dt>Email</dt>
                <dd title={displayUser?.email ?? ''}>{displayUser?.email ?? '—'}</dd>
              </div>
              <div className="dashboard-profile-row">
                <dt>Status</dt>
                <dd><Badge tone={displayUser ? (isActive ? 'green' : 'red') : 'gray'}>{displayUser ? (isActive ? 'Aktif' : 'Nonaktif') : 'Memuat'}</Badge></dd>
              </div>
            </dl>
            <div className="mt-4 border-t border-slate-100 pt-3">
              <p className="dashboard-profile-roles-label mb-2 text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Role / hak akses</p>
              <div className="flex flex-wrap gap-1.5">
                {roles.map((role) => <Badge key={role} tone="brand">{role}</Badge>)}
                {roles.length === 0 && <span className="text-xs text-slate-400">Belum ada role.</span>}
              </div>
            </div>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
