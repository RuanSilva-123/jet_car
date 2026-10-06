<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderInspection;
use App\Models\ServiceOrderPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vistoria de entrada: combustível, avarias, itens conferidos, pertences, fotos e assinatura.
 * Fotos e assinatura ficam no disco privado e só são servidas para usuários logados.
 * Assinada, a vistoria (e as fotos) não muda mais.
 */
class ServiceOrderInspectionController extends Controller
{
    private const MAX_PHOTOS = 30;

    public function show(ServiceOrder $serviceOrder): JsonResponse
    {
        Gate::authorize('view', $serviceOrder);

        return $this->present($serviceOrder);
    }

    public function update(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        Gate::authorize('update', $serviceOrder);
        $inspection = $serviceOrder->inspection;
        $this->ensureNotSigned($inspection);

        $request->merge([
            'belongings' => trim((string) $request->input('belongings')) ?: null,
            'notes' => trim((string) $request->input('notes')) ?: null,
        ]);
        $data = $request->validate([
            'fuel_level' => ['nullable', 'integer', 'between:0,4'],
            'damages' => ['present', 'array', 'max:40'],
            'damages.*.area' => ['required', Rule::in(array_keys(ServiceOrderInspection::DAMAGE_AREAS))],
            'damages.*.type' => ['required', Rule::in(array_keys(ServiceOrderInspection::DAMAGE_TYPES))],
            'damages.*.notes' => ['nullable', 'string', 'max:300'],
            'checklist' => ['present', 'array'],
            'checklist.*' => ['string', Rule::in(array_keys(ServiceOrderInspection::CHECKLIST))],
            'belongings' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'fuel_level.*' => 'Nível de combustível inválido.',
            'damages.*.area.*' => 'Selecione o local da avaria.',
            'damages.*.type.*' => 'Selecione o tipo da avaria.',
            'checklist.*.*' => 'Item da vistoria inválido.',
        ]);

        $data['damages'] = array_values(array_map(fn (array $damage) => [
            'area' => $damage['area'],
            'type' => $damage['type'],
            'notes' => trim((string) ($damage['notes'] ?? '')) ?: null,
        ], $data['damages']));
        $data['checklist'] = array_values(array_unique($data['checklist']));

        DB::transaction(function () use ($serviceOrder, $inspection, $data, $request) {
            $inspection ??= new ServiceOrderInspection(['service_order_id' => $serviceOrder->id]);
            $isNew = ! $inspection->exists;
            $inspection->fill($data);
            $inspection->service_order_id = $serviceOrder->id;
            $inspection->created_by ??= $request->user()->id;
            $inspection->updated_by = $request->user()->id;
            $inspection->save();

            if ($isNew) {
                $serviceOrder->events()->create(['user_id' => $request->user()->id, 'type' => 'inspection', 'description' => 'Vistoria de entrada registrada.']);
            }
        });

        return $this->present($serviceOrder->fresh());
    }

    /** Foto já reduzida no navegador (JPEG ~1600 px); aqui só valida e guarda. */
    public function storePhoto(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        Gate::authorize('update', $serviceOrder);
        $this->ensureNotSigned($serviceOrder->inspection);

        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:150'],
        ], [
            'photo.required' => 'Selecione a foto.',
            'photo.image' => 'O arquivo precisa ser uma imagem.',
            'photo.mimes' => 'Use fotos JPG, PNG ou WEBP.',
            'photo.max' => 'A foto pode ter no máximo 10 MB.',
        ]);

        if ($serviceOrder->photos()->count() >= self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photo' => 'Limite de '.self::MAX_PHOTOS.' fotos por OS.']);
        }

        $file = $data['photo'];
        $path = $file->storeAs("inspections/{$serviceOrder->id}", Str::uuid().'.'.$file->extension(), 'local');

        $serviceOrder->photos()->create([
            'path' => $path,
            'caption' => trim((string) ($data['caption'] ?? '')) ?: null,
            'size' => $file->getSize(),
            'created_by' => $request->user()->id,
        ]);

        return $this->present($serviceOrder);
    }

    public function destroyPhoto(ServiceOrder $serviceOrder, ServiceOrderPhoto $photo): JsonResponse
    {
        Gate::authorize('update', $serviceOrder);
        abort_unless($photo->service_order_id === $serviceOrder->id, 404);
        $this->ensureNotSigned($serviceOrder->inspection);

        Storage::disk('local')->delete($photo->path);
        $photo->delete();

        return $this->present($serviceOrder);
    }

    public function photo(ServiceOrder $serviceOrder, ServiceOrderPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $serviceOrder);
        abort_unless($photo->service_order_id === $serviceOrder->id, 404);

        return Storage::disk('local')->response($photo->path, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Cliente confere e assina (desenho no tablet/celular). Trava a vistoria. */
    public function sign(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        Gate::authorize('update', $serviceOrder);
        $inspection = $serviceOrder->inspection;

        if (! $inspection) {
            throw ValidationException::withMessages(['signature' => 'Preencha a vistoria antes de colher a assinatura.']);
        }
        $this->ensureNotSigned($inspection);

        $data = $request->validate([
            'signed_name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string', 'max:700000', 'starts_with:data:image/png;base64,'],
        ], [
            'signed_name.required' => 'Informe o nome de quem assina.',
            'signature.required' => 'Colha a assinatura do cliente.',
            'signature.*' => 'Assinatura inválida.',
        ]);

        $png = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
        if ($png === false || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['signature' => 'Assinatura inválida.']);
        }

        DB::transaction(function () use ($serviceOrder, $inspection, $data, $png, $request) {
            $path = "inspections/{$serviceOrder->id}/signature-".Str::uuid().'.png';
            Storage::disk('local')->put($path, $png);

            $inspection->forceFill([
                'signed_name' => trim($data['signed_name']),
                'signature_path' => $path,
                'signed_at' => now(),
                'updated_by' => $request->user()->id,
            ])->save();

            $serviceOrder->events()->create([
                'user_id' => $request->user()->id,
                'type' => 'inspection_signed',
                'description' => 'Vistoria de entrada assinada por '.$inspection->signed_name.'.',
            ]);
        });

        return $this->present($serviceOrder->fresh());
    }

    public function signature(ServiceOrder $serviceOrder): StreamedResponse
    {
        Gate::authorize('view', $serviceOrder);
        $path = $serviceOrder->inspection?->signature_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function ensureNotSigned(?ServiceOrderInspection $inspection): void
    {
        if ($inspection?->isSigned()) {
            throw ValidationException::withMessages(['inspection' => 'A vistoria já foi assinada pelo cliente e não pode ser alterada.']);
        }
    }

    private function present(ServiceOrder $order): JsonResponse
    {
        $inspection = $order->inspection()->with('creator')->first();
        $base = "/api/v1/service-orders/{$order->id}/inspection";

        return response()->json([
            'data' => $inspection ? [
                'fuel_level' => $inspection->fuel_level,
                'damages' => $inspection->damages ?? [],
                'checklist' => $inspection->checklist ?? [],
                'belongings' => $inspection->belongings,
                'notes' => $inspection->notes,
                'signed_name' => $inspection->signed_name,
                'signed_at' => $inspection->signed_at?->toIso8601String(),
                'signature_url' => $inspection->signature_path ? "{$base}/signature" : null,
                'created_by' => $inspection->creator?->name,
                'created_at' => $inspection->created_at?->toIso8601String(),
            ] : null,
            'photos' => $order->photos()->get()->map(fn (ServiceOrderPhoto $photo) => [
                'id' => $photo->id,
                'caption' => $photo->caption,
                'url' => "{$base}/photos/{$photo->id}",
                'created_at' => $photo->created_at?->toIso8601String(),
            ]),
            'options' => [
                'fuel_levels' => ServiceOrderInspection::FUEL_LABELS,
                'damage_areas' => ServiceOrderInspection::DAMAGE_AREAS,
                'damage_types' => ServiceOrderInspection::DAMAGE_TYPES,
                'checklist' => ServiceOrderInspection::CHECKLIST,
            ],
        ]);
    }
}
