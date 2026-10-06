import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { finalize } from 'rxjs';

import { Icon } from '../../shared/components/icon/icon';
import { Logo } from '../../shared/components/logo/logo';
import { PublicSurvey, PublicSurveyService } from './public-survey.service';

/**
 * Pesquisa de satisfação que o cliente abre pelo WhatsApp depois da entrega:
 * nota de 0 a 10 (NPS) e um comentário opcional.
 */
@Component({
  selector: 'app-public-survey',
  imports: [Icon, Logo],
  templateUrl: './public-survey.html',
  styleUrl: './public-survey.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PublicSurveyPage implements OnInit {
  private readonly surveys = inject(PublicSurveyService);

  /** :token do link. */
  readonly token = input.required<string>();

  protected readonly scores = Array.from({ length: 11 }, (_, index) => index);

  protected readonly survey = signal<PublicSurvey | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<'invalid' | 'expired' | 'failed' | null>(null);

  protected readonly score = signal<number | null>(null);
  protected readonly comment = signal('');
  protected readonly sending = signal(false);
  protected readonly error = signal<string | null>(null);

  /** Pergunta do comentário muda conforme a nota. */
  protected readonly commentPrompt = computed(() => {
    const score = this.score();
    if (score === null) return 'Quer deixar um comentário?';
    if (score >= 9) return 'Que bom! O que você mais gostou?';
    if (score >= 7) return 'O que faltou para ser nota 10?';
    return 'Sentimos muito. O que deu errado? Vamos ler com atenção.';
  });

  protected readonly shopWhatsapp = computed(() => {
    const phone = this.survey()?.shop.phone?.replace(/\D/g, '');
    return phone && phone.length >= 10 ? `https://wa.me/55${phone}` : null;
  });

  ngOnInit(): void {
    this.surveys
      .get(this.token())
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (survey) => this.survey.set(survey),
        error: (error: unknown) => {
          const status = error instanceof HttpErrorResponse ? error.status : 0;
          this.loadError.set(status === 410 ? 'expired' : status === 404 ? 'invalid' : 'failed');
        },
      });
  }

  protected submit(): void {
    const score = this.score();
    if (score === null) {
      this.error.set('Escolha uma nota de 0 a 10.');
      return;
    }
    if (this.sending()) return;

    this.sending.set(true);
    this.error.set(null);
    this.surveys
      .answer(this.token(), score, this.comment().trim())
      .pipe(finalize(() => this.sending.set(false)))
      .subscribe({
        next: (survey) => {
          this.survey.set(survey);
          window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        error: (error: unknown) => {
          const first =
            error instanceof HttpErrorResponse ? (Object.values(error.error?.errors ?? {})[0] as string[] | undefined) : undefined;
          this.error.set(first?.[0] ?? 'Não foi possível enviar sua avaliação. Tente novamente.');
        },
      });
  }
}
