import { formatBytes, formatMoney, formatPixKey } from './br-format';

describe('formatBytes', () => {
  it('usa a unidade certa com vírgula decimal', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(1536)).toBe('1,5 KB');
    expect(formatBytes(5 * 1024 * 1024)).toBe('5 MB');
    expect(formatBytes(null)).toBe('0 B');
  });
});

describe('formatPixKey', () => {
  it('formata CPF, CNPJ e celular como a pessoa digita', () => {
    expect(formatPixKey('cpf', '52998224725')).toBe('529.982.247-25');
    expect(formatPixKey('cnpj', '11222333000181')).toBe('11.222.333/0001-81');
    expect(formatPixKey('phone', '+5511987654321')).toBe('(11) 98765-4321');
  });

  it('mantém e-mail e chave aleatória como estão', () => {
    expect(formatPixKey('email', 'pix@oficina.com.br')).toBe('pix@oficina.com.br');
    expect(formatPixKey('random', '123e4567-e89b-12d3-a456-426614174000')).toBe('123e4567-e89b-12d3-a456-426614174000');
    expect(formatPixKey('cpf', '')).toBe('');
  });
});

describe('formatMoney', () => {
  it('mostra o sinal de valores negativos (saldo do caixa)', () => {
    expect(formatMoney(123456)).toBe('R$ 1.234,56');
    expect(formatMoney(-552090)).toBe('−R$ 5.520,90');
    expect(formatMoney(null)).toBe('R$ 0,00');
  });
});
