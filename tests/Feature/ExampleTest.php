<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root route has no standalone page — it always redirects into the
     * BackOffice (login, or the dashboard if already signed in).
     */
    public function test_the_application_redirects_to_the_backoffice_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('office.login'));
    }
}
