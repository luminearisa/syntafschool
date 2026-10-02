/** Kunci unik per aksi — dikirim sebagai `Idempotency-Key` agar ketuk ganda/ulang jaringan tidak memproses dua kali. */
export const newIdempotencyKey = (prefix = 'm'): string =>
  `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
