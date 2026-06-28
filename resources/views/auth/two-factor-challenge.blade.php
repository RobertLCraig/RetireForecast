<x-layouts.app title="Two-factor authentication">
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-gray-900">Two-factor authentication</h1>
        <p class="mt-1 text-sm text-gray-600">
            Enter the six-digit code from your authenticator app to finish signing in.
        </p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="code" class="block text-sm font-medium text-gray-700">Authentication code</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                    @error('code') aria-invalid="true" aria-describedby="code-error" @enderror
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                @error('code')
                    <p id="code-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            {{-- Lost your device: a recovery code works instead. Submitting either field is enough;
                 Fortify checks the authentication code first, then the recovery code. --}}
            <details class="rounded-md bg-gray-50 px-4 py-3" @error('recovery_code') open @enderror>
                <summary class="cursor-pointer text-sm text-gray-700">Lost your device? Use a recovery code</summary>
                <div class="mt-3">
                    <label for="recovery_code" class="block text-sm font-medium text-gray-700">Recovery code</label>
                    <input id="recovery_code" name="recovery_code" autocomplete="one-time-code"
                        @error('recovery_code') aria-invalid="true" aria-describedby="recovery-code-error" @enderror
                        class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                    @error('recovery_code')
                        <p id="recovery-code-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            </details>

            <button type="submit" class="w-full rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Log in
            </button>
        </form>
    </div>
</x-layouts.app>
