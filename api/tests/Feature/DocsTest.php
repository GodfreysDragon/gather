<?php

namespace Tests\Feature;

use GraphQL\Type\Introspection;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class DocsTest extends TestCase
{
    use MakesGraphQLRequests;

    public function test_the_root_points_api_consumers_at_the_entry_points(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJsonPath('docs', url('/docs'))
            ->assertJsonPath('graphiql', url('/graphiql'))
            ->assertJsonPath('openapi', url('/openapi.yaml'));
    }

    public function test_the_rest_reference_renders_the_served_openapi_contract(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Scalar.createApiReference', false)
            // Scalar's legacy auto-init claims #api-reference and renders an empty page.
            ->assertDontSee('id="api-reference"', false)
            ->assertSee(json_encode(url('/openapi.yaml')), false);

        $this->get('/openapi.yaml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml');
    }

    public function test_the_graphql_playground_targets_the_graphql_endpoint(): void
    {
        $this->get('/graphiql')
            ->assertOk()
            ->assertSee('GraphiQL.createFetcher', false)
            ->assertSee(json_encode(url('/graphql')), false);
    }

    public function test_the_playground_can_introspect_the_schema_within_the_query_limits(): void
    {
        $this->graphQL(Introspection::getIntrospectionQuery(['descriptions' => true, 'directiveIsRepeatable' => true]))
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.__schema.queryType.name', 'Query');
    }

    public function test_the_depth_limit_still_applies_to_data_fields(): void
    {
        config(['lighthouse.security.max_query_depth' => 1]);

        $this->graphQL(/** @lang GraphQL */ '{ events { data { venue { city } } } }')
            ->assertGraphQLErrorMessage('Max query depth should be 1 but got 2.');

        $this->graphQL(Introspection::getIntrospectionQuery())->assertGraphQLErrorFree();
    }
}
