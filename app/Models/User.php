<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Persona del equipo que entra al panel: administrador, recepción o doctor. */
#[Fillable(['name', 'email', 'password', 'role', 'is_dentist', 'phone', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_dentist' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Perfil de doctor (también lo puede tener un administrador odontólogo). */
    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isReception(): bool
    {
        return $this->role === UserRole::Reception;
    }

    public function isDoctor(): bool
    {
        return $this->role === UserRole::Doctor;
    }

    /** Solo los doctores (y el administrador si es odontólogo) ven lo clínico. */
    public function canSeeClinical(): bool
    {
        return $this->isDoctor() || ($this->isAdmin() && $this->is_dentist);
    }

    /** Administración y recepción manejan la agenda de todos los doctores. */
    public function managesAllAgendas(): bool
    {
        return $this->isAdmin() || $this->isReception();
    }

    /** Id del doctor cuya agenda maneja este usuario, si es doctor. */
    public function doctorId(): ?int
    {
        return $this->doctor?->getKey();
    }
}
