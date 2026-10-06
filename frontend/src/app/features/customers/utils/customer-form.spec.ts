import { displayFipeYear, parseFipeYear, pickFipeYear, sortFipeYears } from './customer-form';

describe('anos da Tabela FIPE', () => {
  it('extrai ano e combustível', () => {
    expect(parseFipeYear({ code: '2013-5', name: '2013 Flex' })).toEqual({ year: 2013, fuel: 'flex' });
    expect(parseFipeYear({ code: '2004-3', name: '2004 Diesel' })).toEqual({ year: 2004, fuel: 'diesel' });
    expect(parseFipeYear({ code: '1998-2', name: '1998 Álcool' })).toEqual({ year: 1998, fuel: 'ethanol' });
  });

  it('trata o ano 32000 como Zero KM (ano atual)', () => {
    const { year, fuel } = parseFipeYear({ code: '32000-1', name: '32000 Gasolina' });
    expect(year).toBe(new Date().getFullYear());
    expect(fuel).toBe('gasoline');
    expect(displayFipeYear({ code: '32000-1', name: '32000 Gasolina' }).name).toBe('Zero KM · Gasolina');
  });

  it('ordena: Zero KM, depois do mais novo ao mais antigo, e por combustível no mesmo ano', () => {
    const sorted = sortFipeYears([
      { code: '2021-5', name: '2021 Flex' },
      { code: '2026-1', name: '2026 Gasolina' },
      { code: '32000-5', name: '32000 Flex' },
      { code: '2026-5', name: '2026 Flex' },
      { code: '2026-3', name: '2026 Diesel' },
    ]);
    expect(sorted.map((item) => item.code)).toEqual(['32000-5', '2026-3', '2026-5', '2026-1', '2021-5']);
  });

  it('escolhe o ano da FIPE que corresponde à placa', () => {
    const years = [
      { code: '2022-1', name: '2022 Gasolina' },
      { code: '2022-5', name: '2022 Flex' },
      { code: '2021-5', name: '2021 Flex' },
    ];
    // Combustível da correspondência FIPE tem prioridade
    expect(pickFipeYear(years, 2022, 'Gasolina', 'flex')?.code).toBe('2022-1');
    // Sem ele, usa o combustível do documento
    expect(pickFipeYear(years, 2022, null, 'flex')?.code).toBe('2022-5');
    // Sem combustível, o primeiro do ano
    expect(pickFipeYear(years, 2022, null, null)?.code).toBe('2022-1');
    // Ano inexistente na marca
    expect(pickFipeYear(years, 2019, null, null)).toBeNull();
  });

  it('formata o nome para exibição', () => {
    expect(displayFipeYear({ code: '2020-5', name: '2020 Flex' }).name).toBe('2020 · Flex');
  });
});
