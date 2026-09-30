import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { QUESTION_TYPES, cbtApi } from '../api/cbtApi';

const emptyOption = () => ({ option_key: 'A', text: '', is_correct: false, match_key: '' });

export default function CbtQuestionsPage() {
  const qc = useQueryClient();
  const [q, setQ] = useState('');
  const [form, setForm] = useState({
    question_type: 'multiple_choice',
    difficulty: 'medium',
    topic: '',
    stem: '',
    points: '1',
    accepted: '',
    expected: '',
    tolerance: '0',
  });
  const [options, setOptions] = useState([
    { option_key: 'A', text: '', is_correct: true, match_key: '' },
    { option_key: 'B', text: '', is_correct: false, match_key: '' },
  ]);

  const list = useQuery({
    queryKey: ['cbt', 'questions', q],
    queryFn: () => cbtApi.questions({ q: q || undefined, per_page: 20 }),
  });

  const create = useMutation({
    mutationFn: () => {
      const type = form.question_type;
      const payload: Record<string, unknown> = {};
      if (type === 'short_answer') payload.accepted = form.accepted.split('|').map((s) => s.trim()).filter(Boolean);
      if (type === 'numeric') {
        payload.expected = Number(form.expected);
        payload.tolerance = Number(form.tolerance || 0);
      }
      const needsOptions = ['multiple_choice', 'multi_select', 'true_false', 'matching', 'ordering'].includes(type);
      return cbtApi.createQuestion({
        question_type: type,
        difficulty: form.difficulty,
        topic: form.topic || null,
        stem: form.stem,
        points: Number(form.points || 1),
        payload: Object.keys(payload).length ? payload : null,
        options: needsOptions ? options.map((o, i) => ({ ...o, position: i, match_key: o.match_key || null })) : [],
      });
    },
    onSuccess: () => {
      toast.success('Soal tersimpan');
      setForm((f) => ({ ...f, stem: '' }));
      qc.invalidateQueries({ queryKey: ['cbt', 'questions'] });
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Bank soal CBT</h1>
        <p className="text-sm text-slate-500">Delapan tipe soal. Perubahan menyimpan revisi, bukan menimpa riwayat.</p>
      </div>

      <Card>
        <CardBody className="space-y-3">
          <div className="grid gap-3 md:grid-cols-4">
            <Select value={form.question_type} onChange={(e) => setForm({ ...form, question_type: e.target.value })}>
              {QUESTION_TYPES.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </Select>
            <Select value={form.difficulty} onChange={(e) => setForm({ ...form, difficulty: e.target.value })}>
              <option value="easy">Mudah</option>
              <option value="medium">Sedang</option>
              <option value="hard">Sulit</option>
            </Select>
            <Input placeholder="Topik" value={form.topic} onChange={(e) => setForm({ ...form, topic: e.target.value })} />
            <Input placeholder="Poin" value={form.points} onChange={(e) => setForm({ ...form, points: e.target.value })} />
          </div>
          <Textarea placeholder="Naskah soal" value={form.stem} onChange={(e) => setForm({ ...form, stem: e.target.value })} />
          {form.question_type === 'short_answer' && (
            <Input placeholder="Jawaban diterima, pisahkan dengan |" value={form.accepted} onChange={(e) => setForm({ ...form, accepted: e.target.value })} />
          )}
          {form.question_type === 'numeric' && (
            <div className="grid grid-cols-2 gap-3">
              <Input placeholder="Nilai benar" value={form.expected} onChange={(e) => setForm({ ...form, expected: e.target.value })} />
              <Input placeholder="Toleransi" value={form.tolerance} onChange={(e) => setForm({ ...form, tolerance: e.target.value })} />
            </div>
          )}
          {['multiple_choice', 'multi_select', 'true_false', 'matching', 'ordering'].includes(form.question_type) && (
            <div className="space-y-2">
              {options.map((opt, i) => (
                <div key={i} className="grid grid-cols-12 gap-2">
                  <Input className="col-span-2" value={opt.option_key} onChange={(e) => {
                    const next = [...options];
                    next[i] = { ...opt, option_key: e.target.value.toUpperCase() };
                    setOptions(next);
                  }} />
                  <Input className="col-span-6" placeholder="Teks opsi" value={opt.text} onChange={(e) => {
                    const next = [...options];
                    next[i] = { ...opt, text: e.target.value };
                    setOptions(next);
                  }} />
                  <label className="col-span-2 flex items-center gap-1 text-xs text-slate-600">
                    <input type="checkbox" checked={opt.is_correct} onChange={(e) => {
                      const next = options.map((o, idx) => ({
                        ...o,
                        is_correct: form.question_type === 'multi_select' ? (idx === i ? e.target.checked : o.is_correct) : idx === i,
                      }));
                      setOptions(next);
                    }} />
                    benar
                  </label>
                  {form.question_type === 'matching' && (
                    <Input className="col-span-2" placeholder="jodoh" value={opt.match_key} onChange={(e) => {
                      const next = [...options];
                      next[i] = { ...opt, match_key: e.target.value };
                      setOptions(next);
                    }} />
                  )}
                </div>
              ))}
              <Button type="button" variant="ghost" size="sm" onClick={() => setOptions([...options, { ...emptyOption(), option_key: String.fromCharCode(65 + options.length) }])}>
                Tambah opsi
              </Button>
            </div>
          )}
          <Button loading={create.isPending} onClick={() => create.mutate()} disabled={!form.stem.trim()}>
            Simpan soal
          </Button>
        </CardBody>
      </Card>

      <Input placeholder="Cari naskah…" value={q} onChange={(e) => setQ(e.target.value)} />
      <div className="space-y-2">
        {(list.data?.data ?? []).map((item) => (
          <Card key={item.id}>
            <CardBody className="flex items-start justify-between gap-4">
              <div>
                <p className="text-sm font-medium text-slate-900">{item.stem}</p>
                <p className="text-xs text-slate-500">
                  {item.question_type} · {item.difficulty} · {item.topic || 'tanpa topik'} · v{item.version}
                </p>
              </div>
              <span className="text-xs text-slate-500">{item.points} poin</span>
            </CardBody>
          </Card>
        ))}
        {!list.isLoading && (list.data?.data ?? []).length === 0 && (
          <p className="text-sm text-slate-500">Belum ada soal.</p>
        )}
      </div>
    </div>
  );
}
