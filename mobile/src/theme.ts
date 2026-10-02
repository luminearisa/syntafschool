// Identitas visual Syntaf School: hijau zamrud dalam + emas hangat di atas
// kanvas krem, dengan motif bintang segi-delapan (khazanah geometri Islam).
export const colors = {
  primary: '#0B4A3C',
  primaryMid: '#116149',
  primarySoft: '#E3F1EC',
  gold: '#C9A24A',
  goldSoft: '#F7EFD9',
  bg: '#F6F4EE',
  card: '#FFFFFF',
  ink: '#14231F',
  inkSoft: '#4B5B56',
  muted: '#8A9792',
  line: '#E6E2D6',
  success: '#1E8E5A',
  warning: '#D98A1F',
  danger: '#C8423B',
  info: '#2F6FB2',
} as const;

export const roleMeta = {
  employee: { label: 'Karyawan', icon: 'briefcase' as const, tint: '#116149' },
  guardian: { label: 'Wali Murid', icon: 'people' as const, tint: '#B8892B' },
  student: { label: 'Siswa', icon: 'school' as const, tint: '#2F6FB2' },
};

export const radius = { sm: 10, md: 16, lg: 22, pill: 999 } as const;
export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 } as const;

export const shadow = {
  shadowColor: '#0B2A22',
  shadowOpacity: 0.08,
  shadowRadius: 14,
  shadowOffset: { width: 0, height: 6 },
  elevation: 3,
} as const;
