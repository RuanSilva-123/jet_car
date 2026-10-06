/**
 * Formatação de dados brasileiros para exibição e máscara de digitação.
 * A API recebe os valores formatados e normaliza do lado dela.
 */

export type BrFormat =
  | 'cpf'
  | 'cnpj'
  | 'document'
  | 'phone'
  | 'cep'
  | 'plate'
  | 'mileage'
  | 'digits'
  | 'upper'
  | 'money'
  | 'quantity';

/** Só letras e números, em maiúsculas. */
export function alphanumeric(value: string | null | undefined): string {
  return (value ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

export function digits(value: string | null | undefined): string {
  return (value ?? '').replace(/\D/g, '');
}

/**
 * Aplica um padrão progressivamente (funciona com o valor parcial, durante a digitação).
 * Tokens: 0 = dígito, A = letra, X = letra ou dígito. Qualquer outro caractere é literal.
 */
export function applyPattern(raw: string, pattern: string): string {
  let output = '';
  let index = 0;

  for (const token of pattern) {
    if (index >= raw.length) break;

    const char = raw[index];
    const accepts = token === '0' ? /\d/ : token === 'A' ? /[A-Z]/ : token === 'X' ? /[A-Z0-9]/ : null;

    if (!accepts) {
      output += token;
      continue;
    }
    if (!accepts.test(char)) break;

    output += char;
    index++;
  }

  return output;
}

export function formatCpf(value: string | null | undefined): string {
  return applyPattern(digits(value), '000.000.000-00');
}

/** CNPJ numérico ou alfanumérico (12 caracteres + 2 dígitos verificadores). */
export function formatCnpj(value: string | null | undefined): string {
  return applyPattern(alphanumeric(value), 'XX.XXX.XXX/XXXX-00');
}

export function formatDocument(value: string | null | undefined): string {
  const clean = alphanumeric(value);
  return clean.length > 11 || /[A-Z]/.test(clean) ? formatCnpj(clean) : formatCpf(clean);
}

/** (11) 98765-4321 ou (11) 3456-7890 */
export function formatPhone(value: string | null | undefined): string {
  const clean = digits(value).slice(0, 11);
  return applyPattern(clean, clean.length > 10 ? '(00) 00000-0000' : '(00) 0000-0000');
}

export function formatCep(value: string | null | undefined): string {
  return applyPattern(digits(value), '00000-000');
}

/** Placa antiga com hífen (ABC-1234); Mercosul sem hífen (ABC1D23). */
export function formatPlate(value: string | null | undefined): string {
  const clean = alphanumeric(value).slice(0, 7);
  const isOldFormat = /^[A-Z]{3}\d{4}$/.test(clean);
  return isOldFormat ? `${clean.slice(0, 3)}-${clean.slice(3)}` : clean;
}

/** 45000 → "45.000" */
export function formatMileage(value: string | number | null | undefined): string {
  const clean = digits(String(value ?? '')).replace(/^0+(?=\d)/, '').slice(0, 7);
  return clean.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

// --- Dinheiro (a API trabalha em centavos inteiros) ---------------------------------

/** 123456 → "R$ 1.234,56" */
/** R$ 1.234,56 · negativo (saldo do caixa) sai como −R$ 1.234,56. */
export function formatMoney(cents: number | null | undefined): string {
  const value = Math.round(cents ?? 0);
  return `${value < 0 ? '−' : ''}R$ ${centsToInput(Math.abs(value))}`;
}

/** 123456 → "1.234,56" (texto do campo) */
export function centsToInput(cents: number | null | undefined): string {
  const value = Math.max(0, Math.round(cents ?? 0));
  const reais = Math.floor(value / 100)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${reais},${String(value % 100).padStart(2, '0')}`;
}

/** "1.234,56" → 123456 */
export function moneyToCents(text: string | null | undefined): number {
  const value = digits(text);
  return value ? Number.parseInt(value.slice(-12), 10) : 0;
}

/** Máscara de digitação estilo caixa registradora: "1" → "0,01", "12345" → "123,45". */
export function maskMoney(text: string | null | undefined): string {
  const value = digits(text);
  return value ? centsToInput(moneyToCents(value)) : '';
}

/** Quantidade com até 2 casas decimais: "4.5" / "4,5" → "4,5" */
export function maskQuantity(text: string | null | undefined): string {
  const clean = (text ?? '').replace(/\./g, ',').replace(/[^\d,]/g, '');
  const [integer, ...decimals] = clean.split(',');
  const decimal = decimals.join('').slice(0, 2);
  return clean.includes(',') ? `${integer.slice(0, 5)},${decimal}` : integer.slice(0, 5);
}

/** "4,5" → 4.5 */
export function parseQuantity(text: string | null | undefined): number {
  const value = Number.parseFloat((text ?? '').replace(',', '.'));
  return Number.isFinite(value) ? value : 0;
}

/** 4.5 → "4,5"; 2 → "2" */
export function formatQuantity(value: number | null | undefined): string {
  return String(Math.round((value ?? 0) * 100) / 100).replace('.', ',');
}

export function formatBr(value: string | number | null | undefined, format: BrFormat): string {
  const text = value === null || value === undefined ? '' : String(value);

  switch (format) {
    case 'cpf':
      return formatCpf(text);
    case 'cnpj':
      return formatCnpj(text);
    case 'document':
      return formatDocument(text);
    case 'phone':
      return formatPhone(text);
    case 'cep':
      return formatCep(text);
    case 'plate':
      return formatPlate(text);
    case 'mileage':
      return formatMileage(text);
    case 'digits':
      return digits(text);
    case 'upper':
      return alphanumeric(text);
    case 'money':
      return maskMoney(text);
    case 'quantity':
      return maskQuantity(text);
  }
}

/** Tamanho de arquivo legível: 1,4 MB. */
export function formatBytes(bytes: number | null | undefined): string {
  let value = Math.max(0, bytes ?? 0);
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value.toLocaleString('pt-BR', { maximumFractionDigits: unit === 0 ? 0 : 1 })} ${units[unit]}`;
}

/** Chave Pix como a pessoa reconhece: CPF/CNPJ com pontuação, celular sem o +55. */
export function formatPixKey(type: string | null | undefined, key: string | null | undefined): string {
  if (!key) return '';
  if (type === 'cpf' || type === 'cnpj') return formatDocument(key);
  if (type === 'phone') return formatPhone(key.replace(/^\+55/, ''));
  return key;
}
