import { formatStock } from './part';

describe('formatStock', () => {
  it('mostra decimais com vírgula e o sinal quando o estoque fica negativo', () => {
    expect(formatStock(4.5)).toBe('4,5');
    expect(formatStock(10)).toBe('10');
    expect(formatStock(-1.25)).toBe('−1,25');
  });
});
