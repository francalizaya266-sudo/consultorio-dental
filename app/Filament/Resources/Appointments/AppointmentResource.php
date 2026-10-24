<?php

namespace App\Filament\Resources\Appointments;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Resources\Appointments\Schemas\AppointmentInfolist;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppointmentResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'citas';

    protected static ?string $model = Appointment::class;

    protected static ?string $modelLabel = 'cita';

    protected static ?string $pluralModelLabel = 'citas';

    protected static ?string $navigationParentItem = 'Agenda';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function form(Schema $schema): Schema
    {
        return AppointmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AppointmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppointmentsTable::configure($table);
    }

    /** Cada doctor ve solo su agenda. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['patient.account', 'doctor', 'treatment'])
            ->when(! $user->managesAllAgendas(), fn (Builder $q) => $q->where('doctor_id', $user->doctorId()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/nueva'),
            'view' => ViewAppointment::route('/{record}'),
        ];
    }
}
