import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';
import JsBarcode from 'jsbarcode';
import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import type { AssetLabel } from '../types';

/**
 * Lencana label siap cetak.
 *
 * Sengaja hanya memuat **identitas fisik**: kode, nama, lokasi, kondisi.
 * Tidak ada harga, tidak ada pemegang, tidak ada nomor seri — label tertempel
 * di barang yang dapat dibaca siapa pun yang memegangnya.
 */
export function AssetLabelCard({
  label,
  size = 'md',
}: {
  label: AssetLabel;
  size?: 'sm' | 'md';
}) {
  const qrRef = useRef<HTMLCanvasElement>(null);
  const barcodeRef = useRef<SVGSVGElement>(null);
  const [qrError, setQrError] = useState<string | null>(null);

  const payload = label.qr_payload ?? label.asset_code;

  useEffect(() => {
    if (qrRef.current) {
      QRCode.toCanvas(qrRef.current, payload, { width: size === 'sm' ? 96 : 128, margin: 1 }, (err) => {
        if (err) setQrError('QR gagal dibuat');
      });
    }
  }, [payload, size]);

  useEffect(() => {
    if (!barcodeRef.current || !label.barcode) return;
    try {
      JsBarcode(barcodeRef.current, label.barcode, {
        format: 'CODE128',
        displayValue: true,
        fontSize: size === 'sm' ? 10 : 12,
        height: size === 'sm' ? 32 : 40,
        margin: 2,
      });
    } catch {
      /* barcode yang tidak valid cukup tampil sebagai QR saja */
    }
  }, [label.barcode, size]);

  return (
    <div className="flex items-start gap-3 rounded-lg border border-dashed border-slate-300 bg-white p-3">
      <div className="shrink-0">
        <canvas ref={qrRef} className="h-auto w-auto" />
        {qrError && <p className="text-[10px] text-red-600">{qrError}</p>}
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-slate-800">{label.name}</p>
        <p className="font-mono text-xs text-slate-600">{label.asset_code}</p>
        {label.location && <p className="truncate text-[11px] text-slate-500">{label.location}</p>}
        <p className="text-[11px] text-slate-500">
          {label.status ?? '-'} · {label.condition ?? '-'}
        </p>
        {label.barcode && <svg ref={barcodeRef} className="mt-1 max-w-full" />}
      </div>
    </div>
  );
}

/** Sakelar "tampilkan versi cetak" untuk satu atau banyak label sekaligus. */
export function LabelPrintButton({
  labels,
  disabled,
  onPrint,
}: {
  labels: AssetLabel[];
  disabled?: boolean;
  onPrint?: () => void;
}) {
  const cetak = () => {
    onPrint?.();
    window.print();
  };

  return (
    <Button variant="outline" size="sm" onClick={cetak} disabled={disabled || labels.length === 0}>
      <Printer className="mr-1 h-4 w-4" /> Cetak {labels.length > 1 ? `${labels.length} label` : 'label'}
    </Button>
  );
}
