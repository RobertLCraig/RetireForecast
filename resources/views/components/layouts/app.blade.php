{{-- The shell every full-page Livewire component renders into. Accessibility is a
     hard constraint (WCAG 2.1 AA): a skip link, a labelled landmark structure and a
     persistent guidance-only disclaimer are part of the frame, not optional polish. --}}
<!DOCTYPE html>
<html lang="en" class="antialiased">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' — RetireForecast' : 'RetireForecast' }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900 flex flex-col">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:shadow focus:ring-2 focus:ring-blue-600">
            Skip to main content
        </a>

        <header class="border-b border-gray-200 bg-white">
            <nav aria-label="Primary" class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                <a href="{{ url('/') }}" class="text-lg font-semibold text-gray-900">RetireForecast</a>
                <ul class="flex items-center gap-4 text-sm">
                    @auth
                        <li><a href="{{ route('dashboard') }}" class="rounded px-3 py-1.5 hover:bg-gray-100">Dashboard</a></li>
                        <li><a href="{{ route('scenarios.create') }}" class="rounded px-3 py-1.5 hover:bg-gray-100">New forecast</a></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="rounded px-3 py-1.5 hover:bg-gray-100">Log out</button>
                            </form>
                        </li>
                    @else
                        <li><a href="{{ route('login') }}" class="rounded px-3 py-1.5 hover:bg-gray-100">Log in</a></li>
                        <li><a href="{{ route('register') }}" class="rounded bg-blue-600 px-3 py-1.5 text-white hover:bg-blue-700">Register</a></li>
                    @endauth
                </ul>
            </nav>
        </header>

        <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
            {{ $slot }}
        </main>

        <footer class="border-t border-gray-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-6 text-xs text-gray-600">
                <p class="font-medium text-gray-700">Guidance only, not financial advice.</p>
                <p class="mt-1">
                    RetireForecast illustrates the consequences of figures and assumptions you enter. It does not
                    recommend a course of action. For free, impartial guidance see
                    <a class="underline" href="https://www.moneyhelper.org.uk/" rel="noopener">MoneyHelper</a> and
                    <a class="underline" href="https://www.moneyhelper.org.uk/en/pensions-and-retirement/pension-wise" rel="noopener">Pension Wise</a>,
                    or speak to an FCA-regulated adviser.
                </p>
            </div>
        </footer>
    </body>
</html>
