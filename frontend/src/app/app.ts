import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationCancel, NavigationEnd, NavigationError, Router, RouterOutlet } from '@angular/router';
import { filter, map, take } from 'rxjs';

import { Toaster } from './shared/components/toaster/toaster';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, Toaster],
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    .boot {
      display: grid;
      place-items: center;
      height: 100vh;
    }

    .boot span {
      width: 32px;
      height: 32px;
      border: 3px solid var(--color-border);
      border-top-color: var(--color-primary);
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }
  `,
  template: `
    @if (booting()) {
      <!-- Enquanto o guard confirma a sessão na API -->
      <div class="boot" role="status" aria-label="Carregando"><span></span></div>
    }
    <router-outlet />
    <app-toaster />
  `,
})
export class App {
  protected readonly booting = toSignal(
    inject(Router).events.pipe(
      filter((event) => event instanceof NavigationEnd || event instanceof NavigationCancel || event instanceof NavigationError),
      take(1),
      map(() => false),
    ),
    { initialValue: true },
  );
}
