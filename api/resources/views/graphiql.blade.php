<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gather GraphQL playground</title>
    <link rel="stylesheet" href="https://unpkg.com/graphiql@3.9.0/graphiql.min.css">
    <style>
        html, body, #graphiql { height: 100%; margin: 0; }
    </style>
</head>
<body>
    <div id="graphiql"></div>
    <script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/graphiql@3.9.0/graphiql.min.js"></script>
    <script>
        const defaultQuery = `# Gather GraphQL API. Reads are public.
# For your own data, log in with
# POST /api/v1/auth/login, then add
# {"Authorization": "Bearer <token>"}
# under Headers below.

query UpcomingEvents {
  events(first: 5, hasSpots: true) {
    data {
      title
      startsAt
      spotsLeft
      venue { name city }
    }
    paginatorInfo { total }
  }
}
`

        ReactDOM.createRoot(document.getElementById('graphiql')).render(
            React.createElement(GraphiQL, {
                fetcher: GraphiQL.createFetcher({ url: @json(url('/graphql')) }),
                defaultQuery,
            }),
        )
    </script>
</body>
</html>
