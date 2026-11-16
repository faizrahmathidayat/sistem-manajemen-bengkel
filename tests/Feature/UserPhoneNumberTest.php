<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\TestCase;

class UserPhoneNumberTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions;

    protected function admin(): User
    {
        $admin = User::factory()->create();
        foreach (['user.view', 'user.create', 'user.edit'] as $code) {
            $this->grantGlobal($admin, $code);
        }

        return User::find($admin->id);
    }

    public function test_phone_numbers_are_normalised_to_international_format(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('0812-3456-7890'));
        $this->assertSame('6281234567890', PhoneNumber::normalize('+62 812 3456 7890'));
        $this->assertSame('6281234567890', PhoneNumber::normalize('6281234567890'));
        $this->assertNull(PhoneNumber::normalize('abc'));
        $this->assertNull(PhoneNumber::normalize('123'));
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize(null));
    }

    public function test_edit_form_saves_a_normalised_number_and_rejects_garbage(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin())->put("/users/{$target->id}", [
            'name' => $target->name, 'username' => $target->username, 'is_active' => 1,
            'phone_number' => '0812 3456 7890',
        ])->assertRedirect();
        $this->assertSame('6281234567890', $target->fresh()->phone_number);

        $this->actingAs($this->admin())->put("/users/{$target->id}", [
            'name' => $target->name, 'username' => $target->username, 'is_active' => 1,
            'phone_number' => 'bukan nomor',
        ])->assertSessionHasErrors('phone_number');
        $this->assertSame('6281234567890', $target->fresh()->phone_number);
    }

    public function test_the_number_can_be_cleared_and_is_set_on_create(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Budi', 'username' => 'budi', 'password' => 'secret123', 'is_active' => 1,
            'phone_number' => '08111222333',
        ])->assertRedirect();
        $budi = User::where('username', 'budi')->firstOrFail();
        $this->assertSame('628111222333', $budi->phone_number);

        $this->actingAs($admin)->put("/users/{$budi->id}", [
            'name' => 'Budi', 'username' => 'budi', 'is_active' => 1, 'phone_number' => '',
        ])->assertRedirect();
        $this->assertNull($budi->fresh()->phone_number);
    }
}
