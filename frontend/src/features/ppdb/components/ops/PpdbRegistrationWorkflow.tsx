import { useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  ArrowRightLeft,
  Ban,
  CheckCircle2,
  ClipboardList,
  FileWarning,
  GraduationCap,
  RotateCcw,
  Trophy,
  XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import { ppdbReRegistrationApi, ppdbRegistrationsApi } from '../../api/ppdbApi';
import { ConfirmModal, Notice, NoteModal } from './PpdbOpsUi';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Can } from '@/components/common/Can';
import { cn } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import {
  REGISTRATION_STATUS_LABELS,
  SELECTION_RESULT_LABELS,
  allowedTransitions,
} from './labels';
import type {
  PpdbInvoice,
  PpdbRegistration,
  PpdbRegistrationStatus,
  PpdbSelectionResult,
} from '@/types/ppdb';

/** Sasaran revisi yang boleh dicentang petugas (field / pertanyaan / dokumen). */
export interface RevisionTargetOption {
  type: 'field' | 'question' | 'document';
  key?: string;
  id?: number;
  label: string;
}

export interface AnnouncementOption {
  id: number;
  title: string;
}

type Intent =
  | {
      kind:
        | 'verify'
        | 'reject'
        | 'cancel'
        | 're_register_open'
        | 're_register_complete'
        | 'convert';
      registration: PpdbRegistration;
    }
  | { kind: 'revision'; registration: PpdbRegistration; targets: RevisionTargetOption[] }
  | { kind: 'transition'; registration: PpdbRegistration; allowed: PpdbRegistrationStatus[] }
  | { kind: 'result'; registration: PpdbRegistration; announcements: AnnouncementOption[] };

const RESULT_OPTIONS: PpdbSelectionResult[] = ['passed', 'failed', 'waitlisted'];

/* ============================ MODAL TRANSISI ============================ */

function TransitionModal({
  open,
  allowed,
  busy,
  errors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  allowed: PpdbRegistrationStatus[];
  busy: boolean;
  errors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (status: PpdbRegistrationStatus, note: string) => void;
}) {
  const [status, setStatus] = useState<PpdbRegistrationStatus | ''>('');
  const [touched, setTouched] = useState(false);

  return (
    <NoteModal
      open={open}
      title="Pindahkan status"
      description="Dipakai bila aksi khusus (verifikasi, tolak, hasil, daftar ulang) tidak mencakup kebutuhan ini. Perpindahan tetap dicatat pada riwayat."
      noteLabel="Catatan perpindahan"
      noteRequired={false}
      confirmLabel="Pindahkan status"
      busy={busy}
      errors={errors}
      onClose={() => {
        setStatus('');
        setTouched(false);
        onClose();
      }}
      onSubmit={(note) => {
        if (!status) {
          setTouched(true);
          return;
        }
        onSubmit(status, note);
        setStatus('');
        setTouched(false);
      }}
    >
      <div>
        <Label required>Status tujuan</Label>
        <Select value={status} onChange={(event) => setStatus(event.target.value as PpdbRegistrationStatus)}>
          <option value="">-- Pilih status tujuan --</option>
          {allowed.map((key) => (
            <option key={key} value={key}>
              {REGISTRATION_STATUS_LABELS[key]}
            </option>
          ))}
        </Select>
        {touched && !status && <p className="mt-1 text-xs text-red-500">Status tujuan wajib dipilih.</p>}
        {allowed.length === 0 && (
          <p className="mt-1 text-xs text-slate-400">
            Tidak ada status tujuan yang sah dari status sekarang.
          </p>
        )}
      </div>
    </NoteModal>
  );
}

/* ============================ MODAL REVISI ============================ */

function RevisionModal({
  open,
  targets,
  busy,
  errors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  targets: RevisionTargetOption[];
  busy: boolean;
  errors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (note: string, selected: RevisionTargetOption[]) => void;
}) {
  const [selected, setSelected] = useState<string[]>([]);

  const groups: { type: 'field' | 'question' | 'document'; label: string }[] = [
    { type: 'field', label: 'Kolom formulir' },
    { type: 'question', label: 'Pertanyaan' },
    { type: 'document', label: 'Dokumen' },
  ];
  const targetKey = (target: RevisionTargetOption) =>
    `${target.type}:${target.key ?? target.id ?? target.label}`;

  return (
    <NoteModal
      open={open}
      title="Minta revisi"
      description="Tandai bagian yang harus diperbaiki orang tua. Hanya bagian yang dicentang yang muncul sebagai daftar perbaikan di portal."
      noteLabel="Catatan revisi"
      noteRequired
      confirmLabel="Kirim permintaan revisi"
      busy={busy}
      errors={errors}
      onClose={() => {
        setSelected([]);
        onClose();
      }}
      onSubmit={(note) => {
        onSubmit(
          note,
          targets.filter((target) => selected.includes(targetKey(target))),
        );
        setSelected([]);
      }}
    >
      {targets.length === 0 ? (
        <Notice tone="gray">
          Skema formulir dan daftar dokumen tidak ikut pada respons ini, sehingga permintaan revisi
          dikirim sebagai catatan umum tanpa daftar bagian yang spesifik.
        </Notice>
      ) : (
        <div className="space-y-3">
          <p className="text-xs text-slate-500">
            Centang bagian yang perlu diperbaiki (boleh dikosongkan bila catatan bersifat umum).
          </p>
          {groups.map((group) => {
            const items = targets.filter((target) => target.type === group.type);
            if (items.length === 0) return null;
            return (
              <div key={group.type}>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  {group.label}
                </p>
                <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-100 p-2">
                  {items.map((target) => {
                    const key = targetKey(target);
                    return (
                      <label key={key} className="flex items-start gap-2 text-sm text-slate-700">
                        <input
                          type="checkbox"
                          className="mt-0.5 h-4 w-4 rounded border-slate-300"
                          checked={selected.includes(key)}
                          onChange={(event) =>
                            setSelected((current) =>
                              event.target.checked
                                ? [...current, key]
                                : current.filter((item) => item !== key),
                            )
                          }
                        />
                        {target.label}
                      </label>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </NoteModal>
  );
}

/* ============================ MODAL HASIL ============================ */

function ResultModal({
  open,
  announcements,
  busy,
  errors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  announcements: AnnouncementOption[];
  busy: boolean;
  errors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (result: PpdbSelectionResult, note: string, announcementId?: number) => void;
}) {
  const [result, setResult] = useState<PpdbSelectionResult>('passed');
  const [announcementId, setAnnouncementId] = useState<number | ''>('');

  return (
    <NoteModal
      open={open}
      title="Tetapkan hasil seleksi"
      description="Hasil ini menjadi status pendaftaran dan baru terlihat orang tua setelah pengumuman diterbitkan."
      noteLabel="Catatan penetapan"
      noteRequired={false}
      confirmLabel="Tetapkan hasil"
      busy={busy}
      errors={errors}
      onClose={() => {
        setResult('passed');
        setAnnouncementId('');
        onClose();
      }}
      onSubmit={(note) => {
        onSubmit(result, note, announcementId === '' ? undefined : announcementId);
        setResult('passed');
        setAnnouncementId('');
      }}
    >
      <div className="space-y-3">
        <div>
          <Label required>Hasil</Label>
          <div className="grid grid-cols-3 gap-2">
            {RESULT_OPTIONS.map((option) => (
              <button
                key={option}
                type="button"
                onClick={() => setResult(option)}
                className={cn(
                  'rounded-lg border px-3 py-2 text-sm font-medium transition-colors',
                  result === option
                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                    : 'border-slate-200 text-slate-600 hover:bg-slate-50',
                )}
              >
                {SELECTION_RESULT_LABELS[option]}
              </button>
            ))}
          </div>
        </div>
        <div>
          <Label>Terhubung ke pengumuman</Label>
          <Select
            value={announcementId}
            onChange={(event) =>
              setAnnouncementId(event.target.value === '' ? '' : Number(event.target.value))
            }
          >
            <option value="">-- Tanpa pengumuman --</option>
            {announcements.map((item) => (
              <option key={item.id} value={item.id}>
                {item.title}
              </option>
            ))}
          </Select>
          {announcements.length === 0 && (
            <p className="mt-1 text-xs text-slate-400">
              Belum ada pengumuman pada periode ini — buat lebih dulu di halaman Pengumuman.
            </p>
          )}
        </div>
      </div>
    </NoteModal>
  );
}

/* ============================ BAR AKSI ============================ */

/**
 * Seluruh aksi alur satu pendaftaran: verifikasi, revisi, tolak, transisi,
 * hasil, daftar ulang, dan konversi menjadi siswa.
 * `variant='queue'` membatasi pada aksi petugas harian di antrean verifikasi.
 */
export function RegistrationWorkflowBar({
  registration,
  serverTransitions,
  revisionTargets = [],
  announcements = [],
  variant = 'full',
  onChanged,
  className,
}: {
  registration: PpdbRegistration;
  serverTransitions?: PpdbRegistrationStatus[] | null;
  revisionTargets?: RevisionTargetOption[];
  announcements?: AnnouncementOption[];
  variant?: 'full' | 'queue';
  onChanged?: () => void;
  className?: string;
}) {
  const queryClient = useQueryClient();
  const [intent, setIntent] = useState<Intent | null>(null);

  function done(message: string) {
    toast.success(message);
    setIntent(null);
    queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    onChanged?.();
  }

  function fail(error: unknown) {
    const errors = getValidationErrors(error);
    const first = errors ? Object.values(errors).flat()[0] : undefined;
    toast.error(first ?? getErrorMessage(error));
  }

  const verifyMutation = useMutation({
    mutationFn: (args: { id: number; note: string }) =>
      ppdbRegistrationsApi.verify(args.id, args.note || undefined),
    onSuccess: () => done('Pendaftaran diverifikasi.'),
    onError: fail,
  });

  const rejectMutation = useMutation({
    mutationFn: (args: { id: number; note: string }) => ppdbRegistrationsApi.reject(args.id, args.note),
    onSuccess: () => done('Pendaftaran ditolak — alasan tersimpan pada riwayat.'),
    onError: fail,
  });

  const cancelMutation = useMutation({
    mutationFn: (args: { id: number; note: string }) => ppdbRegistrationsApi.cancel(args.id, args.note),
    onSuccess: () => done('Pendaftaran dibatalkan.'),
    onError: fail,
  });

  const transitionMutation = useMutation({
    mutationFn: (args: { id: number; status: PpdbRegistrationStatus; note: string }) =>
      ppdbRegistrationsApi.transition(args.id, args.status, args.note || undefined),
    onSuccess: (_data, args) => done(`Status dipindahkan ke ${REGISTRATION_STATUS_LABELS[args.status]}.`),
    onError: fail,
  });

  const revisionMutation = useMutation({
    mutationFn: (args: { id: number; note: string; targets: RevisionTargetOption[] }) =>
      ppdbRegistrationsApi.requestRevision(args.id, {
        note: args.note,
        targets: args.targets.length
          ? args.targets.map((target) => ({ type: target.type, key: target.key, id: target.id }))
          : undefined,
      }),
    onSuccess: () => done('Permintaan revisi dikirim ke orang tua.'),
    onError: fail,
  });

  const resultMutation = useMutation({
    mutationFn: (args: {
      id: number;
      result: PpdbSelectionResult;
      note: string;
      announcement_id?: number;
    }) =>
      ppdbRegistrationsApi.setResult(args.id, args.result, {
        note: args.note || undefined,
        announcement_id: args.announcement_id,
      }),
    onSuccess: (_data, args) => done(`Hasil ditetapkan: ${SELECTION_RESULT_LABELS[args.result]}.`),
    onError: fail,
  });

  const reRegisterMutation = useMutation<
    { registration: PpdbRegistration; invoice?: PpdbInvoice; student?: unknown },
    Error,
    { id: number; mode: 'open' | 'complete' }
  >({
    mutationFn: (args: { id: number; mode: 'open' | 'complete' }) =>
      args.mode === 'open'
        ? ppdbReRegistrationApi.open(args.id).then((result) => ({ ...result }))
        : ppdbReRegistrationApi.complete(args.id),
    onSuccess: (_data, args) =>
      done(
        args.mode === 'open'
          ? 'Daftar ulang dibuka dan tagihan daftar ulang diterbitkan.'
          : 'Daftar ulang diselesaikan.',
      ),
    onError: fail,
  });

  const convertMutation = useMutation({
    mutationFn: (id: number) => ppdbRegistrationsApi.convertToStudent(id),
    onSuccess: (response) => {
      const student = response.data?.student;
      toast.success(
        student
          ? `${response.message || 'Konversi berhasil.'} NIS ${student.nis}`
          : response.message || 'Konversi berhasil.',
      );
      setIntent(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
      queryClient.invalidateQueries({ queryKey: ['students'] });
      onChanged?.();
    },
    onError: fail,
  });

  const status = registration.status;
  const allowed = allowedTransitions(status, serverTransitions);
  const canDecide = status === 'submitted' || status === 'under_review' || status === 'need_revision';

  const decideActions = (
    <>
      <Can perm="ppdb.registration.verify">
        <Button
          size="sm"
          disabled={!canDecide}
          loading={verifyMutation.isPending && intent?.kind === 'verify'}
          onClick={() => setIntent({ kind: 'verify', registration })}
        >
          <CheckCircle2 className="h-3.5 w-3.5" /> Verifikasi
        </Button>
      </Can>
      <Can perm="ppdb.registration.manage">
        <Button
          size="sm"
          variant="outline"
          disabled={!canDecide}
          loading={revisionMutation.isPending && intent?.kind === 'revision'}
          onClick={() => setIntent({ kind: 'revision', registration, targets: revisionTargets })}
        >
          <FileWarning className="h-3.5 w-3.5" /> Minta revisi
        </Button>
      </Can>
      <Can perm="ppdb.registration.verify">
        <Button
          size="sm"
          variant="danger"
          disabled={!canDecide}
          loading={rejectMutation.isPending && intent?.kind === 'reject'}
          onClick={() => setIntent({ kind: 'reject', registration })}
        >
          <XCircle className="h-3.5 w-3.5" /> Tolak
        </Button>
      </Can>
    </>
  );

  const transitionAction = (
    <Can perm="ppdb.registration.manage">
      <Button
        size="sm"
        variant="outline"
        disabled={allowed.length === 0}
        loading={transitionMutation.isPending && intent?.kind === 'transition'}
        onClick={() => setIntent({ kind: 'transition', registration, allowed })}
      >
        <ArrowRightLeft className="h-3.5 w-3.5" /> Pindahkan status
      </Button>
    </Can>
  );

  let dialogs: ReactNode = null;
  if (intent?.kind === 'verify') {
    dialogs = (
      <NoteModal
        open
        title={`Verifikasi pendaftaran ${intent.registration.registration_number}`}
        description="Berkas dan dokumen dinyatakan sesuai. Status berpindah ke Terverifikasi dan orang tua menerima notifikasi."
        noteLabel="Catatan verifikasi (opsional)"
        noteRequired={false}
        confirmLabel="Verifikasi"
        busy={verifyMutation.isPending}
        errors={getValidationErrors(verifyMutation.error)}
        onClose={() => setIntent(null)}
        onSubmit={(note) => verifyMutation.mutate({ id: intent.registration.id, note })}
      />
    );
  } else if (intent?.kind === 'reject') {
    dialogs = (
      <NoteModal
        open
        title={`Tolak pendaftaran ${intent.registration.registration_number}`}
        description="Pendaftaran dihentikan karena berkas tidak dapat diproses. Alasan wajib diisi dan dikirim ke orang tua."
        noteLabel="Alasan penolakan"
        noteRequired
        confirmLabel="Tolak pendaftaran"
        tone="danger"
        busy={rejectMutation.isPending}
        errors={getValidationErrors(rejectMutation.error)}
        onClose={() => setIntent(null)}
        onSubmit={(note) => rejectMutation.mutate({ id: intent.registration.id, note })}
      />
    );
  } else if (intent?.kind === 'cancel') {
    dialogs = (
      <NoteModal
        open
        title={`Batalkan pendaftaran ${intent.registration.registration_number}`}
        description="Pembatalan bersifat final: pendaftaran atas nama calon ini tidak dapat diproses lagi pada periode berjalan."
        noteLabel="Alasan pembatalan"
        noteRequired
        confirmLabel="Batalkan"
        tone="danger"
        busy={cancelMutation.isPending}
        errors={getValidationErrors(cancelMutation.error)}
        onClose={() => setIntent(null)}
        onSubmit={(note) => cancelMutation.mutate({ id: intent.registration.id, note })}
      />
    );
  } else if (intent?.kind === 'revision') {
    dialogs = (
      <RevisionModal
        open
        targets={intent.targets}
        busy={revisionMutation.isPending}
        errors={getValidationErrors(revisionMutation.error)}
        onClose={() => setIntent(null)}
        onSubmit={(note, targets) =>
          revisionMutation.mutate({ id: intent.registration.id, note, targets })
        }
      />
    );
  } else if (intent?.kind === 'transition') {
    dialogs = (
      <TransitionModal
        open
        allowed={intent.allowed}
        busy={transitionMutation.isPending}
        errors={getValidationErrors(transitionMutation.error)}
        onClose={() => setIntent(null)}
        onSubmit={(targetStatus, note) =>
          transitionMutation.mutate({ id: intent.registration.id, status: targetStatus, note })
        }
      />
    );
  } else if (intent?.kind === 'result') {
    dialogs = (
      <ResultModal
        open
        announcements={intent.announcements}
        busy={resultMutation.isPending}
        errors={getValidationErrors(resultMutation.error)}
        onClose={() => setIntent(null)}
        onSubmit={(result, note, announcementId) =>
          resultMutation.mutate({
            id: intent.registration.id,
            result,
            note,
            announcement_id: announcementId,
          })
        }
      />
    );
  } else if (intent?.kind === 're_register_open') {
    dialogs = (
      <ConfirmModal
        open
        title={`Buka daftar ulang — ${intent.registration.registration_number}`}
        description="Status bergeser ke Daftar Ulang dan tagihan daftar ulang diterbitkan otomatis."
        points={[
          'Orang tua menerima notifikasi untuk menyelesaikan daftar ulang.',
          'Calon tetap harus melunasi tagihan daftar ulang sebelum dijadikan siswa.',
        ]}
        confirmLabel="Buka daftar ulang"
        busy={reRegisterMutation.isPending}
        onClose={() => setIntent(null)}
        onConfirm={() => reRegisterMutation.mutate({ id: intent.registration.id, mode: 'open' })}
      />
    );
  } else if (intent?.kind === 're_register_complete') {
    dialogs = (
      <ConfirmModal
        open
        title={`Selesaikan daftar ulang — ${intent.registration.registration_number}`}
        description="Menandai seluruh syarat daftar ulang terpenuhi. Setelah selesai, pendaftaran dapat dikonversi menjadi siswa."
        acknowledgeLabel="Berkas daftar ulang dan pelunasan sudah saya periksa."
        confirmLabel="Selesaikan daftar ulang"
        busy={reRegisterMutation.isPending}
        onClose={() => setIntent(null)}
        onConfirm={() => reRegisterMutation.mutate({ id: intent.registration.id, mode: 'complete' })}
      />
    );
  } else if (intent?.kind === 'convert') {
    dialogs = (
      <ConvertModal
        open
        registration={intent.registration}
        busy={convertMutation.isPending}
        onClose={() => setIntent(null)}
        onConfirm={() => convertMutation.mutate(intent.registration.id)}
      />
    );
  }

  if (variant === 'queue') {
    return (
      <div className={cn('flex flex-wrap items-center gap-1.5', className)}>
        {decideActions}
        <Link
          to={`/ppdb/registrations/${registration.id}`}
          className="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
        >
          <ClipboardList className="h-3.5 w-3.5" /> Buka berkas
        </Link>
        {dialogs}
      </div>
    );
  }

  return (
    <div className={cn('flex flex-wrap items-center gap-1.5', className)}>
      {canDecide && decideActions}

      {status === 'selection' && (
        <Can perm="ppdb.announcement.manage">
          <Button
            size="sm"
            loading={resultMutation.isPending && intent?.kind === 'result'}
            onClick={() => setIntent({ kind: 'result', registration, announcements })}
          >
            <Trophy className="h-3.5 w-3.5" /> Tetapkan hasil
          </Button>
        </Can>
      )}

      {(status === 'passed' || status === 'waitlisted' || allowed.includes('re_registration')) &&
        status !== 're_registration' && (
          <Can perm="ppdb.registration.manage">
            <Button
              size="sm"
              variant="outline"
              loading={reRegisterMutation.isPending && intent?.kind === 're_register_open'}
              onClick={() => setIntent({ kind: 're_register_open', registration })}
            >
              <RotateCcw className="h-3.5 w-3.5" /> Buka daftar ulang
            </Button>
          </Can>
        )}

      {status === 're_registration' && (
        <Can perm="ppdb.registration.manage">
          <Button
            size="sm"
            loading={reRegisterMutation.isPending && intent?.kind === 're_register_complete'}
            onClick={() => setIntent({ kind: 're_register_complete', registration })}
          >
            <CheckCircle2 className="h-3.5 w-3.5" /> Selesaikan daftar ulang
          </Button>
        </Can>
      )}

      {!canDecide && status !== 'completed' && transitionAction}

      {status === 'completed' &&
        (registration.student_id ? (
          <Notice tone="green" className="px-2 py-1">
            Sudah menjadi siswa{registration.student?.nis ? ` — NIS ${registration.student.nis}` : ''}.
          </Notice>
        ) : (
          <Can perm="ppdb.registration.verify">
            <Button size="sm" loading={convertMutation.isPending} onClick={() => setIntent({ kind: 'convert', registration })}>
              <GraduationCap className="h-3.5 w-3.5" /> Konversi ke siswa
            </Button>
          </Can>
        ))}

      {!['completed', 'cancelled', 'failed'].includes(status) && (
        <Can perm="ppdb.registration.manage">
          <Button
            size="sm"
            variant="ghost"
            loading={cancelMutation.isPending && intent?.kind === 'cancel'}
            onClick={() => setIntent({ kind: 'cancel', registration })}
          >
            <Ban className="h-3.5 w-3.5" /> Batalkan
          </Button>
        </Can>
      )}

      {dialogs}
    </div>
  );
}

/* ============================ KONVERSI (dialog terpisah) ============================ */

function ConvertModal({
  open,
  registration,
  busy,
  onClose,
  onConfirm,
}: {
  open: boolean;
  registration: PpdbRegistration;
  busy: boolean;
  onClose: () => void;
  onConfirm: () => void;
}) {
  return (
    <ConfirmModal
      open={open}
      title={`Konversi menjadi siswa — ${registration.registration_number}`}
      description="Aksi ini menulis ke master siswa yang sudah dipakai modul Kesiswaan, bukan ke tabel PPDB saja."
      points={[
        `Calon atas nama ${registration.applicant?.full_name ?? '—'} akan terdaftar sebagai siswa baru dengan NIS dari sekolah.`,
        'Berkas, hasil seleksi, dan riwayat pembayaran pendaftaran menjadi bagian riwayat siswa tersebut.',
        'Aksi berada di luar modul PPDB dan tidak dapat dibatalkan dari halaman ini.',
      ]}
      warning="Pastikan daftar ulang selesai dan seluruh tagihan lunas sebelum konversi."
      acknowledgeLabel="Saya paham data siswa baru akan dibuat pada master data sekolah."
      confirmLabel="Konversi sekarang"
      busy={busy}
      onClose={onClose}
      onConfirm={onConfirm}
    />
  );
}
