export type UserRole = 'master' | 'admin';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  role_label: string;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string | null;
}

export interface LoginCredentials {
  email: string;
  password: string;
  remember: boolean;
}
