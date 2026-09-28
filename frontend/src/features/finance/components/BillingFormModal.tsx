import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { financeApi } from '../api/financeApi';
import { formatRupiah } from '../lib/format';
import type { CreateBillingPayload } from '../api/financeApi';
import type { FinanceBillingItem } from '../types';

type BillingLine = { description: string; amount: string; quantity: string };

interface Props {
  open: boolean;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (payload: CreateBillingPayload) => void;
}

const freshLine = (): BillingLine => ({ description: '', amount: '', quantity: '1' });

export function BillingFormModal({ open, submitting, onClose, onSubmit }: Props) {
  const [studentSearch, setStudentSearch] = useState('');
  const studentsQuery = useQuery({
    queryKey: ['finance', 'billing-students', studentSearch],
    enabled: open,
    staleTime: 60_000,
    queryFn: () => financeApi.studentOptions(studentSearch),
  });
  const studentOptions = (studentsQuery.data ?? []).map((student) => ({
    value: student.id,
    label: `${student.nis} · ${student.full_name}`,
  }));
  const [studentId, setStudentId] = useState<number | ''>('');
  const [dueDate, setDueDate] = useState('');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<BillingLine[]>([freshLine()]);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setStudentId('');
    setStudentSearch('');
    setDueDate('');
    setNotes('');
    setLines([freshLine()]);
    setError('');
  }, [open]);

  const updateLine = (index: number, key: keyof BillingLine, value: string) => {
    setLines((current) => current.map((line, i) => i === index ? { ...line, [key]: value } : line));
  };

  const total = lines.reduce((sum, line) => sum + (Number(line.amount) || 0) * (Number(line.quantity) || 1), 0);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!studentId) {
      setError('Pilih siswa yang akan ditagih.');
      return;
    }
    if (lines.some((line) => !line.description.trim() || Number(line.amount) <= 0 || Number(line.quantity) < 1)) {
      setError('Lengkapi deskripsi, nominal, dan jumlah setiap baris dengan nilai yang valid.');
      return;
    }
    setError('');
    const items: FinanceBillingItem[] = lines.map((line) => ({
      description: line.description.trim(),
      amount: Number(line.amount),
      quantity: Number(line.quantity),
    }));
    onSubmit({
      customer_type: 'student',
      customer_id: Number(studentId),
      student_id: Number(studentId),
      due_date: dueDate || undefined,
      notes: notes.trim() || undefined,
      items,
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Buat Tagihan Siswa"
      size="lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Batal</Button>
          <Button type="submit" form="finance-billing-form" loading={submitting}>Simpan Tagihan</Button>
        </>
      }
    >
      <form id="finance-billing-form" onSubmit={submit} className="space-y-4">
        <div>
          <Label required>Siswa</Label>
          <SearchSelect
            options={studentOptions}
            value={studentId}
            onChange={(value) => setStudentId(value === '' ? '' : Number(value))}
            onSearchChange={setStudentSearch}
            placeholder="Cari dan pilih siswa"
          />
        </div>
        <div>
          <Label>Jatuh Tempo</Label>
          <Input type="date" value={dueDate} onChange={(event) => setDueDate(event.target.value)} />
        </div>
        <div className="space-y-3">
          <div className="flex items-center justify-between gap-2">
            <h4 className="text-sm font-semibold text-slate-800">Rincian Tagihan</h4>
            <Button type="button" size="sm" variant="outline" onClick={() => setLines((current) => [...current, freshLine()])}>
              <Plus className="h-3.5 w-3.5" /> Tambah Baris
            </Button>
          </div>
          {lines.map((line, index) => (
            <div key={index} className="grid grid-cols-1 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_130px_90px_36px]">
              <div>
                <Label required>Deskripsi</Label>
                <Input value={line.description} onChange={(event) => updateLine(index, 'description', event.target.value)} placeholder="Contoh: Biaya kegiatan" />
              </div>
              <div>
                <Label required>Nominal</Label>
                <Input type="number" min="0" step="1000" value={line.amount} onChange={(event) => updateLine(index, 'amount', event.target.value)} placeholder="0" />
              </div>
              <div>
                <Label required>Jumlah</Label>
                <Input type="number" min="1" step="1" value={line.quantity} onChange={(event) => updateLine(index, 'quantity', event.target.value)} />
              </div>
              <div className="flex items-end">
                <Button type="button" variant="ghost" size="icon" title="Hapus baris" disabled={lines.length === 1} onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                  <Trash2 className="h-4 w-4 text-red-500" />
                </Button>
              </div>
            </div>
          ))}
          <p className="text-right text-sm font-semibold text-slate-700">Total: {formatRupiah(total)}</p>
        </div>
        <div>
          <Label>Catatan</Label>
          <Textarea value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Catatan internal (opsional)" />
        </div>
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      </form>
    </Modal>
  );
}
