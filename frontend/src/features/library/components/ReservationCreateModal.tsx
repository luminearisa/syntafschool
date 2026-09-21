import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Info } from 'lucide-react';
import { libraryCatalogApi, libraryMembersApi } from '../api/libraryApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import type { SelectOption } from '@/constants/masterData';

interface Props {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: { member_id?: number | ''; bibliography_id: number | ''; notes?: string }) => void;
}

/**
 * Form pembuatan reservasi.
 *
 * Anggota bersifat opsional: staf dapat membuat reservasi atas namanya sendiri
 * (kosongkan pemilih anggota). Reservasi hanya untuk judul yang belum punya
 * eksemplar tersedia.
 */
export function ReservationCreateModal({ open, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [memberId, setMemberId] = useState<number | ''>('');
  const [bibliographyId, setBibliographyId] = useState<number | ''>('');
  const [notes, setNotes] = useState('');
  const [localErrors, setLocalErrors] = useState<Record<string, string>>({});

  const membersQuery = useQuery({
    queryKey: ['library', 'reservations', 'options', 'members'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => libraryMembersApi.list({ all: true }),
  });

  const recordsQuery = useQuery({
    queryKey: ['library', 'reservations', 'options', 'records'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => libraryCatalogApi.list({ all: true }),
  });

  const memberOptions: SelectOption[] = useMemo(
    () =>
      (membersQuery.data?.data ?? []).map((m) => ({
        value: m.id,
        label: `${m.member_number} — ${m.display_name ?? m.name ?? 'Tanpa nama'}`,
      })),
    [membersQuery.data],
  );

  const recordOptions: SelectOption[] = useMemo(
    () =>
      (recordsQuery.data?.data ?? []).map((r) => ({
        value: r.id,
        label: r.control_number ? `${r.control_number} — ${r.title}` : r.title,
      })),
    [recordsQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setMemberId('');
    setBibliographyId('');
    setNotes('');
    setLocalErrors({});
  }, [open]);

  const err = (key: string) => localErrors[key] || serverErrors?.[key]?.[0];

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (bibliographyId === '') {
      setLocalErrors({ bibliography_id: 'Judul wajib dipilih' });
      return;
    }
    setLocalErrors({});
    onSubmit({ member_id: memberId, bibliography_id: bibliographyId, notes: notes || undefined });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="md"
      title="Buat Reservasi"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-reservation-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="library-reservation-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50/60 p-3">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
          <p className="text-xs text-slate-500">
            Kosongkan pemilih anggota untuk membuat reservasi atas nama Anda sendiri. Reservasi hanya
            untuk judul tanpa eksemplar tersedia.
          </p>
        </div>

        <div>
          <Label>Anggota (opsional)</Label>
          <SearchSelect
            options={memberOptions}
            value={memberId}
            error={err('member_id')}
            placeholder="-- Untuk saya sendiri --"
            onChange={(v) => setMemberId(v === '' ? '' : Number(v))}
          />
        </div>

        <div>
          <Label required>Judul</Label>
          <SearchSelect
            options={recordOptions}
            value={bibliographyId}
            error={err('bibliography_id')}
            placeholder="-- Pilih judul --"
            onChange={(v) => setBibliographyId(v === '' ? '' : Number(v))}
          />
          <p className="mt-1 text-xs text-slate-400">Hanya untuk judul tanpa eksemplar tersedia.</p>
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            value={notes}
            error={err('notes')}
            placeholder="Opsional"
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}