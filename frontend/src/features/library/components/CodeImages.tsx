import { useEffect, useRef } from 'react';
import JsBarcode from 'jsbarcode';
import { useQrCodeDataUrl } from '../lib/codes';
import { cn } from '@/lib/utils';

/**
 * QR asli (dapat dipindai) dari sebuah nilai.
 *
 * Sementara QR dibuat di klien, nilainya tetap milik server (token kartu /
 * kode eksemplar) — tidak ada token yang dikarang di sisi klien.
 */
export function QrImage({
  value,
  size = 200,
  className,
  alt = 'Kode QR',
}: {
  value: string | null | undefined;
  size?: number;
  className?: string;
  alt?: string;
}) {
  const dataUrl = useQrCodeDataUrl(value, size);

  if (!dataUrl) {
    return (
      <div
        className={cn(
          'flex items-center justify-center rounded border border-dashed border-slate-300 text-[10px] text-slate-400',
          className,
        )}
        style={{ width: size, height: size }}
      >
        QR belum tersedia
      </div>
    );
  }

  return (
    <img
      src={dataUrl}
      alt={alt}
      width={size}
      height={size}
      className={cn('rounded bg-white', className)}
    />
  );
}

/**
 * Barcode Code 128 asli memakai JsBarcode (bukan pratinjau pola).
 *
 * `displayValue` menampilkan nilainya di bawah barcode agar petugas tetap
 * dapat membaca secara manual bila pemindai gagal.
 */
export function BarcodeSvg({
  value,
  height = 48,
  width = 2,
  className,
  displayValue = true,
}: {
  value: string | null | undefined;
  height?: number;
  width?: number;
  className?: string;
  displayValue?: boolean;
}) {
  const ref = useRef<SVGSVGElement>(null);

  useEffect(() => {
    const element = ref.current;

    if (!element) return;

    if (!value) {
      element.innerHTML = '';
      return;
    }

    try {
      JsBarcode(element, value, {
        format: 'CODE128',
        displayValue,
        height,
        width,
        margin: 0,
        fontSize: 12,
      });
    } catch {
      element.innerHTML = '';
    }
  }, [value, height, width, displayValue]);

  if (!value) {
    return (
      <div className={cn('text-[10px] text-slate-400', className)}>Barcode belum tersedia</div>
    );
  }

  return <svg ref={ref} className={className} />;
}