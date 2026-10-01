import React, { useState } from 'react';
import { Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import dayjs from 'dayjs';
import { Hero } from '@/components/Hero';
import { Chips, Kv, Sheet, TextArea } from '@/components/forms';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import { lookupAsset, reportDamage, useAvailability, useBook, useCancelBooking, useFacilities, useMyBookings, type Booking, type Facility } from '@/api/asset';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';

type Tab = 'damage' | 'booking';

export default function Aset() {
  const canBook = useAuth((s) => s.can('facility.book'));
  const canReport = useAuth((s) => s.can('asset.maintenance.request'));
  const [tab, setTab] = useState<Tab>(canReport ? 'damage' : 'booking');
  return (
    <Screen header={<Hero back subtitle="Lapor kerusakan & pesan fasilitas" />}>
      <Segmented<Tab> value={tab} onChange={setTab} options={[...(canReport ? [{ key: 'damage' as Tab, label: 'Lapor kerusakan' }] : []), ...(canBook ? [{ key: 'booking' as Tab, label: 'Booking fasilitas' }] : [])]} />
      {!canReport && !canBook ? <EmptyState icon="lock-closed-outline" title="Belum ada akses" hint="Hubungi sarpras untuk mengaktifkan akses layanan ini." /> : null}
      {tab === 'damage' && canReport ? <DamageTab /> : null}
      {tab === 'booking' && canBook ? <BookingTab /> : null}
    </Screen>
  );
}

const SEVERITY = [
  { key: 'MINOR', label: 'Ringan' }, { key: 'MODERATE', label: 'Sedang' }, { key: 'MAJOR', label: 'Berat' }, { key: 'TOTAL', label: 'Rusak total' },
] as const;

function DamageTab() {
  const [code, setCode] = useState('');
  const [found, setFound] = useState<Awaited<ReturnType<typeof lookupAsset>> | null>(null);
  const [severity, setSeverity] = useState<string>('MODERATE');
  const [desc, setDesc] = useState('');
  const [busy, setBusy] = useState(false);

  const find = async () => {
    if (!code.trim()) return;
    setBusy(true);
    try { setFound(await lookupAsset(code)); } catch { setFound(null); Alert.alert('Tidak ditemukan', 'Kode aset tidak dikenali. Periksa label pada barang.'); } finally { setBusy(false); }
  };

  const send = async () => {
    if (!found) return;
    if (desc.trim().length < 5) return Alert.alert('Deskripsi wajib', 'Jelaskan kerusakannya.');
    setBusy(true);
    try {
      const r = await reportDamage({ code: found.asset_code, severity, description: desc.trim() });
      Alert.alert('Laporan terkirim', `No. ${r.report_number} — ${r.asset.name}`);
      setFound(null); setCode(''); setDesc('');
    } catch (e) { Alert.alert('Gagal', errorMessage(e)); } finally { setBusy(false); }
  };

  return (
    <>
      <TextArea label="Kode aset (dari label QR/barcode)" value={code} onChangeText={setCode} multiline={false} autoCapitalize="characters" placeholder="mis. AST-2026-00123" onSubmitEditing={find} />
      <Button label="Cari aset" variant="ghost" icon="search" onPress={find} loading={busy && !found} />
      {found ? (
        <>
          <Card>
            <Text style={{ fontSize: 17, fontWeight: '800', color: colors.ink }}>{found.name}</Text>
            <Kv k="Kode" v={found.asset_code} />
            <Kv k="Lokasi" v={found.location_name} />
            <Kv k="Status" v={found.status} />
            <Kv k="Kondisi" v={found.condition} />
          </Card>
          <Chips label="Tingkat kerusakan" value={severity} options={SEVERITY.map((s) => ({ key: s.key, label: s.label }))} onChange={setSeverity} />
          <TextArea label="Deskripsi kerusakan *" value={desc} onChangeText={setDesc} />
          <Button label="Kirim laporan" icon="send-outline" onPress={send} loading={busy} />
        </>
      ) : null}
    </>
  );
}

const BOOKING_STATUS: Record<string, { label: string; color: string }> = {
  REQUESTED: { label: 'Menunggu', color: colors.warning }, APPROVED: { label: 'Disetujui', color: colors.success }, REJECTED: { label: 'Ditolak', color: colors.danger },
  CANCELLED: { label: 'Dibatalkan', color: colors.muted }, COMPLETED: { label: 'Selesai', color: colors.info }, IN_USE: { label: 'Dipakai', color: colors.info },
};

function BookingTab() {
  const mine = useMyBookings();
  const [open, setOpen] = useState<'new' | Booking | null>(null);
  return (
    <>
      <Button label="Pesan fasilitas" icon="add-circle-outline" onPress={() => setOpen('new')} />
      {mine.isLoading ? <Loading /> : mine.error ? <ErrorNote message={errorMessage(mine.error)} onRetry={() => mine.refetch()} /> : null}
      {mine.data?.length === 0 ? <EmptyState icon="calendar-outline" title="Belum ada booking" /> : null}
      {mine.data?.map((b) => {
        const st = BOOKING_STATUS[b.status] ?? { label: b.status, color: colors.muted };
        return (
          <Card key={b.id} onPress={() => setOpen(b)}>
            <Row left={<Text style={T.title}>{b.title}</Text>} sub={<Text style={T.small}>{b.facility?.name} · {dayjs(b.start_at).format('D MMM HH:mm')}–{dayjs(b.end_at).format('HH:mm')}</Text>} right={<Pill label={st.label} color={st.color} />} />
          </Card>
        );
      })}
      {open === 'new' ? <NewBooking onClose={() => setOpen(null)} /> : null}
      {open && open !== 'new' ? <BookingDetail b={open} onClose={() => setOpen(null)} /> : null}
    </>
  );
}

function NewBooking({ onClose }: { onClose: () => void }) {
  const facilities = useFacilities();
  const book = useBook();
  const [facility, setFacility] = useState<Facility | null>(null);
  const [date, setDate] = useState(dayjs().add(1, 'day').format('YYYY-MM-DD'));
  const [from, setFrom] = useState('08:00');
  const [to, setTo] = useState('10:00');
  const [title, setTitle] = useState('');
  const [people, setPeople] = useState('');
  const avail = useAvailability(facility?.id ?? null, date);

  const submit = () => {
    if (!facility || !title.trim()) return Alert.alert('Lengkapi data', 'Pilih fasilitas dan isi keperluan.');
    if (!/^\d{2}:\d{2}$/.test(from) || !/^\d{2}:\d{2}$/.test(to)) return Alert.alert('Jam tidak valid', 'Gunakan format JJ:MM.');
    book.mutate(
      { facilityId: facility.id, title: title.trim(), start_at: `${date} ${from}:00`, end_at: `${date} ${to}:00`, attendee_count: people ? parseInt(people, 10) : undefined },
      { onSuccess: (b) => { Alert.alert('Booking dibuat', `No. ${b.booking_number}${b.requires_approval ? ' — menunggu persetujuan.' : ''}`); onClose(); }, onError: (e) => Alert.alert('Gagal', errorMessage(e)) },
    );
  };

  return (
    <Sheet visible title="Pesan fasilitas" onClose={onClose} footer={<Button label="Ajukan booking" icon="send-outline" onPress={submit} loading={book.isPending} />}>
      {facilities.isLoading ? <Loading /> : null}
      <Chips label="Fasilitas *" value={facility?.id ?? null} options={(facilities.data ?? []).map((f) => ({ key: f.id, label: f.name }))} onChange={(k) => setFacility(facilities.data?.find((f) => f.id === k) ?? null)} />
      <TextArea label="Tanggal (TTTT-BB-HH)" value={date} onChangeText={setDate} multiline={false} />
      {facility && avail.data ? (
        <Card>
          <Text style={T.title}>Jadwal terpakai pada {dayjs(date).format('D MMM')}</Text>
          {!avail.data.bookable ? <Text style={{ color: colors.danger, fontWeight: '700' }}>Fasilitas sedang tidak dapat dipesan ({avail.data.status}).</Text> : null}
          {avail.data.bookings.length === 0 ? <Text style={T.body}>Belum ada pemesanan.</Text> : avail.data.bookings.map((b) => (
            <Text key={b.id} style={T.body}>{dayjs(b.start_at).format('HH:mm')}–{dayjs(b.end_at).format('HH:mm')} · {b.title}</Text>
          ))}
        </Card>
      ) : null}
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <View style={{ flex: 1 }}><TextArea label="Mulai (JJ:MM)" value={from} onChangeText={setFrom} multiline={false} /></View>
        <View style={{ flex: 1 }}><TextArea label="Selesai (JJ:MM)" value={to} onChangeText={setTo} multiline={false} /></View>
      </View>
      <TextArea label="Keperluan *" value={title} onChangeText={setTitle} multiline={false} />
      <TextArea label="Jumlah peserta" value={people} onChangeText={setPeople} keyboardType="number-pad" multiline={false} />
    </Sheet>
  );
}

function BookingDetail({ b, onClose }: { b: Booking; onClose: () => void }) {
  const cancel = useCancelBooking();
  const [reason, setReason] = useState('');
  const open = ['REQUESTED', 'APPROVED'].includes(b.status);
  const go = () => {
    if (reason.trim().length < 3) return Alert.alert('Alasan wajib', 'Isi alasan pembatalan.');
    cancel.mutate({ id: b.id, reason: reason.trim() }, { onSuccess: onClose, onError: (e) => Alert.alert('Gagal', errorMessage(e)) });
  };
  return (
    <Sheet visible title={b.booking_number} onClose={onClose} footer={open ? <Button label="Batalkan booking" variant="ghost" onPress={go} loading={cancel.isPending} /> : undefined}>
      <Card>
        <Text style={{ fontSize: 17, fontWeight: '800', color: colors.ink }}>{b.title}</Text>
        <Kv k="Fasilitas" v={b.facility?.name} />
        <Kv k="Waktu" v={`${dayjs(b.start_at).format('D MMM YYYY HH:mm')} – ${dayjs(b.end_at).format('HH:mm')}`} />
        <Kv k="Status" v={BOOKING_STATUS[b.status]?.label ?? b.status} />
      </Card>
      {open ? <TextArea label="Alasan pembatalan" value={reason} onChangeText={setReason} /> : null}
    </Sheet>
  );
}
