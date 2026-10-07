<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_guests_from_root(): void
    {
        // Root redirects to the dashboard, which in turn redirects guests to login.
        $response = $this->get('/');

        $response->assertRedirect();
    }
}
