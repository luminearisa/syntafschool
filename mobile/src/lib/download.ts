import { Platform } from 'react-native';
import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { api } from '@/api/client';
import { useAuth } from '@/store/auth';

/**
 * Unduh berkas yang dilindungi token (PDF rapor, dll).
 * Web: ambil sebagai blob lalu picu unduhan peramban. Native: simpan ke cache lalu buka lembar bagikan.
 */
export async function downloadAuthed(path: string, filename: string): Promise<void> {
  if (Platform.OS === 'web') {
    const res = await api.get<Blob>(path, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
    return;
  }
  const token = useAuth.getState().token;
  const file = await File.downloadFileAsync(`${api.defaults.baseURL}${path}`, new File(Paths.cache, filename), {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' }, idempotent: true,
  });
  if (await Sharing.isAvailableAsync()) await Sharing.shareAsync(file.uri, { mimeType: 'application/pdf', dialogTitle: filename });
}
