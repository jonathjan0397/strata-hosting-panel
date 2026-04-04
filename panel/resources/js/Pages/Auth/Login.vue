<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gray-950 px-4 py-12">
        <div class="w-full max-w-md space-y-6">

            <!-- Logo -->
            <div class="text-center">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 mb-4">
                    <span class="text-xl font-bold text-white">S</span>
                </div>
                <h1 class="text-2xl font-semibold text-gray-100">Sign in to Strata Panel</h1>
                <p class="mt-1 text-sm text-gray-400">Open-source hosting control panel</p>
            </div>

            <!-- Demo credentials card -->
            <div v-if="demoMode" class="rounded-xl border border-indigo-700/50 bg-indigo-900/20 p-4 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-600/30 px-2.5 py-0.5 text-xs font-semibold text-indigo-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
                        Public Demo
                    </span>
                    <span class="text-xs text-gray-400">Click any account to autofill</span>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <button
                        v-for="cred in demoCredentials"
                        :key="cred.email"
                        @click="autofill(cred)"
                        class="rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-left hover:border-indigo-600 hover:bg-gray-800 transition-colors group"
                    >
                        <p class="text-xs font-semibold text-indigo-400 group-hover:text-indigo-300">{{ cred.role }}</p>
                        <p class="text-xs font-mono text-gray-400 mt-0.5 truncate">{{ cred.email }}</p>
                        <p class="text-xs font-mono text-gray-500 mt-0.5">{{ cred.password }}</p>
                    </button>
                </div>
            </div>

            <!-- Login form -->
            <div class="rounded-2xl border border-gray-800 bg-gray-900 p-6 space-y-4">
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-300 mb-1.5">Email</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            required
                            class="field w-full"
                            placeholder="admin@example.com"
                        />
                        <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-400">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-300 mb-1.5">Password</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="field w-full"
                        />
                        <p v-if="form.errors.password" class="mt-1.5 text-xs text-red-400">{{ form.errors.password }}</p>
                    </div>

                    <div class="flex items-center">
                        <input
                            id="remember"
                            v-model="form.remember"
                            type="checkbox"
                            class="h-4 w-4 rounded border-gray-700 bg-gray-900 text-indigo-600 focus:ring-indigo-500"
                        />
                        <label for="remember" class="ml-2 text-sm text-gray-400">Keep me signed in</label>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-950"
                    >
                        <span v-if="form.processing">Signing in…</span>
                        <span v-else>Sign in</span>
                    </button>
                </form>
            </div>

            <!-- Links -->
            <div class="flex items-center justify-center gap-4 text-xs text-gray-500">
                <a
                    href="https://github.com/jonathjan0397/strata-panel/issues"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex items-center gap-1.5 hover:text-gray-300 transition-colors"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0 1 12 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/>
                    </svg>
                    Report an issue
                </a>
                <span class="text-gray-700">·</span>
                <a
                    href="https://buymeacoffee.com/jonathan0397"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex items-center gap-1.5 hover:text-yellow-400 transition-colors"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20.216 6.415l-.132-.666c-.119-.598-.388-1.163-1.001-1.379-.197-.069-.42-.098-.57-.241-.152-.143-.196-.366-.231-.572-.065-.378-.125-.756-.192-1.133-.057-.325-.102-.69-.25-.987-.195-.4-.597-.634-.996-.788a5.723 5.723 0 00-.626-.194c-1-.263-2.05-.36-3.077-.416a25.834 25.834 0 00-3.7.062c-.915.083-1.88.184-2.75.5-.318.116-.646.256-.888.501-.297.302-.393.77-.177 1.146.154.267.415.456.692.58.36.162.737.284 1.123.366 1.075.238 2.189.surfactant.262-.043 3.276-.013 4.245.021.47.017.959.067 1.35.39.266.219.437.512.495.846.049.27.064.558.029.832-.054.437-.23.899-.632 1.157-.356.231-.813.302-1.23.236-1.037-.166-1.917-.736-2.697-1.427-.285-.25-.583-.491-.897-.695-.39-.249-.843-.443-1.306-.294-.413.132-.729.488-.87.892-.188.548-.124 1.158.013 1.717.145.592.392 1.15.673 1.685.344.657.756 1.282 1.218 1.861.428.537.904 1.039 1.408 1.51a9.513 9.513 0 001.657 1.182c.56.3 1.148.536 1.764.686a8.59 8.59 0 001.9.213 8.19 8.19 0 001.828-.197 7.45 7.45 0 001.708-.617 6.956 6.956 0 001.46-1.029 6.29 6.29 0 001.117-1.394 5.78 5.78 0 00.623-1.71 5.573 5.573 0 00.068-1.889z"/>
                    </svg>
                    Buy me a coffee
                </a>
            </div>

            <!-- Copyright -->
            <p class="text-center text-xs text-gray-600">
                &copy; {{ year }} Strata Panel &mdash; MIT License &mdash;
                <a href="https://github.com/jonathjan0397/strata-panel" target="_blank" rel="noopener noreferrer" class="hover:text-gray-400 transition-colors">jonathjan0397/strata-panel</a>
            </p>
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    demoMode:        { type: Boolean, default: false },
    demoCredentials: { type: Array,   default: () => [] },
});

const year = new Date().getFullYear();

const form = useForm({
    email:    '',
    password: '',
    remember: false,
});

function autofill(cred) {
    form.email    = cred.email;
    form.password = cred.password;
}

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
