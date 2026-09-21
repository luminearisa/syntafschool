import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Pencil, Trash2, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbApplicantsApi } from '../../api/ppdbApi';
import { PpdbScopeFilters } from '../../components/ops/PpdbOpsFilters';
import {
  ConfirmModal,
  ErrorLine,
  FilterField,
  LoadingLine,
  Notice,
  OpsTable,
  PageHeader,
  RegistrationStatusBadge,
  ReloadButton,
  SectionCard,
  SidePanel,
  StatCard,
  useUrlFilters,
  type OpsColumn,
} from '../../components/ops/PpdbOpsUi';
import {
  REGISTRATION_STATUS_LABELS,
  SIMPLE_STATUS_LABELS,
  formatDateTime,
} from '../../components/ops/labels';
import { usePpdbReferenceOptions } from '../../hooks/usePpdbReferenceOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Pagination } from '@/components/common/Pagination';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate } from '@/lib/utils';
import type { PpdbApplicant, PpdbRegistration } from '@/types/ppdb';

/** Kolom yang boleh diubah petugas dari halaman ini (bukan alur pendaftaran). */
interface ApplicantFormState {
  full_name: string;
  nickname: string;
  nisn: string;
  nik: string;
  birth_place: string;
  birth_date: string;
  gender_id: number | '';
  religion_id: number | '';
  previous_school: string;
  previous_school_npsn: string;
  address: string;
  notes: string;
  status: 'active' | 'inactive';
}

function toFormState(applicant: PpdbApplicant): ApplicantFormState {
  return {
    full_name: applicant.full_name ?? '',
    nickname: applicant.nickname ?? '',
    nisn: applicant.nisn ?? '',
    nik: applicant.nik ?? '',
    birth_place: applicant.birth_place ?? '',
    birth_date: applicant.birth_date ? applicant.birth_date.slice(0, 10) : '',
    gender_id: applicant.gender_id ?? '',
    religion_id: applicant.religion_id ?? '',
    previous_school: applicant.previous_school ?? '',
    previous_school_npsn: applicant.previous_school_npsn ?? '',
    address: applicant.address ?? '',
    notes: applicant.notes ?? '',
    status: applicant.status ?? 'active',
  };
}

/**
 * Berkas pendaftaran milik seorang calon.
 *
 * `PpdbApplicantResource::toArray()` (PpdbApplicantResource.php:31-36) hanya
 * menulis kunci `registrations` bila relasinya termuat, jadi tipenya opsional.
 * Untuk halaman ini kuncinya SELALU ada:
 *  - daftar: `applicantQuery()` memuat `registrations.program` +
 *    `registrations.period` (PpdbParentAccountService.php:488);
 *  - detail: `adminShow()` memanggil `withRelations($applicant, true)` yang
 *    memuat `registrations.*`.
 * Helper ini menutup selisih "opsional di tipe, selalu ada di payload" tanpa
 * menambah optional chaining di tiap tempat pakai.
 */
function registrationsOf(applicant: PpdbApplicant): PpdbRegistration[] {
  return applicant.registrations ?? [];
}

export function PpdbApplicantsPage() {
  const filters = useUrlFilters([
    'search',
    'period_id',
    'program_id',
    'status',
    'gender_id',
    'sort_by',
    'sort_dir',
  ]);
  const queryClient = useQueryClient();

  const [editing, setEditing] = useState<PpdbApplicant | null>(null);
  const [deleting, setDeleting] = useState<PpdbApplicant | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'applicants', filters.values],
    placeholderData: (previous) => previous,
    queryFn: () => ppdbApplicantsApi.list({ ...filters.apiParams(), per_page: 15 }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const detailQuery = useQuery({
    queryKey: ['ppdb', 'applicant', detailId],
    enabled: detailId !== null,
    queryFn: () => ppdbApplicantsApi.show(detailId as number),
  });

  /**
   * Baris daftar yang sedang dibuka — jadi isi panel sampai `adminShow`
   * (`GET /ppdb/applicants/{applicant}`, routes/api/ppdb.php:152) selesai dimuat
   * atau bila permintaan detail gagal.
   */
  const selectedRow = rows.find((row) => row.id === detailId) ?? null;
  const detail = detailQuery.data ?? selectedRow;

  const removeMutation = useMutation({
    mutationFn: (id: number) => ppdbApplicantsApi.remove(id),
    onSuccess: () => {
      toast.success('Calon siswa diarsipkan (soft delete).');
      setDeleting(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const columns = useMemo<OpsColumn<PpdbApplicant>[]>(
    () => [
      {
        key: 'full_name',
        label: 'Nama calon',
        sortable: true,
        render: (row) => (
          <div>
            <button
              type="button"
              onClick={() => setDetailId(row.id)}
              className="text-left text-sm font-medium text-slate-800 hover:text-brand-700"
            >
              {row.full_name}
            </button>
            {row.nickname && <p className="text-xs text-slate-400">panggilan: {row.nickname}</p>}
            <p className="text-xs text-slate-500">
              {row.birth_date ? formatDate(row.birth_date) : 'tanggal lahir belum diisi'}
            </p>
          </div>
        ),
      },
      {
        key: 'nisn',
        label: 'NISN / NIK',
        render: (row) => (
          <div className="text-xs text-slate-600">
            <p className="font-mono">{row.nisn ?? '—'}</p>
            <p className="font-mono text-slate-400">{row.nik ?? '—'}</p>
          </div>
        ),
      },
      {
        key: 'gender',
        label: 'Jenis kelamin',
        // `gender` adalah NAMA master (string), bukan objek relasi.
        render: (row) => <span className="text-xs text-slate-600">{row.gender ?? '—'}</span>,
      },
      {
        key: 'program',
        label: 'Program sasaran',
        quiet: true,
        render: (row) => (
          <div className="text-xs text-slate-600">
            {row.target_program ?? 'belum memilih program'}
            {row.previous_school && <p className="text-slate-400">asal: {row.previous_school}</p>}
          </div>
        ),
      },
      {
        key: 'parent',
        label: 'Akun orang tua',
        quiet: true,
        render: (row) => (
          <div className="text-xs">
            <Link
              to={`/ppdb/parent-accounts?search=${encodeURIComponent(row.parent_name ?? '')}`}
              className="text-brand-700 hover:underline"
            >
              {row.parent_name ?? 'akun tidak tercatat'}
            </Link>
            {/* Baris calon hanya mengirim `parent_name`; email & status akun
                tidak ada pada payload, jadi tidak boleh dikarang. */}
            <p className="text-slate-400">
              {row.parent_name ? 'akun lewat portal' : 'calon belum punya akun orang tua'}
            </p>
          </div>
        ),
      },
      {
        key: 'registrations',
        label: 'Pendaftaran',
        align: 'center',
        render: (row) => {
          const registrations = registrationsOf(row);
          return (
            <div className="space-y-1">
              <p className="text-xs text-slate-500">{registrations.length} berkas</p>
              {registrations.slice(0, 2).map((registration) => (
                <Link
                  key={registration.id}
                  to={`/ppdb/registrations/${registration.id}`}
                  className="block truncate text-xs text-brand-700 hover:underline"
                >
                  {registration.registration_number} ·{' '}
                  {registration.status_label ??
                    REGISTRATION_STATUS_LABELS[registration.status] ??
                    registration.status}
                </Link>
              ))}
              {registrations.length === 0 && <p className="text-xs text-slate-400">belum mendaftar</p>}
            </div>
          );
        },
      },
      {
        key: 'status',
        label: 'Status calon',
        render: (row) => (
          <Badge tone={row.status === 'active' ? 'green' : 'gray'}>
            {SIMPLE_STATUS_LABELS[row.status] ?? row.status}
          </Badge>
        ),
      },
      {
        key: 'actions',
        label: 'Aksi',
        align: 'right',
        render: (row) => (
          <Can perm={['ppdb.create', 'ppdb.update', 'ppdb.delete']}>
            <div className="flex flex-wrap items-center justify-end gap-1">
              <Button size="sm" variant="ghost" onClick={() => setDetailId(row.id)}>
                <UserRound className="h-3.5 w-3.5" /> Lihat
              </Button>
              <Can perm="ppdb.update">
                <Button size="sm" variant="outline" onClick={() => setEditing(row)}>
                  <Pencil className="h-3.5 w-3.5" /> Ubah
                </Button>
              </Can>
              <Can perm="ppdb.delete">
                <Button size="sm" variant="ghost" onClick={() => setDeleting(row)}>
                  <Trash2 className="h-3.5 w-3.5" />
                </Button>
              </Can>
            </div>
          </Can>
        ),
      },
    ],
    [],
  );

  return (
    <div className="space-y-4">
      <PageHeader
        title="Calon Siswa"
        description="Data calon yang dibuat orang tua lewat portal. Calon siswa BELUM menjadi siswa sampai pendaftarannya dikonversi pada halaman berkas pendaftaran."
        badge={meta ? <Badge tone="gray">{meta.total} calon</Badge> : undefined}
        actions={<ReloadButton onClick={() => listQuery.refetch()} busy={listQuery.isFetching} />}
      />

      <Notice tone="amber">
        Halaman ini hanya mengurus identitas calon. Status akademik, NIS, dan rombel tidak ada di sini —
        seorang calon menjadi siswa hanya setelah pendaftaran berstatus Selesai dikonversi menjadi siswa.
      </Notice>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Calon tercatat" value={meta?.total ?? 0} hint="Sesuai filter aktif." />
        <StatCard
          label="Sudah membuat pendaftaran"
          value={rows.filter((row) => registrationsOf(row).length > 0).length}
          tone="brand"
          hint="Baris pada halaman ini."
        />
        <StatCard
          label="Belum mendaftar"
          value={rows.filter((row) => registrationsOf(row).length === 0).length}
          tone="amber"
          hint="Biasanya draf yang belum dikirim."
        />
      </div>

      <PpdbScopeFilters filters={filters} searchPlaceholder="Nama, NISN, NIK...">
        <FilterField label="Jenis kelamin">
          <GenderFilter filters={filters} />
        </FilterField>
        <FilterField label="Status calon">
          <Select
            value={filters.get('status')}
            onChange={(event) => filters.set({ status: event.target.value })}
          >
            <option value="">Semua status</option>
            <option value="active">Aktif</option>
            <option value="inactive">Tidak aktif</option>
          </Select>
        </FilterField>
      </PpdbScopeFilters>

      <OpsTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        loading={listQuery.isLoading}
        error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
        emptyTitle="Belum ada calon siswa"
        emptyDescription="Calon muncul setelah orang tua mengisi data anak di portal PPDB."
        sort={filters.get('sort_by') ? { key: filters.get('sort_by'), dir: filters.get('sort_dir') === 'asc' ? 'asc' : 'desc' } : null}
        onSort={(key) => {
          if (key !== 'full_name') return;
          filters.set({ sort_by: key, sort_dir: filters.get('sort_by') === key && filters.get('sort_dir') === 'desc' ? 'asc' : 'desc' });
        }}
        footer={meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
      />

      <ApplicantEditModal
        applicant={editing}
        onClose={() => setEditing(null)}
        onSaved={() => {
          setEditing(null);
          listQuery.refetch();
        }}
      />

      <ConfirmModal
        open={Boolean(deleting)}
        title={deleting ? `Arsipkan calon ${deleting.full_name}` : 'Arsipkan calon'}
        description="Calon dihapus dari daftar aktif. Berkas pendaftaran yang sudah dibuat tetap tertaut dan dapat dilihat kembali."
        points={[
          'Calon dengan pendaftaran berjalan sebaiknya tidak diarsipkan — selesaikan atau batalkan pendaftarannya lebih dulu.',
          'Tindakan ini soft delete: data tidak dimusnahkan.',
        ]}
        warning="Bila calon ini punya pendaftaran berstatus aktif, orang tua tidak dapat melanjutkan isian formulir."
        tone="danger"
        confirmLabel="Arsipkan calon"
        busy={removeMutation.isPending}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && removeMutation.mutate(deleting.id)}
      />

      <SidePanel
        open={detailId !== null}
        title="Data calon siswa"
        description="Identitas lengkap dan seluruh berkas pendaftaran atas nama calon ini."
        onClose={() => setDetailId(null)}
        footer={
          detail && (
            <Can perm="ppdb.update">
              <Button
                size="sm"
                variant="outline"
                onClick={() => {
                  setEditing(detail);
                  setDetailId(null);
                }}
              >
                <Pencil className="h-3.5 w-3.5" /> Ubah data calon
              </Button>
            </Can>
          )
        }
      >
        {detail === null ? (
          detailQuery.isError ? (
            <ErrorLine message={getErrorMessage(detailQuery.error)} onRetry={() => detailQuery.refetch()} />
          ) : (
            <LoadingLine label="Memuat data calon..." />
          )
        ) : (
          <div className="space-y-3">
            {detailQuery.isError && (
              <Notice tone="amber">
                Permintaan detail gagal, jadi isian di bawah dibaca dari baris daftar. Keduanya
                memakai resource yang sama (`PpdbApplicantResource`) dan sama-sama membawa berkas
                pendaftaran, jadi datanya setara — muat ulang untuk memastikan.
              </Notice>
            )}
            <ApplicantDetail applicant={detail} />
          </div>
        )}
      </SidePanel>
    </div>
  );
}

function GenderFilter({ filters }: { filters: ReturnType<typeof useUrlFilters> }) {
  const { options } = usePpdbReferenceOptions('genders');
  return (
    <SearchSelect
      options={options}
      value={filters.numeric('gender_id')}
      placeholder="-- Semua jenis kelamin --"
      onChange={(value) => filters.set({ gender_id: value })}
    />
  );
}

function ApplicantDetail({ applicant }: { applicant: PpdbApplicant }) {
  const registrations = registrationsOf(applicant);
  return (
    <div className="space-y-4">
      <SectionCard title="Identitas">
        <dl className="divide-y divide-slate-50 text-sm">
          <DetailRow label="Nama lengkap">{applicant.full_name}</DetailRow>
          <DetailRow label="Nama panggilan">{applicant.nickname ?? '—'}</DetailRow>
          <DetailRow label="Jenis kelamin">{applicant.gender ?? '—'}</DetailRow>
          <DetailRow label="Agama">{applicant.religion ?? '—'}</DetailRow>
          <DetailRow label="NISN">{applicant.nisn ?? '—'}</DetailRow>
          <DetailRow label="NIK">{applicant.nik ?? '—'}</DetailRow>
          <DetailRow label="Tempat, tanggal lahir">
            {`${applicant.birth_place ?? '—'}${applicant.birth_date ? `, ${formatDate(applicant.birth_date)}` : ''}`}
          </DetailRow>
          <DetailRow label="Usia">{applicant.age_label ?? '—'}</DetailRow>
          <DetailRow label="Sekolah asal">
            {applicant.previous_school ?? '—'}
            {applicant.previous_school_npsn ? ` (NPSN ${applicant.previous_school_npsn})` : ''}
          </DetailRow>
          <DetailRow label="Alamat">{applicant.address ?? '—'}</DetailRow>
          <DetailRow label="Program sasaran">{applicant.target_program ?? '—'}</DetailRow>
          <DetailRow label="Akun orang tua">{applicant.parent_name ?? '—'}</DetailRow>
          <DetailRow label="Dibuat">{formatDateTime(applicant.created_at)}</DetailRow>
          {applicant.notes && <DetailRow label="Catatan">{applicant.notes}</DetailRow>}
        </dl>
      </SectionCard>

      <SectionCard
        title={`Berkas pendaftaran (${registrations.length})`}
        description="Satu calon dapat mendaftar pada lebih dari satu gelombang atau program."
      >
        {registrations.length === 0 ? (
          <p className="text-sm text-slate-400">Calon ini belum membuat pendaftaran.</p>
        ) : (
          <ul className="divide-y divide-slate-50">
            {registrations.map((registration) => (
              <RegistrationLine key={registration.id} registration={registration} />
            ))}
          </ul>
        )}
      </SectionCard>
    </div>
  );
}

function RegistrationLine({ registration }: { registration: PpdbRegistration }) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-2">
      <div className="min-w-0">
        <Link
          to={`/ppdb/registrations/${registration.id}`}
          className="font-mono text-xs text-brand-700 hover:underline"
        >
          {registration.registration_number}
        </Link>
        <p className="text-xs text-slate-500">
          {registration.program?.name ?? 'program —'} · {registration.period?.name ?? 'periode —'} ·{' '}
          {registration.wave?.name ?? 'tanpa gelombang'}
        </p>
      </div>
      <RegistrationStatusBadge status={registration.status} />
    </li>
  );
}

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 py-1.5 sm:flex-row sm:gap-3">
      <dt className="shrink-0 text-xs font-medium uppercase tracking-wide text-slate-400 sm:w-44">{label}</dt>
      <dd className="text-sm text-slate-700">{children}</dd>
    </div>
  );
}

/* ============================ MODAL UBAH ============================ */

function ApplicantEditModal({
  applicant,
  onClose,
  onSaved,
}: {
  applicant: PpdbApplicant | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [form, setForm] = useState<ApplicantFormState | null>(
    applicant ? toFormState(applicant) : null,
  );
  const [sourceId, setSourceId] = useState<number | null>(applicant?.id ?? null);

  if (applicant && applicant.id !== sourceId) {
    setSourceId(applicant.id);
    setForm(toFormState(applicant));
  }

  const { options: genders } = usePpdbReferenceOptions('genders');
  const { options: religions } = usePpdbReferenceOptions('religions');

  const mutation = useMutation({
    mutationFn: (args: { id: number; payload: Partial<PpdbApplicant> }) =>
      ppdbApplicantsApi.update(args.id, args.payload),
    onSuccess: (updated) => {
      toast.success(`Data ${updated.full_name} disimpan.`);
      onSaved();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const errors = getValidationErrors(mutation.error);
  const patch = (next: Partial<ApplicantFormState>) =>
    setForm((current) => (current ? { ...current, ...next } : current));

  const submit = () => {
    if (!applicant || !form) return;
    mutation.mutate({
      id: applicant.id,
      payload: {
        full_name: form.full_name,
        nickname: form.nickname || null,
        nisn: form.nisn || null,
        nik: form.nik || null,
        birth_place: form.birth_place || null,
        birth_date: form.birth_date || null,
        gender_id: form.gender_id === '' ? null : form.gender_id,
        religion_id: form.religion_id === '' ? null : form.religion_id,
        previous_school: form.previous_school || null,
        previous_school_npsn: form.previous_school_npsn || null,
        address: form.address || null,
        notes: form.notes || null,
        status: form.status,
      } as Partial<PpdbApplicant>,
    });
  };

  return (
    <Modal
      open={Boolean(applicant && form)}
      onClose={onClose}
      size="lg"
      title={applicant ? `Ubah data ${applicant.full_name}` : 'Ubah data calon'}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={mutation.isPending}>
            Batal
          </Button>
          <Button
            loading={mutation.isPending}
            disabled={!form?.full_name.trim()}
            onClick={submit}
          >
            Simpan perubahan
          </Button>
        </>
      }
    >
      {form && (
        <div className="space-y-3">
          <Notice tone="gray">
            Perubahan identitas calon tidak mengubah jawaban formulir yang sudah dikirim. Bila isian
            formulir yang perlu diperbaiki, gunakan "Minta revisi" pada berkas pendaftarannya.
          </Notice>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label required>Nama lengkap</Label>
              <Input
                value={form.full_name}
                error={errors?.full_name?.[0]}
                onChange={(event) => patch({ full_name: event.target.value })}
              />
            </div>
            <div>
              <Label>Nama panggilan</Label>
              <Input value={form.nickname} onChange={(event) => patch({ nickname: event.target.value })} />
            </div>
            <div>
              <Label>NISN</Label>
              <Input
                value={form.nisn}
                error={errors?.nisn?.[0]}
                onChange={(event) => patch({ nisn: event.target.value })}
              />
            </div>
            <div>
              <Label>NIK</Label>
              <Input
                value={form.nik}
                error={errors?.nik?.[0]}
                onChange={(event) => patch({ nik: event.target.value })}
              />
            </div>
            <div>
              <Label>Tempat lahir</Label>
              <Input value={form.birth_place} onChange={(event) => patch({ birth_place: event.target.value })} />
            </div>
            <div>
              <Label>Tanggal lahir</Label>
              <Input
                type="date"
                value={form.birth_date}
                error={errors?.birth_date?.[0]}
                onChange={(event) => patch({ birth_date: event.target.value })}
              />
            </div>
            <div>
              <Label>Jenis kelamin</Label>
              <SearchSelect
                options={genders}
                value={form.gender_id}
                error={errors?.gender_id?.[0]}
                placeholder="-- Belum diisi --"
                onChange={(value) => patch({ gender_id: value === '' ? '' : Number(value) })}
              />
            </div>
            <div>
              <Label>Agama</Label>
              <SearchSelect
                options={religions}
                value={form.religion_id}
                error={errors?.religion_id?.[0]}
                placeholder="-- Belum diisi --"
                onChange={(value) => patch({ religion_id: value === '' ? '' : Number(value) })}
              />
            </div>
            <div>
              <Label>Sekolah asal</Label>
              <Input
                value={form.previous_school}
                onChange={(event) => patch({ previous_school: event.target.value })}
              />
            </div>
            <div>
              <Label>NPSN sekolah asal</Label>
              <Input
                value={form.previous_school_npsn}
                onChange={(event) => patch({ previous_school_npsn: event.target.value })}
              />
            </div>
            <div className="sm:col-span-2">
              <Label>Alamat</Label>
              <Textarea rows={2} value={form.address} onChange={(event) => patch({ address: event.target.value })} />
            </div>
            <div>
              <Label>Status calon</Label>
              <Select
                value={form.status}
                onChange={(event) => patch({ status: event.target.value as 'active' | 'inactive' })}
              >
                <option value="active">Aktif</option>
                <option value="inactive">Tidak aktif</option>
              </Select>
            </div>
            <div className="sm:col-span-2">
              <Label>Catatan petugas</Label>
              <Textarea rows={2} value={form.notes} onChange={(event) => patch({ notes: event.target.value })} />
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}
