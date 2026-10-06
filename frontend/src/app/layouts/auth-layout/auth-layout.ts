import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

import { Icon, IconName } from '../../shared/components/icon/icon';
import { Logo } from '../../shared/components/logo/logo';

interface Feature {
  icon: IconName;
  title: string;
  text: string;
}

/**
 * Layout das telas públicas (login): painel da marca + formulário.
 * As duas colunas usam a mesma grade de 3 faixas (topo / centro / rodapé),
 * então topo, conteúdo e rodapé ficam alinhados entre si.
 */
@Component({
  selector: 'app-auth-layout',
  imports: [RouterOutlet, Logo, Icon],
  templateUrl: './auth-layout.html',
  styleUrl: './auth-layout.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AuthLayout {
  protected readonly year = new Date().getFullYear();

  protected readonly features: Feature[] = [
    { icon: 'shield', title: 'Acesso restrito', text: 'Somente contas criadas pelo administrador.' },
    { icon: 'lock', title: 'Sessão protegida', text: 'Cookies seguros e conexão criptografada.' },
    { icon: 'users', title: 'Equipe sob controle', text: 'O master decide quem entra no painel.' },
  ];
}
