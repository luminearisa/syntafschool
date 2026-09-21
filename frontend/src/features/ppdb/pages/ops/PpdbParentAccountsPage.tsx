import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Ban, KeyRound, UserCheck, Users } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbParentAccountsApi } from '../../api/ppdbApi';
import {
  AccountStatusBadge,
  ConfirmModal,
  CopyButton,
  EmptyList,
  ErrorLine,
  FieldList,
  FieldRow,
  FilterField,
  LoadingLine,
  Notice,
  OpsTable,
  PageHeader,
  ReloadButton,
  SectionCard,
  SidePanel,
  StatCard,
  useUrlFilters,
  type OpsColumn,
} from '../../components/ops/PpdbOpsUi';
import { PpdbScopeFilters } from '../../components/ops/PpdbOpsFilters';
import {
  ACCOUNT_STATUS_LABELS,
  ACCOUNT_STATUS_OPTIONS,
  REGISTRATION_STATUS_LABELS,
  formatDateTime,
  relativeAge,
} from '../../components/ops/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination } from '@/components/common/Pagination';
import { Select } from '@/components/ui/Select';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import type {
  PpdbAccountStatus,
  PpdbAccountStatusResult,
  PpdbAccountRegistrationRow,
  PpdbParentAccount,
} from '@/types/ppdb';

/**
 * `POST /ppdb/parent-accounts/{id}/status` membungkus akun di kunci
 * `parent_account` (PpdbParentAccountController.php:118-127), sedangkan lapisan
 * API masih mendeklarasikan hasilnya sebagai `PpdbParentAccount`. Dibuka di
 * sini supaya pesan toast membaca akun yang sebenarnya.
 */
function accountFromStatusPayload(payload: PpdbParentAccount | PpdbAccountStatusResult): PpdbParentAccount {
  return 'parent_account' in payload ? payload.parent_account : payload;
}

export function PpdbParentAccountsPage() {
  const filters = useUrlFilters(['search', 'account_status', 'period_id']);
  const queryClient = useQueryClient();

  const [detailId, setDetailId] = useState<number | null>(null);
  const [statusTarget, setStatusTarget] = useState<{
    account: PpdbParentAccount;
    status: PpdbAccountStatus;
  } | null>(null);
  const [resetResult, setResetResult] = useState<{ email?: string; password: string; name?: string } | null>(
    null,
  );

  /**
   * `adminQuery()` hanya membaca `account_status`
   * (PpdbParentAccountService.php:276) — kunci `status` diabaikan server, jadi
   * filter ini tidak boleh menulisnya.
   */
  const statusFilter = filters.get('account_status');

  const listQuery = useQuery({
    queryKey: ['ppdb', 'parent-accounts', filters.values],
    placeholderData: (previous) => previous,
    queryFn: () =>
      ppdbParentAccountsApi.list({
        ...filters.apiParams(),
        per_page: 15,
      }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const statusMutation = useMutation({
    mutationFn: async (args: { id: number; status: PpdbAccountStatus }): Promise<
      PpdbParentAccount | PpdbAccountStatusResult
    > => ppdbParentAccountsApi.updateStatus(args.id, args.status),
    onSuccess: (payload) => {
      const account = accountFromStatusPayload(payload);
      const label = account.account_status_label
        ?? ACCOUNT_STATUS_LABELS[account.account_status]
        ?? account.account_status;
      toast.success(`Akun ${account.name ?? account.email ?? `#${account.id}`} kini ${label}.`);
      setStatusTarget(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const resetMutation = useMutation({
    mutationFn: (account: PpdbParentAccount) =>
      ppdbParentAccountsApi.resetPassword(account.id).then((result) => ({ result, account })),
    onSuccess: ({ result, account }) => {
      // Password sementara HANYA ada di `temporary_password` dan sekali tampil;
      // email dibaca dari akun di dalam bungkus respons, fallback baris daftar.
      setResetResult({
        email: result.parent_account?.email ?? account.email ?? undefined,
        password: result.temporary_password,
        name: result.parent_account?.name ?? account.name ?? undefined,
      });
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const detailQuery = useQuery({
    queryKey: ['ppdb', 'parent-account', detailId],
    enabled: detailId !== null,
    queryFn: () => ppdbParentAccountsApi.show(detailId as number),
  });

  const columns: OpsColumn<PpdbParentAccount>[] = [
    {
      key: 'identity',
      label: 'Akun orang tua / wali',
      render: (row) => (
        <div>
          <button
            type="button"
            className="text-left text-sm font-medium text-slate-800 hover:text-brand-700"
            onClick={() => setDetailId(row.id)}
          >
            {row.name ?? 'Tanpa nama'}
          </button>
          <p className="text-xs text-slate-500">{row.email ?? 'email tidak tercatat'}</p>
          <p className="text-xs text-slate-400">{row.phone ?? 'telepon tidak tercatat'}</p>
        </div>
      ),
    },
    {
      key: 'account_status',
      label: 'Status akun',
      render: (row) => <AccountStatusBadge status={row.account_status} />,
    },
    {
      key: 'children',
      label: 'Anak / pendaftaran',
      align: 'center',
      render: (row) => (
        <div className="text-xs text-slate-600">
          <p className="inline-flex items-center gap-1">
            <Users className="h-3.5 w-3.5" />
            {row.applicant_count ?? 0} anak
          </p>
          <p className="text-slate-400">{row.registration_count ?? 0} pendaftaran</p>
        </div>
      ),
    },
    {
      key: 'activity',
      label: 'Aktivitas terakhir',
      quiet: true,
      render: (row) => (
        <div className="text-xs text-slate-500">
          {row.last_activity_at ? (
            <>
              <p>{formatDateTime(row.last_activity_at)}</p>
              <p className="text-slate-400">{relativeAge(row.last_activity_at)}</p>
            </>
          ) : (
            <p className="text-slate-400">belum pernah masuk</p>
          )}
        </div>
      ),
    },
    {
      key: 'verified_at',
      label: 'Diverifikasi',
      quiet: true,
      render: (row) =>
        row.verified_at ? (
          <span className="text-xs text-emerald-600">{formatDateTime(row.verified_at)}</span>
        ) : (
          <span className="text-xs text-amber-600">menunggu verifikasi email</span>
        ),
    },
    {
      key: 'actions',
      label: 'Aksi',
      align: 'right',
      render: (row) => (
        <Can perm="ppdb.account.manage">
          <div className="flex flex-wrap items-center justify-end gap-1">
            <Button size="sm" variant="outline" onClick={() => setDetailId(row.id)}>
              Rincian
            </Button>
            {row.account_status === 'active' ? (
              <Button size="sm" variant="ghost" onClick={() => setStatusTarget({ account: row, status: 'blocked' })}>
                <Ban className="h-3.5 w-3.5" /> Blokir
              </Button>
            ) : (
              <Button size="sm" variant="ghost" onClick={() => setStatusTarget({ account: row, status: 'active' })}>
                <UserCheck className="h-3.5 w-3.5" /> Aktifkan
              </Button>
            )}
            <Button
              size="sm"
              variant="ghost"
              loading={resetMutation.isPending && resetMutation.variables?.id === row.id}
              onClick={() => resetMutation.mutate(row)}
            >
              <KeyRound className="h-3.5 w-3.5" /> Reset sandi
            </Button>
          </div>
        </Can>
      ),
    },
  ];

  const blockedCount = rows.filter((row) => row.account_status === 'blocked').length;
  const pendingCount = rows.filter((row) => row.account_status === 'pending_verification').length;

  return (
    <div className="space-y-4">
      <PageHeader
        title="Akun Orang Tua"
        description="Satu akun dapat memegang beberapa anak. Pemblokiran menghentikan akses portal, tetapi data pendaftaran tetap tersimpan."
        badge={meta ? <Badge tone="gray">{meta.total} akun</Badge> : undefined}
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Total akun" value={meta?.total ?? 0} hint="Sesuai filter aktif." />
        <StatCard label="Menunggu verifikasi" value={pendingCount} tone="amber" hint="Baris pada halaman ini." />
        <StatCard label="Diblokir" value={blockedCount} tone="red" hint="Baris pada halaman ini." />
      </div>

      <PpdbScopeFilters filters={filters} showWaves={false} showPrograms={false} searchPlaceholder="Nama, email, atau nomor telepon...">
        <FilterField label="Status akun">
          <Select
            value={statusFilter}
            onChange={(event) => filters.set({ status: event.target.value, account_status: event.target.value })}
          >
            <option value="">Semua status akun</option>
            {ACCOUNT_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {ACCOUNT_STATUS_LABELS[option.value]}
              </option>
            ))}
          </Select>
        </FilterField>
      </PpdbScopeFilters>

      <div className="flex justify-end">
        <ReloadButton onClick={() => listQuery.refetch()} busy={listQuery.isFetching} />
      </div>

      <OpsTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        loading={listQuery.isLoading}
        error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
        emptyTitle="Belum ada akun orang tua"
        emptyDescription="Akun dibuat saat orang tua mendaftar lewat portal PPDB."
        footer={meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
      />

      <Notice tone="gray">
        Halaman ini tidak menyediakan hapus akun. Menghapus akun akan melepas riwayat pendaftaran anak —
        gunakan blokir bila akses harus dihentikan.
      </Notice>

      {/* ================= DETAIL AKUN ================= */}
      <SidePanel
        open={detailId !== null}
        title="Rincian akun orang tua"
        description="Anak dan pendaftaran pada satu akun — satu akun dapat memiliki beberapa berkas pendaftaran."
        onClose={() => setDetailId(null)}
        footer={
          detailQuery.data && (
            <Can perm="ppdb.account.manage">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-xs text-slate-500">
                  Status saat ini: {ACCOUNT_STATUS_LABELS[detailQuery.data.account_status]}
                </span>
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                      setStatusTarget({
                        account: detailQuery.data,
                        status: detailQuery.data.account_status === 'active' ? 'inactive' : 'active',
                      })
                    }
                  >
                    {detailQuery.data.account_status === 'active' ? 'Nonaktifkan' : 'Aktifkan'}
                  </Button>
                  <Button
                    size="sm"
                    onClick={() =>
                      setStatusTarget({
                        account: detailQuery.data,
                        status: detailQuery.data.account_status === 'blocked' ? 'active' : 'blocked',
                      })
                    }
                    variant={detailQuery.data.account_status === 'blocked' ? 'outline' : 'danger'}
                  >
                    {detailQuery.data.account_status === 'blocked' ? 'Lepas blokir' : 'Blokir akses'}
                  </Button>
                </div>
              </div>
            </Can>
          )
        }
      >
        {detailQuery.isLoading ? (
          <LoadingLine label="Memuat akun..." />
        ) : detailQuery.isError ? (
          <ErrorLine message={getErrorMessage(detailQuery.error)} onRetry={() => detailQuery.refetch()} />
        ) : !detailQuery.data ? (
          <EmptyList title="Akun tidak ditemukan" description="Tarik ulang data bila akun baru saja dibuat." />
        ) : (
          <AccountDetail account={detailQuery.data} />
        )}
      </SidePanel>

      {/* ================= KONFIRMASI STATUS ================= */}
      <ConfirmModal
        open={Boolean(statusTarget)}
        title={
          statusTarget?.status === 'blocked'
            ? 'Blokir akses akun'
            : `Ubah status akun menjadi ${statusTarget ? ACCOUNT_STATUS_LABELS[statusTarget.status] : ''}`
        }
        description={
          statusTarget?.status === 'blocked'
            ? 'Orang tua tidak dapat masuk ke portal PPDB. Berkas pendaftaran yang sudah masuk tetap dapat Anda proses.'
            : 'Akun kembali dapat masuk portal dan melanjutkan pendaftaran anak.'
        }
        points={[
          `Akun: ${statusTarget?.account.name ?? statusTarget?.account.email ?? '—'}`,
          statusTarget?.status === 'blocked'
            ? 'Pendaftaran tetap tercatat; tidak ada data yang dihapus.'
            : 'Perubahan tercatat pada log audit akun.',
        ]}
        warning={
          statusTarget?.status === 'blocked'
            ? 'Pastikan ini akun yang benar — orang tua sedang tidak dapat menghubungi Anda lewat portal saat diblokir.'
            : undefined
        }
        tone={statusTarget?.status === 'blocked' ? 'danger' : 'primary'}
        confirmLabel="Simpan status"
        busy={statusMutation.isPending}
        onClose={() => setStatusTarget(null)}
        onConfirm={() =>
          statusTarget && statusMutation.mutate({ id: statusTarget.account.id, status: statusTarget.status })
        }
      />

      {/* ================= HASIL RESET SANDI ================= */}
      <Modal
        open={Boolean(resetResult)}
        onClose={() => setResetResult(null)}
        title="Sandi baru dibuat"
        footer={
          <Button
            onClick={() => setResetResult(null)}
          >
            Selesai
          </Button>
        }
      >
        {resetResult && (
          <div className="space-y-3">
            <Notice tone="red">
              Sandi ini hanya ditampilkan satu kali dan tidak dapat dilihat ulang dari halaman ini.
              Salin sekarang lalu sampaikan ke orang tua lewat kanal resmi sekolah.
            </Notice>
            <FieldList>
              <FieldRow label="Akun">{resetResult.name ?? '—'}</FieldRow>
              <FieldRow label="Email">{resetResult.email ?? '—'}</FieldRow>
              <FieldRow label="Sandi sementara">
                <span className="inline-flex items-center gap-2">
                  <code className="rounded bg-slate-100 px-2 py-1 font-mono text-sm text-slate-800">
                    {resetResult.password}
                  </code>
                  <CopyButton text={resetResult.password} label="Salin sandi" />
                </span>
              </FieldRow>
            </FieldList>
            <p className="text-xs text-slate-500">
              Minta orang tua mengganti sandi ini setelah masuk. Aktivitas masuk berikutnya akan muncul
              pada kolom "Aktivitas terakhir".
            </p>
          </div>
        )}
      </Modal>
    </div>
  );
}

/* ============================ ISI DETAIL ============================ */

function AccountDetail({ account }: { account: PpdbParentAccount }) {
  const applicants = account.applicants ?? [];

  /**
   * Pendaftaran pada akun ini dikirim sebagai agregat berlabel di kunci
   * `recent_registrations` (PpdbParentAccountService.php:386-415) — BUKAN objek
   * resource pendaftaran, dan `program`/`period`-nya string nama.
   */
  const registrations: PpdbAccountRegistrationRow[] = account.recent_registrations ?? [];

  const byChild = new Map<number, PpdbAccountRegistrationRow[]>();
  for (const registration of registrations) {
    const group = byChild.get(registration.applicant_id) ?? [];
    group.push(registration);
    byChild.set(registration.applicant_id, group);
  }

  return (
    <div className="space-y-4">
      <SectionCard title="Identitas akun">
        <FieldList>
          <FieldRow label="Nama">{account.name ?? '—'}</FieldRow>
          <FieldRow label="Email">{account.email ?? '—'}</FieldRow>
          <FieldRow label="Telepon">{account.phone ?? '—'}</FieldRow>
          <FieldRow label="Alamat">{account.address ?? '—'}</FieldRow>
          <FieldRow label="Status akun">
            <AccountStatusBadge status={account.account_status} />
          </FieldRow>
          <FieldRow label="Verifikasi email">
            {account.verified_at ? formatDateTime(account.verified_at) : 'belum terverifikasi'}
          </FieldRow>
          <FieldRow label="Aktivitas terakhir">
            {account.last_activity_at
              ? `${formatDateTime(account.last_activity_at)} (${relativeAge(account.last_activity_at) ?? '—'})`
              : 'belum pernah masuk'}
          </FieldRow>
          {account.children_note && <FieldRow label="Ringkasan">{account.children_note}</FieldRow>}
          {account.notes && <FieldRow label="Catatan">{account.notes}</FieldRow>}
        </FieldList>
      </SectionCard>

      <SectionCard
        title={`Anak pada akun ini (${applicants.length})`}
        description="Satu akun orang tua dapat mendaftarkan beberapa anak — bahkan pada periode yang sama."
      >
        {applicants.length === 0 ? (
          <EmptyList
            title="Belum ada calon terdaftar"
            description="Akun ini belum membuat data calon siswa."
          />
        ) : (
          <ul className="space-y-2">
            {applicants.map((applicant) => {
              const childRegistrations = byChild.get(applicant.id) ?? [];
              return (
                <li key={applicant.id} className="rounded-lg border border-slate-200 px-3 py-2">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-sm font-medium text-slate-800">{applicant.full_name}</p>
                      <p className="text-xs text-slate-500">
                        {applicant.nisn ? `NISN ${applicant.nisn}` : 'NISN belum diisi'} ·{' '}
                        {applicant.gender ?? 'jenis kelamin belum diisi'}
                      </p>
                    </div>
                    <Badge tone="gray">{childRegistrations.length} pendaftaran</Badge>
                  </div>
                  <ul className="mt-2 space-y-1">
                    {childRegistrations.map((registration) => (
                      <li key={registration.id} className="flex flex-wrap items-center gap-2 text-xs">
                        <Link
                          to={`/ppdb/registrations/${registration.id}`}
                          className="font-mono text-brand-700 hover:underline"
                        >
                          {registration.registration_number}
                        </Link>
                        <span className="text-slate-500">
                          {registration.status_label ??
                            REGISTRATION_STATUS_LABELS[registration.status] ??
                            registration.status}
                        </span>
                        <span className="text-slate-400">
                          {registration.program ?? 'program —'} · {registration.period ?? 'periode —'}
                        </span>
                      </li>
                    ))}
                    {childRegistrations.length === 0 && (
                      <li className="text-xs text-slate-400">
                        Belum ada pendaftaran atas nama anak ini
                        {registrations.length === 0 ? ' — data pendaftaran tidak disertakan server.' : '.'}
                      </li>
                    )}
                  </ul>
                </li>
              );
            })}
          </ul>
        )}
      </SectionCard>

      {applicants.length === 0 && registrations.length > 0 && (
        <SectionCard title="Pendaftaran pada akun ini">
          <ul className="space-y-1 text-sm">
            {registrations.map((registration) => (
              <li key={registration.id} className="flex flex-wrap items-center gap-2">
                <Link
                  to={`/ppdb/registrations/${registration.id}`}
                  className="font-mono text-xs text-brand-700 hover:underline"
                >
                  {registration.registration_number}
                </Link>
                <span className="text-slate-600">{registration.applicant_name ?? 'calon tanpa nama'}</span>
                <span className="text-xs text-slate-400">
                  {registration.status_label ??
                    REGISTRATION_STATUS_LABELS[registration.status] ??
                    registration.status}
                </span>
              </li>
            ))}
          </ul>
        </SectionCard>
      )}
    </div>
  );
}
