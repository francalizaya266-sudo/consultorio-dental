<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetTemporaryPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_person_enters_with_the_temporary_password_and_the_old_one_stops_working(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Dr. Vega', 'email' => 'vega@clinica.test']);

        $this->assertSame(0, Artisan::call('usuario:clave', ['correo' => 'vega@clinica.test']));

        $this->assertSame(1, preg_match('/Contraseña temporal de Dr\. Vega: (\S{12})\s/', Artisan::output(), $found));
        $this->assertTrue(Hash::check($found[1], $admin->refresh()->password));
        $this->assertFalse(Hash::check('password', $admin->password));
    }

    public function test_an_email_that_belongs_to_nobody_changes_nothing_and_says_so(): void
    {
        $other = User::factory()->create(['email' => 'recepcion@clinica.test']);

        $this->artisan('usuario:clave', ['correo' => 'nadie@clinica.test'])
            ->expectsOutput('No hay ningún usuario con ese correo.')
            ->assertFailed();

        $this->assertTrue(Hash::check('password', $other->refresh()->password));
    }

    public function test_it_warns_when_that_person_has_the_panel_access_turned_off(): void
    {
        User::factory()->create(['email' => 'antes@clinica.test', 'is_active' => false]);

        $this->artisan('usuario:clave', ['correo' => 'antes@clinica.test'])
            ->expectsOutputToContain('todavía no podrá entrar')
            ->assertSuccessful();
    }
}
