import { api } from '@/lib/axios';
import type {
  ApiResponse,
  LoginCredentials,
  LoginResult,
  User,
} from '@/types';

export const authApi = {
  async login(credentials: LoginCredentials): Promise<LoginResult> {
    const res = await api.post<ApiResponse<LoginResult>>('/auth/login', {
      device_name: 'web',
      ...credentials,
    });
    return res.data.data;
  },

  async me(): Promise<User> {
    const res = await api.get<ApiResponse<User>>('/auth/me');
    return res.data.data;
  },

  async logout(): Promise<void> {
    await api.post('/auth/logout');
  },
};
