import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, PlusCircle, UserPlus } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { ApplicantForm, ApplicantIdentity, applicantToForm, emptyApplicantForm, formToApplicant, validateApplicantForm } from '../../components/portal/ApplicantForm';
import { DataState } from '../../components/portal/DataState';
import { formatDate } from '../../components/portal/format';
import { ppdbPortalApplicantsApi, ppdbPortalRegistrationsApi, ppdbPublicApi } from '../../api/ppdbApi';
import type { ApplicantFormValues } from '../../components/portal/ApplicantForm';
import type { PpdbApplicant } from '@/types/ppdb';

interface FormState {
  open: boolean;
  applicant: PpdbApplicant | null;
  values: ApplicantFormValues;
  target_program_id: string;
}

const CLOSED: FormState = { open: false, applicant: null, values: emptyApplicantForm, target_program_id: '' };

/** Daftar anak pada satu akun orang tua + pembuatan/perubahan data anak. */
export function PpdbPortalApplicantsPage() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<FormState>(CLOSED);
  const [showErrors, setShowErrors] = useState(false);

  const applicantsQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'applicants', 'all'],
    queryFn: () => ppdbPortalApplicantsApi.listAll(),
  });

  const registrationsQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'registrations', 'cards'],
    queryFn: () => ppdbPortalRegistrationsApi.list({ page: 1, per_page: 100 }),
  });

  const periodsQuery = useQuery({
    queryKey: ['ppdb', 'public', 'periods'],
    queryFn: () => ppdbPublicApi.periods(),
    staleTime: 60_000,
  });

  const openPeriod = (periodsQuery.data ?? []).find((period) => period.registration_open) ?? null;

  const offeringsQuery = useQuery({
    queryKey: ['ppdb', 'public', 'period', openPeriod?.slug ?? ''],
    queryFn: () => ppdbPublicApi.period(openPeriod?.slug ?? ''),
    enabled: Boolean(openPeriod?.slug),
    staleTime: 60_000,
  });

  const programOptions = useMemo(
    () => (offeringsQuery.data?.programs ?? []).map((program) => ({ value: program.id, label: program.name })),
    [offeringsQuery.data],
  );

  const filesByChild = useMemo(() => {
    const grouped = new Map<number, number>();
    for (const card of registrationsQuery.data?.data ?? []) {
      const id = card.applicant?.id;
      if (!id) continue;
      grouped.set(id, (grouped.get(id) ?? 0) + 1);
    }
    return grouped;
  }, [registrationsQuery.data]);

  const saveMutation = useMutation({
    mutationFn: (payload: { editing: boolean; id?: number; data: Partial<PpdbApplicant> }) =>
      payload.editing
        ? ppdbPortalApplicantsApi.update(payload.id as number, payload.data)
        : ppdbPortalApplicantsApi.create(payload.data),
    onSuccess: (_applicant, payload) => {
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'applicants'] });
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'dashboard'] });
      toast.success(payload.editing ? 'Data anak diperbarui.' : 'Data anak ditambahkan.');
      setForm(CLOSED);
      setShowErrors(false);
    },
    onError: (error) => {
      toast.error(getErrorMessage(error));
    },
  });

  const errors = validateApplicantForm(form.values);
  const serverErrors = getValidationErrors(saveMutation.error);
  const errorFor = (key: string) =>
    (showErrors ? errors[key] : undefined) ?? serverErrors?.[key]?.[0];

  const openCreate = () => {
    setShowErrors(false);
    setForm({ ...CLOSED, open: true });
  };

  const openEdit = (applicant: PpdbApplicant) => {
    setShowErrors(false);
    setForm({
      open: true,
      applicant,
      values: applicantToForm(applicant),
      target_program_id: applicant.target_program_id ? String(applicant.target_program_id) : '',
    });
  };

  const submit = () => {
    setShowErrors(true);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali isian yang ditandai merah.');
      return;
    }
    saveMutation.mutate({
      editing: Boolean(form.applicant),
      id: form.applicant?.id,
      data: {
        ...formToApplicant(form.values),
        target_program_id: form.target_program_id ? Number(form.target_program_id) : null,
      },
    });
  };

  const rows = applicantsQuery.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Anak saya</h1>
          <p className="text-sm text-slate-500">
            Satu akun dapat menaungi beberapa anak. Menambahkan data anak belum berarti mendaftar — setiap anak
            tetap perlu dibuatkan berkas pendaftaran.
          </p>
        </div>
        <Button onClick={openCreate}>
          <UserPlus className="h-4 w-4" />
          Tambah anak
        </Button>
      </div>

      <DataState
        isLoading={applicantsQuery.isLoading}
        isError={applicantsQuery.isError}
        error={applicantsQuery.error}
        onRetry={() => applicantsQuery.refetch()}
        isEmpty={rows.length === 0}
        loadingLabel="Memuat data anak..."
        errorTitle="Data anak gagal dimuat"
        emptyTitle="Belum ada data anak"
        emptyDescription="Isi identitas anak terlebih dahulu, lalu pilih periode dan program tujuan pada menu Pendaftaran."
        emptyAction={
          <Button size="sm" className="mt-2" onClick={openCreate}>
            <PlusCircle className="h-4 w-4" />
            Tambah anak pertama
          </Button>
        }
      >
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {rows.map((applicant) => {
            const files = filesByChild.get(applicant.id) ?? applicant.registrations?.length ?? 0;
            const birth = [applicant.birth_place, applicant.birth_date ? formatDate(applicant.birth_date) : null]
              .filter(Boolean)
              .join(', ');

            return (
              <Card key={applicant.id} className="flex flex-col gap-3 p-4">
                <ApplicantIdentity
                  applicant={applicant}
                  subtitle={birth || applicant.previous_school || null}
                />

                <dl className="grid grid-cols-1 gap-x-3 gap-y-1 text-xs text-slate-500 sm:grid-cols-2">
                  <div className="flex gap-1">
                    <dt>NIK:</dt>
                    <dd className="truncate text-slate-700">{applicant.nik ?? 'belum diisi'}</dd>
                  </div>
                  <div className="flex gap-1">
                    <dt>NISN:</dt>
                    <dd className="truncate text-slate-700">{applicant.nisn ?? 'belum diisi'}</dd>
                  </div>
                  <div className="flex gap-1 sm:col-span-2">
                    <dt>Sekolah asal:</dt>
                    <dd className="truncate text-slate-700">{applicant.previous_school ?? 'belum diisi'}</dd>
                  </div>
                </dl>

                <div className="flex flex-wrap items-center gap-2">
                  {applicant.target_program ? (
                    <Badge tone="brand">{applicant.target_program}</Badge>
                  ) : (
                    <Badge tone="gray">Program tujuan belum dipilih</Badge>
                  )}
                  <Badge tone={files > 0 ? 'green' : 'gray'}>
                    {files > 0 ? `${files} berkas pendaftaran` : 'belum ada pendaftaran'}
                  </Badge>
                </div>

                <div className="mt-auto flex flex-wrap items-center gap-2">
                  <Button size="sm" variant="outline" onClick={() => openEdit(applicant)}>
                    <Pencil className="h-3.5 w-3.5" />
                    Ubah data
                  </Button>
                  <Link to={`/ppdb/portal/pendaftaran?applicant_id=${applicant.id}`}>
                    <Button size="sm" variant="ghost" disabled={files === 0}>
                      Lihat pendaftaran
                    </Button>
                  </Link>
                  <Link to="/ppdb/portal/pendaftaran?baru=1">
                    <Button size="sm" variant="ghost">
                      Daftar anak ini
                    </Button>
                  </Link>
                </div>
              </Card>
            );
          })}
        </div>
      </DataState>

      <p className="text-[11px] text-slate-500">
        Jenis kelamin dan agama anak dicatat pada formulir dinamis masing-masing pendaftaran agar sesuai pertanyaan
        yang diberlakukan sekolah pada periode berjalan.
      </p>

      <Modal
        open={form.open}
        onClose={() => {
          setForm(CLOSED);
          setShowErrors(false);
        }}
        title={form.applicant ? `Ubah data ${form.applicant.full_name}` : 'Tambah data anak'}
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setForm(CLOSED)}>
              Batal
            </Button>
            <Button loading={saveMutation.isPending} onClick={submit}>
              {form.applicant ? 'Simpan perubahan' : 'Simpan data anak'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-xs text-slate-500">
            Kolom bertanda * wajib diisi. Data yang salah dapat Anda perbaiki kapan saja sebelum pendaftaran dikirim.
          </p>

          {serverErrors?.full_name && <p className="text-xs text-red-500">{serverErrors.full_name[0]}</p>}

          <ApplicantForm
            values={form.values}
            errors={errors as Record<string, string | undefined>}
            disabled={saveMutation.isPending}
            onChange={(key, value) => {
              setShowErrors(false);
              setForm((prev) => ({ ...prev, values: { ...prev.values, [key]: value } }));
            }}
          />

          <div>
            <Label>Program tujuan</Label>
            {periodsQuery.isLoading ? (
              <div className="flex items-center gap-2 text-xs text-slate-500">
                <Spinner className="h-4 w-4" /> Memuat program...
              </div>
            ) : !openPeriod ? (
              <p className="text-xs text-slate-600">
                Belum ada periode yang dibuka. Program tujuan dapat dipilih nanti saat periode pendaftaran dibuka.
              </p>
            ) : programOptions.length === 0 ? (
              <p className="text-xs text-slate-600">Program pada periode {openPeriod.name} belum dipublikasikan.</p>
            ) : (
              <>
                <SearchSelect
                  options={programOptions}
                  value={form.target_program_id ? Number(form.target_program_id) : ''}
                  onChange={(value) =>
                    setForm((prev) => ({ ...prev, target_program_id: String(value ?? '') }))
                  }
                  placeholder="-- Belum dipilih --"
                />
                <p className="mt-1 text-[11px] text-slate-500">
                  Pilihan ini hanya memandu penawaran awal. Program resmi setiap pendaftaran ditetapkan saat berkas
                  pendaftaran dibuat.
                </p>
              </>
            )}
            {errorFor('target_program_id') && (
              <p className="mt-1 text-xs text-red-500">{errorFor('target_program_id')}</p>
            )}
          </div>

          {openPeriod && (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-600">
              Periode berjalan: {openPeriod.public_title || openPeriod.name}
              {openPeriod.programs.length > 0 ? ` · ${openPeriod.programs.length} program` : ''}
              {openPeriod.registration_end ? ` · pendaftaran ditutup ${formatDate(openPeriod.registration_end)}` : ''}
            </p>
          )}

          {saveMutation.isError && (
            <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{getErrorMessage(saveMutation.error)}</p>
          )}
        </div>
      </Modal>
    </div>
  );
}

export default PpdbPortalApplicantsPage;
