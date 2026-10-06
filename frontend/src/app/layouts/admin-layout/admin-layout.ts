import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { AuthService } from '../../core/auth/services/auth.service';
import { CommandPalette } from '../../shared/components/command-palette/command-palette';
import { Icon, IconName } from '../../shared/components/icon/icon';
import { Logo } from '../../shared/components/logo/logo';
import { initials } from '../../shared/utils/initials';

interface NavItem {
  label: string;
  icon: IconName;
  route?: string;
  /** Visível apenas para o usuário master. */
  masterOnly?: boolean;
  /** Financeiro: oculto para o mecânico. */
  financeOnly?: boolean;
}

interface NavSection {
  title: string;
  items: NavItem[];
}

/** Layout da área autenticada: menu lateral + cabeçalho + conteúdo. */
@Component({
  selector: 'app-admin-layout',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Icon, Logo, CommandPalette],
  host: { '(document:keydown)': 'onGlobalKeydown($event)' },
  templateUrl: './admin-layout.html',
  styleUrl: './admin-layout.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdminLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly user = this.auth.user;
  protected readonly sidebarOpen = signal(false);
  protected readonly loggingOut = signal(false);
  protected readonly searchOpen = signal(false);
  /** ⌘K no Mac, Ctrl+K nos demais. */
  protected readonly shortcut = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K';

  protected readonly initials = computed(() => initials(this.user()?.name ?? ''));

  private readonly sections: NavSection[] = [
    {
      title: 'Geral',
      items: [
        { label: 'Dashboard', icon: 'dashboard', route: '/dashboard' },
        { label: 'Agenda', icon: 'calendar', route: '/agenda' },
      ],
    },
    {
      title: 'Oficina',
      items: [
        { label: 'Ordens de serviço', icon: 'clipboard', route: '/service-orders' },
        { label: 'Clientes', icon: 'contact', route: '/customers' },
        { label: 'Serviços', icon: 'wrench', route: '/services' },
        { label: 'Estoque de peças', icon: 'boxes', route: '/parts' },
        { label: 'Lembretes de revisão', icon: 'bell', route: '/reminders' },
      ],
    },
    {
      title: 'Financeiro',
      items: [
        { label: 'Contas a receber', icon: 'wallet', route: '/finance', financeOnly: true },
        { label: 'Relatórios', icon: 'bar-chart', route: '/reports', financeOnly: true },
      ],
    },
    {
      title: 'Administração',
      items: [
        { label: 'Usuários', icon: 'users', route: '/users', masterOnly: true },
        { label: 'Dados da oficina', icon: 'building', route: '/settings/shop', masterOnly: true },
      ],
    },
  ];

  protected readonly navigation = computed(() =>
    this.sections
      .map((section) => ({
        ...section,
        items: section.items.filter(
          (item) => (!item.masterOnly || this.auth.isMaster()) && (!item.financeOnly || this.auth.canManageFinance()),
        ),
      }))
      .filter((section) => section.items.length > 0),
  );

  /** Ctrl+K / ⌘K abre a busca global de qualquer tela. */
  protected onGlobalKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      this.searchOpen.set(true);
    }
  }

  protected closeSidebar(): void {
    this.sidebarOpen.set(false);
  }

  protected logout(): void {
    this.loggingOut.set(true);

    // Mesmo se a API falhar (sessão já expirada), o usuário sai do painel
    this.auth.logout().subscribe({
      complete: () => this.router.navigate(['/login']),
      error: () => {
        this.auth.clearSession();
        this.router.navigate(['/login']);
      },
    });
  }
}
