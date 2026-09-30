import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { getErrorMessage } from '@/lib/axios';
import { cbtApi } from '../api/cbtApi';

export default function CbtGradingPage() {
  const qc = useQueryClient();
  const list = useQuery({ queryKey: ['cbt', 'grading'], queryFn: cbtApi.grading });
  const [scores, setScores] = useState<Record<number, string>>({});
  const grade = useMutation({
    mutationFn: ({ id, awarded }: { id: number; awarded: number }) => cbtApi.grade(id, awarded),
    onSuccess: () => {
      toast.success('Nilai esai tersimpan');
      qc.invalidateQueries({ queryKey: ['cbt', 'grading'] });
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  });

  const rows = list.data?.data ?? [];

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Antrean penilaian esai</h1>
      {rows.map((row: { id: number; max_points: string; response: { text?: string } | null; question: { stem: string }; attempt: { student: { full_name: string }; session: { title: string } } }) => (
        <Card key={row.id}>
          <CardBody className="space-y-2">
            <p className="text-xs text-slate-500">{row.attempt?.session?.title} · {row.attempt?.student?.full_name}</p>
            <p className="text-sm font-medium">{row.question?.stem}</p>
            <p className="whitespace-pre-wrap rounded bg-slate-50 p-3 text-sm">{row.response?.text || '(kosong)'}</p>
            <div className="flex items-center gap-2">
              <Input className="w-24" placeholder={`0–${row.max_points}`} value={scores[row.id] ?? ''} onChange={(e) => setScores({ ...scores, [row.id]: e.target.value })} />
              <Button size="sm" onClick={() => grade.mutate({ id: row.id, awarded: Number(scores[row.id]) })}>Simpan</Button>
            </div>
          </CardBody>
        </Card>
      ))}
      {!list.isLoading && rows.length === 0 && <p className="text-sm text-slate-500">Tidak ada esai yang menunggu.</p>}
    </div>
  );
}
