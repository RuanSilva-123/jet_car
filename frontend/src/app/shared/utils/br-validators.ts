import { AbstractControl, ValidationErrors, ValidatorFn } from '@angular/forms';

import { alphanumeric, digits } from './br-format';

/** Mesmo algoritmo do backend (App\Support\BrazilianDocument). */
export function isValidCpf(value: string): boolean {
  const cpf = digits(value);
  if (!/^\d{11}$/.test(cpf) || /^(\d)\1{10}$/.test(cpf)) return false;

  for (let position = 9; position <= 10; position++) {
    let sum = 0;
    for (let i = 0; i < position; i++) {
      sum += Number(cpf[i]) * (position + 1 - i);
    }
    if (Number(cpf[position]) !== ((sum * 10) % 11) % 10) return false;
  }
  return true;
}

/** CNPJ numérico ou alfanumérico (cada caractere vale código ASCII − 48). */
export function isValidCnpj(value: string): boolean {
  const cnpj = alphanumeric(value);
  if (!/^[A-Z0-9]{12}\d{2}$/.test(cnpj) || /^(.)\1{13}$/.test(cnpj)) return false;

  const weights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  for (const length of [12, 13]) {
    const offset = 13 - length;
    let sum = 0;
    for (let i = 0; i < length; i++) {
      sum += (cnpj.charCodeAt(i) - 48) * weights[i + offset];
    }
    const remainder = sum % 11;
    if (Number(cnpj[length]) !== (remainder < 2 ? 0 : 11 - remainder)) return false;
  }
  return true;
}

/** Valida CPF ou CNPJ conforme o tipo de pessoa (lido do próprio formulário). Vazio é aceito. */
export function documentValidator(personType: () => 'individual' | 'company'): ValidatorFn {
  return (control: AbstractControl): ValidationErrors | null => {
    const value = control.value as string;
    if (!value) return null;
    if (personType() === 'company') return isValidCnpj(value) ? null : { cnpj: true };
    return isValidCpf(value) ? null : { cpf: true };
  };
}

/** Celular (DDD + 9 dígitos) ou fixo (DDD + 8 dígitos). Vazio é aceito. */
export const phoneValidator: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  const value = digits(control.value);
  if (!value) return null;
  return /^[1-9]{2}(9\d{8}|[2-8]\d{7})$/.test(value) ? null : { phone: true };
};

export const cepValidator: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  const value = digits(control.value);
  return !value || value.length === 8 ? null : { cep: true };
};

/** Placa antiga (ABC1234) ou Mercosul (ABC1D23). */
export const plateValidator: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  const value = alphanumeric(control.value);
  if (!value) return null;
  return /^[A-Z]{3}\d[A-Z0-9]\d{2}$/.test(value) ? null : { plate: true };
};

/** Chassi: 17 caracteres, sem I, O e Q. */
export const vinValidator: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  const value = alphanumeric(control.value);
  if (!value) return null;
  return /^[A-HJ-NPR-Z0-9]{17}$/.test(value) ? null : { vin: true };
};

export const renavamValidator: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  const value = digits(control.value);
  if (!value) return null;
  return value.length >= 9 && value.length <= 11 ? null : { renavam: true };
};
