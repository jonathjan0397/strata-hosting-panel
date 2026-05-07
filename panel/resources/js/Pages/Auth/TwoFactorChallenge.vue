<template>
    <div class="min-h-screen bg-gray-950 flex items-center justify-center p-4">
        <div class="w-full max-w-sm">
            <!-- Logo -->
            <div class="flex justify-center mb-8">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600">
                    <span class="text-lg font-bold text-white">S</span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-800 bg-gray-900 p-8">
                <h1 class="text-lg font-semibold text-gray-100 mb-1">Two-factor authentication</h1>
                <p class="text-sm text-gray-400 mb-6">
                    <span v-if="!useRecovery">Enter the 6-digit code from your authenticator app.</span>
                    <span v-else>Enter one of your emergency recovery codes.</span>
                </p>

                <form @submit.prevent="submit" class="space-y-4">
                    <div v-if="!useRecovery">
                        <input
                            ref="codeInput"
                            v-model="form.code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            placeholder="000 000"
                            maxlength="7"
                            class="field text-center text-2xl tracking-widest font-mono"
                            autofocus
                        />
                        <p v-if="form.errors.code" class="mt-1.5 text-xs text-red-400">{{ form.errors.code }}</p>
                    </div>

                    <div v-else>
                        <input
                            ref="recoveryInput"
                            v-model="form.recovery_code"
                            type="text"
                            autocomplete="off"
                            placeholder="xxxxx-xxxxx"
                            class="field font-mono"
                        />
                        <p v-if="form.errors.code" class="mt-1.5 text-xs text-red-400">{{ form.errors.code }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                    >
                        <span v-if="form.processing">Verifying…</span>
                        <span v-else>Verify</span>
                    </button>
                </form>

                <button
                    @click="toggleMode"
                    class="mt-4 w-full text-center text-xs text-gray-500 hover:text-gray-300 transition-colors"
                >
                    <span v-if="!useRecovery">Use a recovery code instead</span>
                    <span v-else>Use authenticator app instead</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, nextTick } from 'vue';
import { useForm } from '@inertiajs/vue3';

const useRecovery = ref(false);
const codeInput   = ref(null);
const recoveryInput = ref(null);

const form = useForm({
    code:          '',
    recovery_code: '',
});

function submit() {
    form.post(route('two-factor.challenge'), {
        onFinish: () => {
            if (useRecovery.value) {
                form.recovery_code = '';
            } else {
                form.code = '';
            }
        },
    });
}

async function toggleMode() {
    useRecovery.value = !useRecovery.value;
    form.clearErrors();
    form.code = '';
    form.recovery_code = '';
    await nextTick();
    useRecovery.value ? recoveryInput.value?.focus() : codeInput.value?.focus();
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
