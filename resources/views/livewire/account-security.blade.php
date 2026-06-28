<div class="mx-auto max-w-2xl">
    <h1 class="text-2xl font-semibold text-gray-900">Account security</h1>
    <p class="mt-1 text-sm text-gray-600">Manage how your account is protected.</p>

    <section class="mt-8 rounded-lg border border-gray-200 bg-white p-6" aria-labelledby="tfa-heading">
        <h2 id="tfa-heading" class="text-lg font-medium text-gray-900">Two-factor authentication</h2>

        @if ($enabled)
            <p role="status" class="mt-2 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                Two-factor authentication is on. You will be asked for a code from your authenticator app at login.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <button type="button" wire:click="showRecoveryCodes"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:ring-2 focus:ring-blue-500">
                    Show recovery codes
                </button>
                <button type="button" wire:click="regenerateRecoveryCodes"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:ring-2 focus:ring-blue-500">
                    Regenerate recovery codes
                </button>
                <button type="button" wire:click="disable"
                    class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:ring-2 focus:ring-red-500">
                    Turn off
                </button>
            </div>
        @elseif ($pending || $qrSvg)
            <p class="mt-2 text-sm text-gray-600">
                Scan this QR code with your authenticator app, then enter the six-digit code to finish.
            </p>

            @if ($qrSvg)
                <div class="mt-4 inline-block rounded bg-white p-2 ring-1 ring-gray-200">
                    {!! $qrSvg !!}
                </div>
                @if ($setupKey)
                    <p class="mt-3 text-sm text-gray-600">
                        Or enter this setup key manually:
                        <code class="rounded bg-gray-100 px-2 py-1 font-mono text-sm text-gray-800">{{ $setupKey }}</code>
                    </p>
                @endif
            @endif

            <form wire:submit="confirm" class="mt-4 max-w-xs space-y-4">
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700">Authentication code</label>
                    <input id="code" wire:model="code" inputmode="numeric" autocomplete="one-time-code"
                        @error('code') aria-invalid="true" aria-describedby="code-error" @enderror
                        class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                    @error('code')
                        <p id="code-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex gap-3">
                    <button type="submit"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Confirm
                    </button>
                    <button type="button" wire:click="disable"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:ring-2 focus:ring-blue-500">
                        Cancel
                    </button>
                </div>
            </form>
        @else
            <p class="mt-2 text-sm text-gray-600">
                Two-factor authentication is off. Add a second step at login using an authenticator app.
            </p>
            <button type="button" wire:click="enable"
                class="mt-4 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Turn on two-factor authentication
            </button>
        @endif

        @if ($showingRecoveryCodes && $recoveryCodes !== [])
            <div class="mt-6 rounded-md bg-gray-50 p-4">
                <h3 class="text-sm font-medium text-gray-900">Recovery codes</h3>
                <p class="mt-1 text-sm text-gray-600">
                    Keep these somewhere safe. Each one can be used once to sign in if you lose your device.
                </p>
                <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm text-gray-800">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</div>
