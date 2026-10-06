import { initials } from '../../../shared/utils/initials';
import { generatePassword } from './user-display';

describe('generatePassword', () => {
  it('atende a regra da API (mín. 8, letras e números) em todas as gerações', () => {
    for (let i = 0; i < 200; i++) {
      const password = generatePassword();
      expect(password).toHaveLength(12);
      expect(password).toMatch(/[a-zA-Z]/);
      expect(password).toMatch(/\d/);
    }
  });

  it('não usa caracteres ambíguos', () => {
    const all = Array.from({ length: 200 }, () => generatePassword()).join('');
    expect(all).not.toMatch(/[0O1lI]/);
  });

  it('gera senhas diferentes', () => {
    const passwords = new Set(Array.from({ length: 50 }, () => generatePassword()));
    expect(passwords.size).toBe(50);
  });
});

describe('initials', () => {
  it('usa as iniciais das duas primeiras palavras', () => {
    expect(initials('maria  da silva')).toBe('MD');
    expect(initials('João')).toBe('J');
    expect(initials('')).toBe('');
  });
});
