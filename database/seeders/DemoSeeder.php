<?php

namespace Database\Seeders;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\ClinicalFileType;
use App\Enums\ConfirmationChannel;
use App\Enums\Dentition;
use App\Enums\DiscountType;
use App\Enums\DocumentType;
use App\Enums\MessageTemplateKey;
use App\Enums\OdontogramType;
use App\Enums\PatientRelationship;
use App\Enums\PaymentMethod;
use App\Enums\PlanItemStatus;
use App\Enums\ToothState;
use App\Enums\TreatmentPlanStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Faq;
use App\Models\Icd10Code;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Clinical\ConsentService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Datos de demostración para probar el panel en local.
 * Usuarios: admin@consultorio.test, recepcion@consultorio.test,
 * dra.torres@consultorio.test y dr.ramos@consultorio.test (clave: password).
 */
class DemoSeeder extends Seeder
{
    public function run(WhatsAppOutbox $outbox, ClinicalFileService $files, ConsentService $consents, BillingService $billing): void
    {
        Setting::set('clinic.name', 'Consultorio Dental Sonrisas');
        Setting::set('clinic.address', 'Av. Larco 123, Miraflores');
        Setting::set('clinic.phone', '(01) 445 1234');
        Setting::set('clinic.district', 'Miraflores');
        Setting::set('clinic.email', 'citas@sonrisas.test');
        Setting::set('clinic.hours_note', 'Feriados: con cita previa.');
        Setting::set('web.about', "Somos un consultorio de barrio en Miraflores. Atendemos a toda la familia, desde la primera visita de los chicos hasta los abuelos.\n\nAntes de empezar cualquier tratamiento te explicamos qué tienes, qué opciones hay y cuánto cuesta cada una. Sin sorpresas.");

        $admin = User::factory()->admin(isDentist: true)->create(['name' => 'Administrador', 'email' => 'admin@consultorio.test']);
        $reception = User::factory()->reception()->create(['name' => 'Recepción', 'email' => 'recepcion@consultorio.test']);

        $torres = $this->doctor('Dra. Ana Torres', 'dra.torres@consultorio.test', 'Odontología general', '#0ea5e9', '24581');
        $ramos = $this->doctor('Dr. Luis Ramos', 'dr.ramos@consultorio.test', 'Ortodoncia', '#a855f7', '19873');
        $torres->update([
            'bio' => 'Atiendo a toda la familia. Me gusta explicar cada paso con calma, sobre todo a quienes llegan con miedo al dentista.',
            'education' => "Cirujana dentista, Universidad Peruana Cayetano Heredia\nDiplomado en estética dental",
        ]);
        $ramos->update([
            'rne_number' => '03215',
            'bio' => 'Especialista en ortodoncia: brackets y alineadores para chicos y adultos.',
            'education' => "Cirujano dentista, Universidad Nacional Mayor de San Marcos\nEspecialidad en ortodoncia y ortopedia maxilar",
        ]);

        $treatments = Treatment::all()->keyBy('slug');
        $prices = [
            'evaluacion-o-primera-consulta' => 50, 'limpieza-dental' => 120, 'curacion-con-resina' => 150,
            'extraccion-simple' => 150, 'blanqueamiento' => 450, 'endodoncia' => 600,
            'control-de-ortodoncia' => 150, 'colocacion-de-brackets' => 2500, 'implante' => 3500,
        ];

        foreach ($prices as $slug => $price) {
            $treatments[$slug]?->update(['price_from' => $price]);
        }

        $treatments['limpieza-dental']->update(['description' => <<<'MD'
            La limpieza retira el sarro y la placa que el cepillo no alcanza, sobre todo entre los dientes y bajo la encía. Así se previenen las caries, el sangrado de encías y el mal aliento.

            ## Cómo es la sesión

            1. Revisamos tus dientes y encías.
            2. Retiramos el sarro con ultrasonido y pulimos.
            3. Te damos indicaciones de cepillado según lo que vimos.

            Dura unos 45 minutos y no duele; si tienes las encías sensibles, avísanos y vamos más despacio.
            MD]);

        $ids = fn (array $slugs) => array_map(fn (string $slug) => $treatments[$slug]->id, $slugs);

        $torres->treatments()->sync($ids([
            'evaluacion-o-primera-consulta', 'limpieza-dental', 'curacion-con-resina', 'extraccion-simple', 'blanqueamiento', 'endodoncia', 'implante',
        ]));
        $ramos->treatments()->sync($ids(['evaluacion-o-primera-consulta', 'limpieza-dental', 'colocacion-de-brackets']));
        $ramos->treatments()->attach($treatments['control-de-ortodoncia']->id, ['duration_minutes' => 20]);
        $this->faqs($treatments['blanqueamiento']);

        // Una familia: la mamá tiene el celular y tiene a cargo a su hijo y a su madre.
        $quispe = Account::factory()->verified()->create(['phone' => '+51987654321', 'accepts_promotions' => true]);
        $maria = Patient::factory()->create(['account_id' => $quispe->id, 'first_name' => 'María', 'last_name' => 'Quispe Huamán', 'birth_date' => '1988-04-12']);
        $mateo = Patient::factory()->dependentOf($quispe)->create(['first_name' => 'Mateo', 'last_name' => 'Rojas Quispe', 'birth_date' => '2018-09-03']);
        $rosa = Patient::factory()->dependentOf($quispe, PatientRelationship::Parent)->create(['first_name' => 'Rosa', 'last_name' => 'Huamán Torres', 'birth_date' => '1956-01-20', 'document_type' => DocumentType::Dni, 'document_number' => '07654321']);

        $others = Patient::factory()->count(6)->create();

        $today = CarbonImmutable::today();
        $workday = fn (int $days) => $this->nextWorkday($today->addDays($days));

        $evaluation = $treatments['evaluacion-o-primera-consulta'];
        $cleaning = $treatments['limpieza-dental'];
        $resin = $treatments['curacion-con-resina'];
        $ortho = $treatments['control-de-ortodoncia'];

        // Agenda de hoy y los próximos días con distintos estados.
        $this->appointment($maria, $torres, $cleaning, $workday(0)->setTime(9, 0), AppointmentStatus::Confirmed);
        $this->appointment($others[0], $torres, $evaluation, $workday(0)->setTime(10, 0), AppointmentStatus::CheckedIn);
        $this->appointment($others[1], $torres, $resin, $workday(0)->setTime(15, 0), AppointmentStatus::Pending);
        $this->appointment($others[2], $ramos, $ortho, $workday(0)->setTime(9, 30), AppointmentStatus::Confirmed, BookingChannel::Doctor);
        $this->appointment($mateo, $ramos, $evaluation, $workday(1)->setTime(16, 0), AppointmentStatus::Pending)
            ->update(['unconfirmed_flagged_at' => now()]);
        $this->appointment($others[3], $torres, $evaluation, $workday(2)->setTime(11, 0), AppointmentStatus::Pending);
        $web = $this->appointment($others[4], $torres, $cleaning, $workday(3)->setTime(9, 0), AppointmentStatus::Pending);
        $outbox->queueForAppointment($web, MessageTemplateKey::BookingConfirmation);

        // Historia de una paciente atendida: consulta, odontograma, plan y pago.
        $past = $this->appointment($rosa, $torres, $evaluation, $today->subDays(7)->setTime(10, 0), AppointmentStatus::Completed);
        $rosa->forceFill(['first_visit_at' => $past->starts_at, 'last_visit_at' => $past->starts_at])->save();
        $consultation = Consultation::create([
            'patient_id' => $rosa->id,
            'doctor_id' => $torres->id,
            'appointment_id' => $past->id,
            'attended_at' => $past->starts_at,
            'chief_complaint' => 'Refiere dolor al masticar en pieza 36.',
            'clinical_exam' => 'Extraoral: sin alteraciones. Intraoral: caries profunda en 36, restauración antigua en 46.',
            'procedures' => 'Evaluación completa. Se toma radiografía periapical de 36.',
            'indications' => 'Evitar masticar del lado izquierdo hasta el tratamiento.',
            'next_steps' => 'Endodoncia de 36 en dos sesiones y luego resina.',
            'closed_at' => $past->starts_at->addMinutes(40),
        ]);
        $rosa->medicalBackground()->create([
            'conditions' => ['hypertension'],
            'allergies' => 'Penicilina',
            'medications' => 'Losartán 50 mg cada mañana',
            'updated_by_user_id' => $torres->user_id,
        ]);
        $consultation->diagnoses()->create(['icd10_code_id' => Icd10Code::where('code', 'K02.1')->value('id'), 'tooth' => '36']);

        $odontogram = $rosa->odontograms()->create([
            'consultation_id' => $consultation->id,
            'doctor_id' => $torres->id,
            'type' => OdontogramType::Initial,
            'dentition' => Dentition::Permanent,
        ]);
        $conditions = OdontogramCondition::all()->keyBy('code');
        $odontogram->entries()->createMany([
            ['tooth' => 36, 'surface' => 'O', 'odontogram_condition_id' => $conditions['caries']->id, 'state' => ToothState::Bad, 'abbreviation' => 'CD'],
            ['tooth' => 46, 'surface' => 'O', 'odontogram_condition_id' => $conditions['restauracion']->id, 'state' => ToothState::Good, 'abbreviation' => 'AM'],
            ['tooth' => 18, 'odontogram_condition_id' => $conditions['ausente']->id, 'state' => ToothState::Good],
            ['tooth' => 26, 'odontogram_condition_id' => $conditions['corona']->id, 'state' => ToothState::Good, 'abbreviation' => 'CM'],
            ['tooth' => 21, 'odontogram_condition_id' => $conditions['tratamiento_pulpar']->id, 'state' => ToothState::Good, 'abbreviation' => 'TC'],
            ['tooth' => 38, 'odontogram_condition_id' => $conditions['remanente_radicular']->id, 'state' => ToothState::Bad, 'abbreviation' => 'RR'],
            ['tooth' => 14, 'surface' => 'M', 'odontogram_condition_id' => $conditions['caries']->id, 'state' => ToothState::Bad, 'abbreviation' => 'CE'],
        ]);

        $plan = $rosa->treatmentPlans()->create([
            'doctor_id' => $torres->id,
            'consultation_id' => $consultation->id,
            'title' => 'Plan inicial',
            'status' => TreatmentPlanStatus::Accepted,
            'accepted_at' => now()->subDays(7),
        ]);
        [$endodontics] = $plan->items()->createMany([
            ['treatment_id' => $treatments['endodoncia']->id, 'description' => 'Endodoncia', 'tooth' => '36', 'unit_price' => 600, 'sessions_planned' => 2, 'status' => PlanItemStatus::Pending, 'sort_order' => 1],
            ['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'tooth' => '36', 'surfaces' => 'O', 'unit_price' => 150, 'status' => PlanItemStatus::Pending, 'sort_order' => 2],
            ['treatment_id' => $treatments['extraccion-simple']->id, 'description' => 'Extracción simple', 'tooth' => '38', 'unit_price' => 150, 'status' => PlanItemStatus::Pending, 'sort_order' => 3],
        ]);

        // Primera sesión de la endodoncia, ya agendada desde el plan.
        $this->appointment($rosa, $torres, $treatments['endodoncia'], $workday(1)->setTime(11, 0), AppointmentStatus::Confirmed, BookingChannel::Reception)
            ->update(['treatment_plan_item_id' => $endodontics->id]);

        $prescription = $consultation->prescriptions()->create([
            'patient_id' => $rosa->id,
            'doctor_id' => $torres->id,
            'issued_at' => $consultation->closed_at,
            'indications' => $consultation->indications,
        ]);
        $prescription->items()->create(['medication' => 'Paracetamol 500 mg', 'instructions' => '1 tableta cada 8 horas por 3 días, si hay dolor']);

        // La radiografía de esa consulta y el consentimiento firmado para la endodoncia.
        $files->store($rosa, $this->demoImage('periapical-36.jpg'), ClinicalFileType::Xray, $torres->user, $consultation, 'Periapical de 36', $past->starts_at);
        $consents->sign($rosa, $consents->templateFor($treatments['endodoncia']), $treatments['endodoncia'], $consents->defaultSigner($rosa), $this->demoSignature(), $torres->user);

        Charge::create([
            'patient_id' => $rosa->id, 'appointment_id' => $past->id, 'treatment_id' => $evaluation->id, 'doctor_id' => $torres->id,
            'description' => 'Evaluación', 'amount' => 50, 'charged_at' => $past->starts_at, 'created_by_user_id' => $reception->id,
        ]);
        Payment::create([
            'patient_id' => $rosa->id, 'appointment_id' => $past->id, 'treatment_id' => $evaluation->id, 'doctor_id' => $torres->id,
            'amount' => 50, 'method' => PaymentMethod::Yape, 'paid_at' => $past->starts_at->addHour(), 'received_by_user_id' => $reception->id,
        ]);

        // María pagó una parte de su limpieza: queda con saldo pendiente.
        $cleaned = $this->appointment($maria, $torres, $cleaning, $today->subDays(21)->setTime(16, 0), AppointmentStatus::Completed);
        $maria->forceFill(['first_visit_at' => $cleaned->starts_at, 'last_visit_at' => $cleaned->starts_at])->save();
        Charge::create([
            'patient_id' => $maria->id, 'appointment_id' => $cleaned->id, 'treatment_id' => $cleaning->id, 'doctor_id' => $torres->id,
            'description' => 'Limpieza dental', 'amount' => 120, 'charged_at' => $cleaned->starts_at, 'created_by_user_id' => $reception->id,
        ]);
        Payment::create([
            'patient_id' => $maria->id, 'appointment_id' => $cleaned->id, 'treatment_id' => $cleaning->id, 'doctor_id' => $torres->id,
            'amount' => 80, 'method' => PaymentMethod::Plin, 'paid_at' => $cleaned->starts_at->addHour(), 'received_by_user_id' => $reception->id,
        ]);

        // Si hoy se atiende, una cita ya atendida y lista para "Cobrar".
        if ($workday(0)->isToday()) {
            $done = $this->appointment($others[5], $ramos, $cleaning, $today->setTime(10, 0), AppointmentStatus::Completed);
            $billing->chargeAppointment($done, $reception);
        }

        Campaign::create([
            'name' => 'Blanqueamiento 20% de descuento',
            'slug' => 'blanqueamiento-20',
            'summary' => 'Sonrisa más blanca en una sesión. Válido hasta fin de mes.',
            'treatment_id' => $treatments['blanqueamiento']->id,
            'discount_type' => DiscountType::Percent,
            'discount_value' => 20,
            'starts_at' => $today->startOfMonth(),
            'ends_at' => $today->endOfMonth(),
            'promo_code' => 'BLANCO20',
            'max_redemptions' => 30,
            'whatsapp_text' => 'Hola, quiero la promoción de blanqueamiento 20%',
        ]);

        $this->history([$torres, $ramos], $treatments->all(), $reception);

        $this->command?->info("Demo lista. Entra a /panel con {$admin->email} / password");
    }

    /**
     * Un año de consultorio en marcha, para que Resumen y Ganancias tengan qué mostrar.
     * Siempre salen los mismos datos (semilla fija) y no toca hoy ni los días que vienen.
     *
     * @param  list<Doctor>  $doctors  [general, ortodoncista]
     * @param  array<string, Treatment>  $treatments
     */
    private function history(array $doctors, array $treatments, User $reception): void
    {
        mt_srand(2026);

        $pick = fn (array $weighted) => $this->weighted($weighted);
        $menus = [
            [['limpieza-dental', 5], ['curacion-con-resina', 4], ['extraccion-simple', 1], ['blanqueamiento', 1], ['endodoncia', 1]],
            [['control-de-ortodoncia', 8], ['limpieza-dental', 1], ['colocacion-de-brackets', 1]],
        ];
        $hours = [[9, 2], [10, 4], [11, 4], [12, 2], [15, 2], [16, 3], [17, 4], [18, 3]];
        $origins = [[AppointmentOrigin::Google, 5], [AppointmentOrigin::Recommendation, 4], [AppointmentOrigin::Instagram, 3], [AppointmentOrigin::Facebook, 2], [AppointmentOrigin::WalkIn, 1], [AppointmentOrigin::Referral, 1], [null, 2]];
        $methods = [[PaymentMethod::Yape, 4], [PaymentMethod::Cash, 3], [PaymentMethod::Card, 2], [PaymentMethod::Plin, 2], [PaymentMethod::Transfer, 1]];

        /** @var array<int, list<Patient>> $regulars Pacientes que ya vinieron, por doctor. */
        $regulars = [[], []];
        $today = CarbonImmutable::today();
        $start = $today->subMonthsNoOverflow(11)->startOfMonth();

        for ($day = $start; $day->lt($today); $day = $day->addDay()) {
            if ($day->isSunday()) {
                continue;
            }

            // El consultorio crece: cada mes, un poco más de citas.
            $month = (int) $start->diffInMonths($day);

            foreach ($doctors as $d => $doctor) {
                $taken = [];

                for ($i = mt_rand(1, $day->isSaturday() ? 3 : 3 + intdiv($month, 4)); $i > 0; $i--) {
                    $hour = $pick($hours);
                    $at = $day->setTime($hour, 0);

                    if (isset($taken[$hour]) || Appointment::query()->whereBelongsTo($doctor)->where('starts_at', $at)->exists()) {
                        continue;
                    }

                    $taken[$hour] = true;
                    $isNew = $regulars[$d] === [] || mt_rand(1, 100) <= 30;
                    $origin = $pick($origins);
                    $patient = $isNew
                        ? Patient::factory()->create(['origin' => $origin])
                        : $regulars[$d][mt_rand(0, count($regulars[$d]) - 1)];
                    $treatment = $treatments[$isNew ? 'evaluacion-o-primera-consulta' : $pick($menus[$d])];
                    $roll = mt_rand(1, 100);
                    $status = match (true) {
                        $roll <= 8 => AppointmentStatus::NoShow,
                        $roll <= 12 => AppointmentStatus::Cancelled,
                        default => AppointmentStatus::Completed,
                    };

                    $appointment = $this->appointment($patient, $doctor, $treatment, $at, $status, mt_rand(1, 100) <= 55 ? BookingChannel::Web : BookingChannel::Reception);
                    $appointment->update(['origin' => $isNew ? $origin : null, 'is_new_patient' => $isNew]);

                    if ($status !== AppointmentStatus::Completed) {
                        continue;
                    }

                    if ($isNew) {
                        $regulars[$d][] = $patient;
                    }

                    $patient->forceFill(['first_visit_at' => $patient->first_visit_at ?? $at, 'last_visit_at' => $at])->save();
                    $price = (float) $treatment->price_from;
                    Charge::create([
                        'patient_id' => $patient->id, 'appointment_id' => $appointment->id, 'treatment_id' => $treatment->id, 'doctor_id' => $doctor->id,
                        'description' => $treatment->name, 'amount' => $price, 'charged_at' => $at->addMinutes(40), 'created_by_user_id' => $reception->id,
                    ]);

                    // Casi todos pagan al salir; algunos dejan una parte y unos pocos, todo para después.
                    $paid = match (true) {
                        mt_rand(1, 100) <= 4 => 0,
                        $price >= 400 && mt_rand(1, 100) <= 50 => round($price / 2, 2),
                        mt_rand(1, 100) <= 8 => round($price * 0.6, 2),
                        default => $price,
                    };

                    if ($paid > 0) {
                        Payment::create([
                            'patient_id' => $patient->id, 'appointment_id' => $appointment->id, 'treatment_id' => $treatment->id, 'doctor_id' => $doctor->id,
                            'amount' => $paid, 'method' => $pick($methods), 'paid_at' => $at->addMinutes(50), 'received_by_user_id' => $reception->id,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * @template T
     *
     * @param  list<array{0: T, 1: int}>  $options  [valor, peso]
     * @return T
     */
    private function weighted(array $options): mixed
    {
        $roll = mt_rand(1, array_sum(array_column($options, 1)));

        foreach ($options as [$value, $weight]) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return $options[0][0];
    }

    /** Una "radiografía" de muestra: fondo oscuro con dos piezas claras. */
    /** Preguntas de ejemplo para ver la web completa; el consultorio las reemplaza por las suyas. */
    private function faqs(Treatment $whitening): void
    {
        $faqs = [
            ['¿Cuánto cuesta la evaluación?', "La evaluación cuesta S/ 50 e incluye la revisión completa y tu presupuesto.\n\nSi decides hacer el tratamiento con nosotros, la descontamos del total.", null],
            ['¿Cómo pago?', 'En el consultorio, con efectivo, tarjeta, Yape o Plin. No se cobra nada al reservar en la web.', null],
            ['¿Atienden a niños?', 'Sí, desde los 3 años. La primera visita es corta y tranquila para que se acostumbren.', null],
            ['¿Cómo confirmo, cambio o cancelo mi cita?', 'Con el enlace que te enviamos por WhatsApp al reservar. Si faltan más de 24 horas, el cambio se hace al instante.', null],
            ['¿El blanqueamiento daña los dientes?', 'Hecho por un dentista y con el producto adecuado, no daña el esmalte. Algunas personas sienten sensibilidad uno o dos días; pasa sola.', $whitening->id],
        ];

        foreach ($faqs as $i => [$question, $answer, $treatmentId]) {
            Faq::create(['question' => $question, 'answer' => $answer, 'treatment_id' => $treatmentId, 'sort_order' => $i]);
        }
    }

    private function demoImage(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(900, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 18, 18, 22));

        foreach ([[300, 170], [600, 190]] as [$x, $gray]) {
            $color = imagecolorallocate($image, $gray, $gray, $gray);
            imagefilledellipse($image, $x, 420, 260, 320, $color);
            imagefilledpolygon($image, [$x - 110, 480, $x + 110, 480, $x + 60, 1050, $x - 60, 1050], $color);
        }

        $path = tempnam(sys_get_temp_dir(), 'demo').'.jpg';
        imagejpeg($image, $path, 90);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** Un trazo como el de una firma hecha con el dedo. */
    private function demoSignature(): string
    {
        $image = imagecreatetruecolor(600, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagesetthickness($image, 4);
        $ink = imagecolorallocate($image, 15, 23, 42);
        $points = [];

        for ($x = 40; $x <= 560; $x += 8) {
            $points[] = [$x, (int) (110 + 45 * sin($x / 38) * cos($x / 95))];
        }

        foreach (array_slice($points, 1) as $i => [$x, $y]) {
            imageline($image, $points[$i][0], $points[$i][1], $x, $y, $ink);
        }

        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function doctor(string $name, string $email, string $specialty, string $color, string $cop): Doctor
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'role' => UserRole::Doctor]);

        return Doctor::factory()
            ->withSchedule()
            ->create(['user_id' => $user->id, 'name' => $name, 'specialty' => $specialty, 'agenda_color' => $color, 'cop_number' => $cop]);
    }

    private function appointment(
        Patient $patient,
        Doctor $doctor,
        Treatment $treatment,
        CarbonImmutable $startsAt,
        AppointmentStatus $status,
        BookingChannel $via = BookingChannel::Web,
    ): Appointment {
        $minutes = $doctor->durationFor($treatment);

        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'treatment_id' => $treatment->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($minutes),
            'buffer_minutes' => $treatment->bufferMinutes(),
            'status' => $status,
            'booked_via' => $via,
            'is_new_patient' => $treatment->is_evaluation,
            'confirmed_at' => $status === AppointmentStatus::Pending ? null : $startsAt->subDay(),
            'confirmed_via' => $status === AppointmentStatus::Pending ? null : ConfirmationChannel::PatientLink,
            'checked_in_at' => in_array($status, [AppointmentStatus::CheckedIn, AppointmentStatus::Completed], true) ? $startsAt->subMinutes(5) : null,
            'started_at' => $status === AppointmentStatus::Completed ? $startsAt : null,
            'finished_at' => $status === AppointmentStatus::Completed ? $startsAt->addMinutes($minutes + 5) : null,
        ]);
    }

    private function nextWorkday(CarbonImmutable $date): CarbonImmutable
    {
        return $date->isSunday() ? $date->addDay() : $date;
    }
}
