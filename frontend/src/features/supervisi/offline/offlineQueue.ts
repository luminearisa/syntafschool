/**
 * Antrean jawaban luring (mode luring, Fase 5).
 *
 * Observasi kelas sering terjadi di ruang tanpa sinyal. Modul ini menyimpan
 * jawaban di perangkat lebih dulu, lalu mengirimkannya saat jaringan kembali.
 *
 * Prinsip yang dijaga:
 *  - **Satu jawaban = satu `client_uuid`.** Server menyimpan jawaban per
 *    indikator (`visit_id` + `indicator_id`) dan mengenali ulangan lewat
 *    `client_uuid`, sehingga pengiriman berulang tidak pernah menjadi ganda.
 *  - **Tidak hilang saat ditutup.** Antrean disimpan di `localStorage`, jadi
 *    menutup tab atau kehabisan baterai tidak menghapus pekerjaan supervisor.
 *  - **Tidak menimpa keputusan server.** Baris yang di server sudah lebih baru
 *    dilaporkan sebagai *konflik* oleh server; antrean menghapusnya dari daftar
 *    tunggu dan meminta supervisor memeriksa ulang — bukan mengirim diam-diam.
 */

export interface QueuedAnswer {
  indicator_id: number;
  option_id?: number | null;
  option_code?: string | null;
  value_number?: number | null;
  value_text?: string | null;
  value_long_text?: string | null;
  is_na?: boolean;
  comment?: string | null;
  client_uuid: string;
  /** Waktu perangkat merekam — dipakai server untuk mendeteksi konflik. */
  captured_at: string;
  /** Label untuk ditampilkan di daftar tunggu (tidak dikirim ke server). */
  indicator_label?: string;
  value_label?: string;
}

export interface SyncResult {
  applied: number;
  skipped: Array<{ indicator_id: number }>;
  rejected: Array<{ indicator_id: number; reason: string }>;
  conflicts: Array<{ indicator_id: number; statement?: string; reason: string }>;
  visit: { answers_count: number; is_locked: boolean };
  server_time: string;
}

const STORAGE_PREFIX = 'supervisi:offline:';

const keyFor = (visitId: number) => `${STORAGE_PREFIX}${visitId}`;

const available = (): boolean => typeof window !== 'undefined' && window.localStorage !== undefined;

/** Buat uuid sederhana tanpa dependensi (cukup unik per perangkat). */
export const newClientUuid = (): string => {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }

  return `c-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
};

/** Seluruh jawaban yang menunggu dikirim untuk satu kegiatan. */
export function pendingAnswers(visitId: number): QueuedAnswer[] {
  if (!available()) return [];

  try {
    const raw = window.localStorage.getItem(keyFor(visitId));
    const parsed = raw === null ? [] : (JSON.parse(raw) as QueuedAnswer[]);

    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

/**
 * Tambahkan/segarkan satu jawaban di antrean.
 *
 * Satu indikator hanya menempati satu baris: ketikan terbaru menggantikan yang
 * lama (supervisor boleh mengubah nilai sebelum sempat terkirim).
 */
export function queueAnswer(visitId: number, answer: Omit<QueuedAnswer, 'client_uuid' | 'captured_at'> & { client_uuid?: string }): QueuedAnswer[] {
  const existing = pendingAnswers(visitId).filter((row) => row.indicator_id !== answer.indicator_id);

  const row: QueuedAnswer = {
    ...answer,
    client_uuid: answer.client_uuid ?? newClientUuid(),
    captured_at: new Date().toISOString(),
  };

  const next = [...existing, row];
  write(visitId, next);

  return next;
}

/** Ganti seluruh antrean (mis. saat menyimpan draf halaman). */
export function replaceQueue(visitId: number, answers: QueuedAnswer[]): QueuedAnswer[] {
  write(visitId, answers);

  return answers;
}

export function clearQueue(visitId: number): void {
  if (!available()) return;

  window.localStorage.removeItem(keyFor(visitId));
}

/** Buang baris yang sudah diterima server (terkirim/dilewati/konflik/ditolak). */
export function removeFromQueue(visitId: number, indicatorIds: number[]): QueuedAnswer[] {
  const drop = new Set(indicatorIds);
  const next = pendingAnswers(visitId).filter((row) => !drop.has(row.indicator_id));
  write(visitId, next);

  return next;
}

function write(visitId: number, answers: QueuedAnswer[]): void {
  if (!available()) return;

  try {
    window.localStorage.setItem(keyFor(visitId), JSON.stringify(answers));
  } catch {
    // Penyimpanan penuh/diblokir: antrean tetap hidup di memori pemanggil.
  }
}

/** Apakah peramban sedang daring? */
export const isOnline = (): boolean => typeof navigator === 'undefined' || navigator.onLine !== false;
