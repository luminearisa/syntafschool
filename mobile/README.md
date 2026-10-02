# Syntaf School — Aplikasi Mobile

Satu aplikasi untuk **wali murid, siswa, dan karyawan** (Android · iOS · Web), dibangun dengan
Expo + React Native + TypeScript + Expo Router. Terhubung ke API Laravel yang sama dengan ERP web.

## Satu akun, banyak peran

Akun yang terhubung ke lebih dari satu identitas (mis. guru yang juga wali murid) memilih peran
setelah login dan dapat berganti kapan saja lewat lencana peran di header (*Ganti peran*).

- `POST /auth/login` dengan `client: "mobile"` → token awal `ctx:pending` + daftar peran
  (satu peran saja → langsung terpilih).
- `POST /auth/switch-context` → token baru ber-ability `ctx:employee | ctx:guardian | ctx:student`;
  token lama dicabut.
- **Ditegakkan di server** (`EnforceContext`): token wali/siswa hanya boleh memanggil `auth/*` dan
  `mobile/*`, sehingga role pegawai pada akun yang sama tidak bocor ke mode wali. Data anak dibatasi
  kepemilikan (`student_guardians`); anak orang lain → 404.
- Admin menautkan identitas tambahan lewat `POST /users/{user}/identities` (tabel `user_identities`).
  `GET /users/{user}/identities/suggestions` hanya memberi petunjuk (telepon/email sama);
  penautan selalu dikonfirmasi admin.

## Menjalankan

```bash
cd mobile
cp .env.example .env        # EXPO_PUBLIC_API_URL → alamat API (HP fisik: IP LAN, bukan localhost)
npm install
npm start                   # lalu tekan a (Android) / i (iOS) / w (web)
npm run typecheck
npm run export:web          # build web statis ke dist/
```

## Catatan

- **Tanpa Firebase.** Kabar/notifikasi memakai inbox (`/mobile/notifications`) dengan polling 60 dtk
  saat aplikasi aktif. Push saat aplikasi tertutup sengaja belum ada; bila nanti dibutuhkan,
  tambahkan kanal di backend tanpa mengubah layar.
- Token disimpan di Keychain/Keystore (`expo-secure-store`); di web memakai `localStorage`.
- Motif latar: bintang segi-delapan (`src/components/Pattern.tsx`), palet di `src/theme.ts`.
