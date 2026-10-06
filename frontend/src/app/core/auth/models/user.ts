export type UserRole = 'master' | 'admin' | 'mechanic';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  role_label: string;
  /** Pagamentos, contas a receber e relatórios (o mecânico não acessa). */
  can_manage_finance?: boolean;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string | null;
}

export interface LoginCredentials {
  email: string;
  password: string;
  remember: boolean;
}
