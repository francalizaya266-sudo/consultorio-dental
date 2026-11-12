<?php

namespace App\Services\Dashboard;

use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\DailyClosing;
use App\Models\DeskNote;
use App\Models\Payment;
use App\Models\RescheduleRequest;
use App\Models\ScheduleBlock;
use App\Models\WaitlistEntry;
use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El pulso del día: una marca que cambia cada vez que alguien guarda algo de lo que muestran "Hoy", la agenda
 * o la campana (una cita, un cobro, un aviso…). Cada pantalla abierta la consulta cada pocos segundos
 * (PanelPulse) y, si cambió, se vuelve a pintar. Así lo que marca recepción lo ve el doctor casi al momento,
 * y mientras nadie guarda nada las pantallas no vuelven a leer la agenda entera.
 */
class TodayPulse
{
    /** @var list<class-string<Model>> Lo que, al guardarse o borrarse, cambia lo que otros tienen en pantalla. */
    public const WATCHED = [
        Appointment::class,
        Consultation::class,
        Payment::class,
        Charge::class,
        DailyClosing::class,
        WaitlistEntry::class,
        RescheduleRequest::class,
        ScheduleBlock::class,
        DeskNote::class,
        WhatsappMessage::class,
        DatabaseNotification::class,
    ];

    private const KEY = 'today.pulse';

    /** Desde que arranca la aplicación, cada cambio en lo vigilado mueve la marca. */
    public static function watch(): void
    {
        foreach (self::WATCHED as $model) {
            $model::saved(self::beat(...));
            $model::deleted(self::beat(...));
        }
    }

    /**
     * La marca se mueve cuando el cambio ya quedó guardado (al terminar la transacción, si hay una): quien la
     * vea distinta y vuelva a leer, encuentra los datos nuevos. Si la caché falla, la cita igual se guarda:
     * las pantallas se enteran con su recarga de cada 30 segundos.
     */
    public static function beat(): void
    {
        DB::afterCommit(fn () => rescue(fn () => Cache::forever(self::KEY, (string) Str::uuid())));
    }

    /** La marca de ahora. Vacía si todavía nadie guardó nada. */
    public static function stamp(): string
    {
        return (string) Cache::get(self::KEY, '');
    }
}
