<template>
    <div class="flex min-h-screen items-center justify-center bg-gray-950 px-4">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="mb-8 text-center">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 mb-4">
                    <span class="text-xl font-bold text-white">S</span>
                </div>
                <h1 class="text-2xl font-semibold text-gray-100">Sign in to Strata Panel</h1>
                <p class="mt-1 text-sm text-gray-400">Hosting control panel</p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-300 mb-1.5">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="block w-full rounded-lg border border-gray-700 bg-gray-900 px-3.5 py-2.5 text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                        placeholder="admin@example.com"
                    />
                    <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-400">{{ form.errors.email }}</p>
                </div>

                <!-- Password -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-1.5">Password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="block w-full rounded-lg border border-gray-700 bg-gray-900 px-3.5 py-2.5 text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                    />
                    <p v-if="form.errors.password" class="mt-1.5 text-xs text-red-400">{{ form.errors.password }}</p>
                </div>

                <!-- Remember -->
                <div class="flex items-center">
                    <input
                        id="remember"
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-gray-700 bg-gray-900 text-indigo-600 focus:ring-indigo-500"
                    />
                    <label for="remember" class="ml-2 text-sm text-gray-400">Keep me signed in</label>
                </div>

                <!-- Submit -->
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-950 disabled:opacity-60 transition-colors"
                >
                    <span v-if="form.processing">Signing in…</span>
                    <span v-else>Sign in</span>
                </button>
            </form>
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>
