<?php

namespace App\Services\Inventory;

use App\Enums\SupplyCategory;
use App\Models\Supply;
use App\Models\Treatment;
use Illuminate\Support\Facades\DB;

/**
 * Una lista para empezar: los materiales de uso diario en un consultorio y lo que usa cada
 * tratamiento del catálogo. Se agrega solo lo que falta y no toca lo que el consultorio ya armó.
 * Después se cuenta lo que hay (Conteo) y se ajustan mínimos y recetas.
 */
final class StarterCatalog
{
    /** @var array<string, array{0: SupplyCategory, 1: string, 2: int, 3?: bool}> Nombre => categoría, unidad, avisar con menos de, ¿con número de serie? */
    public const SUPPLIES = [
        'Guantes de nitrilo' => [SupplyCategory::Biosafety, 'par', 50],
        'Baberos descartables' => [SupplyCategory::Biosafety, 'unidad', 50],
        'Eyectores de saliva' => [SupplyCategory::Biosafety, 'unidad', 50],
        'Mascarillas' => [SupplyCategory::Biosafety, 'unidad', 50],
        'Vasos descartables' => [SupplyCategory::Biosafety, 'unidad', 50],
        'Gasas estériles' => [SupplyCategory::Biosafety, 'unidad', 100],
        'Dique de goma' => [SupplyCategory::Biosafety, 'unidad', 10],
        'Lidocaína 2 % con epinefrina' => [SupplyCategory::Anesthesia, 'carpule', 50],
        'Agujas dentales cortas' => [SupplyCategory::Anesthesia, 'unidad', 50],
        'Pasta profiláctica' => [SupplyCategory::Prevention, 'g', 50],
        'Copas de profilaxis' => [SupplyCategory::Prevention, 'unidad', 20],
        'Resina compuesta A2' => [SupplyCategory::Restorative, 'g', 8],
        'Ácido grabador 37 %' => [SupplyCategory::Restorative, 'ml', 5],
        'Adhesivo dental' => [SupplyCategory::Restorative, 'ml', 3],
        'Microaplicadores' => [SupplyCategory::Restorative, 'unidad', 50],
        'Hipoclorito de sodio 2.5 %' => [SupplyCategory::Endodontics, 'ml', 500],
        'Conos de gutapercha' => [SupplyCategory::Endodontics, 'unidad', 60],
        'Sutura reabsorbible 4-0' => [SupplyCategory::Surgery, 'unidad', 5],
        'Kit de blanqueamiento' => [SupplyCategory::Other, 'kit', 2],
        'Brackets metálicos' => [SupplyCategory::Orthodontics, 'kit', 2],
        'Arcos de níquel-titanio' => [SupplyCategory::Orthodontics, 'unidad', 10],
        'Ligaduras elásticas' => [SupplyCategory::Orthodontics, 'unidad', 100],
        'Implante dental' => [SupplyCategory::Implants, 'unidad', 2, true],
    ];

    /** @var array<string, array<string, float>> Tratamiento (slug) => material => cuánto usa en una atención. */
    public const RECIPES = [
        'evaluacion-o-primera-consulta' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Vasos descartables' => 1],
        'limpieza-dental' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Eyectores de saliva' => 1, 'Pasta profiláctica' => 5, 'Copas de profilaxis' => 1],
        'curacion-con-resina' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Eyectores de saliva' => 1, 'Lidocaína 2 % con epinefrina' => 1, 'Agujas dentales cortas' => 1, 'Ácido grabador 37 %' => 0.2, 'Adhesivo dental' => 0.1, 'Resina compuesta A2' => 0.5, 'Microaplicadores' => 2],
        'extraccion-simple' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Eyectores de saliva' => 1, 'Lidocaína 2 % con epinefrina' => 2, 'Agujas dentales cortas' => 1, 'Gasas estériles' => 4, 'Sutura reabsorbible 4-0' => 1],
        'blanqueamiento' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Eyectores de saliva' => 1, 'Kit de blanqueamiento' => 1],
        'endodoncia' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Eyectores de saliva' => 1, 'Lidocaína 2 % con epinefrina' => 2, 'Agujas dentales cortas' => 1, 'Dique de goma' => 1, 'Hipoclorito de sodio 2.5 %' => 10, 'Conos de gutapercha' => 5],
        'colocacion-de-brackets' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Eyectores de saliva' => 1, 'Brackets metálicos' => 1, 'Arcos de níquel-titanio' => 2, 'Ligaduras elásticas' => 20],
        'control-de-ortodoncia' => ['Guantes de nitrilo' => 1, 'Baberos descartables' => 1, 'Ligaduras elásticas' => 20],
        'implante' => ['Guantes de nitrilo' => 2, 'Baberos descartables' => 1, 'Eyectores de saliva' => 1, 'Lidocaína 2 % con epinefrina' => 3, 'Agujas dentales cortas' => 1, 'Gasas estériles' => 6, 'Sutura reabsorbible 4-0' => 1, 'Implante dental' => 1],
    ];

    /** @return array{supplies: int, recipes: int} Cuántos materiales y recetas se agregaron. */
    public function install(): array
    {
        return DB::transaction(function () {
            $created = 0;
            $supplies = [];

            foreach (self::SUPPLIES as $name => $definition) {
                [$category, $unit, $minimum] = $definition;
                $supply = Supply::query()->firstOrCreate(['name' => $name], [
                    'category' => $category,
                    'unit' => $unit,
                    'min_stock' => $minimum,
                    'tracks_serial' => $definition[3] ?? false,
                ]);
                $created += $supply->wasRecentlyCreated ? 1 : 0;
                $supplies[$name] = $supply;
            }

            $recipes = 0;

            foreach (self::RECIPES as $slug => $lines) {
                $treatment = Treatment::query()->where('slug', $slug)->first();

                // Una receta que el consultorio ya armó no se toca.
                if ($treatment === null || $treatment->supplies()->exists()) {
                    continue;
                }

                $treatment->supplies()->attach(collect($lines)->mapWithKeys(fn (float|int $quantity, string $name) => [$supplies[$name]->getKey() => ['quantity' => $quantity]])->all());
                $recipes++;
            }

            return ['supplies' => $created, 'recipes' => $recipes];
        });
    }
}
