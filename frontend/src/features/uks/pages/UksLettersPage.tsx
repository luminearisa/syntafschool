import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, Plus } from 'lucide-react';
import { toast } from 'sonner';
import { uksApi } from '../api/uksApi';
import { StudentPicker } from '../components/StudentPicker';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { api, getErrorMessage } from '@/lib/axios';

/** Surat keterangan UKS (istirahat/sakit/rujukan) beserta unduhan PDF-nya. */
export default function UksLettersPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [studentId, setStudentId] = useState<number | null>(null);
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({ letter_type: 'ISTIRAHAT', date_from: today, date_to: today, diagnosis_note: '', recommendation: '' });

  const meta = useQuery({ queryKey: ['uks', 'meta'], queryFn: () => uksApi.metadata() });
  const letters = useQuery({
    queryKey: ['uks', 'letters', page],
    queryFn: () => uksApi.letters({ page }),
  });

  const create = useMutation({
    mutationFn: () => uksApi.createLetter({ student_id: studentId, ...form }),
    onSuccess: () => {
      toast.success('Surat diterbitkan');
      setCreating(false);
      queryClient.invalidateQueries({ queryKey: ['uks', 'letters'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const download = async (id: number, number: string) => {
    try {
      const response = await api.get(`/uks/letters/${id}/download`, { responseType: 'blob' });
      const url = URL.createObjectURL(response.data as Blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `surat-uks-${number}.pdf`;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (error) {
      toast.error(getErrorMessage(error));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Surat Keterangan</h1>
          <p className="text-xs text-slate-400">Setiap surat punya kode verifikasi yang bisa diperiksa pihak luar.</p>
        </div>
        <Can perm="uks.letter.issue">
          <Button onClick={() => setCreating(true)}><Plus className="mr-1 h-4 w-4" /> Terbitkan</Button>
        </Can>
      </div>

      <Card>
        <CardBody className="p-0">
          {letters.isLoading ? <Spinner /> : null}
          {letters.data && letters.data.data.length === 0 ? (
            <EmptyState title="Belum ada surat" description="Surat istirahat, keterangan sakit, dan rujukan muncul di sini." />
          ) : null}
          <ul className="divide-y divide-slate-100">
            {letters.data?.data.map((letter) => (
              <li key={letter.id} className="flex items-center justify-between gap-3 px-4 py-3">
                <div>
                  <p className="text-sm font-medium">
                    {letter.student?.full_name ?? `Siswa #${letter.student_id}`}
                    <span className="ml-2 text-xs text-slate-400">{letter.number}</span>
                  </p>
                  <p className="text-xs text-slate-500">
                    {letter.date_from} s.d. {letter.date_to} · kode {letter.verification_code}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone="blue">{letter.letter_type}</Badge>
                  <Button variant="ghost" size="sm" onClick={() => download(letter.id, letter.number)}>
                    <Download className="h-4 w-4" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
          {letters.data ? <Pagination meta={letters.data.meta} onPageChange={setPage} /> : null}
        </CardBody>
      </Card>

      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title="Terbitkan Surat"
        footer={<><Button variant="ghost" onClick={() => setCreating(false)}>Batal</Button>
          <Button loading={create.isPending} disabled={!studentId} onClick={() => create.mutate()}>Terbitkan</Button></>}
      >
        <div className="space-y-3">
          <StudentPicker value={studentId} onChange={(id) => setStudentId(id)} />
          <div>
            <Label>Jenis surat</Label>
            <Select value={form.letter_type} onChange={(e) => setForm({ ...form, letter_type: e.target.value })}>
              {meta.data?.letter_types.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label>Dari</Label><Input type="date" value={form.date_from} onChange={(e) => setForm({ ...form, date_from: e.target.value })} /></div>
            <div><Label>Sampai</Label><Input type="date" value={form.date_to} onChange={(e) => setForm({ ...form, date_to: e.target.value })} /></div>
          </div>
          <div><Label>Keterangan</Label><Textarea rows={2} value={form.diagnosis_note} onChange={(e) => setForm({ ...form, diagnosis_note: e.target.value })} /></div>
          <div><Label>Anjuran</Label><Textarea rows={2} value={form.recommendation} onChange={(e) => setForm({ ...form, recommendation: e.target.value })} /></div>
        </div>
      </Modal>
    </div>
  );
}
