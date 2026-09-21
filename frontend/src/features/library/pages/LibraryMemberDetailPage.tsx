import { useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, History, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryMembersApi } from '../api/libraryApi';
import { MemberCardPanel, MemberRenewModal } from '../components/MemberRenewModal';
import { MemberFormModal } from '../components/MemberFormModal';
import { LINKED_TYPE_LABELS, MEMBER_STATUS_LABELS, memberStatusTone } from '../lib/catalogLabels';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDate } from '@/lib/utils';
import type { LibraryMember, LibraryMemberPayload, MemberStatus } from '@/types/libraryCatalog';

const STATUS_OPTIONS = Object.keys(MEMBER_STATUS_LABELS) as MemberStatus[];

function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-slate-50 py-2 last:border-0 sm:flex-row sm:items-start sm:gap-3">
      <span className="w-44 shrink-0 text-xs font-medium text-slate-500">{label}</span>
      <span className="text-sm text-slate-800">{children}</span>
    </div>
  );
}

/** Nama & nomor identitas entitas tertaut (NIS/NIP) bila tersedia. */
function linkedEntity(member: LibraryMember): { name: string; identifier: string | null } {
  switch (member.linked_type) {
    case 'student':
      return { name: member.student?.full_name ?? '—', identifier: member.student?.nis ?? null };
    case 'employee':
      return { name: member.employee?.full_name ?? '—', identifier: member.employee?.nip ?? null };
    case 'guardian':
      return { name: member.guardian?.full_name ?? '—', identifier: null };
    case 'user':
      return { name: member.user?.name ?? '—', identifier: null };
    default:
      return { name: member.name ?? '—', identifier: null };
  }
}

/* ============================ MODAL UBAH STATUS ============================ */

function StatusModal({
  open,
  current,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  current: MemberStatus;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (status: MemberStatus, reason: string) => void;
}) {
  const [status, setStatus] = useState<MemberStatus>(current);
  const [reason, setReason] = useState('');

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    onSubmit(status, reason.trim());
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ubah Status Keanggotaan"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-member-status-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="library-member-status-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Status</Label>
          <Select
            value={status}
            error={serverErrors?.status?.[0]}
            onChange={(e) => setStatus(e.target.value as MemberStatus)}
          >
            {STATUS_OPTIONS.map((key) => (
              <option key={key} value={key}>
                {MEMBER_STATUS_LABELS[key]}
              </option>
            ))}
          </Select>
          <p className="mt-1 text-xs text-slate-400">
            Perubahan status tidak menghapus data keanggotaan.
          </p>
        </div>
        <div>
          <Label>Alasan</Label>
          <Textarea
            rows={3}
            value={reason}
            error={serverErrors?.reason?.[0]}
            placeholder="Alasan perubahan status (opsional)"
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Detail satu anggota perpustakaan (§38).
 *
 * Identitas anggota ditarik dari entitas tertaut; kartu dikelola lewat
 * `MemberCardPanel`. Aktivitas sirkulasi (peminjaman/riwayat/reservasi) baru
 * tersedia pada modul Circulation (Fase 4).
 */
export function LibraryMemberDetailPage() {
  const params = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const memberId = Number(params.id);

  const [formOpen, setFormOpen] = useState(false);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [renewOpen, setRenewOpen] = useState(false);
  const [renewErrors, setRenewErrors] = useState<Record<string, string[]>>({});
  const [statusOpen, setStatusOpen] = useState(false);
  const [statusErrors, setStatusErrors] = useState<Record<string, string[]>>({});
  const [deleteOpen, setDeleteOpen] = useState(false);

  const memberQuery = useQuery({
    queryKey: ['library', 'members', memberId],
    enabled: Number.isFinite(memberId),
    queryFn: () => libraryMembersApi.show(memberId),
  });

  const member = memberQuery.data;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library', 'members'] });

  const saveMutation = useMutation({
    mutationFn: (payload: LibraryMemberPayload) => libraryMembersApi.update(memberId, payload),
    onSuccess: () => {
      toast.success('Anggota diperbarui');
      setFormOpen(false);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const renewMutation = useMutation({
    mutationFn: ({ validUntil, validFrom }: { validUntil: string; validFrom?: string }) =>
      libraryMembersApi.renew(memberId, validUntil, validFrom),
    onSuccess: (res) => {
      toast.success(res.message);
      setRenewOpen(false);
      setRenewErrors({});
      invalidate();
    },
    onError: (err) => {
      setRenewErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const statusMutation = useMutation({
    mutationFn: ({ status, reason }: { status: MemberStatus; reason: string }) =>
      libraryMembersApi.setStatus(memberId, status, reason || undefined),
    onSuccess: (res) => {
      toast.success(res.message);
      setStatusOpen(false);
      setStatusErrors({});
      invalidate();
    },
    onError: (err) => {
      setStatusErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: () => libraryMembersApi.remove(memberId),
    onSuccess: () => {
      toast.success('Anggota dihapus');
      setDeleteOpen(false);
      navigate('/library/members');
    },
    onError: (err) => {
      const errors = getValidationErrors(err);
      const first = errors ? Object.values(errors)[0]?.[0] : undefined;
      toast.error(first ?? getErrorMessage(err));
    },
  });

  const displayName = member?.display_name ?? member?.name ?? 'Detail Anggota';
  const linked = member ? linkedEntity(member) : null;
  const initials = displayName
    .split(' ')
    .map((part) => part.charAt(0))
    .slice(0, 2)
    .join('')
    .toUpperCase();

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <Link
            to="/library/members"
            className="mb-1 inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700"
          >
            <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke anggota
          </Link>
          <h1 className="text-lg font-semibold text-slate-900">{displayName}</h1>
          {member && (
            <p className="font-mono text-xs text-slate-500">{member.member_number}</p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => memberQuery.refetch()}>
            <RefreshCw className={memberQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.membership.update">
            <Button
              variant="outline"
              disabled={!member}
              onClick={() => {
                setRenewErrors({});
                setRenewOpen(true);
              }}
            >
              Perpanjang
            </Button>
            <Button
              variant="outline"
              disabled={!member}
              onClick={() => {
                setStatusErrors({});
                setStatusOpen(true);
              }}
            >
              Ubah Status
            </Button>
          </Can>
          <Can perm="library.member.update">
            <Button
              disabled={!member}
              onClick={() => {
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Pencil className="h-4 w-4" /> Edit
            </Button>
          </Can>
          <Can perm="library.member.delete">
            <Button variant="danger" disabled={!member} onClick={() => setDeleteOpen(true)}>
              <Trash2 className="h-4 w-4" /> Hapus
            </Button>
          </Can>
        </div>
      </div>

      {memberQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat anggota...
          </div>
        </Card>
      ) : memberQuery.isError || !member ? (
        <Card>
          <EmptyState
            title="Gagal memuat anggota"
            description={getErrorMessage(memberQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => memberQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card className="p-4">
            <h2 className="mb-3 text-sm font-semibold text-slate-900">Identitas</h2>
            <div className="mb-3 flex items-center gap-3">
              {member.photo_path ? (
                <img
                  src={member.photo_path}
                  alt={displayName}
                  className="h-20 w-20 rounded-full object-cover"
                />
              ) : (
                <div className="flex h-20 w-20 items-center justify-center rounded-full bg-slate-100 text-lg font-semibold text-slate-500">
                  {initials || '—'}
                </div>
              )}
              <div>
                <div className="font-medium text-slate-800">{displayName}</div>
                <div className="font-mono text-xs text-slate-500">{member.member_number}</div>
              </div>
            </div>
            <InfoRow label="Tipe Tautan">
              <Badge tone="gray">
                {LINKED_TYPE_LABELS[member.linked_type] ?? member.linked_type}
              </Badge>
            </InfoRow>
            <InfoRow label="Entitas Tertaut">{linked?.name ?? '—'}</InfoRow>
            {linked?.identifier && (
              <InfoRow
                label={member.linked_type === 'employee' ? 'NIP' : 'NIS'}
              >
                <span className="font-mono text-xs">{linked.identifier}</span>
              </InfoRow>
            )}
          </Card>

          <Card className="p-4">
            <h2 className="mb-2 text-sm font-semibold text-slate-900">Keanggotaan</h2>
            <InfoRow label="Perpustakaan">{member.library?.name ?? '—'}</InfoRow>
            <InfoRow label="Jenis Keanggotaan">{member.membership_type?.name ?? '—'}</InfoRow>
            <InfoRow label="Kebijakan Pinjam">
              {member.membership_type ? (
                <span className="text-slate-600">
                  Maks {member.membership_type.max_loan} eksemplar ·{' '}
                  {member.membership_type.loan_duration_days} hari
                  <span className="ml-1 text-xs text-slate-400">(policy referensi — Fase 4)</span>
                </span>
              ) : (
                '—'
              )}
            </InfoRow>
            <InfoRow label="Terdaftar">{formatDate(member.registered_at)}</InfoRow>
            <InfoRow label="Berlaku Dari">{formatDate(member.valid_from)}</InfoRow>
            <InfoRow label="Berlaku Sampai">{formatDate(member.valid_until)}</InfoRow>
            <InfoRow label="Status">
              <Badge tone={memberStatusTone(member.status)}>
                {MEMBER_STATUS_LABELS[member.status] ?? member.status}
              </Badge>
            </InfoRow>
            <InfoRow label="Dalam Masa Berlaku">
              {member.is_within_validity ? 'Ya' : 'Tidak'}
            </InfoRow>
            <InfoRow label="Catatan">{member.notes ?? '—'}</InfoRow>
          </Card>

          <Card className="p-4">
            <h2 className="mb-2 text-sm font-semibold text-slate-900">Kontak</h2>
            {member.linked_type !== 'public' && (
              <p className="mb-2 text-xs text-slate-400">
                Biodata ditarik dari entitas tertaut — nilai di bawah hanya untuk referensi kontak.
              </p>
            )}
            <InfoRow label="Telepon">{orDash(member.phone)}</InfoRow>
            <InfoRow label="Email">{orDash(member.email)}</InfoRow>
            <InfoRow label="Nomor Identitas">{orDash(member.identity_number)}</InfoRow>
            <InfoRow label="Alamat">{orDash(member.address)}</InfoRow>
          </Card>

          <div className="space-y-4">
            <MemberCardPanel memberId={memberId} />

            <Card className="p-4">
              <div className="flex items-center gap-2">
                <History className="h-4 w-4 text-slate-400" />
                <h2 className="text-sm font-semibold text-slate-900">Aktivitas</h2>
              </div>
              <p className="mt-2 text-sm text-slate-500">
                Peminjaman aktif, riwayat, dan reservasi anggota akan tampil di sini setelah modul
                Sirkulasi (Fase 4) tersedia.
              </p>
            </Card>
          </div>
        </div>
      )}

      <MemberFormModal
        open={formOpen}
        editing={member ?? null}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => setFormOpen(false)}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <MemberRenewModal
        open={renewOpen}
        member={member ?? null}
        submitting={renewMutation.isPending}
        serverErrors={renewErrors}
        onClose={() => setRenewOpen(false)}
        onSubmit={(validUntil, validFrom) => renewMutation.mutate({ validUntil, validFrom })}
      />

      {member && (
        <StatusModal
          open={statusOpen}
          current={member.status}
          submitting={statusMutation.isPending}
          serverErrors={statusErrors}
          onClose={() => setStatusOpen(false)}
          onSubmit={(status, reason) => statusMutation.mutate({ status, reason })}
        />
      )}

      <ConfirmDialog
        open={deleteOpen}
        title="Hapus Anggota"
        message={`Hapus anggota "${displayName}" (${member?.member_number ?? ''})? Anggota yang masih memiliki kartu tidak dapat dihapus.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  );
}