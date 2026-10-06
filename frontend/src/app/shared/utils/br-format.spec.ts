import { FormControl } from '@angular/forms';

import { applyPattern, formatCep, formatDocument, formatMileage, formatPhone, formatPlate } from './br-format';
import { documentValidator, isValidCnpj, isValidCpf, phoneValidator, plateValidator } from './br-validators';

describe('formatação brasileira', () => {
  it('aplica o padrão progressivamente durante a digitação', () => {
    expect(applyPattern('529', '000.000.000-00')).toBe('529');
    expect(applyPattern('5299', '000.000.000-00')).toBe('529.9');
    expect(applyPattern('52998224725', '000.000.000-00')).toBe('529.982.247-25');
  });

  it('formata CPF, CNPJ numérico e CNPJ alfanumérico', () => {
    expect(formatDocument('52998224725')).toBe('529.982.247-25');
    expect(formatDocument('11222333000181')).toBe('11.222.333/0001-81');
    expect(formatDocument('12abc34501de35')).toBe('12.ABC.345/01DE-35');
  });

  it('formata celular e fixo', () => {
    expect(formatPhone('11987654321')).toBe('(11) 98765-4321');
    expect(formatPhone('1134567890')).toBe('(11) 3456-7890');
    expect(formatPhone('119')).toBe('(11) 9');
  });

  it('formata CEP, placa e quilometragem', () => {
    expect(formatCep('01001000')).toBe('01001-000');
    expect(formatPlate('abc1234')).toBe('ABC-1234');
    expect(formatPlate('abc-1d23')).toBe('ABC1D23');
    expect(formatMileage('045000')).toBe('45.000');
    expect(formatMileage(1234567)).toBe('1.234.567');
  });
});

describe('validadores brasileiros', () => {
  it('valida CPF pelo dígito verificador', () => {
    expect(isValidCpf('529.982.247-25')).toBe(true);
    expect(isValidCpf('529.982.247-24')).toBe(false);
    expect(isValidCpf('111.111.111-11')).toBe(false);
  });

  it('valida CNPJ numérico e alfanumérico (exemplo oficial da Receita)', () => {
    expect(isValidCnpj('11.222.333/0001-81')).toBe(true);
    expect(isValidCnpj('12.ABC.345/01DE-35')).toBe(true);
    expect(isValidCnpj('12.ABC.345/01DE-36')).toBe(false);
  });

  it('documentValidator segue o tipo de pessoa', () => {
    let type: 'individual' | 'company' = 'individual';
    const control = new FormControl('11.222.333/0001-81', documentValidator(() => type));
    expect(control.hasError('cpf')).toBe(true);

    type = 'company';
    control.updateValueAndValidity();
    expect(control.valid).toBe(true);
  });

  it('valida telefone e placa (vazio é aceito)', () => {
    expect(phoneValidator(new FormControl('(11) 98765-4321'))).toBeNull();
    expect(phoneValidator(new FormControl('(11) 1234-567'))).toEqual({ phone: true });
    expect(phoneValidator(new FormControl(''))).toBeNull();
    expect(plateValidator(new FormControl('ABC1D23'))).toBeNull();
    expect(plateValidator(new FormControl('ABC-1234'))).toBeNull();
    expect(plateValidator(new FormControl('AB12345'))).toEqual({ plate: true });
  });
});
