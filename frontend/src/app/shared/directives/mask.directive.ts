import { Directive, inject, input } from '@angular/core';
import { NgControl } from '@angular/forms';

import { BrFormat, formatBr } from '../utils/br-format';

/**
 * Máscara de digitação para inputs de formulário reativo:
 * <input formControlName="phone" appMask="phone" />
 */
@Directive({
  selector: 'input[appMask]',
  host: {
    '(input)': 'onInput($event)',
    '(blur)': 'onInput($event)',
  },
})
export class MaskDirective {
  readonly appMask = input.required<BrFormat>();

  private readonly control = inject(NgControl, { self: true });

  protected onInput(event: Event): void {
    const element = event.target as HTMLInputElement;
    const formatted = formatBr(element.value, this.appMask());

    if (formatted !== element.value) {
      element.value = formatted;
    }
    if (formatted !== this.control.value) {
      this.control.control?.setValue(formatted);
    }
  }
}
