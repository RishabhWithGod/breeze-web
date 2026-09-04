<!doctype html>
{{--
  en-US, not the bare locale: every date, number and phone number in this app is
  written the American way, and the document should say so — for a screen reader
  reading "09/03/2026", for spellcheck, and for translation tools.

  It does not decide how a date picker draws itself: browsers take that from the
  browser's or the operating system's own region setting, not from this
  attribute. Every date the app writes out is formatted in code for that reason.
--}}
<html lang="en-US">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta
      name="description"
      content="AI-powered electrical takeoff — upload drawings and get automated symbol detection, material counts and cost breakdowns."
    />
    <meta name="theme-color" content="#0a1c33" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <link rel="icon" type="image/png" href="/favicon.png" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap"
      rel="stylesheet"
    />

    <title inertia>{{ config('app.name') }}</title>

    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
  </head>
  <body>
    @inertia
  </body>
</html>
