import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { cbtApi } from '../api/cbtApi';

interface Q {
  id: number;
  type: string;
  stem: string;
  points: number;
  flagged: boolean;
  response: Record<string, unknown> | null;
  options: { key: string; text: string }[];
}

export default function CbtPlayerPage() {
  const { attemptId } = useParams();
  const location = useLocation();
  const nav = useNavigate();
  const initial = location.state as { attempt: { id: number; deadline_at: string }; session: { title: string }; questions: Q[] } | null;
  const [questions, setQuestions] = useState<Q[]>(initial?.questions ?? []);
  const [index, setIndex] = useState(0);
  const [deadline] = useState(initial?.attempt.deadline_at ?? '');
  const [now, setNow] = useState(Date.now());
  const [done, setDone] = useState<string | null>(null);
  const id = Number(attemptId);

  useEffect(() => {
    if (!initial) {
      toast.error('Buka ujian dari daftar ujian saya.');
      nav('/cbt/my');
    }
  }, [initial, nav]);

  useEffect(() => {
    const tick = window.setInterval(() => setNow(Date.now()), 1000);
    const beat = window.setInterval(() => {
      cbtApi.heartbeat(id).then((res) => {
        if (res.status !== 'in_progress') setDone(res.status);
      }).catch(() => undefined);
    }, 20000);
    const onHide = () => {
      if (document.hidden) cbtApi.incident(id, 'tab_blur', 'halaman disembunyikan').catch(() => undefined);
    };
    document.addEventListener('visibilitychange', onHide);
    return () => {
      window.clearInterval(tick);
      window.clearInterval(beat);
      document.removeEventListener('visibilitychange', onHide);
    };
  }, [id]);

  const remaining = useMemo(() => {
    if (!deadline) return '';
    const sec = Math.max(0, Math.floor((new Date(deadline).getTime() - now) / 1000));
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
  }, [deadline, now]);

  const current = questions[index];

  async function save(next: Record<string, unknown> | null, flagged = current?.flagged) {
    if (!current) return;
    setQuestions((qs) => qs.map((q) => (q.id === current.id ? { ...q, response: next, flagged: flagged ?? q.flagged } : q)));
    try {
      await cbtApi.save(id, current.id, next, flagged);
    } catch (e) {
      toast.error(getErrorMessage(e));
    }
  }

  async function submit() {
    try {
      const res = await cbtApi.submit(id);
      setDone(res.status);
      toast.success('Ujian dikumpulkan');
    } catch (e) {
      toast.error(getErrorMessage(e));
    }
  }

  if (done) {
    return (
      <Card>
        <CardBody className="space-y-2">
          <h1 className="text-lg font-semibold">Ujian selesai</h1>
          <p className="text-sm text-slate-600">Status: {done}. Nilai muncul setelah pengawas merilis hasil, atau langsung bila sesi mengizinkan.</p>
          <Button onClick={() => nav('/cbt/my')}>Kembali</Button>
        </CardBody>
      </Card>
    );
  }

  if (!current) return null;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">{initial?.session.title}</h1>
        <span className="rounded-full bg-slate-900 px-3 py-1 text-sm text-white">{remaining}</span>
      </div>
      <div className="flex flex-wrap gap-1">
        {questions.map((q, i) => (
          <button key={q.id} type="button" onClick={() => setIndex(i)} className={`h-8 w-8 rounded text-xs ${i === index ? 'bg-brand-600 text-white' : q.response ? 'bg-emerald-100' : 'bg-slate-100'}`}>
            {i + 1}
          </button>
        ))}
      </div>
      <Card>
        <CardBody className="space-y-4">
          <p className="text-sm text-slate-500">Soal {index + 1} · {current.points} poin · {current.type}</p>
          <p className="whitespace-pre-wrap text-slate-900">{current.stem}</p>
          {['multiple_choice', 'true_false'].includes(current.type) && current.options.map((opt) => (
            <label key={opt.key} className="flex items-center gap-2 text-sm">
              <input type="radio" name={`q${current.id}`} checked={(current.response as { key?: string } | null)?.key === opt.key} onChange={() => save({ key: opt.key })} />
              <span>{opt.key}. {opt.text}</span>
            </label>
          ))}
          {current.type === 'multi_select' && current.options.map((opt) => {
            const keys = ((current.response as { keys?: string[] } | null)?.keys ?? []);
            return (
              <label key={opt.key} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={keys.includes(opt.key)} onChange={(e) => {
                  const next = e.target.checked ? [...keys, opt.key] : keys.filter((k) => k !== opt.key);
                  save({ keys: next });
                }} />
                <span>{opt.key}. {opt.text}</span>
              </label>
            );
          })}
          {current.type === 'short_answer' && (
            <Input value={String((current.response as { text?: string } | null)?.text ?? '')} onChange={(e) => save({ text: e.target.value })} />
          )}
          {current.type === 'numeric' && (
            <Input type="number" value={String((current.response as { value?: number } | null)?.value ?? '')} onChange={(e) => save({ value: e.target.value === '' ? null : Number(e.target.value) })} />
          )}
          {current.type === 'essay' && (
            <Textarea value={String((current.response as { text?: string } | null)?.text ?? '')} onChange={(e) => save({ text: e.target.value })} />
          )}
          {current.type === 'matching' && current.options.map((opt) => (
            <div key={opt.key} className="grid grid-cols-2 gap-2 text-sm">
              <span>{opt.key}. {opt.text}</span>
              <Input value={String(((current.response as { pairs?: Record<string, string> } | null)?.pairs ?? {})[opt.key] ?? '')} onChange={(e) => {
                const pairs = { ...((current.response as { pairs?: Record<string, string> } | null)?.pairs ?? {}), [opt.key]: e.target.value.toUpperCase() };
                save({ pairs });
              }} />
            </div>
          ))}
          <div className="flex justify-between">
            <Button variant="ghost" disabled={index === 0} onClick={() => setIndex(index - 1)}>Sebelumnya</Button>
            {index < questions.length - 1 ? (
              <Button onClick={() => setIndex(index + 1)}>Berikutnya</Button>
            ) : (
              <Button onClick={submit}>Kumpulkan</Button>
            )}
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
