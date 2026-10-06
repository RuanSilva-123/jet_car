import { UserRole } from '../../../core/auth/models/user';

export interface RoleOption {
  value: UserRole;
  label: string;
  description: string;
}

export const ROLE_OPTIONS: RoleOption[] = [
  { value: 'admin', label: 'Administrador', description: 'Usa o painel no dia a dia.' },
  { value: 'master', label: 'Administrador Master', description: 'Acesso total, inclusive à gestão de usuários.' },
];

/**
 * Gera uma senha forte que atende a regra da API (mín. 8, letras e números).
 * Sem caracteres ambíguos (0/O, 1/l/I) para facilitar repassar ao funcionário.
 */
export function generatePassword(length = 12): string {
  const letters = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
  const digits = '23456789';
  const all = letters + digits;

  const random = new Uint32Array(length);
  crypto.getRandomValues(random);

  const chars = Array.from(random, (value) => all[value % all.length]);
  // Garante ao menos uma letra e um número
  chars[0] = letters[random[0] % letters.length];
  chars[1] = digits[random[1] % digits.length];

  // Embaralha (Fisher–Yates) para as posições garantidas não ficarem previsíveis
  const shuffle = new Uint32Array(length);
  crypto.getRandomValues(shuffle);
  for (let i = chars.length - 1; i > 0; i--) {
    const j = shuffle[i] % (i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }

  return chars.join('');
}
