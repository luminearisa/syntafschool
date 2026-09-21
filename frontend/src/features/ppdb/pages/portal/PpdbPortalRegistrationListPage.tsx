import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, PlusCircle, SlidersHorizontal } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { ApplicationCard } from '../../components/portal/ApplicationCard';
import { DataState } from '../../components/portal/DataState';
import { formatRupiah, quotaLine, toId } from '../../components/portal/format';
import { REGISTRATION_STATUS_LABELS, toOptions } from '../../components/portal/labels';
import { ppdbPortalApi, ppdbPortalApplicantsApi, ppdbPortalRegistrationsApi, ppdbPublicApi } from '../../api/ppdbApi';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';

const PER_PAGE = 12;

/** Semua pendaftaran pada akun ini + pembuatan pendaftaran baru ("daftar anak lain"). */
export function PpdbPortalRegistrationListPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();

  const [page, setPage] = useState(1);
  const [createOpen, setCreateOpen] = useState(params.get('baru') === '1');
  const [form, setForm] = useState({ applicant_id: '', period_id: '', program_id: '', wave_id: '' });
  const [createError, setCreateError] = useState<string | null>(null);

  const status = params.get('status') ?? '';
  const applicantId = params.get('applicant_id') ?? '';
  const periodId = params.get('period_id') ?? '';

  const patch = (updates: Record<string, string | null>) => {
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        for (const [key, value] of Object.entries(updates)) {
          if (value === null || value === '') next.delete(key);
          else next.set(key, value);
        }
        return next;
      },
      { replace: true },
    );
    setPage(1);
  };

  const dashboardQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'dashboard'],
    queryFn: () => ppdbPortalApi.dashboard(),
    staleTime: 30_000,
  });
  const accountActive = dashboardQuery.data
    ? Boolean(dashboardQuery.data.parent?.can_transact) && dashboardQuery.data.parent?.account_status === 'active'
    : true;

  const listQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'registrations', { page, status, applicantId, periodId }],
    queryFn: () =>
      ppdbPortalRegistrationsApi.list({
        page,
        per_page: PER_PAGE,
        ...(status ? { status } : {}),
        ...(applicantId ? { applicant_id: Number(applicantId) } : {}),
        ...(periodId ? { period_id: Number(periodId) } : {}),
      }),
  });

  const applicantsQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'applicants', 'all'],
    queryFn: () => ppdbPortalApplicantsApi.listAll(),
  });

  const periodsQuery = useQuery({
    queryKey: ['ppdb', 'public', 'periods'],
    queryFn: () => ppdbPublicApi.periods(),
    staleTime: 60_000,
  });

  const chosenPeriod = periodsQuery.data?.find((period) => String(period.id) === form.period_id) ?? null;

  const offeringsQuery = useQuery({
    queryKey: ['ppdb', 'public', 'period', chosenPeriod?.slug ?? ''],
    queryFn: () => ppdbPublicApi.period(chosenPeriod?.slug ?? ''),
    enabled: Boolean(chosenPeriod?.slug),
    staleTime: 60_000,
  });

  const childOptions = useMemo(
    () => (applicantsQuery.data ?? []).map((applicant) => ({ value: applicant.id, label: applicant.full_name })),
    [applicantsQuery.data],
  );
  const periodOptions = useMemo(
    () =>
      (periodsQuery.data ?? [])
        .filter((period) => period.registration_open)
        .map((period) => ({ value: period.id, label: period.public_title || period.name })),
    [periodsQuery.data],
  );
  const programOptions = useMemo(
    () => (offeringsQuery.data?.programs ?? []).map((program) => ({ value: program.id, label: program.name })),
    [offeringsQuery.data],
  );
  const waveOptions = useMemo(
    () => (offeringsQuery.data?.waves ?? []).map((wave) => ({ value: wave.id, label: wave.name })),
    [offeringsQuery.data],
  );

  const chosenProgram = (offeringsQuery.data?.programs ?? []).find((item) => String(item.id) === form.program_id) ?? null;
  const chosenWave = (offeringsQuery.data?.waves ?? []).find((item) => String(item.id) === form.wave_id) ?? null;
  const feePreview = useMemo(() => {
    const fees = offeringsQuery.data?.fees ?? [];
    return fees
      .filter((fee) => (fee.program_id === null || fee.program_id === toId(form.program_id)) && (fee.wave_id === null || fee.wave_id === toId(form.wave_id)))
      .reduce((sum, fee) => sum + Number(fee.amount ?? 0), 0);
  }, [offeringsQuery.data, form.program_id, form.wave_id]);

  const createMutation = useMutation({
    mutationFn: () =>
      ppdbPortalRegistrationsApi.create({
        applicant_id: Number(form.applicant_id),
        period_id: Number(form.period_id),
        program_id: Number(form.program_id),
        wave_id: form.wave_id ? Number(form.wave_id) : '',
      }),
    onSuccess: (registration) => {
      toast.success('Pendaftaran baru dibuat. Lengkapi formulir anak Anda.');
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'registrations'] });
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'dashboard'] });
      setCreateOpen(false);
      patch({ baru: null });
      navigate(`/ppdb/portal/registrations/${registration.id}`);
    },
    onError: (error) => {
      setCreateError(getErrorMessage(error));
    },
  });

  useEffect(() => {
    if (params.get('baru') === '1') setCreateOpen(true);
  }, [params]);

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const filtersActive = Boolean(status || applicantId || periodId);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pendaftaran saya</h1>
          <p className="text-sm text-slate-500">
            Setiap anak memiliki berkas pendaftaran terpisah pada akun yang sama.
          </p>
        </div>
        <Button
          onClick={() => setCreateOpen(true)}
          disabled={!accountActive}
          title={accountActive ? undefined : 'Akun Anda belum aktif'}
        >
          <PlusCircle className="h-4 w-4" />
          Daftar anak lain
        </Button>
      </div>

      {!accountActive && (
        <Card className="border-amber-200 bg-amber-50/60 p-3 text-xs text-amber-900">
          Akun Anda belum dapat membuat pendaftaran baru. Lengkapi verifikasi akun terlebih dahulu.
        </Card>
      )}

      <Card className="p-3">
        <div className="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-slate-500">
          <SlidersHorizontal className="h-3.5 w-3.5" />
          Saring
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <Label htmlFor="filter-status">Status pendaftaran</Label>
            <Select id="filter-status" value={status} onChange={(e) => patch({ status: e.target.value })}>
              <option value="">Semua status</option>
              {toOptions(REGISTRATION_STATUS_LABELS).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Anak</Label>
            <SearchSelect
              options={childOptions}
              value={applicantId ? Number(applicantId) : ''}
              onChange={(value) => patch({ applicant_id: value === '' ? null : String(value) })}
              placeholder="Semua anak"
            />
          </div>
          <div>
            <Label>Periode</Label>
            <SearchSelect
              options={(periodsQuery.data ?? []).map((period) => ({ value: period.id, label: period.public_title || period.name }))}
              value={periodId ? Number(periodId) : ''}
              onChange={(value) => patch({ period_id: value === '' ? null : String(value) })}
              placeholder="Semua periode"
            />
          </div>
        </div>
        {filtersActive && (
          <button
            type="button"
            onClick={() => patch({ status: null, applicant_id: null, period_id: null })}
            className="mt-2 text-xs font-medium text-brand-600 hover:underline"
          >
            Hapus semua filter
          </button>
        )}
      </Card>

      <DataState
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        isEmpty={rows.length === 0}
        loadingLabel="Memuat daftar pendaftaran..."
        errorTitle="Daftar pendaftaran gagal dimuat"
        emptyTitle={filtersActive ? 'Tidak ada pendaftaran pada filter ini' : 'Belum ada pendaftaran'}
        emptyDescription={
          filtersActive
            ? 'Ubah filter status, anak, atau periode untuk melihat berkas lainnya.'
            : 'Buat pendaftaran pertama Anda: pilih anak, periode, dan program yang dituju.'
        }
        emptyAction={
          filtersActive ? undefined : (
            <Button size="sm" className="mt-2" onClick={() => setCreateOpen(true)} disabled={!accountActive}>
              Daftar anak lain
            </Button>
          )
        }
      >
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {rows.map((card) => (
            <ApplicationCard key={card.registration_id} card={card} showPeriod />
          ))}
        </div>

        {meta && meta.last_page > 1 && (
          <div className="mt-3 flex items-center justify-between gap-2">
            <p className="text-xs text-slate-500">
              Halaman {meta.current_page} dari {meta.last_page} · total {meta.total} berkas
            </p>
            <div className="flex items-center gap-1">
              <Button
                size="sm"
                variant="outline"
                disabled={meta.current_page <= 1}
                onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
              >
                <ChevronLeft className="h-4 w-4" />
                Sebelumnya
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setPage((prev) => prev + 1)}
              >
                Berikutnya
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          </div>
        )}
      </DataState>

      <Modal
        open={createOpen}
        onClose={() => {
          setCreateOpen(false);
          setCreateError(null);
        }}
        title="Daftar anak lain"
        size="md"
        footer={
          <>
            <Button variant="outline" onClick={() => setCreateOpen(false)}>
              Batal
            </Button>
            <Button
              loading={createMutation.isPending}
              disabled={!form.applicant_id || !form.period_id || !form.program_id}
              onClick={() => {
                setCreateError(null);
                createMutation.mutate();
              }}
            >
              Buat pendaftaran
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-xs text-slate-500">
            Pendaftaran baru dibuat sebagai draf. Anda akan diarahkan ke formulir untuk melengkapinya.
          </p>

          {getValidationErrors(createMutation.error)?.program_id && (
            <p className="text-xs text-red-500">{getValidationErrors(createMutation.error)!.program_id[0]}</p>
          )}

          <div>
            <Label required>Anak yang didaftarkan</Label>
            {applicantsQuery.isLoading ? (
              <div className="flex items-center gap-2 text-xs text-slate-500">
                <Spinner className="h-4 w-4" /> Memuat data anak...
              </div>
            ) : childOptions.length === 0 ? (
              <p className="text-xs text-slate-600">
                Belum ada data anak pada akun ini.{' '}
                <Link to="/ppdb/portal/applicants" className="font-medium text-brand-600 hover:underline">
                  Tambah data anak
                </Link>{' '}
                lebih dahulu.
              </p>
            ) : (
              <SearchSelect
                options={childOptions}
                value={form.applicant_id ? Number(form.applicant_id) : ''}
                onChange={(value) => setForm((prev) => ({ ...prev, applicant_id: String(value ?? '') }))}
                placeholder="-- Pilih anak --"
              />
            )}
          </div>

          <div>
            <Label required>Periode</Label>
            {periodsQuery.isLoading ? (
              <div className="flex items-center gap-2 text-xs text-slate-500">
                <Spinner className="h-4 w-4" /> Memuat periode...
              </div>
            ) : periodOptions.length === 0 ? (
              <p className="text-xs text-slate-600">Belum ada periode yang dibuka untuk pendaftaran.</p>
            ) : (
              <SearchSelect
                options={periodOptions}
                value={form.period_id ? Number(form.period_id) : ''}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, period_id: String(value ?? ''), program_id: '', wave_id: '' }))
                }
                placeholder="-- Pilih periode --"
              />
            )}
          </div>

          {chosenPeriod && (
            <div>
              <Label required>Program tujuan</Label>
              {offeringsQuery.isLoading ? (
                <div className="flex items-center gap-2 text-xs text-slate-500">
                  <Spinner className="h-4 w-4" /> Memuat program...
                </div>
              ) : programOptions.length === 0 ? (
                <p className="text-xs text-slate-600">Program pada periode ini belum dipublikasikan.</p>
              ) : (
                <>
                  <SearchSelect
                    options={programOptions}
                    value={form.program_id ? Number(form.program_id) : ''}
                    onChange={(value) => setForm((prev) => ({ ...prev, program_id: String(value ?? '') }))}
                    placeholder="-- Pilih program --"
                  />
                  {chosenProgram && (
                    <p className="mt-1 text-[11px] text-slate-500">
                      {quotaLine(chosenProgram.quota, chosenProgram.registered)}
                      {chosenProgram.is_full ? ' · kuota penuh' : ''}
                    </p>
                  )}
                </>
              )}
            </div>
          )}

          {chosenPeriod && waveOptions.length > 0 && (
            <div>
              <Label>Gelombang</Label>
              <SearchSelect
                options={waveOptions}
                value={form.wave_id ? Number(form.wave_id) : ''}
                onChange={(value) => setForm((prev) => ({ ...prev, wave_id: String(value ?? '') }))}
                placeholder="-- Tanpa gelombang --"
              />
              {chosenWave && (
                <p className="mt-1 text-[11px] text-slate-500">
                  {quotaLine(chosenWave.quota, chosenWave.registered)}
                  {chosenWave.is_open ? ' · gelombang dibuka' : ' · gelombang tidak aktif hari ini'}
                </p>
              )}
            </div>
          )}

          {feePreview > 0 && (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
              Perkiraan biaya untuk pilihan ini: <span className="font-semibold text-slate-800">{formatRupiah(feePreview)}</span>
              . Rincian resmi muncul pada tagihan setelah pendaftaran dibuat.
            </p>
          )}

          {createError && <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{createError}</p>}
        </div>
      </Modal>
    </div>
  );
}

export default PpdbPortalRegistrationListPage;
