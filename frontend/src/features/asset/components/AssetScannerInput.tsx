import { useEffect, useRef, useState } from 'react';
import { Camera, CameraOff, ScanLine } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';

/**
 * Kolom pemindaian yang bekerja dengan **pemindai genggam apa pun**.
 *
 * Pemindai barcode/QR di pasaran bekerja sebagai papan ketik: karakter dikirim
 * sangat cepat lalu diakhiri Enter. Karena itu kolom ini:
 *   1. menyimpan fokus otomatis (operator tidak perlu menekan apa pun), dan
 *   2. mengirim nilai saat Enter — tanpa tombol "Cari" per barang.
 *
 * Kamera hanya menjadi *jalan alternatif*: bila browser tidak menyediakan
 * `BarcodeDetector` (umumnya Safari/Firefox), tombol kamera menyatakan dirinya
 * tidak tersedia alih-alih diam-diam gagal.
 */
export function AssetScannerInput({
  onScan,
  placeholder = 'Pindai QR/barcode atau ketik kode aset…',
  disabled,
  autoFocus = true,
  hint,
}: {
  onScan: (code: string) => void;
  placeholder?: string;
  disabled?: boolean;
  autoFocus?: boolean;
  hint?: string;
}) {
  const [value, setValue] = useState('');
  const [kameraAktif, setKameraAktif] = useState(false);
  const [kameraPesan, setKameraPesan] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const videoRef = useRef<HTMLVideoElement>(null);

  const kirim = () => {
    const kode = value.trim();
    if (!kode) return;
    onScan(kode);
    setValue('');
    inputRef.current?.focus();
  };

  useEffect(() => {
    if (autoFocus && !disabled && !kameraAktif) {
      inputRef.current?.focus();
    }
  }, [autoFocus, disabled, kameraAktif]);

  // Deteksi kamera sekali per perubahan status agar tidak meminta izin berulang.
  useEffect(() => {
    if (!kameraAktif) return;
    let stream: MediaStream | null = null;
    let timer: number | null = null;

    const jalankan = async () => {
      const adaDetektor = 'BarcodeDetector' in window;
      if (!adaDetektor) {
        setKameraPesan('Browser ini belum mendukung pemindaian lewat kamera. Gunakan pemindai genggam atau ketik kodenya.');
        setKameraAktif(false);
        return;
      }

      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          await videoRef.current.play();
        }

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const detector = new (window as any).BarcodeDetector({ formats: ['qr_code', 'code_128', 'ean_13', 'code_39'] });
        timer = window.setInterval(async () => {
          if (!videoRef.current) return;
          try {
            const hasil = await detector.detect(videoRef.current);
            const pertama = hasil?.[0]?.rawValue as string | undefined;
            if (pertama) {
              onScan(pertama);
              setKameraAktif(false);
            }
          } catch {
            /* bingkai yang gagal dibaca bukan galat: coba bingkai berikutnya */
          }
        }, 400);
      } catch {
        setKameraPesan('Izin kamera ditolak. Gunakan pemindai genggam atau ketik kodenya.');
        setKameraAktif(false);
      }
    };

    void jalankan();

    return () => {
      if (timer) window.clearInterval(timer);
      stream?.getTracks().forEach((t) => t.stop());
    };
  }, [kameraAktif, onScan]);

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <ScanLine className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <Input
            ref={inputRef}
            value={value}
            disabled={disabled}
            placeholder={placeholder}
            className="pl-9"
            onChange={(e) => setValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                kirim();
              }
            }}
          />
        </div>
        <Button type="button" variant="outline" size="sm" onClick={kirim} disabled={disabled || !value.trim()}>
          Catat
        </Button>
        <Button
          type="button"
          variant={kameraAktif ? 'danger' : 'ghost'}
          size="icon"
          title={kameraAktif ? 'Matikan kamera' : 'Pindai dengan kamera'}
          onClick={() => {
            setKameraPesan(null);
            setKameraAktif((v) => !v);
          }}
          disabled={disabled}
        >
          {kameraAktif ? <CameraOff className="h-4 w-4" /> : <Camera className="h-4 w-4" />}
        </Button>
      </div>

      {hint && !kameraPesan && <p className="text-[11px] text-slate-500">{hint}</p>}
      {kameraPesan && <p className="text-[11px] text-amber-700">{kameraPesan}</p>}

      {kameraAktif && (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-slate-900">
          <video ref={videoRef} className="h-48 w-full object-cover" muted playsInline />
        </div>
      )}
    </div>
  );
}
