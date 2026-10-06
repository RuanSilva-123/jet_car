import { Pipe, PipeTransform } from '@angular/core';

import { formatMoney } from '../utils/br-format';

/** {{ order.total_cents | money }} → R$ 1.234,56 */
@Pipe({ name: 'money' })
export class MoneyPipe implements PipeTransform {
  transform(cents: number | null | undefined): string {
    return formatMoney(cents);
  }
}
