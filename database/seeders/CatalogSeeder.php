<?php

namespace Database\Seeders;

use App\Enums\MessageTemplateKey;
use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use App\Models\ClinicalTemplate;
use App\Models\ConsentTemplate;
use App\Models\Icd10Code;
use App\Models\MessageTemplate;
use App\Models\OdontogramCondition;
use App\Models\TextSnippet;
use App\Models\Treatment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Catálogos base que necesita el sistema en producción. Se puede volver a
 * ejecutar sin duplicar nada: solo agrega lo que falta y no pisa lo que el
 * consultorio ya editó.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTreatments();
        $this->seedIcd10();
        $this->seedOdontogramConditions();
        $this->seedMessageTemplates();
        $this->seedSnippets();
        $this->seedClinicalTemplates();
        $this->seedConsentTemplate();
    }

    /** Tipos de cita de la propuesta. Los precios los carga el consultorio. */
    private function seedTreatments(): void
    {
        $treatments = [
            ['Evaluación o primera consulta', 30, '1', true, true, 'Revisión completa, diagnóstico y presupuesto. Si no sabes qué necesitas, empieza aquí.'],
            ['Control de ortodoncia', 30, 'Una vez al mes', false, false, 'Ajuste y seguimiento de tus brackets o alineadores.'],
            ['Limpieza dental', 45, '1', false, true, 'Retiro de sarro y placa, y pulido. Lo ideal es hacerla cada seis meses.'],
            ['Curación con resina', 60, '1', false, false, 'Repara caries o dientes rotos con resina del color de tu diente.'],
            ['Extracción simple', 45, '1', false, false, 'Retiro de una pieza dañada, con anestesia local.'],
            ['Blanqueamiento', 90, '1 – 2', false, false, 'Aclara el color de tus dientes en el consultorio, con control del dentista.'],
            ['Endodoncia', 90, '1 – 3', false, false, 'Tratamiento de conducto para salvar un diente con el nervio dañado.'],
            ['Colocación de brackets', 120, '1', false, false, 'Inicio de tu ortodoncia para alinear los dientes y la mordida.'],
            ['Implante', 120, 'Varias sesiones', false, false, 'Reemplaza un diente perdido con una raíz de titanio y una corona.'],
        ];

        foreach ($treatments as $i => [$name, $minutes, $sessions, $isEvaluation, $onlineBookable, $summary]) {
            Treatment::firstOrCreate(['slug' => Str::slug($name)], [
                'name' => $name,
                'summary' => $summary,
                'duration_minutes' => $minutes,
                'sessions_label' => $sessions,
                'is_evaluation' => $isEvaluation,
                'online_bookable' => $onlineBookable,
                'show_price' => true,
                'is_public' => true,
                'sort_order' => $i + 1,
            ]);
        }
    }

    /** Diagnósticos CIE-10 frecuentes en odontología. */
    private function seedIcd10(): void
    {
        $codes = [
            'K00.0' => 'Anodoncia',
            'K00.1' => 'Dientes supernumerarios',
            'K00.4' => 'Alteraciones en la formación de los dientes',
            'K00.6' => 'Alteraciones en la erupción dentaria',
            'K01.0' => 'Dientes incluidos',
            'K01.1' => 'Dientes impactados',
            'K02.0' => 'Caries limitada al esmalte',
            'K02.1' => 'Caries de la dentina',
            'K02.2' => 'Caries del cemento',
            'K02.3' => 'Caries dentaria detenida',
            'K02.9' => 'Caries dental, no especificada',
            'K03.0' => 'Atrición excesiva de los dientes',
            'K03.1' => 'Abrasión de los dientes',
            'K03.2' => 'Erosión de los dientes',
            'K03.6' => 'Depósitos (acreciones) en los dientes: placa y cálculo',
            'K03.7' => 'Cambios posteruptivos del color de los tejidos duros de los dientes',
            'K04.0' => 'Pulpitis',
            'K04.1' => 'Necrosis de la pulpa',
            'K04.4' => 'Periodontitis apical aguda originada en la pulpa',
            'K04.5' => 'Periodontitis apical crónica',
            'K04.6' => 'Absceso periapical con fístula',
            'K04.7' => 'Absceso periapical sin fístula',
            'K04.8' => 'Quiste radicular',
            'K05.0' => 'Gingivitis aguda',
            'K05.1' => 'Gingivitis crónica',
            'K05.2' => 'Periodontitis aguda',
            'K05.3' => 'Periodontitis crónica',
            'K05.4' => 'Periodontosis',
            'K06.0' => 'Retracción gingival',
            'K06.1' => 'Hiperplasia gingival',
            'K07.2' => 'Anomalías de la relación entre los arcos dentarios',
            'K07.3' => 'Anomalías de la posición del diente',
            'K07.4' => 'Maloclusión de tipo no especificado',
            'K07.6' => 'Trastornos de la articulación temporomaxilar',
            'K08.1' => 'Pérdida de dientes por accidente, extracción o enfermedad periodontal local',
            'K08.3' => 'Raíz dental retenida',
            'K08.8' => 'Otras afecciones especificadas de los dientes y estructuras de sostén',
            'K09.0' => 'Quistes originados por el desarrollo de los dientes',
            'K10.3' => 'Alveolitis del maxilar',
            'K12.0' => 'Estomatitis aftosa recurrente',
            'K12.2' => 'Celulitis y absceso de boca',
            'K13.0' => 'Enfermedades de los labios',
            'K14.0' => 'Glositis',
            'S02.5' => 'Fractura de los dientes',
            'S03.2' => 'Luxación de diente',
            'Z01.2' => 'Examen odontológico',
            'Z46.3' => 'Prueba y ajuste de prótesis dental',
            'Z46.4' => 'Prueba y ajuste de dispositivo ortodóncico',
        ];

        foreach ($codes as $code => $description) {
            Icd10Code::firstOrCreate(['code' => $code], ['description' => $description, 'is_dental' => true]);
        }
    }

    /**
     * Hallazgos del odontograma según la norma técnica del MINSA.
     * Rojo (bad) = mal estado o por tratar; azul (good) = buen estado o tratado.
     */
    private function seedOdontogramConditions(): void
    {
        // [código, nombre, se aplica a, siglas, permite azul, permite rojo]
        $conditions = [
            ['caries', 'Caries', 'surface', ['MB', 'CE', 'CD', 'CDP'], false, true],
            ['restauracion', 'Restauración definitiva', 'surface', ['AM', 'R', 'IV', 'IM', 'IE', 'C'], true, true],
            ['restauracion_temporal', 'Restauración temporal', 'surface', [], false, true],
            ['sellante', 'Sellante', 'surface', ['S'], true, true],
            ['defecto_esmalte', 'Defecto de desarrollo del esmalte', 'surface', ['HP', 'HM', 'O', 'D', 'Fluorosis'], false, true],
            ['superficie_desgastada', 'Superficie desgastada', 'tooth', ['DES'], false, true],
            ['fosas_fisuras', 'Fosas y fisuras profundas', 'tooth', ['FFP'], false, true],
            ['tratamiento_pulpar', 'Tratamiento pulpar', 'tooth', ['TC', 'PC', 'PP'], true, true],
            ['corona', 'Corona definitiva', 'tooth', ['CM', 'CF', 'CMC', 'CV', 'CLM'], true, true],
            ['corona_temporal', 'Corona temporal', 'tooth', ['CT'], false, true],
            ['implante', 'Implante dental', 'tooth', ['IMP'], true, true],
            ['extraccion_indicada', 'Extracción indicada', 'tooth', [], false, true],
            ['ausente', 'Pieza dentaria ausente', 'tooth', [], true, false],
            ['remanente_radicular', 'Remanente radicular', 'tooth', ['RR'], false, true],
            ['fractura', 'Fractura', 'tooth', [], false, true],
            ['erupcion', 'Pieza en erupción', 'tooth', [], true, false],
            ['extruida', 'Pieza extruida', 'tooth', [], false, true],
            ['intruida', 'Pieza intruida', 'tooth', [], false, true],
            ['giroversion', 'Giroversión', 'tooth', [], false, true],
            ['migracion', 'Migración', 'tooth', [], false, true],
            ['posicion', 'Posición dentaria', 'tooth', ['M', 'D', 'V', 'P', 'L'], false, true],
            ['movilidad', 'Movilidad patológica', 'tooth', ['M1', 'M2', 'M3'], false, true],
            ['impactacion', 'Impactación', 'tooth', ['I'], false, true],
            ['supernumeraria', 'Pieza supernumeraria', 'tooth', [], true, false],
            ['macrodoncia', 'Macrodoncia', 'tooth', ['MAC'], true, false],
            ['microdoncia', 'Microdoncia', 'tooth', ['MIC'], true, false],
            ['fusion', 'Fusión', 'tooth', [], true, false],
            ['geminacion', 'Geminación', 'tooth', [], true, false],
            ['diastema', 'Diastema', 'span', [], true, false],
            ['transposicion', 'Transposición', 'span', [], true, false],
            ['protesis_fija', 'Prótesis fija', 'span', [], true, true],
            ['protesis_removible', 'Prótesis removible', 'span', [], true, true],
            ['protesis_total', 'Prótesis total', 'span', [], true, true],
            ['ortodoncia_fija', 'Aparato ortodóntico fijo', 'span', [], true, true],
            ['ortodoncia_removible', 'Aparato ortodóntico removible', 'span', [], true, true],
            ['edentulo_total', 'Edéntulo total', 'span', [], true, false],
        ];

        foreach ($conditions as $i => [$code, $name, $appliesTo, $abbreviations, $good, $bad]) {
            OdontogramCondition::firstOrCreate(['code' => $code], [
                'name' => $name,
                'applies_to' => $appliesTo,
                'abbreviations' => $abbreviations ?: null,
                'allows_good_state' => $good,
                'allows_bad_state' => $bad,
                'sort_order' => $i + 1,
            ]);
        }
    }

    private function seedMessageTemplates(): void
    {
        foreach (MessageTemplateKey::cases() as $key) {
            MessageTemplate::firstOrCreate(['key' => $key], [
                'name' => $key->getLabel(),
                'body' => $key->defaultBody(),
            ]);
        }
    }

    /** Opciones rápidas compartidas para empezar; cada doctor suma las suyas. */
    private function seedSnippets(): void
    {
        $snippets = [
            [SnippetField::ChiefComplaint, 'Dolor dental', 'Paciente refiere dolor en pieza ', null],
            [SnippetField::ChiefComplaint, 'Control y limpieza', 'Acude para control y limpieza dental.', null],
            [SnippetField::ChiefComplaint, 'Sensibilidad', 'Refiere sensibilidad al frío y al dulce.', null],
            [SnippetField::ClinicalExam, 'Examen sin alteraciones', 'Extraoral: sin alteraciones. Intraoral: mucosas hidratadas, de color rosado, sin lesiones.', 'normal'],
            [SnippetField::Procedures, 'Profilaxis completa', 'Se realizó profilaxis: destartraje supragingival con ultrasonido, pulido coronal y aplicación de flúor barniz.', 'limp'],
            [SnippetField::Procedures, 'Restauración con resina', 'Aislamiento relativo, remoción de caries, grabado ácido, adhesivo y restauración con resina fotocurable. Se ajusta oclusión y se pule.', 'res'],
            [SnippetField::Procedures, 'Extracción simple', 'Anestesia infiltrativa, sindesmotomía, luxación y avulsión de la pieza. Hemostasia con gasa. Se dan indicaciones.', 'ext'],
            [SnippetField::Indications, 'Indicaciones post-extracción', 'No enjuagarse ni escupir por 24 horas. Morder la gasa 30 minutos. Dieta blanda y fría. Hielo en la zona por intervalos de 15 minutos. No fumar ni usar sorbete.', 'postex'],
            [SnippetField::Indications, 'Indicaciones post-resina', 'Puede comer normalmente. Si siente la mordida alta o molestia que no cede en 2 días, comuníquese con el consultorio.', 'postres'],
            [SnippetField::Prescription, 'Ibuprofeno 400 mg', 'Ibuprofeno 400 mg: 1 tableta cada 8 horas por 3 días, después de los alimentos.', 'ibu'],
            [SnippetField::Prescription, 'Paracetamol 500 mg', 'Paracetamol 500 mg: 1 tableta cada 8 horas por 3 días, si hay dolor.', 'para'],
        ];

        foreach ($snippets as [$field, $label, $body, $shortcut]) {
            TextSnippet::firstOrCreate(['user_id' => null, 'field' => $field, 'label' => $label], [
                'body' => $body,
                'shortcut' => $shortcut,
                'status' => SnippetStatus::Active,
            ]);
        }
    }

    private function seedClinicalTemplates(): void
    {
        $templates = [
            'limpieza-dental' => [
                'chief_complaint' => 'Acude para control y limpieza dental.',
                'procedures' => 'Se realizó profilaxis: destartraje supragingival con ultrasonido, pulido coronal y aplicación de flúor barniz.',
                'indications' => 'Cepillado 3 veces al día con técnica indicada. Uso de hilo dental. Control en 6 meses.',
            ],
            'extraccion-simple' => [
                'procedures' => 'Anestesia infiltrativa, sindesmotomía, luxación y avulsión de la pieza. Hemostasia con gasa.',
                'indications' => 'No enjuagarse ni escupir por 24 horas. Morder la gasa 30 minutos. Dieta blanda y fría. Hielo en la zona por intervalos de 15 minutos. No fumar ni usar sorbete.',
                'prescription_items' => [
                    ['medication' => 'Ibuprofeno 400 mg', 'dose' => '1 tableta', 'frequency' => 'cada 8 horas', 'duration' => '3 días'],
                ],
            ],
            'curacion-con-resina' => [
                'procedures' => 'Aislamiento relativo, remoción de caries, grabado ácido, adhesivo y restauración con resina fotocurable. Se ajusta oclusión y se pule.',
                'indications' => 'Puede comer normalmente. Si siente la mordida alta o molestia que no cede en 2 días, comuníquese con el consultorio.',
            ],
        ];

        foreach ($templates as $slug => $content) {
            $treatment = Treatment::where('slug', $slug)->first();

            if ($treatment) {
                ClinicalTemplate::firstOrCreate(
                    ['treatment_id' => $treatment->getKey(), 'user_id' => null],
                    ['name' => $treatment->name, 'content' => $content],
                );
            }
        }
    }

    private function seedConsentTemplate(): void
    {
        ConsentTemplate::firstOrCreate(['name' => 'Consentimiento informado general'], [
            'body' => <<<'TXT'
                Paciente: {paciente}.

                Yo, {firmante}, identificado(a) con {documento}, {calidad}, autorizo a {doctor} a realizar el tratamiento de {tratamiento}.

                Se me explicó en un lenguaje claro en qué consiste el tratamiento, sus beneficios, los riesgos y molestias más frecuentes, y las alternativas disponibles. Pude hacer preguntas y fueron respondidas.

                Entiendo que puedo retirar este consentimiento en cualquier momento antes del procedimiento.

                {ciudad}, {fecha}.
                TXT,
        ]);
    }
}
