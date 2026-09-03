<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_agent_domain_redirects_to_sso_login(): void
    {
        // Default test host is the agent subdomain (see phpunit.xml), so
        // GET / hits agent.home, auth middleware redirects guests to the
        // agent SSO login via redirectGuestsTo.
        $response = $this->get('/');

        $response->assertRedirect(route('agent.login'));
    }
}
