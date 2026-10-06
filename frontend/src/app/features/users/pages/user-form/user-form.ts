import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit, signal } from '@angular/core';
import {
  AbstractControl,
  NonNullableFormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  ValidatorFn,
  Validators,
} from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { User, UserRole } from '../../../../core/auth/models/user';
import { AuthService } from '../../../../core/auth/services/auth.service';
import { ValidationErrorBody } from '../../../../core/http/api';
import { ToastService } from '../../../../core/services/toast.service';
import { Icon } from '../../../../shared/components/icon/icon';
import { UsersService } from '../../services/users.service';
import { generatePassword, ROLE_OPTIONS } from '../../utils/user-display';

/** Mesma regra da API: mínimo 8 caracteres, com letras e números. Vazio = sem erro. */
const passwordStrength: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  const value = control.value as string;
  if (!value) return null;
  if (value.length < 8) return { minlength: true };
  if (!/\p{L}/u.test(value)) return { letters: true };
  if (!/\d/.test(value)) return { numbers: true };
  return null;
};

const passwordsMatch: ValidatorFn = (group: AbstractControl): ValidationErrors | null => {
  const password = group.get('password')?.value;
  const confirmation = group.get('password_confirmation')?.value;
  return password && password !== confirmation ? { mismatch: true } : null;
};

type FieldName = 'name' | 'email' | 'role' | 'is_active' | 'password' | 'password_confirmation';

const MESSAGES: Partial<Record<FieldName, Record<string, string>>> = {
  name: { required: 'Informe o nome.', maxlength: 'O nome pode ter no máximo 120 caracteres.' },
  email: { required: 'Informe o e-mail.', email: 'Informe um e-mail válido.', maxlength: 'E-mail muito longo.' },
  password: {
    required: 'Informe a senha.',
    minlength: 'A senha deve ter pelo menos 8 caracteres.',
    letters: 'A senha deve conter pelo menos uma letra.',
    numbers: 'A senha deve conter pelo menos um número.',
  },
};

@Component({
  selector: 'app-user-form',
  imports: [ReactiveFormsModule, RouterLink, Icon],
  templateUrl: './user-form.html',
  styleUrl: './user-form.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UserForm implements OnInit {
  private readonly users = inject(UsersService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  /** Parâmetro :id da rota (ausente no cadastro). */
  readonly id = input<string>();

  protected readonly isEdit = computed(() => this.id() !== undefined);
  protected readonly roleOptions = ROLE_OPTIONS;

  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly submitted = signal(false);
  protected readonly showPassword = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly editedUser = signal<User | null>(null);

  protected readonly isSelf = computed(() => {
    const edited = this.editedUser();
    return edited !== null && edited.id === this.auth.user()?.id;
  });

  protected readonly form = inject(NonNullableFormBuilder).group(
    {
      name: ['', [Validators.required, Validators.maxLength(120)]],
      email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],
      role: ['admin' as UserRole, Validators.required],
      is_active: [true],
      password: ['', passwordStrength],
      password_confirmation: [''],
    },
    { validators: passwordsMatch },
  );

  ngOnInit(): void {
    const id = this.id();

    if (id === undefined) {
      // No cadastro a senha é obrigatória
      this.form.controls.password.addValidators(Validators.required);
      return;
    }

    this.loading.set(true);
    this.users
      .get(Number(id))
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (user) => this.fillForm(user),
        error: () => {
          this.toast.error('Usuário não encontrado.');
          this.router.navigate(['/users']);
        },
      });
  }

  protected errorFor(field: FieldName): string | null {
    const control = this.form.controls[field];
    if (!control.errors || !(control.touched || this.submitted())) return null;

    if (control.errors['server']) return control.errors['server'];

    const key = Object.keys(control.errors)[0];
    return MESSAGES[field]?.[key] ?? 'Valor inválido.';
  }

  protected confirmationError(): string | null {
    const control = this.form.controls.password_confirmation;
    if (control.errors?.['server']) return control.errors['server'];

    const visible = control.touched || this.submitted();
    return visible && this.form.hasError('mismatch') ? 'A confirmação não confere com a senha.' : null;
  }

  protected generate(): void {
    const password = generatePassword();
    this.form.patchValue({ password, password_confirmation: password });
    this.form.controls.password.markAsDirty();
    this.showPassword.set(true);
  }

  protected async copyPassword(): Promise<void> {
    try {
      await navigator.clipboard.writeText(this.form.controls.password.value);
      this.toast.success('Senha copiada.');
    } catch {
      this.toast.error('Não foi possível copiar. Selecione e copie manualmente.');
    }
  }

  protected submit(): void {
    this.submitted.set(true);
    this.formError.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    const payload = this.form.getRawValue();
    const request = this.isEdit()
      ? this.users.update(Number(this.id()), payload)
      : this.users.create(payload);

    request.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: (user) => {
        this.toast.success(this.isEdit() ? 'Alterações salvas.' : `${user.name} foi cadastrado.`);
        this.router.navigate(['/users']);
      },
      error: (error: unknown) => this.handleError(error),
    });
  }

  private fillForm(user: User): void {
    this.editedUser.set(user);
    this.form.reset({
      name: user.name,
      email: user.email,
      role: user.role,
      is_active: user.is_active,
      password: '',
      password_confirmation: '',
    });

    // O master não pode tirar o próprio acesso (a API também bloqueia)
    if (this.isSelf()) {
      this.form.controls.role.disable();
      this.form.controls.is_active.disable();
    }
  }

  private handleError(error: unknown): void {
    if (!(error instanceof HttpErrorResponse)) {
      this.formError.set('Não foi possível salvar. Tente novamente.');
      return;
    }

    if (error.status === 422) {
      const { errors } = error.error as ValidationErrorBody;
      for (const [field, messages] of Object.entries(errors ?? {})) {
        const control = this.form.get(field);
        control?.setErrors({ server: messages[0] });
        control?.markAsTouched();
      }
      this.formError.set('Revise os campos destacados.');
      return;
    }

    this.formError.set(
      error.status === 403
        ? 'Você não tem permissão para gerenciar usuários.'
        : 'Não foi possível salvar. Tente novamente em instantes.',
    );
  }
}
