import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  ArrowRight,
  Bell,
  CalendarClock,
  CheckCircle2,
  FileWarning,
  Megaphone,
  PlusCircle,
  Users,
} from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { ApplicationCard } from '../../components/portal/ApplicationCard';
import { DataState } from '../../components/portal/DataState';
import { useAuthStore } from '@/stores/authStore';
import { ppdbPortalApi, ppdbPortalNotificationsApi } from '../../api/ppdbApi';
import { countdownLabel, formatDate, formatDateTime } from '../../components/portal/format';
import {
  ACCOUNT_STATUS_HINTS,
  ACCOUNT_STATUS_LABELS,
  ACCOUNT_STATUS_TONES,
  NOTIFICATION_TYPE_LABELS,
  NOTIFICATION_TYPE_TONES,
} from '../../components/portal/labels';
import { cn } from '@/lib/utils';
import type { PpdbDeadline, PpdbPortalParentRow } from '@/types/ppdbPortal';

function Counter({
  label,
  value,
  tone,
  to,
}: {
  label: string;
  value: number;
  tone?: 'red' | 'amber' | 'brand';
  to?: string;
}) {
  const body = (
    <div
      className={cn(
        'rounded-lg border px-3 py-2.5',
        tone === 'red'
          ? 'border-red-200 bg-red-50'
          : tone === 'amber'
            ? 'border-amber-200 bg-amber-50'
            : tone === 'brand'
              ? 'border-brand-500 bg-brand-50'
              : 'border-slate-200 bg-white',
      )}
    >
      <p className="text-xl font-semibold text-slate-900">{value}</p>
      <p className="text-[11px] leading-tight text-slate-500">{label}</p>
    </div>
  );
  return to ? <Link to={to}>{body}</Link> : body;
}

/** Ringkasan portal orang tua: status akun, anak, tenggat, pengumuman, notifikasi. */
export function PpdbPortalDashboardPage() {
  const user = useAuthStore((state) => state.user);
  const dashboardQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'dashboard'],
    queryFn: () => ppdbPortalApi.dashboard(),
  });

  const notificationsQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'notifications', 'preview'],
    queryFn: () => ppdbPortalNotificationsApi.list({ per_page: 20 }),
  });

  const data = dashboardQuery.data;
  const applications = data?.applications ?? [];
  /** Akun orang tua pada dashboard ada di kunci `parent` (`PpdbParentAccountResource::row()`). */
  const parent = data?.parent ?? null;
  const isActive = Boolean(parent?.can_transact) && parent?.account_status === 'active';
  /** Penghitung datang dari `applicant_count`/`registration_count` + `counts`. */
  const applicantCount = data?.applicant_count ?? 0;
  const registrationCount = data?.registration_count ?? 0;
  const unpaidCount = data?.counts.by_payment_status.unpaid ?? 0;
  const needRevisionCount = data?.counts.by_status.need_revision ?? 0;
  const rejectedDocumentCount = data?.counts.by_document_status.rejected ?? 0;
  /** Periode acuan dashboard memakai kunci `period`, dan bisa null. */
  const period = data?.period ?? null;
  const deadlines = data?.deadlines ?? [];
  const notifications = notificationsQuery.data?.data ?? [];
  /**
   * Dashboard tidak mengirim daftar pengumuman; kanal portal satu-satunya untuk
   * pengumuman panitia adalah kotak masuk notifikasi (`type: announcement`).
   */
  const announcements = notifications.filter((row) => row.type === 'announcement').slice(0, 4);
  const latestNotifications = notifications.slice(0, 4);

  if (!data) {
    return (
      <DataState
        isLoading={dashboardQuery.isLoading}
        isError={dashboardQuery.isError}
        error={dashboardQuery.error}
        onRetry={() => dashboardQuery.refetch()}
        isEmpty
        loadingLabel="Memuat ringkasan akun..."
        errorTitle="Ringkasan tidak dapat dimuat"
        emptyTitle="Data portal belum tersedia"
        emptyDescription="Muat ulang halaman ini. Bila terus terjadi, hubungi panitia PPDB melalui kanal kontak periode."
      >
        <span />
      </DataState>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">
          Halo, {data?.parent?.name || user?.name || 'Bapak/Ibu'}
        </h1>
        <p className="text-sm text-slate-500">
          {isActive
            ? 'Akun Anda aktif. Lanutkan pendaftaran anak Anda atau daftarkan anak lain.'
            : 'Perhatikan status akun Anda di bawah ini sebelum melanjutkan.'}
        </p>
      </div>

      {parent && <AccountStatusCard parent={parent} isActive={isActive} applicantCount={applicantCount} />}

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Counter label="Anak terdaftar" value={applicantCount} to="/ppdb/portal/applicants" />
        <Counter label="Total pendaftaran" value={registrationCount} to="/ppdb/portal/pendaftaran" />
        <Counter
          label="Menunggu pembayaran"
          value={unpaidCount}
          tone={unpaidCount > 0 ? 'red' : undefined}
          to="/ppdb/portal/pendaftaran"
        />
        <Counter
          label="Perlu revisi"
          value={needRevisionCount}
          tone={needRevisionCount > 0 ? 'amber' : undefined}
          to="/ppdb/portal/pendaftaran?status=need_revision"
        />
      </div>

      {(period || deadlines.length > 0) && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-1.5">
              <CalendarClock className="h-4 w-4 text-brand-600" />
              Periode & tenggat
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            {period ? (
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-slate-800">{period.public_title || period.name}</p>
                  <p className="text-xs text-slate-500">
                    {formatDate(period.registration_start)} - {formatDate(period.registration_end)}
                    {period.registration_open ? ' · pendaftaran dibuka' : ' · pendaftaran ditutup'}
                  </p>
                  <p className="text-xs text-slate-500">
                    {period.quota.total_quota === null
                      ? `${period.quota.registered} pendaftar · kuota belum ditetapkan`
                      : `${period.quota.registered}/${period.quota.total_quota} kuota terisi`}
                  </p>
                </div>
                <Link to={`/ppdb/${period.slug}`}>
                  <Button size="sm" variant="outline">
                    Lihat informasi
                  </Button>
                </Link>
              </div>
            ) : (
              <p className="text-sm text-slate-500">
                Belum ada periode yang dibuka. Anda akan menerima notifikasi saat pendaftaran dimulai.
              </p>
            )}

            {deadlines.length > 0 && (
              <ul className="divide-y divide-slate-100 border-t border-slate-100 pt-1">
                {deadlines.map((deadline) => (
                  <li key={deadline.key} className="flex items-center justify-between gap-2 py-2 text-sm">
                    <span className="min-w-0 truncate text-slate-600">{deadline.label}</span>
                    <span className="shrink-0 text-right">
                      <span className="block text-xs text-slate-500">{formatDate(deadline.date)}</span>
                      <span
                        className={cn(
                          'text-[11px] font-medium',
                          deadline.state === 'passed'
                            ? 'text-red-600'
                            : deadline.state === 'soon'
                              ? 'text-amber-600'
                              : 'text-brand-600',
                        )}
                      >
                        {/* `state` + `days_left` adalah penanda tenggat dari backend. */}
                        {deadlineCountdown(deadline)}
                      </span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      )}

      <section>
        <div className="mb-2 flex items-center justify-between gap-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Pendaftaran anak</h2>
          {applications.length > 0 && (
            <Link to="/ppdb/portal/pendaftaran" className="text-xs font-medium text-brand-600 hover:underline">
              Lihat semua
            </Link>
          )}
        </div>
        {applications.length === 0 ? (
          <Card className="p-4">
            {/* `empty_state` disusun backend saat akun belum punya berkas pendaftaran. */}
            <p className="text-sm text-slate-600">{data.empty_state?.title ?? 'Belum ada pendaftaran pada akun ini.'}</p>
            <p className="mt-1 text-xs text-slate-500">
              {data.empty_state?.description ??
                'Tambahkan data anak terlebih dahulu, lalu buat pendaftaran pada periode yang dibuka.'}
            </p>
            <div className="mt-3 flex flex-col gap-2 sm:flex-row">
              <Link to="/ppdb/portal/applicants">
                <Button size="sm" className="w-full sm:w-auto">
                  Tambah data anak
                </Button>
              </Link>
              <Link to={period ? `/ppdb/${period.slug}` : '/ppdb'}>
                <Button size="sm" variant="outline" className="w-full sm:w-auto">
                  Cek syarat & jadwal
                  <ArrowRight className="h-4 w-4" />
                </Button>
              </Link>
            </div>
          </Card>
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {applications.map((card) => (
              <ApplicationCard key={card.registration_id} card={card} showPeriod />
            ))}
          </div>
        )}
      </section>

      <section className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-1.5">
              <Megaphone className="h-4 w-4 text-brand-600" />
              Pengumuman panitia
            </CardTitle>
          </CardHeader>
          <CardBody>
            {announcements.length === 0 ? (
              <p className="text-sm text-slate-500">
                Belum ada pengumuman pada kotak masuk Anda. Pengumuman panitia dikirim sebagai notifikasi bertanda
                &quot;Pengumuman&quot;.
              </p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {announcements.map((item) => (
                  <li key={item.id} className="py-2 first:pt-0 last:pb-0">
                    <p className="text-sm font-medium text-slate-800">{item.title ?? 'Pengumuman periode'}</p>
                    <p className="mt-0.5 line-clamp-3 text-xs text-slate-500">{item.body}</p>
                    <p className="mt-1 text-[11px] text-slate-400">{formatDateTime(item.created_at)}</p>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center justify-between gap-2">
              <span className="flex items-center gap-1.5">
                <Bell className="h-4 w-4 text-brand-600" />
                Notifikasi terbaru
              </span>
              {data.unread_notifications > 0 && (
                <Badge tone="red">{data.unread_notifications} belum dibaca</Badge>
              )}
            </CardTitle>
          </CardHeader>
          <CardBody>
            {latestNotifications.length === 0 ? (
              <p className="text-sm text-slate-500">
                {notificationsQuery.isLoading ? 'Memuat notifikasi...' : 'Belum ada notifikasi.'}
              </p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {latestNotifications.map((item) => {
                  /* Tautan detail disusun dari `context.registration_id`; `links.detail`
                     backend menunjuk path lama yang tidak dipakai router SPA. */
                  const to = item.context.registration_id
                    ? `/ppdb/portal/registrations/${item.context.registration_id}`
                    : '/ppdb/portal/notifikasi';
                  return (
                    <li key={item.id} className="py-2 first:pt-0 last:pb-0">
                      <Link to={to} className="block">
                        <p className="flex items-center gap-1.5 text-sm font-medium text-slate-800">
                          <Badge tone={NOTIFICATION_TYPE_TONES[item.type]}>
                            {NOTIFICATION_TYPE_LABELS[item.type]}
                          </Badge>
                          {!item.is_read && <span className="h-1.5 w-1.5 rounded-full bg-brand-600" />}
                        </p>
                        <p className="mt-0.5 truncate text-xs text-slate-600">{item.title ?? item.body}</p>
                        <p className="text-[11px] text-slate-400">{formatDateTime(item.created_at)}</p>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            )}
            <Link to="/ppdb/portal/notifikasi" className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">
              Buka semua notifikasi
            </Link>
          </CardBody>
        </Card>
      </section>

      {rejectedDocumentCount > 0 && (
        <Card className="border-red-200 bg-red-50/60 p-4">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-red-800">
            <FileWarning className="h-4 w-4" />
            {rejectedDocumentCount} pendaftaran memiliki dokumen yang perlu diperbaiki
          </p>
          <p className="mt-1 text-xs text-red-700">
            Buka pendaftaran anak yang bersangkutan, unggah berkas pengganti pada langkah Dokumen, lalu
            kirim ulang.
          </p>
        </Card>
      )}
    </div>
  );
}

/** Kartu status akun — seluruh isinya dibaca dari baris `parent` (`PpdbParentAccountResource::row()`). */
function AccountStatusCard({
  parent,
  isActive,
  applicantCount,
}: {
  parent: PpdbPortalParentRow;
  isActive: boolean;
  applicantCount: number;
}) {
  const status = parent.account_status;

  return (
    <Card
      className={cn(
        'p-4',
        status === 'active'
          ? 'border-green-200 bg-green-50/60'
          : status === 'blocked'
            ? 'border-red-200 bg-red-50/60'
            : 'border-amber-200 bg-amber-50/60',
      )}
    >
      <div className="flex items-start gap-3">
        <div
          className={cn(
            'rounded-full p-2',
            status === 'active'
              ? 'bg-green-100 text-green-600'
              : status === 'blocked'
                ? 'bg-red-100 text-red-600'
                : 'bg-amber-100 text-amber-600',
          )}
        >
          {status === 'active' ? <CheckCircle2 className="h-5 w-5" /> : status === 'blocked' ? <AlertTriangle className="h-5 w-5" /> : <ClockIcon />}
        </div>
        <div className="min-w-0 flex-1">
          <p className="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-slate-900">
            Status akun: {ACCOUNT_STATUS_LABELS[status]}
            <Badge tone={ACCOUNT_STATUS_TONES[status]}>
              {applicantCount > 0 ? 'anak sudah ditambahkan' : 'belum ada anak'}
            </Badge>
          </p>
          <p className="mt-1 text-xs leading-relaxed text-slate-700">{ACCOUNT_STATUS_HINTS[status]}</p>
          <p className="mt-1 text-[11px] text-slate-500">
            {parent.applicant_count} anak · {parent.registration_count} berkas pendaftaran pada akun ini
            {parent.last_activity_at ? ` · aktivitas terakhir ${formatDateTime(parent.last_activity_at)}` : ''}
          </p>
          <div className="mt-3 flex flex-col gap-2 sm:flex-row">
            {!isActive && (
              <Link to="/ppdb/portal/profil">
                <Button size="sm" variant="outline" className="w-full sm:w-auto">
                  Lengkapi profil saya
                </Button>
              </Link>
            )}
            <Link to="/ppdb/portal/applicants">
              <Button size="sm" variant="outline" className="w-full sm:w-auto">
                <Users className="h-4 w-4" />
                Kelola data anak
              </Button>
            </Link>
            {isActive && (
              <Link to="/ppdb/portal/pendaftaran?baru=1">
                <Button size="sm" className="w-full sm:w-auto">
                  <PlusCircle className="h-4 w-4" />
                  Daftar anak lain
                </Button>
              </Link>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}

/** Kalimat tenggat: backend mengirim `state` + `days_left`, bukan penanda selesai. */
function deadlineCountdown(deadline: PpdbDeadline): string {
  if (deadline.state === 'passed') return 'sudah dilewati';
  if (deadline.days_left === 0) return 'berakhir hari ini';
  return `sisa ${countdownLabel(deadline.date)}`;
}

function ClockIcon() {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      className="h-5 w-5"
      aria-hidden
    >
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </svg>
  );
}

export default PpdbPortalDashboardPage;
