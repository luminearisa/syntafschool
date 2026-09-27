import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Bell, MoonStar } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { DetailRow, FormField, StatCard, StudentStatusBadge, Tabs, fmtDate, fmtDateTime } from '../components/BoardingUi';
import { portalApi } from '../api/boardingApi';
import {
  HEALTH_STATUS_LABELS,
  HOMECOMING_STATUS_LABELS,
  PERMISSION_STATUS_LABELS,
  VISITOR_STATUS_LABELS,
  labelOf,
} from '../lib/labels';
import type {
  BoardingAttendance,
  BoardingHealthRecord,
  BoardingHomecoming,
  BoardingPermission,
  BoardingPortalChild,
  BoardingViolation,
  BoardingVisitor,
} from '@/types/boarding';

const TABS = [
  { key: 'overview', label: 'Ringkasan' },
  { key: 'attendances', label: 'Absensi' },
  { key: 'violations', label: 'Pelanggaran' },
  { key: 'achievements', label: 'Prestasi' },
  { key: 'health', label: 'Kesehatan' },
  { key: 'permissions', label: 'Izin Keluar' },
  { key: 'homecomings', label: 'Kepulangan' },
  { key: 'visitors', label: 'Kunjungan' },
] as const;

function Overview({ child }: { child: BoardingPortalChild }) {
  const summaryQuery = useQuery({
    queryKey: ['boarding', 'portal', child.student_id, 'summary'],
    queryFn: () => portalApi.summary(child.student_id),
  });

  const pointsQuery = useQuery({
    queryKey: ['boarding', 'portal', child.student_id, 'points'],
    queryFn: () => portalApi.points(child.student_id),
  });

  const s = summaryQuery.data;
  const p = pointsQuery.data;

  if (summaryQuery.isLoading || !s) {
    return <Card><div className="flex justify-center py-14"><Spinner /></div></Card>;
  }

  const placement = s.placement;

  return (
    <div className="space-y-4">
      {s.unread_notifications > 0 && (
        <Card className="flex items-center gap-2 border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          <Bell className="h-4 w-4" />
          Ada {s.unread_notifications} notifikasi baru di menu Notifikasi aplikasi.
        </Card>
      )}

      <Card className="p-4">
        <p className="mb-2 text-sm font-semibold text-slate-700">Keberadaan saat ini</p>
        <div className="space-y-1.5 text-sm">
          <DetailRow label="Status" value={<StudentStatusBadge status={placement.status} />} />
          {placement.in_boarding ? (
            <>
              <DetailRow label="Asrama" value={placement.dormitory_name ?? '-'} />
              <DetailRow label="Kamar" value={`${placement.room_code ?? ''}${placement.room_name ? ` — ${placement.room_name}` : ''}`} />
              <DetailRow label="Bed" value={placement.bed_number ?? '-'} />
            </>
          ) : (
            <DetailRow label="Penempatan" value="Tidak tinggal di asrama saat ini" />
          )}
          <DetailRow label="Pengasuh (musyrif)" value={placement.musyrif_name ?? '-'} />
        </div>
      </Card>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatCard label={`Total ${s.points.point_label}`} value={s.points.total_points} tone={s.points.total_points > 0 ? 'amber' : 'green'} />
        <StatCard label="Prestasi" value={s.counts.achievements} tone="brand" />
        <StatCard label="Catatan sakit aktif" value={s.counts.health_open} tone={s.counts.health_open > 0 ? 'red' : 'default'} />
        <StatCard label="Izin + pulang berjalan" value={s.counts.permissions + s.counts.homecomings} />
      </div>

      {p?.next_threshold && (
        <Card className="p-3 text-sm text-slate-600">
          {p.next_threshold.label} berikutnya tercapai pada {p.next_threshold.threshold} —
          kekurangan <span className="font-semibold">{p.next_threshold.remaining}</span> {p.point_label} lagi.
        </Card>
      )}

      <Card className="p-4">
        <p className="mb-2 text-sm font-semibold text-slate-700">Pelanggaran terakhir</p>
        {s.recent_violations.length === 0 ? (
          <p className="text-sm text-slate-400">Belum ada catatan pelanggaran. Alhamdulillah.</p>
        ) : (
          <ul className="space-y-1.5 text-sm">
            {s.recent_violations.map((r) => (
              <li key={r.id} className="flex items-center justify-between border-b border-slate-50 pb-1">
                <span>{fmtDate(r.date)} — {r.type_name ?? '-'}</span>
                <span className="flex items-center gap-2">
                  <Badge tone={r.status === 'closed' ? 'green' : 'amber'}>{r.status}</Badge>
                  <span className="font-medium text-red-600">+{r.points}</span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

function PagedSection<T>({ queryKey, fetcher, renderRow }: {
  queryKey: unknown[];
  fetcher: (page: number) => Promise<{ rows: T[]; meta?: import('@/types').PaginationMeta }>;
  renderRow: (row: T, index: number) => ReactNode;
}) {
  const [page, setPage] = useState(1);
  const listQuery = useQuery({ queryKey: [...queryKey, page], queryFn: () => fetcher(page) });
  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta;

  if (listQuery.isLoading) return <Card><div className="flex justify-center py-14"><Spinner /></div></Card>;
  if (rows.length === 0) return <Card><EmptyState title="Belum ada data" /></Card>;

  return (
    <Card>
      <ul className="divide-y divide-slate-50">{rows.map((r, i) => renderRow(r, i))}</ul>
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </Card>
  );
}

export function BoardingPortalPage() {
  const [tab, setTab] = useState<(typeof TABS)[number]['key']>('overview');
  const [childId, setChildId] = useState<number | ''>('');

  const childrenQuery = useQuery({
    queryKey: ['boarding', 'portal', 'children'],
    queryFn: () => portalApi.children(),
  });

  const children = (childrenQuery.data ?? []) as BoardingPortalChild[];
  const activeChild = children.find((c) => c.student_id === childId) ?? children[0] ?? null;

  if (childrenQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat data ananda...</div>
      </Card>
    );
  }

  if (children.length === 0) {
    return (
      <Card>
        <EmptyState
          title="Belum ada santri asrama"
          description="Akun Anda belum tertaut dengan santri yang tinggal di asrama. Hubungi bagian tata usaha bila ini terasa keliru."
        />
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <MoonStar className="h-5 w-5 text-brand-600" />
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Portal Orang Tua — Boarding</h1>
          <p className="text-sm text-slate-500">Kondisi ananda di asrama: kehadiran, kedisiplinan, kesehatan, dan izin.</p>
        </div>
      </div>

      {children.length > 1 && (
        <Card className="p-3">
          <FormField label="Pilih ananda">
            <select
              className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
              value={String(activeChild?.student_id ?? '')}
              onChange={(e) => setChildId(Number(e.target.value))}
            >
              {children.map((c) => (
                <option key={c.student_id} value={c.student_id}>
                  {c.full_name} {c.nis ? `(${c.nis})` : ''} {c.in_boarding ? '' : '— non-boarding'}
                </option>
              ))}
            </select>
          </FormField>
        </Card>
      )}

      {activeChild && children.length === 1 && (
        <Card className="flex items-center justify-between p-4">
          <div>
            <p className="font-semibold text-slate-800">{activeChild.full_name}</p>
            <p className="text-xs text-slate-400">
              {activeChild.nis ?? ''} {activeChild.dormitory_name ? ` · ${activeChild.dormitory_name}` : ''}{activeChild.room_code ? ` · Kamar ${activeChild.room_code}` : ''}
            </p>
          </div>
          <StudentStatusBadge status={activeChild.status} />
        </Card>
      )}

      {activeChild && (
        <>
          <Tabs tabs={TABS.map((t) => ({ key: t.key, label: t.label }))} active={tab} onChange={(k) => setTab(k as typeof tab)} />

          {tab === 'overview' && <Overview child={activeChild} />}

          {tab === 'attendances' && (
            <PagedSection
              queryKey={['boarding', 'portal', activeChild.student_id, 'attendances']}
              fetcher={(page) => portalApi.attendances(activeChild.student_id, { page, per_page: 20 })}
              renderRow={(row) => {
                const a = row as BoardingAttendance;
                const category = a.attendance_type?.category ?? '';
                return (
                  <li key={a.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                    <span>{fmtDate(a.date)} · {a.activity?.name ?? a.session ?? 'Absensi'}</span>
                    <span className="flex items-center gap-2">
                      {a.is_late && <Badge tone="amber">Terlambat</Badge>}
                      <Badge tone={category === 'present' ? 'green' : category === 'unexcused' ? 'red' : 'blue'}>
                        {a.attendance_type?.name ?? '-'}
                      </Badge>
                    </span>
                  </li>
                );
              }}
            />
          )}

          {tab === 'violations' && (
            <PagedSection
              queryKey={['boarding', 'portal', activeChild.student_id, 'violations']}
              fetcher={(page) => portalApi.violations(activeChild.student_id, { page, per_page: 20 })}
              renderRow={(row) => {
                const v = row as BoardingViolation;
                return (
                  <li key={v.id} className="px-4 py-2.5 text-sm">
                    <div className="flex items-center justify-between">
                      <span className="font-medium text-slate-800">{v.type_name ?? 'Pelanggaran'}</span>
                      <span className="font-semibold text-red-600">+{v.points}</span>
                    </div>
                    <p className="text-xs text-slate-400">{fmtDate(v.date)} · {labelOf({ open: 'Masih terbuka', actioned: 'Ditindak', closed: 'Selesai' }, v.status)}</p>
                    {v.narrative && <p className="mt-1 text-xs text-slate-500">{v.narrative}</p>}
                  </li>
                );
              }}
            />
          )}

          {tab === 'achievements' && (
            <PagedSection
              queryKey={['boarding', 'portal', activeChild.student_id, 'achievements']}
              fetcher={(page) => portalApi.achievements(activeChild.student_id, { page, per_page: 20 })}
              renderRow={(row) => {
                const a = row as import('@/types/boarding').BoardingAchievement;
                return (
                  <li key={a.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                    <span>{a.title}<span className="block text-xs text-slate-400">{fmtDate(a.date)}</span></span>
                    <Badge tone="brand">{a.category_label}</Badge>
                  </li>
                );
              }}
            />
          )}

          {tab === 'health' && (
            <PagedSection
              queryKey={['boarding', 'portal', activeChild.student_id, 'health']}
              fetcher={(page) => portalApi.health(activeChild.student_id, { page, per_page: 20 })}
              renderRow={(row) => {
                const h = row as BoardingHealthRecord;
                return (
                  <li key={h.id} className="px-4 py-2.5 text-sm">
                    <div className="flex items-center justify-between">
                      <span className="font-medium text-slate-800">{h.condition ?? '-'}</span>
                      <Badge tone={h.status === 'recovered' ? 'green' : h.status === 'referred' ? 'blue' : 'amber'}>
                        {h.status_label || labelOf(HEALTH_STATUS_LABELS, h.status)}
                      </Badge>
                    </div>
                    <p className="text-xs text-slate-400">{fmtDate(h.date)}{h.action ? ` · Tindakan: ${h.action}` : ''}</p>
                  </li>
                );
              }}
            />
          )}

          {tab === 'permissions' && (
            <PagedSection
              queryKey={['boarding', 'portal', activeChild.student_id, 'permissions']}
              fetcher={(page) => portalApi.permissions(activeChild.student_id, { page, per_page: 20 })}
              renderRow={(row) => {
                const p2 = row as BoardingPermission;
                return (
                  <li key={p2.id} className="px-4 py-2.5 text-sm">
                    <div className="flex items-center justify-between">
                      <span className="font-medium text-slate-800">{p2.destination ?? p2.permission_type_label}</span>
                      <Badge tone={p2.status === 'returned' ? 'green' : p2.status === 'out' ? 'blue' : 'gray'}>
                        {p2.status_label || labelOf(PERMISSION_STATUS_LABELS, p2.status)}
                      </Badge>
                    </div>
                    <p className="text-xs text-slate-400">
                      {fmtDateTime(p2.start_at)} → {fmtDateTime(p2.expected_return_at)}
                      {p2.actual_return_at ? ` · kembali ${fmtDateTime(p2.actual_return_at)}` : ''}
                    </p>
                  </li>
                );
              }}
            />
          )}

          {tab === 'homecomings' && (
            <PagedSection
              queryKey={['boarding', 'portal', activeChild.student_id, 'homecomings']}
              fetcher={(page) => portalApi.homecomings(activeChild.student_id, { page, per_page: 20 })}
              renderRow={(row) => {
                const h = row as BoardingHomecoming;
                return (
                  <li key={h.id} className="px-4 py-2.5 text-sm">
                    <div className="flex items-center justify-between">
                      <span className="font-medium text-slate-800">{h.type_label}</span>
                      <Badge tone={h.status === 'returned' ? 'green' : h.status === 'out' ? 'brand' : 'gray'}>
                        {h.status_label || labelOf(HOMECOMING_STATUS_LABELS, h.status)}
                      </Badge>
                    </div>
                    <p className="text-xs text-slate-400">
                      Pulang {fmtDate(h.home_date)} · kembali {h.actual_return_date ? fmtDate(h.actual_return_date) : `(rencana) ${fmtDate(h.return_date)}`}
                      {h.late_return ? ' · TERLAMBAT' : ''}
                    </p>
                  </li>
                );
              }}
            />
          )}

          {tab === 'visitors' && (
            <PagedSection
              queryKey={['boarding', 'portal', activeChild.student_id, 'visitors']}
              fetcher={(page) => portalApi.visitors(activeChild.student_id, { page, per_page: 20 })}
              renderRow={(row) => {
                const v = row as BoardingVisitor;
                return (
                  <li key={v.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                    <span>{v.visitor_name}<span className="block text-xs text-slate-400">{fmtDate(v.visit_date)} · {v.purpose ?? '-'}</span></span>
                    <Badge tone={v.status === 'finished' ? 'green' : v.status === 'visiting' ? 'blue' : 'gray'}>
                      {v.status_label || labelOf(VISITOR_STATUS_LABELS, v.status)}
                    </Badge>
                  </li>
                );
              }}
            />
          )}
        </>
      )}
    </div>
  );
}
