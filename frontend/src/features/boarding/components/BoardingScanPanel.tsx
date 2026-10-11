import { useCallback, useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Html5Qrcode } from 'html5-qrcode';
import { Camera, CameraOff, Keyboard, ScanLine, SquarePen } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { FormField, fmtDateTime } from '../components/BoardingUi';
import { attendancesApi } from '../api/boardingApi';
import { useActivityOptions, useAttendanceTypeOptions } from '../hooks/useBoardingOptions';
import { getErrorMessage } from '@/lib/axios';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));
const today = () => new Date().toISOString().slice(0, 10);
const READER_ID = 'boarding-qr-reader';
/** Jeda minimum antar-scan kode yang sama — mencegah tembakan ganda reader kamera/RFID. */
const DEDUPE_MS = 2500;

type ScanMode = 'rfid' | 'qr' | 'manual';

interface ScanResultRow {
  id: number;
  at: string;
  name: string;
  nis: string;
  activityName: string;
  typeName: string;
  duplicate: boolean;
  ok: boolean;
  message: string;
}

/** Bunyi umpan balik pendek (satu AudioContext dibagi pakai). */
function useBeep() {
  const ctxRef = useRef<AudioContext | null>(null);

  return useCallback((kind: 'ok' | 'dup' | 'err') => {
    try {
      ctxRef.current ??= new AudioContext();
      const ctx = ctxRef.current;
      const notes: Array<[number, number]> =
        kind === 'ok' ? [[880, 90], [1320, 90]] : kind === 'dup' ? [[660, 140]] : [[220, 260]];
      let t = ctx.currentTime;
      for (const [freq, ms] of notes) {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.08, t);
        gain.gain.exponentialRampToValueAtTime(0.001, t + ms / 1000);
        osc.connect(gain).connect(ctx.destination);
        osc.start(t);
        osc.stop(t + ms / 1000);
        t += ms / 1000 + 0.02;
      }
    } catch {
      /* audio tidak tersedia — abaikan */
    }
  }, []);
}

/**
 * Layar pindai absensi: satu panel untuk tiga saluran input — kamera QR
 * (isi kode = NIS santri), reader RFID (keyboard wedge), dan ketik manual.
 * Dedupelikasi berlapis: di sisi klien (jeda per kode) dan di server
 * (unik per santri-kegiatan-tanggal-sesi).
 */
export function BoardingScanPanel() {
  const qc = useQueryClient();
  const beep = useBeep();
  const { options: activityOptions } = useActivityOptions();
  const { options: typeOptions } = useAttendanceTypeOptions();

  const [mode, setMode] = useState<ScanMode>('rfid');
  const [activityId, setActivityId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [typeId, setTypeId] = useState<number | ''>('');
  const [input, setInput] = useState('');
  const [scanning, setScanning] = useState(false);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [rows, setRows] = useState<ScanResultRow[]>([]);
  const [stats, setStats] = useState({ ok: 0, dup: 0, err: 0 });

  const scannerRef = useRef<Html5Qrcode | null>(null);
  const processingRef = useRef(false);
  const lastScanRef = useRef<Map<string, number>>(new Map());
  const inputRef = useRef<HTMLInputElement | null>(null);
  const counterRef = useRef(0);
  const activityRef = useRef<number | ''>('');
  activityRef.current = activityId;

  const focusInput = useCallback(() => {
    if (mode !== 'qr') inputRef.current?.focus();
  }, [mode]);

  const stopCamera = useCallback(async () => {
    const instance = scannerRef.current;
    scannerRef.current = null;
    if (instance) {
      try {
        await instance.stop();
        instance.clear();
      } catch {
        /* kamera mungkin belum mulai */
      }
    }
    setScanning(false);
  }, []);

  const scan = useMutation({
    mutationFn: (vars: { value: string; via: 'uid' | 'code' }) =>
      attendancesApi.scan({
        ...(vars.via === 'uid' ? { uid: vars.value } : { code: vars.value }),
        ...(activityId ? { activity_id: Number(activityId) } : {}),
        ...(typeId ? { attendance_type_id: Number(typeId) } : {}),
        date,
      }),
    onSuccess: (res) => {
      const data = res as {
        message?: string;
        already_recorded?: boolean;
        student?: { full_name?: string; nis?: string };
        activity?: { name?: string };
        attendance?: { attendance_type?: { name?: string } };
      };
      const duplicate = !!data?.already_recorded;
      const name = data?.student?.full_name ?? '-';
      beep(duplicate ? 'dup' : 'ok');
      counterRef.current += 1;
      setRows((list) => [
        {
          id: counterRef.current,
          at: new Date().toISOString(),
          name,
          nis: data?.student?.nis ?? '',
          activityName: data?.activity?.name ?? '',
          typeName: data?.attendance?.attendance_type?.name ?? '',
          duplicate,
          ok: true,
          message: data?.message ?? (duplicate ? 'Sudah tercatat' : 'Tercatat'),
        },
        ...list.slice(0, 14),
      ]);
      setStats((s) => (duplicate ? { ...s, dup: s.dup + 1 } : { ...s, ok: s.ok + 1 }));
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => {
      const message = getErrorMessage(err);
      beep('err');
      toast.error(message);
      counterRef.current += 1;
      setRows((list) => [
        {
          id: counterRef.current,
          at: new Date().toISOString(),
          name: message,
          nis: '',
          activityName: '',
          typeName: '',
          duplicate: false,
          ok: false,
          message,
        },
        ...list.slice(0, 14),
      ]);
      setStats((s) => ({ ...s, err: s.err + 1 }));
    },
    onSettled: () => {
      processingRef.current = false;
      focusInput();
    },
  });

  /** Satu pintu untuk ketiga saluran: dedupe klien + kunci proses. */
  const accept = useCallback(
    (raw: string, via: 'uid' | 'code') => {
      const value = raw.trim();
      if (value === '' || processingRef.current) return;

      const key = `${via}:${value.toUpperCase()}:${activityRef.current}:${date}`;
      const last = lastScanRef.current.get(key) ?? 0;
      if (Date.now() - last < DEDUPE_MS) return;
      lastScanRef.current.set(key, Date.now());

      processingRef.current = true;
      scan.mutate({ value, via });
    },
    [date, scan],
  );

  const startCamera = useCallback(async () => {
    setCameraError(null);
    try {
      const scanner = new Html5Qrcode(READER_ID);
      scannerRef.current = scanner;
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 240, height: 240 } },
        (decoded) => accept(decoded, 'code'),
        () => undefined,
      );
      setScanning(true);
    } catch {
      scannerRef.current = null;
      setCameraError('Browser tidak memiliki izin kamera atau ada kendala perangkat.');
    }
  }, [accept]);

  // Bersihkan kamera saat unmount / pindah mode.
  useEffect(() => {
    if (mode !== 'qr') void stopCamera();
    return () => {
      if (scannerRef.current) void stopCamera();
    };
  }, [mode, stopCamera]);

  const submitInput = () => {
    const value = input.trim();
    if (value === '') return;
    setInput('');
    accept(value, mode === 'rfid' ? 'uid' : 'code');
  };

  const ready = date !== '';

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-5">
      <div className="space-y-3 lg:col-span-3">
        <Card className="space-y-3 p-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <FormField label="Kegiatan (kosong = ikut jadwal)">
              <Select value={String(activityId)} onChange={(e) => setActivityId(toId(e.target.value))}>
                <option value="">-- Otomatis dari jadwal --</option>
                {activityOptions.map((o) => (
                  <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
                ))}
              </Select>
            </FormField>
            <FormField label="Status hasil scan (kosong = default)">
              <Select value={String(typeId)} onChange={(e) => setTypeId(toId(e.target.value))}>
                <option value="">-- Tipe default kegiatan --</option>
                {typeOptions.map((o) => (
                  <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
                ))}
              </Select>
            </FormField>
            <FormField label="Tanggal">
              <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            </FormField>
          </div>

          <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
            {(
              [
                { key: 'rfid', label: 'Tap RFID', icon: ScanLine },
                { key: 'qr', label: 'Kamera QR', icon: Camera },
                { key: 'manual', label: 'Ketik NIS', icon: SquarePen },
              ] as Array<{ key: ScanMode; label: string; icon: typeof ScanLine }>
            ).map(({ key, label, icon: Icon }) => (
              <Button
                key={key}
                size="sm"
                variant={mode === key ? 'primary' : 'outline'}
                onClick={() => setMode(key)}
              >
                <Icon className="h-3.5 w-3.5" /> {label}
              </Button>
            ))}
          </div>

          {mode === 'qr' ? (
            <div className="space-y-2">
              <div className="flex gap-2">
                {scanning ? (
                  <Button size="sm" variant="danger" onClick={() => void stopCamera()}>
                    <CameraOff className="h-3.5 w-3.5" /> Matikan Kamera
                  </Button>
                ) : (
                  <Button size="sm" onClick={() => void startCamera()} disabled={!ready}>
                    <Camera className="h-3.5 w-3.5" /> Nyalakan Kamera
                  </Button>
                )}
              </div>
              <div id={READER_ID} className="w-full max-w-sm overflow-hidden rounded-lg border border-slate-200" />
              {cameraError && <p className="text-sm text-red-600">{cameraError}</p>}
              <p className="text-xs text-slate-400">
                Arahkan QR pada kartu santri (isi kode = NIS). Hasil pindai sama dengan ketik manual.
              </p>
            </div>
          ) : (
            <FormField label={mode === 'rfid' ? 'Tempel kartu RFID lalu Enter' : 'Ketik NIS santri lalu Enter'}>
              <div className="flex gap-2">
                <Input
                  ref={inputRef}
                  autoFocus
                  value={input}
                  placeholder={mode === 'rfid' ? 'UID kartu (contoh: A1B2C3D4)...' : 'NIS santri...'}
                  onChange={(e) => setInput(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && submitInput()}
                />
                <Button variant="outline" onClick={submitInput} disabled={input.trim() === '' || scan.isPending}>
                  <Keyboard className="h-4 w-4" /> Kirim
                </Button>
              </div>
            </FormField>
          )}
        </Card>

        <Card className="p-4">
          <div className="grid grid-cols-3 gap-3 text-center">
            <div>
              <p className="text-2xl font-semibold text-emerald-600">{stats.ok}</p>
              <p className="text-xs text-slate-500">Tercatat baru</p>
            </div>
            <div>
              <p className="text-2xl font-semibold text-amber-600">{stats.dup}</p>
              <p className="text-xs text-slate-500">Sudah tercatat</p>
            </div>
            <div>
              <p className="text-2xl font-semibold text-red-600">{stats.err}</p>
              <p className="text-xs text-slate-500">Ditolak</p>
            </div>
          </div>
        </Card>
      </div>

      <Card className="p-4 lg:col-span-2">
        <p className="mb-2 text-sm font-medium text-slate-700">Riwayat pindai sesi ini</p>
        {rows.length === 0 ? (
          <p className="py-10 text-center text-sm text-slate-400">Belum ada pindaian.</p>
        ) : (
          <ul className="divide-y divide-slate-50">
            {rows.map((row) => (
              <li key={row.id} className="flex items-start justify-between gap-2 py-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-slate-800">{row.name}</p>
                  <p className="text-xs text-slate-400">
                    {row.nis}
                    {row.activityName ? ` • ${row.activityName}` : ''}
                    {row.typeName ? ` • ${row.typeName}` : ''}
                  </p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                  <Badge tone={!row.ok ? 'red' : row.duplicate ? 'amber' : 'green'}>
                    {row.ok ? (row.duplicate ? 'Duplikat' : 'OK') : 'Gagal'}
                  </Badge>
                  <span className="text-[10px] text-slate-400">{fmtDateTime(row.at)}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
