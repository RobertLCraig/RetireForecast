<x-layouts.app title="Log in">
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-gray-900">Log in</h1>
        <p class="mt-1 text-sm text-gray-600">Welcome back. Your saved forecasts are private to your account.</p>

        @if (session('status'))
            <div role="status" class="mt-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                @error('email')
                    <p id="email-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                @error('password')
                    <p id="password-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="remember" class="rounded border-gray-300"> Remember me
                </label>
                <a href="{{ route('password.request') }}" class="text-sm text-blue-700 underline">Forgot password?</a>
            </div>

            <button type="submit" class="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Log in
            </button>
        </form>

        <p class="mt-6 text-sm text-gray-600">
            No account yet? <a href="{{ route('register') }}" class="text-blue-700 underline">Register</a>
        </p>
    </div>
</x-layouts.app>
