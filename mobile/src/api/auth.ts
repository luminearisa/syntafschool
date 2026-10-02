import { Platform } from 'react-native';
import { post } from './client';
import type { AuthPayload, RoleType } from './types';

export const authApi = {
  login: (email: string, password: string) =>
    post<AuthPayload>('/auth/login', { email, password, client: 'mobile', device_name: `app-${Platform.OS}` }),
  switchContext: (context: RoleType) => post<AuthPayload>('/auth/switch-context', { context }),
  logout: () => post<null>('/auth/logout'),
};
