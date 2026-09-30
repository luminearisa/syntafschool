import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { getErrorMessage } from '@/lib/axios';
import { cbtApi } from '../api/cbtApi';

export default function CbtMyExamsPage() {
  const nav = useNavigate();
  const { data, isLoading } = useQuery({ queryKey: ['cbt', 'mine'], queryFn: cbtApi.mySessions });
  const [tokens, setTokens] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState<number | null>(null);

  async function start(id: number) {
    setBusy(id);
    try {
      const player = await cbtApi.start(id, tokens[id]);
      nav(`/cbt/play/${player.attempt.id}`, { state: player });
    } catch (e) {
      toast.error(getErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Ujian saya</h1>
      {isLoading && <p className="text-sm text-slate-500">Memuat…</p>}
      {(data ?? []).map((row: { id: number; title: string; status: string; subject: string | null; require_token: boolean; starts_at: string | null; attempt: { id: number; status: string; percentage: number | null } | null }) => (
        <Card key={row.id}>
          <CardBody className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="font-medium">{row.title}</p>
              <p className="text-xs text-slate-500">{row.subject ?? 'Umum'} · {row.status}{row.attempt ? ` · percobaan ${row.attempt.status}` : ''}</p>
              {row.attempt?.percentage != null && <p className="text-sm">Nilai {row.attempt.percentage}%</p>}
            </div>
            {row.status === 'open' && row.attempt?.status !== 'graded' && row.attempt?.status !== 'submitted' && (
              <div className="flex items-center gap-2">
                {row.require_token && (
                  <Input className="w-28 uppercase" placeholder="Token" value={tokens[row.id] ?? ''} onChange={(e) => setTokens({ ...tokens, [row.id]: e.target.value.toUpperCase() })} />
                )}
                <Button size="sm" loading={busy === row.id} onClick={() => start(row.id)}>
                  {row.attempt?.status === 'in_progress' ? 'Lanjutkan' : 'Mulai'}
                </Button>
              </div>
            )}
          </CardBody>
        </Card>
      ))}
      {!isLoading && (data ?? []).length === 0 && <p className="text-sm text-slate-500">Tidak ada ujian untuk akun ini.</p>}
    </div>
  );
}
