import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/axios';
import { cbtApi, type CbtSessionRow } from '../api/cbtApi';

export default function CbtSessionsPage() {
  const qc = useQueryClient();
  const blueprints = useQuery({ queryKey: ['cbt', 'blueprints'], queryFn: cbtApi.blueprints });
  const sessions = useQuery({ queryKey: ['cbt', 'sessions'], queryFn: () => cbtApi.sessions() });
  const [form, setForm] = useState({ title: '', blueprint_id: '', school_class_id: '', starts_at: '', ends_at: '' });

  const create = useMutation({
    mutationFn: () =>
      cbtApi.createSession({
        title: form.title,
        blueprint_id: Number(form.blueprint_id),
        school_class_id: form.school_class_id ? Number(form.school_class_id) : null,
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        settings: { require_token: true, show_score: false, max_blur: 5 },
      }),
    onSuccess: () => {
      toast.success('Sesi dibuat. Token muncul setelah dibuka.');
      setForm((f) => ({ ...f, title: '' }));
      qc.invalidateQueries({ queryKey: ['cbt', 'sessions'] });
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  });

  const act = useMutation({
    mutationFn: ({ id, action }: { id: number; action: string }) => cbtApi.act(id, action),
    onSuccess: (data, vars) => {
      if (vars.action === 'token' && data?.access_token) toast.success('Token baru: ' + data.access_token);
      else toast.success('Sesi diperbarui');
      qc.invalidateQueries({ queryKey: ['cbt', 'sessions'] });
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  });

  const rows: CbtSessionRow[] = sessions.data?.data ?? [];
  const bps = blueprints.data?.data ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Sesi ujian</h1>
        <p className="text-sm text-slate-500">Jadwalkan, daftarkan rombel, buka dengan token, lalu pantau.</p>
      </div>
      <Card>
        <CardBody className="grid gap-3 md:grid-cols-2">
          <Input placeholder="Judul ujian" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <Select value={form.blueprint_id} onChange={(e) => setForm({ ...form, blueprint_id: e.target.value })}>
            <option value="">Pilih kisi</option>
            {bps.map((b: { id: number; name: string }) => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </Select>
          <Input placeholder="ID rombel (opsional)" value={form.school_class_id} onChange={(e) => setForm({ ...form, school_class_id: e.target.value })} />
          <Input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} />
          <Input type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
          <div>
            <Button disabled={!form.title || !form.blueprint_id} loading={create.isPending} onClick={() => create.mutate()}>
              Buat sesi
            </Button>
          </div>
        </CardBody>
      </Card>

      <div className="space-y-2">
        {rows.map((row) => (
          <Card key={row.id}>
            <CardBody className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="font-medium">{row.title}</p>
                <p className="text-xs text-slate-500">
                  {row.code} · {row.status} · {row.participants_count ?? 0} peserta
                  {row.access_token ? ` · token ${row.access_token}` : ''}
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" onClick={() => cbtApi.enroll(row.id, { from_class: true }).then(() => toast.success('Rombel didaftarkan')).catch((e) => toast.error(getErrorMessage(e)))}>
                  Daftar rombel
                </Button>
                <Button size="sm" variant="outline" onClick={() => act.mutate({ id: row.id, action: 'open' })}>Buka</Button>
                <Button size="sm" variant="outline" onClick={() => act.mutate({ id: row.id, action: 'close' })}>Tutup</Button>
                <Button size="sm" variant="ghost" onClick={() => act.mutate({ id: row.id, action: 'token' })}>Ganti token</Button>
                <Link to={`/cbt/sessions/${row.id}`} className="inline-flex h-8 items-center rounded-md border px-3 text-xs">Monitor</Link>
              </div>
            </CardBody>
          </Card>
        ))}
      </div>
    </div>
  );
}
