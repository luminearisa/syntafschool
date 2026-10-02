import React, { useEffect, useRef, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import { Chips, Kv, Sheet, TextArea } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { saveAnswers, useAcknowledge, useMyEmployeeId, useSubmitVisit, useVisit, useVisits } from '@/api/supervisi';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
type Tab = 'do' | 'mine';

const EXECUTABLE = ['DRAFT', 'SCHEDULED', 'PRE_CONFERENCE', 'OBSERVED'];

/** Supervisi: guru/pegawai melaksanakan penilaian (sebagai supervisor) dan menerima hasil (sebagai yang disupervisi). */
export default function Supervisi() {
  const me = useMyEmployeeId();
  const q = useVisits();
  const [tab, setTab] = useState<Tab>('do');
  const [open, setOpen] = useState<number | null>(null);
  const all = q.data ?? [];
  const todo = all.filter((v) => v.supervisor?.id === me && EXECUTABLE.includes(v.status));
  const mine = all.filter((v) => v.supervisee?.id === me);
  const list = tab === 'do' ? todo : mine;

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="Supervisi" />}>
      <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'do', label: `Perlu saya nilai (${todo.length})` }, { key: 'mine', label: `Hasil saya (${mine.length})` }]} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {!q.isLoading && list.length === 0 ? <EmptyState icon="eye-outline" title={tab === 'do' ? 'Tidak ada supervisi yang perlu dinilai' : 'Belum ada hasil supervisi'} /> : null}
      {list.map((v) => (
        <Card key={v.id} onPress={() => setOpen(v.id)}>
          <Row left={<Text style={T.title} numberOfLines={2}>{v.instrument?.name ?? v.code}</Text>} sub={<Text style={T.small}>{tab === 'do' ? v.supervisee?.full_name : `Oleh ${v.supervisor?.full_name ?? '-'}`}{v.observed_at ? ` · ${dateShort(v.observed_at)}` : ''}</Text>}
            right={v.needs_acknowledgment ? <Pill label="Perlu ditandai" color={colors.warning} /> : <Pill label={v.status_label} color={v.is_finalized ? colors.success : colors.info} />} />
          {v.is_finalized && v.final_score !== null ? <Text style={T.small}>Skor {v.final_score}/{v.max_score}{v.grade_label ? ` · ${v.grade_label}` : ''}</Text> : null}
        </Card>
      ))}
      {open ? <VisitSheet id={open} canExecute={tab === 'do'} onClose={() => setOpen(null)} /> : null}
    </Screen>
  );
}

function VisitSheet({ id, canExecute, onClose }: { id: number; canExecute: boolean; onClose: () => void }) {
  const q = useVisit(id);
  const submit = useSubmitVisit();
  const ack = useAcknowledge();
  const d = q.data;
  const [drafts, setDrafts] = useState<Record<number, R>>({});
  const [preview, setPreview] = useState<R | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const dirty = useRef<Record<number, R>>({});

  useEffect(() => {
    if (!d) return;
    setDrafts(Object.fromEntries((d.answers as R[]).map((a) => [a.indicator_id, a])));
    setPreview(d.summary);
  }, [d?.visit?.id, q.dataUpdatedAt]); // eslint-disable-line react-hooks/exhaustive-deps

  const flush = async () => {
    const rows = Object.values(dirty.current); dirty.current = {};
    if (!rows.length) return;
    try { const r = await saveAnswers(id, rows); if (r?.preview?.summary) setPreview(r.preview.summary); setErr(null); }
    catch (e) { setErr(errorMessage(e)); rows.forEach((r) => { dirty.current[r.indicator_id] = { ...r, ...dirty.current[r.indicator_id] }; }); }
  };
  const set = (indicatorId: number, patch: R) => {
    setDrafts((s) => ({ ...s, [indicatorId]: { ...(s[indicatorId] ?? {}), ...patch } }));
    dirty.current[indicatorId] = { ...(dirty.current[indicatorId] ?? {}), indicator_id: indicatorId, ...patch };
    clearTimeout(timer.current); timer.current = setTimeout(flush, 700);
  };

  const finish = () => Alert.alert('Kirim untuk analisis?', 'Pastikan semua indikator wajib sudah terisi.', [
    { text: 'Batal', style: 'cancel' },
    { text: 'Kirim', onPress: async () => { await flush(); submit.mutate(id, { onSuccess: () => { Alert.alert('Terkirim', 'Kegiatan dikirim untuk dianalisis.'); onClose(); }, onError: (e) => Alert.alert('Belum dapat dikirim', errorMessage(e)) }); } },
  ]);

  const v = d?.visit as R | undefined;
  const editable = canExecute && !!v && EXECUTABLE.includes(v.status);

  return (
    <Sheet visible title={v?.instrument?.name ?? 'Supervisi'} onClose={() => { flush(); onClose(); }}
      footer={editable ? <Button label="Kirim untuk analisis" icon="send-outline" onPress={finish} loading={submit.isPending} /> : v?.needs_acknowledgment ? <Button label="Tandai sudah membaca" icon="checkmark-done" onPress={() => ack.mutate({ id }, { onSuccess: () => { Alert.alert('Terima kasih', 'Hasil sudah ditandai.'); onClose(); }, onError: (e) => Alert.alert('Gagal', errorMessage(e)) })} loading={ack.isPending} /> : undefined}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {v ? (
        <>
          <Card>
            <Kv k="Kode" v={v.code} />
            <Kv k="Disupervisi" v={v.supervisee?.full_name} />
            <Kv k="Supervisor" v={v.supervisor?.full_name} />
            <Kv k="Status" v={v.status_label} />
            {v.lesson_topic ? <Kv k="Topik" v={v.lesson_topic} /> : null}
            {preview?.total_score !== undefined && preview?.total_score !== null ? <Kv k="Skor sementara" v={`${preview.total_score}${preview.max_score ? ` / ${preview.max_score}` : ''}`} /> : null}
            {d!.band?.label ? <Kv k="Predikat" v={d!.band.label} /> : null}
          </Card>
          {err ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.small}>{err}</Text></Card> : null}

          {v.is_finalized || !editable ? (
            <>
              {v.strengths ? <Card><Text style={T.title}>Kekuatan</Text><Text style={T.body}>{v.strengths}</Text></Card> : null}
              {v.improvements ? <Card><Text style={T.title}>Yang perlu ditingkatkan</Text><Text style={T.body}>{v.improvements}</Text></Card> : null}
              {(d!.findings as R[] | undefined)?.map((f) => <Card key={f.id}><Text style={T.title}>{f.component}</Text><Text style={T.body}>{f.recommendation ?? f.description ?? ''}</Text></Card>)}
            </>
          ) : null}

          {editable ? (d!.sections as R[]).map((sec) => (
            <View key={sec.id} style={{ gap: 10 }}>
              <Text style={[T.title, { marginTop: 6 }]}>{sec.title}</Text>
              {(sec.indicators as R[]).map((ind) => <Indicator key={ind.id} ind={ind} value={drafts[ind.id]} onChange={(p) => set(ind.id, p)} />)}
            </View>
          )) : null}
        </>
      ) : null}
    </Sheet>
  );
}

function Indicator({ ind, value, onChange }: { ind: R; value?: R; onChange: (p: R) => void }) {
  const na = !!value?.is_na;
  const box = { minHeight: 48, borderRadius: 12, borderWidth: 1, borderColor: colors.line, backgroundColor: '#fff', padding: 12, fontSize: 15, color: colors.ink } as const;
  let input: React.ReactNode = null;
  switch (ind.input_type) {
    case 'SCALE':
      input = <Chips label="" value={value?.option_id ?? null} options={(ind.options as R[]).map((o) => ({ key: o.id as number, label: `${o.label}` }))} onChange={(k) => onChange({ option_id: k, is_na: false })} />;
      break;
    case 'MULTI_CHOICE': {
      const ids: number[] = value?.value_json?.option_ids ?? value?.option_ids ?? [];
      input = (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {(ind.options as R[]).map((o) => { const on = ids.includes(o.id); return (
            <Pressable key={o.id} onPress={() => { const n = on ? ids.filter((i) => i !== o.id) : [...ids, o.id]; onChange({ option_ids: n, value_json: { option_ids: n }, is_na: false }); }} style={{ paddingHorizontal: 12, paddingVertical: 8, borderRadius: 12, borderWidth: 1.5, borderColor: on ? colors.primary : colors.line, backgroundColor: on ? colors.primarySoft : '#fff' }}>
              <Text style={{ fontWeight: '700', color: on ? colors.primary : colors.inkSoft }}>{o.label}</Text>
            </Pressable>); })}
        </View>
      );
      break;
    }
    case 'BOOLEAN':
      input = <Chips label="" value={value?.value_text ?? null} options={[{ key: 'YES', label: 'Ya' }, { key: 'PARTIAL', label: 'Sebagian' }, { key: 'NO', label: 'Tidak' }]} onChange={(k) => onChange({ value_text: k, is_na: false })} />;
      break;
    case 'NUMBER':
      input = <TextInput accessibilityLabel={ind.statement} keyboardType="numeric" value={value?.value_number == null ? '' : String(value.value_number)} onChangeText={(t) => onChange({ value_number: t.trim() === '' ? null : Number(t.replace(',', '.')), is_na: false })} placeholder={`${ind.score_min ?? ''}–${ind.score_max ?? ''} ${ind.score_unit ?? ''}`} placeholderTextColor={colors.muted} style={box} />;
      break;
    case 'TEXT':
      input = <TextInput accessibilityLabel={ind.statement} value={value?.value_text ?? ''} onChangeText={(t) => onChange({ value_text: t, is_na: false })} style={box} />;
      break;
    case 'LONG_TEXT':
      input = <TextArea label="" value={value?.value_long_text ?? ''} onChangeText={(t) => onChange({ value_long_text: t, is_na: false })} />;
      break;
    case 'DATE':
      input = <TextInput accessibilityLabel={ind.statement} value={value?.value_date ?? ''} onChangeText={(t) => onChange({ value_date: t, is_na: false })} placeholder="YYYY-MM-DD" placeholderTextColor={colors.muted} style={box} />;
      break;
    default:
      input = <Text style={T.small}>Lampiran bukti diunggah melalui web.</Text>;
  }
  return (
    <Card>
      <Row left={<Text style={T.body}>{ind.code ? `${ind.code}. ` : ''}{ind.statement}</Text>} right={ind.is_required ? <Text style={{ color: colors.danger, fontWeight: '800' }}>*</Text> : undefined} />
      {ind.guidance ? <Text style={T.small}>{ind.guidance}</Text> : null}
      {na ? <Text style={T.small}>Ditandai tidak berlaku.</Text> : input}
      {ind.allow_na ? <Pressable onPress={() => onChange({ is_na: !na })}><Text style={{ color: colors.primary, fontWeight: '700', fontSize: 12.5 }}>{na ? 'Isi indikator ini' : 'Tandai tidak berlaku'}</Text></Pressable> : null}
      <TextInput accessibilityLabel="Catatan" value={value?.comment ?? ''} onChangeText={(t) => onChange({ comment: t })} placeholder={ind.min_comment_below != null ? 'Catatan (wajib bila skor rendah)' : 'Catatan (opsional)'} placeholderTextColor={colors.muted} style={[box, { minHeight: 40, fontSize: 13.5 }]} />
    </Card>
  );
}
