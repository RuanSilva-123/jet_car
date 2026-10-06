import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { finalize } from 'rxjs';

import { AuthService } from '../../../../core/auth/services/auth.service';
import { Icon } from '../../../../shared/components/icon/icon';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, Icon],
  templateUrl: './login.html',
  styleUrl: './login.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Login {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  /** Preenchido pelo authGuard quando o usuário tentou abrir uma página protegida. */
  readonly returnUrl = input<string>();

  protected readonly form = inject(NonNullableFormBuilder).group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
    remember: [false],
  });

  protected readonly submitting = signal(false);
  protected readonly showPassword = signal(false);
  protected readonly errorMessage = signal<string | null>(null);

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);

    this.auth
      .login(this.form.getRawValue())
      .pipe(finalize(() => this.submitting.set(false)))
      .subscribe({
        next: () => this.router.navigateByUrl(this.safeReturnUrl()),
        error: (error: unknown) => {
          this.errorMessage.set(this.describeError(error));
          this.form.controls.password.reset();
        },
      });
  }

  protected hasError(control: 'email' | 'password', error: string): boolean {
    const field = this.form.controls[control];
    return field.touched && field.hasError(error);
  }

  /** Aceita apenas caminhos internos, evitando redirecionamento para outro site. */
  private safeReturnUrl(): string {
    const url = this.returnUrl();
    return url?.startsWith('/') && !url.startsWith('//') ? url : '/dashboard';
  }

  private describeError(error: unknown): string {
    if (!(error instanceof HttpErrorResponse)) {
      return 'Não foi possível entrar. Tente novamente.';
    }

    const apiMessage: string | undefined = error.error?.errors?.email?.[0] ?? error.error?.message;

    switch (error.status) {
      case 422:
      case 429:
        return apiMessage ?? 'E-mail ou senha inválidos.';
      case 0:
        return 'Sem conexão com o servidor. Verifique sua rede.';
      default:
        return 'Erro inesperado ao entrar. Tente novamente em instantes.';
    }
  }
}
