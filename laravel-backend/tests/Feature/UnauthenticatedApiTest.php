<?php

namespace Tests\Feature;

use Tests\TestCase;

/** An API call without a token gets a French 401, even when the client does not ask for JSON. */
class UnauthenticatedApiTest extends TestCase
{
    public function test_api_call_without_token_is_401_in_french_without_accept_header(): void
    {
        $this->get('/api/v1/clinics')
            ->assertStatus(401)
            ->assertJson(['message' => 'Connectez-vous pour accéder à cette ressource.']);
    }

    public function test_api_call_without_token_is_401_with_json_accept_header(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }
}
