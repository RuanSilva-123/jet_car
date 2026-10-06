import { Pipe, PipeTransform } from '@angular/core';

import { BrFormat, formatBr } from '../utils/br-format';

/** {{ customer.document | brFormat: 'document' }} → 529.982.247-25 */
@Pipe({ name: 'brFormat' })
export class BrFormatPipe implements PipeTransform {
  transform(value: string | number | null | undefined, format: BrFormat): string {
    return formatBr(value, format);
  }
}
