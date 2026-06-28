<x-layouts.app title="Confirm password">
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-gray-900">Confirm your password</h1>
        <p class="mt-1 text-sm text-gray-600">
            This is a secured area. Please confirm your password before continuing.
        </p>

        <form method="POST" action="{{ route('password.confirm.store') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input id="password" name="password" type="password" required autofocus autocomplete="current-password"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                @error('password')
                    <p id="password-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Confirm
            </button>
        </form>
    </div>
</x-layouts.app>
