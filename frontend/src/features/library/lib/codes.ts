import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

/**
 * QR asli (dapat dipindai) memakai pustaka `qrcode`.
 *
 * Dipakai kartu anggota (token kartu) dan label eksemplar (kode eksemplar).
 * Nilai yang dikodekan tetap berasal dari server — pustaka ini hanya
 * menggambar, bukan membuat token.
 */
export function useQrCodeDataUrl(value: string | null | undefined, size = 220): string | null {
  const [dataUrl, setDataUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    if (!value) {
      setDataUrl(null);
      return () => {
        cancelled = true;
      };
    }

    QRCode.toDataURL(value, { width: size, margin: 1, errorCorrectionLevel: 'M' })
      .then((url) => {
        if (!cancelled) setDataUrl(url);
      })
      .catch(() => {
        if (!cancelled) setDataUrl(null);
      });

    return () => {
      cancelled = true;
    };
  }, [value, size]);

  return dataUrl;
}