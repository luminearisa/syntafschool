import { Alert as NativeAlert, Platform } from 'react-native';

interface Button { text?: string; style?: 'default' | 'cancel' | 'destructive'; onPress?: () => void }

/**
 * Pengganti `Alert` React Native yang juga berfungsi di web.
 * `Alert.alert` bawaan adalah no-op di react-native-web (konfirmasi & pesan galat
 * tidak muncul sama sekali), sehingga tombol keluar/setujui diam saja di web.
 * API sama persis; di web memakai `window.alert` / `window.confirm`.
 */
export const Alert = {
  alert(title: string, message?: string, buttons?: Button[]): void {
    if (Platform.OS !== 'web') {
      NativeAlert.alert(title, message, buttons);
      return;
    }

    const text = message ? `${title}\n\n${message}` : title;
    const actions = (buttons ?? []).filter((b) => b.style !== 'cancel');

    if ((buttons?.length ?? 0) > 1 && actions.length > 0) {
      if (globalThis.confirm?.(text)) actions[0]!.onPress?.();
      return;
    }

    globalThis.alert?.(text);
    buttons?.[0]?.onPress?.();
  },
};
