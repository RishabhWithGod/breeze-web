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
    <meta name="theme-color" content="#0b1a3f" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    {{-- Roboto: the Breeze app's typeface, used by the client list. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@500;700&display=swap" />
    <link rel="icon" type="image/png" href="/favicon.png" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" />

    <script>
      // Set the theme before first paint so a dark-mode user never sees a light flash.
      (function () {
        try {
          var t = localStorage.getItem('breeze.theme');
          if (t !== 'light' && t !== 'dark') {
            t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
          }
          document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
      })();
    </script>

    <title inertia>{{ config('app.name') }}</title>

    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
  </head>
  <body>
    @inertia
  </body>
</html>
