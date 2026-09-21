import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Megaphone, Pencil, Send, Trash2, Trophy } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbAnnouncementsApi, ppdbRegistrationsApi } from '../../api/ppdbApi';
import {
  RegistrationPicker,
  registrationLabel,
  usePpdbPeriodOptions,
} from '../../components/ops/PpdbOpsFilters';
import {
  ConfirmModal,
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
  type UrlFilters,
} from '../../components/ops/PpdbOpsUi';
import {
  ANNOUNCEMENT_CHANNEL_LABELS,
  ANNOUNCEMENT_CHANNEL_OPTIONS,
  ANNOUNCEMENT_STATUS_LABELS,
  ANNOUNCEMENT_STATUS_OPTIONS,
  SELECTION_RESULT_LABELS,
  SELECTION_RESULT_OPTIONS,
  announcementStatusTone,
  formatDateTime,
  statusLabelOf,
} from '../../components/ops/labels';
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
import type {
  PpdbAnnouncement,
  PpdbAnnouncementChannel,
  PpdbAnnouncementPayload,
  PpdbAnnouncementStatus,
  PpdbRegistration,
  PpdbSelectionResult,
} from '@/types/ppdb';

const BLANK: PpdbAnnouncementPayload = {
  period_id: '',
  title: '',
  body: '',
  channel: 'portal',
  status: 'draft',
};

export function PpdbAnnouncementsPage() {
  const filters = useUrlFilters(['period_id', 'status']);
  const queryClient = useQueryClient();

  const [form, setForm] = useState<{ payload: PpdbAnnouncementPayload; row?: PpdbAnnouncement } | null>(null);
  const [publishing, setPublishing] = useState<PpdbAnnouncement | null>(null);
  const [deleting, setDeleting] = useState<PpdbAnnouncement | null>(null);
  const [reading, setReading] = useState<PpdbAnnouncement | null>(null);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'announcements', filters.values],
    placeholderData: (previous) => previous,
    queryFn: () =>
      ppdbAnnouncementsApi.list({
        ...filters.apiParams(),
        per_page: 15,
      }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const pendingResultQuery = useQuery({
    queryKey: ['ppdb', 'registrations', 'awaiting-result', filters.get('period_id')],
    staleTime: 1000 * 60,
    queryFn: () =>
      ppdbRegistrationsApi.list({
        page: 1,
        per_page: 1,
        status: 'selection',
        period_id: filters.numeric('period_id') || undefined,
      }),
  });

  const saveMutation = useMutation({
    mutationFn: (args: { id?: number; payload: PpdbAnnouncementPayload }) =>
      args.id
        ? ppdbAnnouncementsApi.update(args.id, args.payload)
        : ppdbAnnouncementsApi.create(args.payload),
    onSuccess: (announcement, args) => {
      toast.success(args.id ? 'Pengumuman diperbarui.' : `Draf "${announcement.title}" dibuat.`);
      setForm(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const publishMutation = useMutation({
    mutationFn: (id: number) => ppdbAnnouncementsApi.publish(id),
    onSuccess: () => {
      toast.success('Pengumuman diterbitkan — orang tua terkait menerima notifikasi.');
      setPublishing(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => ppdbAnnouncementsApi.remove(id),
    onSuccess: () => {
      toast.success('Pengumuman dihapus.');
      setDeleting(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const columns: OpsColumn<PpdbAnnouncement>[] = [
    {
      key: 'title',
      label: 'Judul',
      render: (row) => (
        <div>
          <button
            type="button"
            className="text-left text-sm font-medium text-slate-800 hover:text-brand-700"
            onClick={() => setReading(row)}
          >
            {row.title}
          </button>
          <p className="text-xs text-slate-400">
            {row.period?.name ?? 'periode tidak tercatat'} · dibuat {formatDateTime(row.created_at)}
          </p>
        </div>
      ),
    },
    {
      key: 'channel',
      label: 'Kanal',
      render: (row) => <Badge tone="blue">{ANNOUNCEMENT_CHANNEL_LABELS[row.channel] ?? row.channel}</Badge>,
    },
    {
      key: 'status',
      label: 'Status',
      render: (row) => (
        <Badge tone={announcementStatusTone(row.status)}>
          {ANNOUNCEMENT_STATUS_LABELS[row.status] ?? row.status}
        </Badge>
      ),
    },
    {
      key: 'published_at',
      label: 'Diterbitkan',
      quiet: true,
      render: (row) => (
        <span className="text-xs text-slate-500">
          {row.published_at ? formatDateTime(row.published_at) : 'belum diterbitkan'}
          {row.publisher_name && <span className="block text-slate-400">oleh {row.publisher_name}</span>}
        </span>
      ),
    },
    {
      key: 'actions',
      label: 'Aksi',
      align: 'right',
      render: (row) => (
        <Can perm="ppdb.announcement.manage">
          <div className="flex flex-wrap items-center justify-end gap-1">
            <Button size="sm" variant="outline" disabled={row.status === 'published'} loading={publishMutation.isPending && publishing?.id === row.id} onClick={() => setPublishing(row)}>
              <Send className="h-3.5 w-3.5" /> Terbitkan
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => setForm({ payload: { period_id: row.period_id, title: row.title, body: row.body, channel: row.channel, status: row.status }, row })}
            >
              <Pencil className="h-3.5 w-3.5" /> Ubah
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setDeleting(row)}>
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
          </div>
        </Can>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        title="Pengumuman Hasil"
        description="Draf dan terbitkan pengumuman hasil seleksi. Penerapan mengirim notifikasi ke akun orang tua yang bersangkutan."
        actions={
          <Can perm="ppdb.announcement.manage">
            <Button onClick={() => setForm({ payload: { ...BLANK, period_id: filters.numeric('period_id') } })}>
              <Megaphone className="h-4 w-4" /> Draf baru
            </Button>
          </Can>
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Total pengumuman" value={meta?.total ?? 0} hint="Sesuai filter periode/status." />
        <StatCard
          label="Sudah terbit"
          value={rows.filter((row) => row.status === 'published').length}
          tone="green"
          hint="Dihitung dari baris pada halaman ini."
        />
        <StatCard
          label="Menunggu penetapan hasil"
          value={pendingResultQuery.data?.meta.total ?? 0}
          tone="amber"
          hint='Pendaftaran berstatus "Ikut Seleksi".'
        />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,360px)]">
        <div className="space-y-3">
          <PpdbPeriodAndStatusFilters filters={filters} />
          <OpsTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            loading={listQuery.isLoading}
            error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
            emptyTitle="Belum ada pengumuman"
            emptyDescription="Buat draf lebih dulu; pengumuman hanya dikirim saat Anda menerbitkannya."
            footer={meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
          />
          <div className="flex justify-end">
            <ReloadButton onClick={() => listQuery.refetch()} busy={listQuery.isFetching} />
          </div>
        </div>

        <SetResultPanel periodId={filters.numeric('period_id')} onChanged={() => listQuery.refetch()} />
      </div>

      {form && (
        <AnnouncementFormModal
          payload={form.payload}
          editing={Boolean(form.row)}
          onChange={(payload) => setForm({ ...form, payload })}
          busy={saveMutation.isPending}
          errors={getValidationErrors(saveMutation.error)}
          onClose={() => setForm(null)}
          onSubmit={() => saveMutation.mutate({ id: form.row?.id, payload: form.payload })}
        />
      )}

      <ConfirmModal
        open={Boolean(publishing)}
        title={publishing ? `Terbitkan "${publishing.title}"` : 'Terbitkan pengumuman'}
        description="Pengumuman akan tampil di portal orang tua dan dikirim lewat kanal yang dipilih."
        points={[
          `Kanal: ${publishing ? ANNOUNCEMENT_CHANNEL_LABELS[publishing.channel] ?? publishing.channel : '—'}.`,
          'Orang tua yang pendaftarannya sudah diberi hasil akan menerima pemberitahuan.',
          'Isi pengumuman tidak dapat ditarik kembali setelah terbit — periksa sebelum lanjut.',
        ]}
        acknowledgeLabel="Isi dan kanal pengumuman sudah saya periksa."
        confirmLabel="Terbitkan sekarang"
        busy={publishMutation.isPending}
        onClose={() => setPublishing(null)}
        onConfirm={() => publishing && publishMutation.mutate(publishing.id)}
      />

      <ConfirmModal
        open={Boolean(deleting)}
        title={deleting ? `Hapus "${deleting.title}"` : 'Hapus pengumuman'}
        description="Pengumuman yang sudah terbit tetap tercatat pada riwayat pendaftaran yang menautkannya."
        warning="Hapus draf yang tidak jadi dipakai. Untuk pengumuman terbit, pertimbangkan arsipkan status alih-alih menghapus."
        tone="danger"
        confirmLabel="Hapus pengumuman"
        busy={removeMutation.isPending}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && removeMutation.mutate(deleting.id)}
      />

      <SidePanel
        open={Boolean(reading)}
        title={reading?.title ?? 'Pengumuman'}
        description={
          reading
            ? `${reading.period?.name ?? 'periode'} · ${statusLabelOf(ANNOUNCEMENT_STATUS_LABELS, reading.status)}`
            : undefined
        }
        onClose={() => setReading(null)}
      >
        {reading ? (
          <div className="space-y-3">
            <FieldList>
              <FieldRow label="Kanal">
                {ANNOUNCEMENT_CHANNEL_LABELS[reading.channel] ?? reading.channel}
              </FieldRow>
              <FieldRow label="Diterbitkan">
                {reading.published_at ? formatDateTime(reading.published_at) : 'belum terbit'}
              </FieldRow>
              <FieldRow label="Penyebab status">
                {reading.publisher_name ?? 'belum ada penerbit'}
              </FieldRow>
            </FieldList>
            <p className="whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
              {reading.body}
            </p>
          </div>
        ) : (
          <LoadingLine />
        )}
      </SidePanel>
    </div>
  );
}

function PpdbPeriodAndStatusFilters({ filters }: { filters: UrlFilters }) {
  const { options: periodOptions, isLoading } = usePpdbPeriodOptions();
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FilterField label="Periode">
          <SearchSelect
            options={periodOptions}
            value={filters.numeric('period_id')}
            disabled={isLoading}
            placeholder={isLoading ? 'Memuat periode...' : '-- Semua periode --'}
            onChange={(value) => filters.set({ period_id: value })}
          />
        </FilterField>
        <FilterField label="Status pengumuman">
          <Select value={filters.get('status')} onChange={(event) => filters.set({ status: event.target.value })}>
            <option value="">Semua status</option>
            {ANNOUNCEMENT_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {ANNOUNCEMENT_STATUS_LABELS[option.value]}
              </option>
            ))}
          </Select>
        </FilterField>
      </div>
    </div>
  );
}

/* ============================ PANEL TETAPKAN HASIL ============================ */

function SetResultPanel({
  periodId,
  onChanged,
}: {
  periodId: number | '';
  onChanged: () => void;
}) {
  const queryClient = useQueryClient();
  const [registrationId, setRegistrationId] = useState<number | ''>('');
  const [result, setResult] = useState<PpdbSelectionResult | ''>('');
  const [note, setNote] = useState('');
  const [announcementId, setAnnouncementId] = useState<number | ''>('');

  const announcementsQuery = useQuery({
    queryKey: ['ppdb', 'announcements', 'options', periodId],
    enabled: periodId !== '',
    queryFn: () => ppdbAnnouncementsApi.listAll({ period_id: periodId }),
  });
  const announcementOptions = (announcementsQuery.data ?? []).map((item) => ({
    value: item.id,
    label: `${item.title} (${statusLabelOf(ANNOUNCEMENT_STATUS_LABELS, item.status)})`,
  }));

  const selectedRegistration = useQuery({
    queryKey: ['ppdb', 'registration', registrationId],
    enabled: registrationId !== '',
    queryFn: () => ppdbRegistrationsApi.show(registrationId as number),
  });
  const registration: PpdbRegistration | undefined = (selectedRegistration.data as { registration?: PpdbRegistration } | undefined)
    ?.registration;

  const mutation = useMutation({
    mutationFn: (args: { id: number; result: PpdbSelectionResult; note: string; announcementId: number | '' }) =>
      ppdbRegistrationsApi.setResult(args.id, args.result, {
        note: args.note || undefined,
        announcement_id: args.announcementId === '' ? undefined : args.announcementId,
      }),
    onSuccess: (updated) => {
      toast.success(`Hasil untuk ${updated.registration_number} ditetapkan.`);
      setResult('');
      setNote('');
      setRegistrationId('');
      setAnnouncementId('');
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
      onChanged();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const errors = getValidationErrors(mutation.error);

  return (
    <SectionCard
      title="Tetapkan hasil akhir"
      description="Menandai pendaftaran Lulus, Tidak lulus, atau Cadangan. Hasil ini yang dilihat orang tua di portal."
    >
      <div className="space-y-3">
        <Notice tone="amber">
          Menetapkan hasil tidak otomatis menerbitkan pengumuman. Tautkan satu pengumuman agar orang tua
          menerima notifikasi yang isinya sama dengan yang Anda lihat di sini.
        </Notice>

        <RegistrationPicker
          required
          label="Pendaftaran"
          status="selection"
          periodId={periodId}
          value={registrationId}
          onChange={setRegistrationId}
          error={errors?.registration_id?.[0]}
        />

        {registrationId !== '' && (
          <p className="text-xs text-slate-500">
            {selectedRegistration.isLoading
              ? 'Memuat berkas...'
              : registration
                ? `${registrationLabel(registration)} — hasil saat ini: ${
                    registration.selection_result
                      ? SELECTION_RESULT_LABELS[registration.selection_result]
                      : 'belum ditetapkan'
                  }`
                : 'Pendaftaran tidak ditemukan.'}
          </p>
        )}

        <div>
          <Label required>Hasil seleksi</Label>
          <Select
            value={result}
            onChange={(event) => setResult(event.target.value as PpdbSelectionResult | '')}
          >
            <option value="">-- Pilih hasil --</option>
            {SELECTION_RESULT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {SELECTION_RESULT_LABELS[option.value]}
              </option>
            ))}
          </Select>
          {errors?.result && <p className="mt-1 text-xs text-red-500">{errors.result[0]}</p>}
        </div>

        <div>
          <Label>Tautkan pengumuman</Label>
          <SearchSelect
            options={announcementOptions}
            value={announcementId}
            disabled={periodId === ''}
            placeholder={periodId === '' ? 'Pilih periode dulu' : '-- Tanpa pengumuman --'}
            onChange={(value) => setAnnouncementId(value === '' ? '' : Number(value))}
          />
        </div>

        <div>
          <Label>Catatan hasil</Label>
          <Textarea
            rows={3}
            value={note}
            placeholder="Contoh: lolos jalur prestasi, skor tes 86."
            onChange={(event) => setNote(event.target.value)}
          />
        </div>

        <Can perm="ppdb.registration.manage">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <Link
              to={registrationId === '' ? '/ppdb/registrations?status=selection' : `/ppdb/registrations/${registrationId}`}
              className="text-xs font-medium text-brand-700 hover:underline"
            >
              {registrationId === '' ? 'Buka register status Ikut Seleksi' : 'Buka berkas pendaftaran'}
            </Link>
            <Button
              size="sm"
              disabled={registrationId === '' || result === ''}
              loading={mutation.isPending}
              onClick={() =>
                mutation.mutate({
                  id: registrationId as number,
                  result: result as PpdbSelectionResult,
                  note,
                  announcementId,
                })
              }
            >
              <Trophy className="h-4 w-4" /> Tetapkan hasil
            </Button>
          </div>
        </Can>
      </div>
    </SectionCard>
  );
}

/* ============================ MODAL FORM ============================ */

function AnnouncementFormModal({
  payload,
  editing,
  onChange,
  busy,
  errors,
  onClose,
  onSubmit,
}: {
  payload: PpdbAnnouncementPayload;
  editing: boolean;
  onChange: (payload: PpdbAnnouncementPayload) => void;
  busy: boolean;
  errors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: () => void;
}) {
  const { options: periodOptions } = usePpdbPeriodOptions();
  const patch = (next: Partial<PpdbAnnouncementPayload>) => onChange({ ...payload, ...next });

  return (
    <Modal
      open
      size="lg"
      onClose={onClose}
      title={editing ? 'Ubah pengumuman' : 'Draf pengumuman baru'}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={busy}>
            Batal
          </Button>
          <Button loading={busy} disabled={!payload.title.trim() || !payload.body.trim() || payload.period_id === ''} onClick={onSubmit}>
            {editing ? 'Simpan perubahan' : 'Simpan draf'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <Label required>Periode</Label>
            <SearchSelect
              options={periodOptions}
              value={payload.period_id}
              error={errors?.period_id?.[0]}
              placeholder="-- Pilih periode --"
              onChange={(value) => patch({ period_id: value === '' ? '' : Number(value) })}
            />
          </div>
          <div>
            <Label>Kanal penyebaran</Label>
            <Select
              value={payload.channel ?? 'portal'}
              onChange={(event) => patch({ channel: event.target.value as PpdbAnnouncementChannel })}
            >
              {ANNOUNCEMENT_CHANNEL_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {ANNOUNCEMENT_CHANNEL_LABELS[option.value]}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div>
          <Label required>Judul</Label>
          <Input
            value={payload.title}
            error={errors?.title?.[0]}
            placeholder="Contoh: Hasil Seleksi Jalur Reguler Gelombang 1"
            onChange={(event) => patch({ title: event.target.value })}
          />
        </div>

        <div>
          <Label required>Isi pengumuman</Label>
          <Textarea
            rows={8}
            value={payload.body}
            error={errors?.body?.[0]}
            placeholder="Tulis pengumuman yang dilihat orang tua di portal."
            onChange={(event) => patch({ body: event.target.value })}
          />
        </div>

        <div>
          <Label>Status draf</Label>
          <Select
            value={payload.status ?? 'draft'}
            onChange={(event) => patch({ status: event.target.value as PpdbAnnouncementStatus })}
          >
            {ANNOUNCEMENT_STATUS_OPTIONS.filter((option) => option.value !== 'published').map((option) => (
              <option key={option.value} value={option.value}>
                {ANNOUNCEMENT_STATUS_LABELS[option.value]}
              </option>
            ))}
          </Select>
          <p className="mt-1 text-xs text-slate-400">
            Status "Terbit" hanya lewat tombol Terbitkan — bukan dari form ini.
          </p>
        </div>

        {editing && payload.status === 'published' && (
          <Notice tone="red">
            Pengumuman ini sudah terbit. Mengubah isinya akan langsung mengubah teks yang dilihat orang
            tua di portal.
          </Notice>
        )}
      </div>
    </Modal>
  );
}
