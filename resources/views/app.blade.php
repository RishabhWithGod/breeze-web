<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta
      name="description"
      content="AI-powered electrical takeoff — upload drawings and get automated symbol detection, material counts and cost breakdowns."
    />
    <meta name="theme-color" content="#0a1c33" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap"
      rel="stylesheet"
    />

    <title inertia>{{ config('app.name') }} — AI Electrical Takeoff</title>

    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
  </head>
  <body>
    @inertia
  </body>
</html>
