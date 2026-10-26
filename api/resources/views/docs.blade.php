<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gather REST API reference</title>
</head>
<body>
    <div id="app"></div>
    {{-- Renders ../openapi.yaml, the same contract the tests validate against. --}}
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.73.1/dist/browser/standalone.js"></script>
    <script>
        // Not #api-reference: Scalar's legacy auto-init claims that id and renders nothing.
        Scalar.createApiReference('#app', {
            url: @json(url('/openapi.yaml')),
            // "Try it" calls this server rather than the spec's localhost default.
            servers: [{ url: @json(url('/api/v1')), description: 'This server' }],
            authentication: { preferredSecurityScheme: 'bearerAuth' },
            hideClientButton: true,
            showDeveloperTools: 'never',
            agent: { disabled: true },
            mcp: { disabled: true },
        })
    </script>
</body>
</html>
