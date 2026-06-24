<x-layouts.app title="Reset password">
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-gray-900">Forgot your password?</h1>
        <p class="mt-1 text-sm text-gray-600">Enter your email and we will send a link to choose a new password.</p>

        @if (session('status'))
            <div role="status" class="mt-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
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

            <button type="submit" class="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Email password reset link
            </button>
        </form>

        <p class="mt-6 text-sm text-gray-600">
            <a href="{{ route('login') }}" class="text-blue-700 underline">Back to log in</a>
        </p>
    </div>
</x-layouts.app>
