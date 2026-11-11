<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * La salida de emergencia: si el administrador olvida su contraseña y el hosting no envía correos, nadie más
 * puede cambiársela desde el panel. Quien tiene acceso al servidor le pone una temporal con este comando.
 */
#[Signature('usuario:clave {correo : Correo con el que esa persona entra al panel}')]
#[Description('Pone una contraseña temporal a alguien que no puede entrar al panel')]
class SetTemporaryPassword extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('correo'))->first();

        if ($user === null) {
            $this->error('No hay ningún usuario con ese correo.');

            return self::FAILURE;
        }

        $password = Str::password(12, symbols: false);

        // La clave se guarda cifrada (cast "hashed") y el "recuérdame" de antes deja de servir.
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        $this->info("Contraseña temporal de {$user->name}: {$password}");
        $this->line('Que la cambie al entrar, en Mi perfil.');

        if (! $user->is_active) {
            $this->warn('Ojo: este usuario tiene apagado "Puede entrar al panel", así que todavía no podrá entrar.');
        }

        return self::SUCCESS;
    }
}
