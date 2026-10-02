import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AppState, Pressable, Text, TextInput, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import dayjs from 'dayjs';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, T } from '@/components/ui';
import { Sheet } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { cbtHeartbeat, cbtIncident, cbtLoad, cbtSave, cbtStart, cbtSubmit, useCbtSessions, useInvalidateCbt, type CbtCard, type CbtPlayer, type CbtQuestion } from '@/api/cbt';
import { errorMessage, get } from '@/api/client';
import { useQuery } from '@tanstack/react-query';
import { colors } from '@/theme';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const STATUS: Record<string, { label: string; color: string }> = {
  scheduled: { label: 'Terjadwal', color: colors.info }, open: { label: 'Dibuka', color: colors.success }, closed: { label: 'Ditutup', color: colors.muted },
};
const ATT: Record<string, string> = { in_progress: 'Sedang dikerjakan', submitted: 'Terkumpul', expired: 'Waktu habis', graded: 'Dinilai', voided: 'Dibatalkan' };

export default function Ujian() {
  const q = useCbtSessions();
  const refresh = useInvalidateCbt();
  const [attemptId, setAttemptId] = useState<number | null>(null);
  const [starting, setStarting] = useState<CbtCard | null>(null);
  const [token, setToken] = useState('');
  const [busy, setBusy] = useState(false);
  const [initial, setInitial] = useState<CbtPlayer | null>(null);
  const [review, setReview] = useState<number | null>(null);

  const begin = async (s: CbtCard) => {
    setBusy(true);
    try {
      const p = await cbtStart(s.id, s.require_token ? token.trim().toUpperCase() : undefined);
      setInitial(p); setAttemptId(p.attempt.id); setStarting(null); setToken('');
    } catch (e) {
      Alert.alert('Tidak dapat memulai', errorMessage(e));
    } finally { setBusy(false); }
  };
  const resume = async (id: number) => {
    setBusy(true);
    try { const p = await cbtLoad(id); setInitial(p); setAttemptId(id); } catch (e) { Alert.alert('Gagal', errorMessage(e)); } finally { setBusy(false); }
  };

  const items = q.data ?? [];
  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="Ujian berbasis komputer" />}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {!q.isLoading && items.length === 0 ? <EmptyState icon="school-outline" title="Belum ada ujian" hint="Ujian yang dijadwalkan untuk Anda akan muncul di sini." /> : null}
      {items.map((s) => {
        const st = STATUS[s.status] ?? STATUS.closed!;
        const inProgress = s.attempt?.status === 'in_progress';
        const canStart = s.status === 'open' && (!s.attempt || inProgress || ['voided'].includes(s.attempt.status));
        return (
          <Card key={s.id}>
            <Row left={<Text style={T.title}>{s.title}</Text>} sub={<Text style={T.small}>{s.subject ?? ''}{s.starts_at ? ` · ${dayjs(s.starts_at).format('D MMM HH:mm')}` : ''}{s.ends_at ? ` – ${dayjs(s.ends_at).format('HH:mm')}` : ''}</Text>} right={<Pill label={st.label} color={st.color} />} />
            {s.attempt ? (
              <Text style={T.small}>
                {ATT[s.attempt.status] ?? s.attempt.status}
                {s.attempt.percentage !== null ? ` · nilai ${Math.round(Number(s.attempt.percentage))}%${s.attempt.passed === null ? '' : s.attempt.passed ? ' (lulus)' : ' (belum lulus)'}` : ''}
              </Text>
            ) : null}
            {s.attempt && s.attempt.percentage !== null && ['submitted', 'graded', 'expired'].includes(s.attempt.status) ? <Button label="Lihat pembahasan" variant="ghost" icon="document-text-outline" onPress={() => setReview(s.attempt!.id)} /> : null}
            {inProgress ? <Button label="Lanjutkan ujian" icon="play" onPress={() => resume(s.attempt!.id)} loading={busy} /> : null}
            {!inProgress && canStart ? <Button label="Mulai ujian" icon="play" onPress={() => setStarting(s)} /> : null}
            {s.status === 'scheduled' ? <Text style={T.small}>Ujian dibuka oleh pengawas pada waktunya.</Text> : null}
          </Card>
        );
      })}

      {starting ? (
        <Sheet visible title={starting.title} onClose={() => setStarting(null)} footer={<Button label="Mulai sekarang" icon="play" onPress={() => begin(starting)} loading={busy} />}>
          <Card style={{ backgroundColor: colors.goldSoft }}>
            <Text style={T.title}>Sebelum mulai</Text>
            <Text style={T.body}>• Waktu berjalan sejak Anda menekan mulai.{'\n'}• Jawaban tersimpan otomatis ke server.{'\n'}• Keluar dari aplikasi atau berpindah layar selama ujian dicatat dan dilaporkan ke pengawas.{'\n'}• Pastikan baterai dan koneksi internet stabil.</Text>
          </Card>
          {starting.require_token ? (
            <View style={{ gap: 6 }}>
              <Text style={{ fontSize: 12.5, fontWeight: '700', color: colors.inkSoft }}>Token ujian (dari pengawas)</Text>
              <TextInput accessibilityLabel="Token ujian" value={token} onChangeText={setToken} autoCapitalize="characters" autoCorrect={false} maxLength={12} placeholder="ABC123" placeholderTextColor={colors.muted}
                style={{ height: 52, borderRadius: 14, borderWidth: 1, borderColor: colors.line, backgroundColor: '#fff', paddingHorizontal: 14, fontSize: 20, letterSpacing: 4, fontWeight: '800', color: colors.ink }} />
            </View>
          ) : null}
        </Sheet>
      ) : null}

      {review ? <Review id={review} onClose={() => setReview(null)} /> : null}
      {attemptId && initial ? <Player initial={initial} onExit={() => { setAttemptId(null); setInitial(null); refresh(); }} /> : null}
    </Screen>
  );
}

function Review({ id, onClose }: { id: number; onClose: () => void }) {
  const q = useQuery({ queryKey: ['cbt', 'review', id], queryFn: () => get<R>(`/cbt/attempts/${id}/review`) });
  const d = q.data;
  const show = (r: R | null) => (r ? Object.values(r).map((x) => (typeof x === 'object' ? JSON.stringify(x) : String(x))).join(', ') : '–');
  return (
    <Sheet visible title="Pembahasan" onClose={onClose}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {d ? <Card><Row left={<Text style={T.title}>Nilai</Text>} right={<Text style={{ fontSize: 22, fontWeight: '800', color: colors.primary }}>{d.attempt.percentage !== null ? `${Math.round(Number(d.attempt.percentage))}%` : '–'}</Text>} /></Card> : null}
      {(d?.answers ?? []).map((a: R, i: number) => (
        <Card key={a.id}>
          <Row left={<Text style={T.small}>Soal {i + 1}</Text>} right={a.is_correct === null || a.is_correct === undefined ? <Pill label={a.awarded === null ? 'Menunggu nilai' : `${a.awarded}/${a.max_points}`} color={colors.info} /> : <Pill label={a.is_correct ? 'Benar' : 'Kurang tepat'} color={a.is_correct ? colors.success : colors.danger} />} />
          <Text style={T.body}>{a.stem}</Text>
          <Text style={T.small}>Jawaban Anda: {show(a.response)}</Text>
          {a.explanation ? <Text style={[T.body, { fontStyle: 'italic' }]}>{a.explanation}</Text> : null}
        </Card>
      ))}
    </Sheet>
  );
}

const fmt = (s: number) => `${String(Math.floor(s / 3600)).padStart(2, '0')}:${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

function Player({ initial, onExit }: { initial: CbtPlayer; onExit: () => void }) {
  const attemptId = initial.attempt.id;
  const qs = initial.questions;
  const lock = initial.session.lock_navigation;
  const [idx, setIdx] = useState(0);
  const [answers, setAnswers] = useState<Record<number, R | null>>(() => Object.fromEntries(qs.map((q) => [q.id, q.response ?? null])));
  const [flags, setFlags] = useState<Record<number, boolean>>(() => Object.fromEntries(qs.map((q) => [q.id, !!q.flagged])));
  const [offset] = useState(() => dayjs(initial.server_now).valueOf() - Date.now()); // selisih jam server
  const [deadline, setDeadline] = useState<number | null>(initial.attempt.deadline_at ? dayjs(initial.attempt.deadline_at).valueOf() : null);
  const [left, setLeft] = useState<number | null>(null);
  const [result, setResult] = useState<R | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [saveError, setSaveError] = useState(false);
  const done = useRef(false);
  const timers = useRef<Record<number, ReturnType<typeof setTimeout>>>({});
  const pending = useRef<Record<number, { response?: R | null; flagged?: boolean }>>({});

  const flush = useCallback(async () => {
    const entries = Object.entries(pending.current);
    pending.current = {};
    for (const [qid, body] of entries) {
      try { await cbtSave(attemptId, Number(qid), body); setSaveError(false); } catch { pending.current[Number(qid)] = { ...body, ...(pending.current[Number(qid)] ?? {}) }; setSaveError(true); }
    }
  }, [attemptId]);

  const queueSave = (qid: number, body: { response?: R | null; flagged?: boolean }) => {
    pending.current[qid] = { ...(pending.current[qid] ?? {}), ...body };
    clearTimeout(timers.current[qid]);
    timers.current[qid] = setTimeout(flush, 500);
  };

  const finish = useCallback(async (silent = false) => {
    if (done.current) return;
    done.current = true;
    setSubmitting(true);
    try {
      Object.values(timers.current).forEach(clearTimeout);
      await flush();
      setResult(await cbtSubmit(attemptId));
    } catch (e) {
      done.current = false;
      if (!silent) Alert.alert('Gagal mengumpulkan', errorMessage(e));
    } finally { setSubmitting(false); }
  }, [attemptId, flush]);

  // Hitung mundur berdasarkan jam server; habis → kumpulkan otomatis.
  useEffect(() => {
    if (deadline === null || result) return;
    const tick = () => {
      const s = Math.max(0, Math.round((deadline - (Date.now() + offset)) / 1000));
      setLeft(s);
      if (s <= 0) finish(true);
    };
    tick();
    const t = setInterval(tick, 1000);
    return () => clearInterval(t);
  }, [deadline, offset, result, finish]);

  // Heartbeat: sinkron deadline & hentikan bila server menutup percobaan (void/expired).
  useEffect(() => {
    if (result) return;
    const t = setInterval(async () => {
      try {
        const h = await cbtHeartbeat(attemptId);
        if (h.deadline_at) setDeadline(dayjs(h.deadline_at).valueOf());
        if (h.status !== 'in_progress' && !done.current) {
          done.current = true;
          Alert.alert('Ujian berakhir', h.status === 'voided' ? 'Percobaan dibatalkan oleh pengawas.' : 'Waktu ujian telah habis.');
          setResult({ status: h.status, ended: true });
        }
      } catch { /* jaringan putus: jawaban tetap di perangkat, dicoba lagi */ }
      if (Object.keys(pending.current).length) flush();
    }, 25_000);
    return () => clearInterval(t);
  }, [attemptId, result, flush]);

  // Pemantauan: keluar dari aplikasi/tab selama ujian dilaporkan ke pengawas.
  useEffect(() => {
    if (result) return;
    const sub = AppState.addEventListener('change', (st) => {
      if (st !== 'active' && !done.current) cbtIncident(attemptId, 'tab_blur', 'Aplikasi ditinggalkan saat ujian');
    });
    return () => sub.remove();
  }, [attemptId, result]);

  const q = qs[idx]!;
  const answered = (id: number) => { const r = answers[id]; return !!r && Object.values(r).some((v) => (Array.isArray(v) ? v.length > 0 : v !== '' && v !== null && v !== undefined && (typeof v !== 'object' || Object.keys(v).length > 0))); };
  const setAnswer = (r: R | null) => { setAnswers((a) => ({ ...a, [q.id]: r })); queueSave(q.id, { response: r }); };
  const toggleFlag = () => { const v = !flags[q.id]; setFlags((f) => ({ ...f, [q.id]: v })); queueSave(q.id, { flagged: v }); };
  const unanswered = qs.filter((x) => !answered(x.id)).length;

  const confirmSubmit = () => Alert.alert('Kumpulkan ujian?', unanswered > 0 ? `${unanswered} soal belum dijawab. Setelah dikumpulkan tidak dapat diubah.` : 'Setelah dikumpulkan tidak dapat diubah.', [
    { text: 'Periksa lagi', style: 'cancel' }, { text: 'Kumpulkan', onPress: () => finish() },
  ]);

  if (result) {
    return (
      <Sheet visible title={initial.session.title} onClose={onExit} footer={<Button label="Selesai" onPress={onExit} />}>
        <Card style={{ alignItems: 'center', gap: 8 }}>
          <Ionicons name={result.ended ? 'alert-circle' : 'checkmark-circle'} size={56} color={result.ended ? colors.warning : colors.success} />
          <Text style={T.title}>{result.ended ? 'Ujian berakhir' : 'Ujian dikumpulkan'}</Text>
          {result.percentage !== null && result.percentage !== undefined ? <Text style={{ fontSize: 34, fontWeight: '800', color: colors.primary }}>{Math.round(Number(result.percentage))}%</Text> : <Text style={T.body}>Nilai akan ditampilkan setelah dirilis oleh sekolah.</Text>}
          {result.needs_manual_grade ? <Text style={T.small}>Sebagian soal (esai) dinilai manual oleh guru.</Text> : null}
        </Card>
      </Sheet>
    );
  }

  return (
    <Sheet
      visible
      title={`${initial.session.title}`}
      onClose={() => Alert.alert('Tutup ujian?', 'Waktu tetap berjalan. Anda dapat melanjutkan dari daftar ujian.', [{ text: 'Tetap di sini', style: 'cancel' }, { text: 'Tutup', onPress: () => { flush(); onExit(); } }])}
      footer={
        <>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            {!lock ? <View style={{ flex: 1 }}><Button label="Sebelumnya" variant="ghost" onPress={() => setIdx((i) => Math.max(0, i - 1))} disabled={idx === 0} /></View> : null}
            {idx < qs.length - 1 ? <View style={{ flex: 1 }}><Button label="Berikutnya" onPress={() => setIdx((i) => i + 1)} /></View> : <View style={{ flex: 1 }}><Button label="Kumpulkan" variant="gold" icon="send" onPress={confirmSubmit} loading={submitting} /></View>}
          </View>
        </>
      }
    >
      <Card style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
        <Text style={T.small}>Soal {idx + 1} dari {qs.length} · {answered(q.id) ? 'dijawab' : 'belum dijawab'}</Text>
        {left !== null ? <Pill label={fmt(left)} color={left < 300 ? colors.danger : colors.primary} /> : null}
      </Card>
      {saveError ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.small}>Jawaban belum tersimpan ke server (koneksi bermasalah). Akan dicoba lagi otomatis.</Text></Card> : null}
      {!lock ? (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
          {qs.map((x, i) => (
            <Pressable key={x.id} onPress={() => setIdx(i)} accessibilityLabel={`Soal ${i + 1}`} style={{ width: 38, height: 38, borderRadius: 10, alignItems: 'center', justifyContent: 'center', borderWidth: 1.5, borderColor: i === idx ? colors.primary : flags[x.id] ? colors.gold : colors.line, backgroundColor: answered(x.id) ? colors.primarySoft : '#fff' }}>
              <Text style={{ fontWeight: '800', color: colors.ink }}>{i + 1}</Text>
            </Pressable>
          ))}
        </View>
      ) : null}
      <Card>
        <Row left={<Text style={T.small}>{q.points} poin</Text>} right={<Pressable onPress={toggleFlag} hitSlop={8} accessibilityLabel="Tandai ragu"><Ionicons name={flags[q.id] ? 'flag' : 'flag-outline'} size={22} color={flags[q.id] ? colors.gold : colors.muted} /></Pressable>} />
        <Text style={{ fontSize: 16, lineHeight: 24, color: colors.ink }} selectable={false}>{q.stem}</Text>
      </Card>
      <Answer key={q.id} q={q} value={answers[q.id] ?? null} onChange={setAnswer} />
      {idx === qs.length - 1 ? <Text style={T.small}>{unanswered === 0 ? 'Semua soal sudah dijawab.' : `${unanswered} soal belum dijawab.`}</Text> : null}
    </Sheet>
  );
}

function Opt({ label, text, on, onPress }: { label: string; text: string; on: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={`Pilihan ${label}`} style={{ flexDirection: 'row', gap: 12, alignItems: 'center', padding: 14, borderRadius: 14, borderWidth: 1.5, borderColor: on ? colors.primary : colors.line, backgroundColor: on ? colors.primarySoft : '#fff' }}>
      <View style={{ width: 30, height: 30, borderRadius: 15, alignItems: 'center', justifyContent: 'center', backgroundColor: on ? colors.primary : colors.bg }}>
        <Text style={{ fontWeight: '800', color: on ? '#fff' : colors.inkSoft }}>{label}</Text>
      </View>
      <Text style={{ flex: 1, fontSize: 15, color: colors.ink }}>{text}</Text>
    </Pressable>
  );
}

function Answer({ q, value, onChange }: { q: CbtQuestion; value: R | null; onChange: (r: R | null) => void }) {
  const v = value ?? {};
  const input = { minHeight: 52, borderRadius: 14, borderWidth: 1, borderColor: colors.line, backgroundColor: '#fff', padding: 12, fontSize: 16, color: colors.ink } as const;

  switch (q.type) {
    case 'multiple_choice':
    case 'true_false':
      return <View style={{ gap: 10 }}>{q.options.map((o) => <Opt key={o.key} label={o.key} text={o.text} on={v.key === o.key} onPress={() => onChange({ key: o.key })} />)}</View>;
    case 'multi_select': {
      const keys: string[] = v.keys ?? [];
      return (
        <View style={{ gap: 10 }}>
          <Text style={T.small}>Pilih semua jawaban yang benar.</Text>
          {q.options.map((o) => <Opt key={o.key} label={o.key} text={o.text} on={keys.includes(o.key)} onPress={() => onChange({ keys: keys.includes(o.key) ? keys.filter((k) => k !== o.key) : [...keys, o.key] })} />)}
        </View>
      );
    }
    case 'short_answer':
      return <TextInput accessibilityLabel="Jawaban" value={v.text ?? ''} onChangeText={(t) => onChange({ text: t })} placeholder="Tulis jawaban singkat" placeholderTextColor={colors.muted} style={input} />;
    case 'numeric':
      return <TextInput accessibilityLabel="Jawaban angka" value={v.value === undefined ? '' : String(v.value)} keyboardType="numeric" onChangeText={(t) => onChange(t.trim() === '' ? null : { value: t.replace(',', '.') })} placeholder="Jawaban angka" placeholderTextColor={colors.muted} style={input} />;
    case 'essay':
      return <TextInput accessibilityLabel="Jawaban esai" value={v.text ?? ''} onChangeText={(t) => onChange({ text: t })} multiline placeholder="Tulis jawaban Anda" placeholderTextColor={colors.muted} textAlignVertical="top" style={[input, { minHeight: 200 }]} />;
    case 'matching': {
      const prompts: R[] = (q.payload_public.prompts ?? []).map((p: R | string, i: number) => (typeof p === 'string' ? { key: String.fromCharCode(65 + i), text: p } : { key: p.key ?? p.id ?? String.fromCharCode(65 + i), text: p.text ?? p.label ?? String(p.key) }));
      const pairs: R = v.pairs ?? {};
      return (
        <View style={{ gap: 14 }}>
          <Text style={T.small}>Pasangkan setiap pernyataan dengan pasangannya.</Text>
          {q.options.map((o) => (
            <Card key={o.key}>
              <Text style={T.title}>{o.text}</Text>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                {prompts.map((p) => {
                  const on = pairs[o.key] === p.key;
                  return (
                    <Pressable key={p.key} onPress={() => onChange({ pairs: { ...pairs, [o.key]: p.key } })} style={{ paddingHorizontal: 12, paddingVertical: 8, borderRadius: 12, borderWidth: 1.5, borderColor: on ? colors.primary : colors.line, backgroundColor: on ? colors.primarySoft : '#fff' }}>
                      <Text style={{ fontWeight: '700', color: on ? colors.primary : colors.inkSoft }}>{p.text}</Text>
                    </Pressable>
                  );
                })}
              </View>
            </Card>
          ))}
        </View>
      );
    }
    case 'ordering': {
      const order: string[] = v.order?.length === q.options.length ? v.order : q.options.map((o) => o.key);
      const text = (k: string) => q.options.find((o) => o.key === k)?.text ?? k;
      const move = (i: number, d: number) => { const n = [...order]; const j = i + d; if (j < 0 || j >= n.length) return; [n[i], n[j]] = [n[j]!, n[i]!]; onChange({ order: n }); };
      return (
        <View style={{ gap: 10 }}>
          <Text style={T.small}>Urutkan dengan tombol panah.{!v.order ? ' (Urutan awal belum disimpan — geser satu item untuk menyimpan.)' : ''}</Text>
          {order.map((k, i) => (
            <Card key={k} style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
              <Text style={{ fontWeight: '800', color: colors.primary, width: 22 }}>{i + 1}</Text>
              <Text style={{ flex: 1, color: colors.ink }}>{text(k)}</Text>
              <Pressable onPress={() => move(i, -1)} hitSlop={8} accessibilityLabel="Naik"><Ionicons name="chevron-up" size={22} color={colors.primary} /></Pressable>
              <Pressable onPress={() => move(i, 1)} hitSlop={8} accessibilityLabel="Turun"><Ionicons name="chevron-down" size={22} color={colors.primary} /></Pressable>
            </Card>
          ))}
        </View>
      );
    }
    default:
      return <Text style={T.body}>Jenis soal ini belum didukung di aplikasi. Kerjakan melalui web.</Text>;
  }
}
