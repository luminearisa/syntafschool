import React, { useEffect, useMemo, useState } from 'react';
import { Text, TextInput, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Hero } from '@/components/Hero';
import { Chips, Kv, Sheet, TextArea } from '@/components/forms';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, T } from '@/components/ui';
import {
  useAssessmentSheet, useAssessmentTypes, useCreateAssessment, useMyAssessments, useSaveScores, useSubmitAssessment, type AssessmentRow,
} from '@/api/assessment';
import { useMyClasses } from '@/api/teaching';
import { errorMessage } from '@/api/client';
import { colors, radius } from '@/theme';
import { dateShort } from '@/lib/format';

const STATUS: Record<AssessmentRow['status'], { label: string; color: string }> = {
  draft: { label: 'Draf', color: colors.muted },
  submitted: { label: 'Diserahkan', color: colors.info },
  verified: { label: 'Terverifikasi', color: colors.success },
  finalized: { label: 'Final', color: colors.success },
  revision_requested: { label: 'Perlu revisi', color: colors.warning },
};

export default function Nilai() {
  const q = useMyAssessments();
  const [open, setOpen] = useState<number | 'new' | null>(null);

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="Input nilai & penilaian" />}>
      <Button label="Penilaian baru" icon="add-circle-outline" onPress={() => setOpen('new')} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data?.length === 0 ? <EmptyState icon="ribbon-outline" title="Belum ada penilaian" hint="Buat penilaian untuk kelas dan mapel yang Anda ampu." /> : null}
      {q.data?.map((a) => {
        const st = STATUS[a.status];
        return (
          <Card key={a.id} onPress={() => setOpen(a.id)}>
            <Row left={<Text style={T.title} numberOfLines={2}>{a.name}</Text>} sub={<Text style={T.small}>{a.subject?.name} · {a.school_class?.name} · {a.type?.name}{a.date ? ` · ${dateShort(a.date)}` : ''}</Text>} right={<Pill label={st.label} color={st.color} />} />
            <Text style={T.small}>{a.graded_count ?? 0} nilai terisi · maks {a.max_score}</Text>
          </Card>
        );
      })}
      {open === 'new' ? <NewAssessment onClose={() => setOpen(null)} /> : null}
      {typeof open === 'number' ? <ScoreSheet id={open} onClose={() => setOpen(null)} /> : null}
    </Screen>
  );
}

function NewAssessment({ onClose }: { onClose: () => void }) {
  const classes = useMyClasses();
  const types = useAssessmentTypes();
  const create = useCreateAssessment();
  const [classId, setClassId] = useState<number | null>(null);
  const [subjectId, setSubjectId] = useState<number | null>(null);
  const [typeId, setTypeId] = useState<number | null>(null);
  const [name, setName] = useState('');
  const [max, setMax] = useState('100');

  const subjects = classes.data?.classes.find((c) => c.class_id === classId)?.subjects ?? [];
  const semesterId = classes.data?.semester?.id;

  const submit = () => {
    if (!classId || !subjectId || !typeId || !semesterId || !name.trim()) return Alert.alert('Lengkapi data', 'Kelas, mapel, jenis, dan nama penilaian wajib diisi.');
    create.mutate(
      { class_id: classId, subject_id: subjectId, semester_id: semesterId, assessment_type_id: typeId, name: name.trim(), max_score: parseFloat(max) || 100 },
      { onSuccess: onClose, onError: (e) => Alert.alert('Gagal', errorMessage(e)) },
    );
  };

  return (
    <Sheet visible title="Penilaian baru" onClose={onClose} footer={<Button label="Buat penilaian" onPress={submit} loading={create.isPending} />}>
      {classes.isLoading ? <Loading /> : null}
      <Chips label="Kelas *" value={classId} options={(classes.data?.classes ?? []).map((c) => ({ key: c.class_id, label: c.class_name }))} onChange={(k) => { setClassId(k); setSubjectId(null); }} />
      {classId ? <Chips label="Mata pelajaran *" value={subjectId} options={subjects.map((s) => ({ key: s.subject_id, label: s.subject_name }))} onChange={setSubjectId} /> : null}
      <Chips label="Jenis penilaian *" value={typeId} options={(types.data ?? []).map((t) => ({ key: t.id, label: t.name }))} onChange={setTypeId} />
      <TextArea label="Nama penilaian *" value={name} onChangeText={setName} multiline={false} placeholder="mis. Ulangan Harian 1" />
      <TextArea label="Nilai maksimum" value={max} onChangeText={setMax} keyboardType="decimal-pad" multiline={false} />
    </Sheet>
  );
}

function ScoreSheet({ id, onClose }: { id: number; onClose: () => void }) {
  const q = useAssessmentSheet(id);
  const save = useSaveScores();
  const submit = useSubmitAssessment();
  const [vals, setVals] = useState<Record<number, string>>({});
  const [reason, setReason] = useState('');

  useEffect(() => {
    const next: Record<number, string> = {};
    q.data?.items.forEach((r) => { next[r.student_id] = r.score === null ? '' : String(r.score); });
    setVals(next);
  }, [q.data]);

  const a = q.data?.assessment;
  const max = q.data?.max_score ?? 100;
  const locked = !!q.data?.is_locked;
  const needsReason = !!a && a.status !== 'draft';

  const entries = useMemo(
    () => (q.data?.items ?? [])
      .filter((r) => (vals[r.student_id] ?? '') !== (r.score === null ? '' : String(r.score)))
      .map((r) => ({ student_id: r.student_id, score: (vals[r.student_id] ?? '') === '' ? null : parseFloat(vals[r.student_id]!.replace(',', '.')) })),
    [q.data, vals],
  );

  const invalid = entries.find((e) => e.score !== null && (Number.isNaN(e.score) || e.score < 0 || e.score > max));

  const persist = (then?: () => void) => {
    if (invalid) return Alert.alert('Nilai tidak valid', `Nilai harus 0–${max}.`);
    if (entries.length === 0) return then?.();
    if (needsReason && reason.trim().length < 3) return Alert.alert('Alasan wajib', 'Penilaian sudah diserahkan — isi alasan perubahan nilai.');
    save.mutate({ id, entries, reason: reason.trim() || undefined }, { onSuccess: () => { setReason(''); if (then) then(); else Alert.alert('Tersimpan', `${entries.length} nilai disimpan.`); }, onError: (e) => Alert.alert('Gagal menyimpan', errorMessage(e)) });
  };

  const doSubmit = (force = false) => {
    submit.mutate({ id, force, reason: force ? 'Diserahkan dengan nilai belum lengkap (dari aplikasi)' : undefined }, {
      onSuccess: onClose,
      onError: (e) => {
        const msg = errorMessage(e);
        if (!force && msg.includes('belum dinilai')) {
          Alert.alert('Nilai belum lengkap', `${msg}\n\nSerahkan tetap?`, [{ text: 'Batal', style: 'cancel' }, { text: 'Serahkan', onPress: () => doSubmit(true) }]);
        } else Alert.alert('Gagal', msg);
      },
    });
  };

  return (
    <Sheet
      visible
      title={a?.name ?? 'Penilaian'}
      onClose={onClose}
      footer={q.data && !locked ? (
        <>
          <Button label={`Simpan nilai${entries.length ? ` (${entries.length})` : ''}`} icon="save-outline" onPress={() => persist()} loading={save.isPending} />
          {a?.status === 'draft' ? <Button label="Serahkan nilai" variant="gold" icon="send-outline" onPress={() => persist(() => doSubmit())} loading={submit.isPending} /> : null}
        </>
      ) : undefined}
    >
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data && a ? (
        <>
          <Card>
            <Row left={<Text style={T.title}>{a.subject?.name} · {a.school_class?.name}</Text>} right={<Pill label={STATUS[a.status].label} color={STATUS[a.status].color} />} />
            <Kv k="Jenis" v={a.type?.name} />
            <Kv k="Nilai maksimum" v={max} />
            <Kv k="KKM" v={q.data.passing_grade} />
            <Kv k="Terisi" v={`${q.data.graded}/${q.data.total}`} />
          </Card>
          {locked ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>Penilaian sudah final — nilai tidak dapat diubah.</Text></Card> : null}
          {q.data.items.map((r) => {
            const v = vals[r.student_id] ?? '';
            const n = parseFloat(v.replace(',', '.'));
            const bad = v !== '' && (Number.isNaN(n) || n < 0 || n > max);
            const low = q.data!.passing_grade !== null && v !== '' && !bad && n < (q.data!.passing_grade ?? 0);
            return (
              <Card key={r.student_id} style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <View style={{ flex: 1 }}>
                  <Text style={T.title} numberOfLines={1}>{r.full_name}</Text>
                  <Text style={T.small}>NIS {r.nis}{r.grade ? ` · ${r.grade}` : ''}</Text>
                </View>
                <TextInput
                  value={v}
                  onChangeText={(t) => setVals((p) => ({ ...p, [r.student_id]: t }))}
                  editable={!locked}
                  keyboardType="decimal-pad"
                  placeholder="–"
                  placeholderTextColor={colors.muted}
                  accessibilityLabel={`Nilai ${r.full_name}`}
                  style={{ width: 84, height: 46, borderRadius: radius.sm, textAlign: 'center', fontSize: 18, fontWeight: '800', borderWidth: 1.5, borderColor: bad ? colors.danger : low ? colors.warning : colors.line, color: bad ? colors.danger : low ? colors.warning : colors.ink, backgroundColor: '#fff' }}
                />
              </Card>
            );
          })}
          {needsReason && !locked ? <TextArea label="Alasan perubahan nilai" hint="Wajib karena penilaian sudah diserahkan." value={reason} onChangeText={setReason} /> : null}
        </>
      ) : null}
    </Sheet>
  );
}
