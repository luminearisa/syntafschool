import React, { useState } from 'react';
import { Text, View } from 'react-native';
import dayjs from 'dayjs';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, StatTile, T } from '@/components/ui';
import { Chips, DateField, Kv, Sheet, TextArea, isDate } from '@/components/forms';
import { ChildHeader, ChildPicker } from '@/components/ChildPicker';
import { Hero } from '@/components/Hero';
import { useChildren } from '@/api/hooks';
import { useBoardingCancel, useBoardingList, useBoardingRequest, useBoardingSummary } from '@/api/family';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type Tab = 'summary' | 'violations' | 'achievements' | 'attendances' | 'permissions' | 'homecomings' | 'visitors' | 'health';
type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
const dt = (v?: string | null) => (v ? dayjs(v).format('D MMM YYYY HH:mm') : '-');

const PERM_TYPES = [['out', 'Keluar biasa'], ['sick', 'Berobat'], ['family', 'Kunjungan keluarga'], ['school_activity', 'Kegiatan sekolah'], ['special', 'Istimewa']] as const;
const HOME_TYPES = [['weekend', 'Akhir pekan'], ['semester_break', 'Libur semester'], ['lebaran', 'Lebaran'], ['special', 'Istimewa']] as const;

export default function Asrama() {
  const { children, child, isLoading } = useChildren();
  const [tab, setTab] = useState<Tab>('summary');
  const summary = useBoardingSummary(child?.id);

  return (
    <Screen refreshing={summary.isRefetching} onRefresh={() => summary.refetch()} header={<Hero back subtitle="Kehidupan asrama anak" />}>
      {isLoading ? <Loading /> : null}
      {child ? (
        <>
          <ChildPicker children={children} current={child} />
          <ChildHeader child={child} />
          <Segmented<Tab> value={tab} onChange={setTab} options={[
            { key: 'summary', label: 'Ringkasan' }, { key: 'permissions', label: 'Izin keluar' }, { key: 'homecomings', label: 'Kepulangan' }, { key: 'violations', label: 'Pelanggaran' },
            { key: 'achievements', label: 'Prestasi' }, { key: 'attendances', label: 'Absensi' }, { key: 'visitors', label: 'Tamu' }, { key: 'health', label: 'Kesehatan' },
          ]} />
          {tab === 'summary' ? <Summary studentId={child.id} /> : null}
          {tab === 'permissions' ? <Requests studentId={child.id} kind="permissions" /> : null}
          {tab === 'homecomings' ? <Requests studentId={child.id} kind="homecomings" /> : null}
          {tab === 'violations' ? <Plain studentId={child.id} kind="violations" render={(v) => <Item title={v.type_name ?? v.type?.name} sub={`${dateShort(v.date)} · ${v.category_label ?? ''}`} pill={v.status_label} extra={`${v.points ?? 0} poin${v.narrative ? ` — ${v.narrative}` : ''}`} />} /> : null}
          {tab === 'achievements' ? <Plain studentId={child.id} kind="achievements" render={(v) => <Item title={v.title} sub={`${dateShort(v.date)} · ${v.level_label ?? ''}`} extra={v.description} />} /> : null}
          {tab === 'attendances' ? <Plain studentId={child.id} kind="attendances" render={(v) => <Item title={v.activity?.name ?? 'Kegiatan'} sub={`${dateShort(v.date)} · ${v.session ?? ''}`} pill={v.attendance_type?.name} pillColor={v.is_present ? colors.success : colors.warning} extra={v.is_late ? `Terlambat ${v.late_minutes ?? ''} menit` : undefined} />} /> : null}
          {tab === 'visitors' ? <Plain studentId={child.id} kind="visitors" render={(v) => <Item title={v.visitor_name} sub={`${dateShort(v.visit_date)} · ${v.relationship ?? ''}`} pill={v.status_label} extra={v.purpose} />} /> : null}
          {tab === 'health' ? <Plain studentId={child.id} kind="health" render={(v) => <Item title={v.condition ?? v.complaint} sub={dateShort(v.date)} pill={v.status_label} extra={v.action ? `Tindakan: ${v.action}` : undefined} />} /> : null}
        </>
      ) : null}
    </Screen>
  );
}

function Item({ title, sub, pill, pillColor, extra }: { title?: string; sub?: string; pill?: string; pillColor?: string; extra?: string }) {
  return (
    <Card>
      <Row left={<Text style={T.title}>{title ?? '-'}</Text>} sub={sub ? <Text style={T.small}>{sub}</Text> : undefined} right={pill ? <Pill label={pill} color={pillColor} /> : undefined} />
      {extra ? <Text style={T.body}>{extra}</Text> : null}
    </Card>
  );
}

function Summary({ studentId }: { studentId: number }) {
  const q = useBoardingSummary(studentId);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  const d = q.data as R;
  const pl = d.placement ?? {};
  return (
    <>
      <Card>
        <Row left={<Text style={T.title}>{pl.status_label ?? 'Status'}</Text>} right={<Pill label={pl.in_boarding ? 'Di asrama' : 'Tidak di asrama'} color={pl.in_boarding ? colors.success : colors.muted} />} />
        <Kv k="Asrama" v={pl.dormitory_name} />
        <Kv k="Kamar" v={pl.room_code ? `${pl.room_code}${pl.bed_number ? ` · ranjang ${pl.bed_number}` : ''}` : null} />
        <Kv k="Musyrif" v={pl.musyrif_name} />
      </Card>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <StatTile icon="alert-circle" tint={colors.warning} label={d.points?.point_label ?? 'Poin'} value={String(d.points?.total_points ?? 0)} hint={d.points?.open_count ? `${d.points.open_count} terbuka` : undefined} />
        <StatTile icon="trophy" tint={colors.gold} label="Prestasi" value={String(d.counts?.achievements ?? 0)} />
      </View>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <StatTile icon="exit" tint={colors.info} label="Izin berjalan" value={String(d.counts?.permissions ?? 0)} />
        <StatTile icon="home" tint={colors.primary} label="Kepulangan" value={String(d.counts?.homecomings ?? 0)} />
      </View>
      {d.points?.next_threshold ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>Batas berikutnya: {JSON.stringify(d.points.next_threshold)}</Text></Card> : null}
    </>
  );
}

function Plain({ studentId, kind, render }: { studentId: number; kind: Parameters<typeof useBoardingList>[1]; render: (v: R) => React.ReactNode }) {
  const q = useBoardingList(studentId, kind);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  if (!q.data?.length) return <EmptyState icon="leaf-outline" title="Belum ada data" />;
  return <>{q.data.map((v) => <React.Fragment key={v.id}>{render(v)}</React.Fragment>)}</>;
}

/** Izin keluar & kepulangan: daftar + pengajuan baru + batalkan pengajuan sendiri. */
function Requests({ studentId, kind }: { studentId: number; kind: 'permissions' | 'homecomings' }) {
  const q = useBoardingList(studentId, kind);
  const cancel = useBoardingCancel(kind);
  const me = useAuth((s) => s.user?.id);
  const [open, setOpen] = useState(false);

  const askCancel = (id: number) => Alert.alert('Batalkan pengajuan?', 'Pengajuan akan dibatalkan.', [{ text: 'Tidak', style: 'cancel' }, { text: 'Batalkan', style: 'destructive', onPress: () => cancel.mutate({ studentId, id }, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) }) }]);

  return (
    <>
      <Button label={kind === 'permissions' ? 'Ajukan izin keluar' : 'Ajukan kepulangan'} icon="add-circle-outline" onPress={() => setOpen(true)} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data?.length === 0 ? <EmptyState icon="paper-plane-outline" title="Belum ada pengajuan" /> : null}
      {q.data?.map((r) => {
        const cancellable = Number(r.requested_by) === Number(me) && ['draft', 'submitted', 'approved'].includes(r.status);
        return (
          <Card key={r.id}>
            <Row
              left={<Text style={T.title}>{kind === 'permissions' ? r.permission_type_label : r.type_label}</Text>}
              sub={<Text style={T.small}>{kind === 'permissions' ? `${dt(r.start_at)} → ${dt(r.expected_return_at)}` : `${dateShort(r.home_date)} → ${dateShort(r.return_date)}`}</Text>}
              right={<Pill label={r.status_label ?? r.status} color={r.status === 'rejected' ? colors.danger : r.status === 'approved' || r.status === 'completed' ? colors.success : colors.info} />}
            />
            {r.destination ? <Text style={T.body}>Tujuan: {r.destination}</Text> : null}
            {r.reason ? <Text style={T.body}>{r.reason}</Text> : null}
            {r.is_overdue ? <Text style={{ color: colors.danger, fontWeight: '700', fontSize: 12.5 }}>Melewati batas kembali</Text> : null}
            {cancellable ? <Button label="Batalkan pengajuan" variant="ghost" onPress={() => askCancel(r.id)} disabled={cancel.isPending} /> : null}
          </Card>
        );
      })}
      {open ? <RequestSheet studentId={studentId} kind={kind} onClose={() => setOpen(false)} /> : null}
    </>
  );
}

function RequestSheet({ studentId, kind, onClose }: { studentId: number; kind: 'permissions' | 'homecomings'; onClose: () => void }) {
  const send = useBoardingRequest(kind);
  const [type, setType] = useState<string>(kind === 'permissions' ? 'out' : 'weekend');
  const [from, setFrom] = useState(kind === 'permissions' ? `${dayjs().add(1, 'day').format('YYYY-MM-DD')} 08:00` : dayjs().add(1, 'day').format('YYYY-MM-DD'));
  const [to, setTo] = useState(kind === 'permissions' ? `${dayjs().add(1, 'day').format('YYYY-MM-DD')} 17:00` : dayjs().add(3, 'day').format('YYYY-MM-DD'));
  const [place, setPlace] = useState('');
  const [reason, setReason] = useState('');
  const withTime = kind === 'permissions';

  const submit = () => {
    if (!isDate(from, withTime) || !isDate(to, withTime)) return Alert.alert('Tanggal tidak valid', withTime ? 'Gunakan format TTTT-BB-HH JJ:MM.' : 'Gunakan format TTTT-BB-HH.');
    if (!dayjs(to).isAfter(dayjs(from)) && withTime) return Alert.alert('Waktu tidak valid', 'Waktu kembali harus setelah waktu mulai.');
    if (kind === 'permissions' && reason.trim().length < 3) return Alert.alert('Alasan wajib', 'Isi alasan izin.');
    const body = kind === 'permissions'
      ? { permission_type: type, destination: place.trim() || undefined, reason: reason.trim(), start_at: from, expected_return_at: to }
      : { type, home_date: from, return_date: to, transport: place.trim() || undefined, notes: reason.trim() || undefined };
    send.mutate({ studentId, body }, { onSuccess: () => { Alert.alert('Terkirim', 'Pengajuan dikirim ke pengasuh untuk persetujuan.'); onClose(); }, onError: (e) => Alert.alert('Tidak dapat mengajukan', errorMessage(e)) });
  };

  return (
    <Sheet visible title={kind === 'permissions' ? 'Ajukan izin keluar' : 'Ajukan kepulangan'} onClose={onClose} footer={<Button label="Kirim pengajuan" icon="send-outline" onPress={submit} loading={send.isPending} />}>
      <Chips label="Jenis" value={type} options={(kind === 'permissions' ? PERM_TYPES : HOME_TYPES).map(([k, l]) => ({ key: k as string, label: l }))} onChange={setType} />
      <DateField label={kind === 'permissions' ? 'Mulai' : 'Tanggal pulang'} value={from} onChange={setFrom} withTime={withTime} />
      <DateField label={kind === 'permissions' ? 'Perkiraan kembali' : 'Tanggal kembali'} value={to} onChange={setTo} withTime={withTime} />
      <TextArea label={kind === 'permissions' ? 'Tujuan' : 'Transportasi'} value={place} onChangeText={setPlace} multiline={false} />
      <TextArea label={kind === 'permissions' ? 'Alasan *' : 'Catatan'} value={reason} onChangeText={setReason} />
    </Sheet>
  );
}
