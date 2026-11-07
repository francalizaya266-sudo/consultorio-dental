<?php

namespace App\Console\Commands;

use App\Models\Doctor;
use App\Services\Agenda\LearnedDurations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agenda:aprender-duraciones')]
#[Description('Mide cuánto tarda en realidad cada doctor en cada tratamiento, para que la agenda reserve ese tiempo')]
class LearnDurations extends Command
{
    public function handle(LearnedDurations $durations): int
    {
        $doctors = Doctor::query()->active()->with('treatments')->get();

        $doctors->each(fn (Doctor $doctor) => $durations->refresh($doctor));

        $this->info("Doctores revisados: {$doctors->count()}");

        return self::SUCCESS;
    }
}
