import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { walletApi } from '../api/walletApi';

export function WalletCreateModal({
  open,
  submitting,
  onClose,
  onSubmit,
}: {
  open: boolean;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (payload: { student_id: number; notes?: string }) => void;
}) {
  const [search, setSearch] = useState('');
  const [studentId, setStudentId] = useState<number | ''>('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');
  const studentsQuery = useQuery({
    queryKey: ['wallet', 'student-options', search],
    enabled: open,
    staleTime: 60_000,
    queryFn: () => walletApi.studentOptions(search),
  });
  const options = (studentsQuery.data ?? []).map((student) => ({ value: student.id, label: `${student.nis} · ${student.full_name}` }));

  useEffect(() => {
    if (!open) return;
    setSearch('');
    setStudentId('');
    setNotes('');
    setError('');
  }, [open]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!studentId) {
      setError('Pilih siswa yang akan dibuatkan wallet.');
      return;
    }
    setError('');
    onSubmit({ student_id: Number(studentId), notes: notes.trim() || undefined });
  };

  return (
    <Modal open={open} onClose={onClose} title="Buka Wallet Siswa" footer={<><Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Batal</Button><Button type="submit" form="wallet-create-form" loading={submitting}>Buka Wallet</Button></>}>
      <form id="wallet-create-form" onSubmit={submit} className="space-y-4">
        <div>
          <Label required>Siswa</Label>
          <SearchSelect
            options={options}
            value={studentId}
            onChange={(value) => setStudentId(value === '' ? '' : Number(value))}
            onSearchChange={setSearch}
            placeholder="Cari nama atau NIS siswa"
          />
          {studentsQuery.isLoading && <p className="mt-1 text-xs text-slate-500">Memuat pilihan siswa...</p>}
          {studentsQuery.isError && <p className="mt-1 text-xs text-red-600">Gagal memuat siswa. Coba cari lagi.</p>}
        </div>
        <div>
          <Label>Catatan Internal</Label>
          <Input value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Catatan petugas (opsional)" />
        </div>
        <p className="rounded-lg bg-blue-50 p-3 text-xs text-blue-800">Wallet baru dibuka dengan saldo nol. Saldo hanya dapat berubah melalui transaksi ledger yang terverifikasi.</p>
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      </form>
    </Modal>
  );
}
