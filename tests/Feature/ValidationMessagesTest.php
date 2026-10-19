<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ValidationMessagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_errors_are_shown_in_french_instead_of_translation_keys(): void
    {
        app()->setLocale('fr');
        $sender = User::factory()->create(['can_send' => true]);

        $this->actingAs($sender)->post(route('documents.store'), [])
            ->assertSessionHasErrors(['title' => 'Le champ titre est obligatoire.']);
    }
}
