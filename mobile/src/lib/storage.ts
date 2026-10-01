import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

// Token disimpan di Keychain/Keystore pada perangkat; di web memakai localStorage.
export const secureStorage = {
  async getItem(key: string): Promise<string | null> {
    try {
      if (Platform.OS === 'web') return globalThis.localStorage?.getItem(key) ?? null;
      return await SecureStore.getItemAsync(key);
    } catch {
      return null;
    }
  },
  async setItem(key: string, value: string): Promise<void> {
    try {
      if (Platform.OS === 'web') globalThis.localStorage?.setItem(key, value);
      else await SecureStore.setItemAsync(key, value);
    } catch {
      /* penyimpanan tidak tersedia: sesi hanya hidup di memori */
    }
  },
  async removeItem(key: string): Promise<void> {
    try {
      if (Platform.OS === 'web') globalThis.localStorage?.removeItem(key);
      else await SecureStore.deleteItemAsync(key);
    } catch {
      /* abaikan */
    }
  },
};
