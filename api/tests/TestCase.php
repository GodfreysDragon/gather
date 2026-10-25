<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;

abstract class TestCase extends BaseTestCase
{
    private static ?ValidatorInterface $openApi = null;

    /**
     * Every JSON request a test makes to the REST API is also a contract test:
     * the response is validated against ../openapi.yaml for the matched route,
     * method and status code. An undocumented status, a missing required field or
     * a wrong type fails the test that produced it.
     *
     * When the API accepts a request (any non-error status), the request is
     * validated too, so the server can't quietly accept input the contract doesn't
     * describe. Rejected requests are skipped: tests send invalid input on purpose.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @param  int  $options
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        $response = parent::json($method, $uri, $data, $headers, $options);

        $this->assertResponseMatchesOpenApi($response, $method);

        return $response;
    }

    protected function assertResponseMatchesOpenApi(TestResponse $response, string $method): void
    {
        $route = $this->app['router']->current();

        if ($route === null || ! str_starts_with($route->uri(), 'api/v1/')) {
            return;
        }

        $path = substr($route->uri(), strlen('api/v1'));

        try {
            self::openApi()->validate($response->baseResponse, $path, $method);

            if ($response->getStatusCode() < 400) {
                self::openApi()->validate($this->requestAsSent(), $path, $method);
            }
        } catch (ValidationException $e) {
            $this->fail(sprintf(
                "Response to %s %s violates openapi.yaml: %s\n\nResponse %d: %s",
                strtoupper($method), $path, $e->getMessage(), $response->getStatusCode(), $response->getContent(),
            ));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The request the API just handled. Tests authenticate with Sanctum::actingAs(),
     * which sends no Authorization header, so a placeholder stands in for the token
     * that a real client would send.
     */
    private function requestAsSent(): Request
    {
        $request = clone $this->app['request'];

        if (! $request->headers->has('Authorization') && $this->app['auth']->guard('sanctum')->check()) {
            $request->headers->set('Authorization', 'Bearer test-token');
        }

        return $request;
    }

    private static function openApi(): ValidatorInterface
    {
        return self::$openApi ??= ValidatorBuilder::fromYamlFile(dirname(__DIR__, 2).'/openapi.yaml')->getValidator();
    }
}
