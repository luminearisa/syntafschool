import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { getErrorMessage } from '@/lib/axios';
import { cbtApi } from '../api/cbtApi';

export default function CbtBlueprintsPage() {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    name: '',
    duration_minutes: '60',
    question_count: '10',
    passing_score: '70',
    topic: '',
    count: '10',
    points: '1',
  });

  const list = useQuery({ queryKey: ['cbt', 'blueprints'], queryFn: cbtApi.blueprints });
  const create = useMutation({
    mutationFn: () =>
      cbtApi.createBlueprint({
        name: form.name,
        duration_minutes: Number(form.duration_minutes),
        question_count: Number(form.question_count),
        passing_score: Number(form.passing_score),
        shuffle_questions: true,
        shuffle_options: true,
        attempts_allowed: 1,
        rules: form.topic
          ? [{ topic: form.topic, count: Number(form.count), points: Number(form.points) }]
          : [],
      }),
    onSuccess: () => {
      toast.success('Kisi tersimpan');
      setForm((f) => ({ ...f, name: '' }));
      qc.invalidateQueries({ queryKey: ['cbt', 'blueprints'] });
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  });

  const rows = list.data?.data ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Kisi ujian</h1>
        <p className="text-sm text-slate-500">Aturan penarikan acak dari bank soal menurut topik, plus butir wajib.</p>
      </div>
      <Card>
        <CardBody className="grid gap-3 md:grid-cols-3">
          <Input className="md:col-span-3" placeholder="Nama kisi" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Input placeholder="Durasi (menit)" value={form.duration_minutes} onChange={(e) => setForm({ ...form, duration_minutes: e.target.value })} />
          <Input placeholder="Jumlah soal" value={form.question_count} onChange={(e) => setForm({ ...form, question_count: e.target.value })} />
          <Input placeholder="KKM %" value={form.passing_score} onChange={(e) => setForm({ ...form, passing_score: e.target.value })} />
          <Input placeholder="Topik ditarik (opsional)" value={form.topic} onChange={(e) => setForm({ ...form, topic: e.target.value })} />
          <Input placeholder="Jumlah dari topik" value={form.count} onChange={(e) => setForm({ ...form, count: e.target.value })} />
          <Input placeholder="Poin per butir" value={form.points} onChange={(e) => setForm({ ...form, points: e.target.value })} />
          <div className="md:col-span-3">
            <Button loading={create.isPending} disabled={!form.name.trim()} onClick={() => create.mutate()}>Simpan kisi</Button>
          </div>
        </CardBody>
      </Card>
      <div className="space-y-2">
        {rows.map((row: { id: number; name: string; duration_minutes: number; question_count: number; passing_score: string; rules_count: number; items_count: number }) => (
          <Card key={row.id}>
            <CardBody className="flex justify-between text-sm">
              <span className="font-medium">{row.name}</span>
              <span className="text-slate-500">{row.duration_minutes} mnt · {row.question_count} soal · KKM {row.passing_score}% · {row.rules_count} aturan</span>
            </CardBody>
          </Card>
        ))}
      </div>
    </div>
  );
}
