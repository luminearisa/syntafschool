import React, { useEffect, useMemo, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Ionicons } from '@expo/vector-icons';
import dayjs from 'dayjs';
import { Hero } from '@/components/Hero';
import { Chips } from '@/components/forms';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, T } from '@/components/ui';
import { useAttendanceLookups, useClassSheet, useSaveSheet } from '@/api/attendance';
import { useJournalToday, useMyClasses } from '@/api/teaching';
import { errorMessage } from '@/api/client';
import { colors, radius } from '@/theme';
import { dateLong } from '@/lib/format';

interface Target { key: string; label: string; classId: number; scheduleId?: number; subjectId?: number }

const CODE_COLOR: Record<string, string> = { H: colors.success, S: colors.info, I: colors.warning, A: colors.danger, T: '#8E4EC6' };
const NEEDS_LEAVE = ['S', 'I'];

export default function AbsenSiswa() {
  const [date, setDate] = useState(dayjs().format('YYYY-MM-DD'));
  const classes = useMyClasses();
  const today = useJournalToday(date);
  const lookups = useAttendanceLookups();
  const [targetKey, setTargetKey] = useState<string | null>(null);

  // Pilihan sesi: jadwal pada tanggal itu (per mapel) + presensi harian untuk kelas yang diwalikan.
  const targets = useMemo<Target[]>(() => {
    const list: Target[] = (today.data?.items ?? []).map((i) => ({
      key: `s${i.schedule_id}`, label: `${i.start_time} ${i.subject?.name} · ${i.school_class?.name}`, classId: i.class_id, scheduleId: i.schedule_id, subjectId: i.subject_id,
    }));
    classes.data?.classes.filter((c) => c.is_homeroom).forEach((c) => list.push({ key: `h${c.class_id}`, label: `Harian · ${c.class_name}`, classId: c.class_id }));
    return list;
  }, [today.data, classes.data]);

  const target = targets.find((t) => t.key === targetKey) ?? targets[0];
  const semesterId = today.data?.semester_id ?? classes.data?.semester?.id;
  const sheet = useClassSheet({ classId: target?.classId, semesterId, date, scheduleId: target?.scheduleId });
  const save = useSaveSheet();

  // Pilihan lokal: student_id → { statusId, leaveTypeId }. Diisi ulang saat lembar berganti.
  const [picks, setPicks] = useState<Record<number, { statusId: number; leaveTypeId: number | null }>>({});
  useEffect(() => {
    const next: typeof picks = {};
    sheet.data?.items.forEach((r) => { if (r.attendance_status_id) next[r.student_id] = { statusId: r.attendance_status_id, leaveTypeId: r.leave_type_id }; });
    setPicks(next);
  }, [sheet.data]);

  const statuses = lookups.data?.statuses ?? [];
  const byCode = (code: string) => statuses.find((s) => s.code === code)?.id;
  const codeOf = (statusId?: number) => statuses.find((s) => s.id === statusId)?.code;

  const set = (studentId: number, code: string) => {
    const id = byCode(code);
    if (!id) return;
    setPicks((p) => ({ ...p, [studentId]: { statusId: id, leaveTypeId: NEEDS_LEAVE.includes(code) ? p[studentId]?.leaveTypeId ?? null : null } }));
  };
  const allPresent = () => {
    const id = byCode('H');
    if (!id) return;
    const next = { ...picks };
    sheet.data?.items.forEach((r) => { if (!next[r.student_id]) next[r.student_id] = { statusId: id, leaveTypeId: null }; });
    setPicks(next);
  };

  const rows = sheet.data?.items ?? [];
  const filled = rows.filter((r) => picks[r.student_id]).length;
  const missingLeave = rows.filter((r) => NEEDS_LEAVE.includes(codeOf(picks[r.student_id]?.statusId) ?? '') && !picks[r.student_id]?.leaveTypeId).length;

  const submit = () => {
    if (!target || !semesterId) return;
    if (filled === 0) return Alert.alert('Belum ada isian', 'Tandai kehadiran minimal satu siswa.');
    if (missingLeave > 0) return Alert.alert('Jenis izin wajib', `${missingLeave} siswa sakit/izin belum dipilih jenis izinnya.`);
    save.mutate(
      {
        class_id: target.classId, semester_id: semesterId, date, schedule_id: target.scheduleId, subject_id: target.subjectId,
        entries: rows.filter((r) => picks[r.student_id]).map((r) => ({ student_id: r.student_id, attendance_status_id: picks[r.student_id]!.statusId, leave_type_id: picks[r.student_id]!.leaveTypeId })),
      },
      {
        onSuccess: (res) => Alert.alert('Tersimpan', `${res.total} presensi disimpan (${res.created} baru, ${res.updated} diperbarui).`),
        onError: (e) => Alert.alert('Gagal menyimpan', errorMessage(e)),
      },
    );
  };

  return (
    <Screen header={<Hero back subtitle="Presensi siswa per kelas" />}>
      <Row
        left={<Text style={T.title}>{dateLong(date)}</Text>}
        right={
          <View style={{ flexDirection: 'row', gap: 14 }}>
            <Ionicons name="chevron-back-circle" size={30} color={colors.primary} onPress={() => setDate(dayjs(date).subtract(1, 'day').format('YYYY-MM-DD'))} />
            <Ionicons name="chevron-forward-circle" size={30} color={colors.primary} onPress={() => setDate(dayjs(date).add(1, 'day').format('YYYY-MM-DD'))} />
          </View>
        }
      />
      {classes.isLoading || today.isLoading ? <Loading /> : null}
      {classes.error ? <ErrorNote message={errorMessage(classes.error)} onRetry={() => classes.refetch()} /> : null}
      {!classes.isLoading && targets.length === 0 ? <EmptyState icon="school-outline" title="Tidak ada kelas" hint="Tidak ada jadwal pada tanggal ini dan Anda bukan wali kelas." /> : null}

      {targets.length > 0 ? <Chips label="Sesi" value={target?.key ?? null} options={targets.map((t) => ({ key: t.key, label: t.label }))} onChange={setTargetKey} /> : null}

      {sheet.isLoading ? <Loading /> : sheet.error ? <ErrorNote message={errorMessage(sheet.error)} onRetry={() => sheet.refetch()} /> : null}
      {sheet.data && sheet.data.is_school_day === false ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>Tanggal ini bukan hari efektif — penyimpanan akan ditolak.</Text></Card> : null}

      {sheet.data ? (
        <>
          <View style={{ flexDirection: 'row', gap: 10, alignItems: 'center' }}>
            <Pill label={`${filled}/${rows.length} terisi`} color={filled === rows.length ? colors.success : colors.warning} />
            <View style={{ flex: 1 }} />
            <Pressable onPress={allPresent} accessibilityRole="button"><Text style={{ color: colors.primary, fontWeight: '800' }}>Semua hadir</Text></Pressable>
          </View>

          {rows.map((r) => {
            const pick = picks[r.student_id];
            const code = codeOf(pick?.statusId);
            return (
              <Card key={r.student_id} style={{ gap: 10 }}>
                <Row left={<Text style={T.title} numberOfLines={1}>{r.full_name}</Text>} sub={<Text style={T.small}>NIS {r.nis}</Text>} />
                <View style={{ flexDirection: 'row', gap: 8 }}>
                  {['H', 'S', 'I', 'A', 'T'].filter((c) => byCode(c)).map((c) => {
                    const on = code === c;
                    return (
                      <Pressable key={c} onPress={() => set(r.student_id, c)} accessibilityRole="button" accessibilityState={{ selected: on }} accessibilityLabel={statuses.find((s) => s.code === c)?.name}
                        style={{ flex: 1, height: 40, borderRadius: radius.sm, alignItems: 'center', justifyContent: 'center', borderWidth: 1.5, borderColor: CODE_COLOR[c], backgroundColor: on ? CODE_COLOR[c] : '#fff' }}>
                        <Text style={{ fontWeight: '800', color: on ? '#fff' : CODE_COLOR[c] }}>{c}</Text>
                      </Pressable>
                    );
                  })}
                </View>
                {code && NEEDS_LEAVE.includes(code) ? (
                  <Chips<number> value={pick?.leaveTypeId ?? null} options={(lookups.data?.leave_types ?? []).map((l) => ({ key: l.id, label: l.name }))} onChange={(k) => setPicks((p) => ({ ...p, [r.student_id]: { ...p[r.student_id]!, leaveTypeId: k } }))} />
                ) : null}
              </Card>
            );
          })}

          <Button label="Simpan presensi" icon="save-outline" onPress={submit} loading={save.isPending} />
        </>
      ) : null}
    </Screen>
  );
}
