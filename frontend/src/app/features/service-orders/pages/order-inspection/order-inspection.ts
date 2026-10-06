import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { ToastService } from '../../../../core/services/toast.service';
import { ConfirmDialog } from '../../../../shared/components/confirm-dialog/confirm-dialog';
import { Icon } from '../../../../shared/components/icon/icon';
import { BrFormatPipe } from '../../../../shared/pipes/br-format.pipe';
import { resizeImage } from '../../../../shared/utils/image';
import { ServiceOrder } from '../../models/service-order';
import { Damage, InspectionPhoto, InspectionResponse, InspectionService } from '../../services/inspection.service';
import { ServiceOrdersService } from '../../services/service-orders.service';

/**
 * Vistoria na entrada do veículo: combustível, itens conferidos, avarias, pertences e fotos.
 */
@Component({
  selector: 'app-order-inspection',
  imports: [RouterLink, DatePipe, Icon, BrFormatPipe, ConfirmDialog],
  templateUrl: './order-inspection.html',
  styleUrl: './order-inspection.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrderInspection implements OnInit {
  private readonly orders = inject(ServiceOrdersService);
  private readonly inspections = inject(InspectionService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  readonly id = input.required<string>();

  protected readonly order = signal<ServiceOrder | null>(null);
  protected readonly options = signal<InspectionResponse['options'] | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly uploading = signal(0);
  protected readonly error = signal<string | null>(null);

  // Formulário
  protected readonly fuelLevel = signal<number | null>(null);
  protected readonly checklist = signal<string[]>([]);
  protected readonly damages = signal<Damage[]>([]);
  protected readonly belongings = signal('');
  protected readonly notes = signal('');
  protected readonly photos = signal<InspectionPhoto[]>([]);
  protected readonly dirty = signal(false);

  // Situação
  protected readonly saved = signal(false);
  protected readonly createdBy = signal<string | null>(null);
  protected readonly savedAt = signal<string | null>(null);

  protected readonly pendingPhotoRemoval = signal<InspectionPhoto | null>(null);
  protected readonly removingPhoto = signal(false);

  protected readonly areas = computed(() => Object.entries(this.options()?.damage_areas ?? {}).map(([value, label]) => ({ value, label })));
  protected readonly types = computed(() => Object.entries(this.options()?.damage_types ?? {}).map(([value, label]) => ({ value, label })));
  protected readonly checklistOptions = computed(() =>
    Object.entries(this.options()?.checklist ?? {}).map(([value, label]) => ({ value, label })),
  );
  protected readonly pdfUrl = computed(() => (this.order() ? this.orders.pdfUrl(this.order()!.id, 'inspection') : null));

  ngOnInit(): void {
    const orderId = Number(this.id());
    forkJoin({ order: this.orders.get(orderId), inspection: this.inspections.get(orderId) })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ order, inspection }) => {
          this.order.set(order);
          this.apply(inspection);
        },
        error: () => {
          this.toast.error('OS não encontrada.');
          this.router.navigate(['/service-orders']);
        },
      });
  }

  protected setFuel(level: number): void {
    this.fuelLevel.set(this.fuelLevel() === level ? null : level);
    this.dirty.set(true);
  }

  protected toggleCheck(value: string): void {
    this.checklist.update((list) => (list.includes(value) ? list.filter((item) => item !== value) : [...list, value]));
    this.dirty.set(true);
  }

  protected addDamage(): void {
    this.damages.update((list) => [...list, { area: 'front', type: 'scratch', notes: '' }]);
    this.dirty.set(true);
  }

  protected updateDamage(index: number, changes: Partial<Damage>): void {
    this.damages.update((list) => list.map((damage, i) => (i === index ? { ...damage, ...changes } : damage)));
    this.dirty.set(true);
  }

  protected removeDamage(index: number): void {
    this.damages.update((list) => list.filter((_, i) => i !== index));
    this.dirty.set(true);
  }

  protected setText(field: 'belongings' | 'notes', value: string): void {
    this[field].set(value);
    this.dirty.set(true);
  }

  protected save(): void {
    const order = this.order();
    if (!order || this.saving()) return;

    this.error.set(null);
    this.saving.set(true);
    this.inspections
      .save(order.id, {
        fuel_level: this.fuelLevel(),
        damages: this.damages().map((damage) => ({ ...damage, notes: damage.notes?.trim() || null })),
        checklist: this.checklist(),
        belongings: this.belongings(),
        notes: this.notes(),
      })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.apply(response);
          this.toast.success('Vistoria salva.');
        },
        error: (error: unknown) => this.error.set(this.describeError(error, 'Não foi possível salvar a vistoria.')),
      });
  }

  /** Várias fotos de uma vez; cada uma é reduzida no navegador antes de subir. */
  protected async onPhotos(event: Event): Promise<void> {
    const order = this.order();
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    if (!order || !files.length) return;

    for (const file of files) {
      this.uploading.update((count) => count + 1);
      const blob = await resizeImage(file);
      const name = file.name.replace(/\.[^.]+$/, '') + (blob === file ? file.name.slice(file.name.lastIndexOf('.')) : '.jpg');
      this.inspections
        .uploadPhoto(order.id, blob, name)
        .pipe(finalize(() => this.uploading.update((count) => count - 1)))
        .subscribe({
          next: (response) => this.photos.set(response.photos),
          error: (error: unknown) => this.toast.error(this.describeError(error, `Não foi possível enviar ${file.name}.`)),
        });
    }
  }

  protected confirmPhotoRemoval(): void {
    const order = this.order();
    const photo = this.pendingPhotoRemoval();
    if (!order || !photo) return;

    this.removingPhoto.set(true);
    this.inspections
      .removePhoto(order.id, photo.id)
      .pipe(finalize(() => this.removingPhoto.set(false)))
      .subscribe({
        next: (response) => {
          this.photos.set(response.photos);
          this.pendingPhotoRemoval.set(null);
        },
        error: (error: unknown) => {
          this.pendingPhotoRemoval.set(null);
          this.toast.error(this.describeError(error, 'Não foi possível remover a foto.'));
        },
      });
  }

  private apply(response: InspectionResponse): void {
    this.options.set(response.options);
    this.photos.set(response.photos);

    const data = response.data;
    this.saved.set(data !== null);
    this.fuelLevel.set(data?.fuel_level ?? null);
    this.checklist.set(data?.checklist ?? []);
    this.damages.set((data?.damages ?? []).map((damage) => ({ ...damage, notes: damage.notes ?? '' })));
    this.belongings.set(data?.belongings ?? '');
    this.notes.set(data?.notes ?? '');
    this.createdBy.set(data?.created_by ?? null);
    this.savedAt.set(data?.created_at ?? null);
    this.dirty.set(false);
  }

  private describeError(error: unknown, fallback: string): string {
    if (error instanceof HttpErrorResponse) {
      const first = Object.values(error.error?.errors ?? {})[0] as string[] | undefined;
      return first?.[0] ?? error.error?.message ?? fallback;
    }
    return fallback;
  }
}
