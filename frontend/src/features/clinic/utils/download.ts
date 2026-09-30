/**
 * Utilitas unduh berkas untuk modul Clinic.
 *
 * Berkas diambil sebagai blob lewat klien API (yang menyertakan token
 * otorisasi), lalu disimpan dari memori. Pola ini menghindari tautan unduhan
 * publik yang bisa ditebak — penting untuk dokumen kesehatan.
 */
export function downloadBlob(blob: Blob, filename: string): void {
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.URL.revokeObjectURL(url);
}

/** Ekspor laporan CSV: ambil berkas lalu simpan. */
export async function downloadCsv(generate: () => Promise<{ blob: Blob; filename: string }>): Promise<void> {
  const { blob, filename } = await generate();
  downloadBlob(blob, filename);
}
