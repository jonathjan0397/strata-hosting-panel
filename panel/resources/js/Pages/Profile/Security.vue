<template>
    <AppLayout title="Security">
        <div class="max-w-2xl space-y-6">

            <!-- Change password -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200 mb-4">Change Password</h3>
                <form @submit.prevent="submitPassword" class="space-y-4">
                    <FormField label="Current Password" :error="pwdForm.errors.current_password">
                        <input v-model="pwdForm.current_password" type="password" class="field" autocomplete="current-password" />
                    </FormField>
                    <FormField label="New Password" :error="pwdForm.errors.password">
                        <input v-model="pwdForm.password" type="password" class="field" autocomplete="new-password" placeholder="Min. 12 characters" />
                    </FormField>
                    <FormField label="Confirm New Password" :error="pwdForm.errors.password_confirmation">
                        <input v-model="pwdForm.password_confirmation" type="password" class="field" autocomplete="new-password" />
                    </FormField>
                    <button
                        type="submit"
                        :disabled="pwdForm.processing"
                        class="btn-primary"
                    >
                        {{ pwdForm.processing ? 'Updating…' : 'Update Password' }}
                    </button>
                </form>
            </div>

            <!-- Two-factor authentication -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-200">Two-Factor Authentication</h3>
                        <p class="text-xs text-gray-500 mt-0.5">TOTP via any authenticator app (Google Authenticator, Aegis, etc.)</p>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="twoFactorEnabled ? 'bg-emerald-900/40 text-emerald-300' : 'bg-gray-800 text-gray-400'"
                    >
                        <span class="h-1.5 w-1.5 rounded-full" :class="twoFactorEnabled ? 'bg-emerald-400' : 'bg-gray-500'"></span>
                        {{ twoFactorEnabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>

                <!-- Not enabled, not in setup mode -->
                <div v-if="!twoFactorEnabled && !twoFactorSetupMode">
                    <p class="text-sm text-gray-400 mb-4">
                        Add an extra layer of security to your account. After enabling, you'll need your authenticator app on every login.
                    </p>
                    <button @click="enableTwoFactor" :disabled="enableForm.processing" class="btn-primary">
                        {{ enableForm.processing ? 'Setting up…' : 'Enable 2FA' }}
                    </button>
                </div>

                <!-- Setup mode: scan QR + confirm -->
                <div v-else-if="twoFactorSetupMode" class="space-y-5">
                    <div class="rounded-lg border border-amber-700/50 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
                        2FA is not yet active. Scan the QR code and enter the confirmation code below.
                    </div>

                    <div class="flex flex-col items-center gap-4">
                        <!-- QR code -->
                        <div
                            class="rounded-xl bg-white p-3 w-52 h-52 flex items-center justify-center"
                            v-html="qrCodeSvg"
                        ></div>
                        <p class="text-xs text-gray-500">Scan with your authenticator app</p>
                    </div>

                    <form @submit.prevent="confirmTwoFactor" class="space-y-4">
                        <FormField label="Confirmation Code" :error="confirmForm.errors.code">
                            <input
                                v-model="confirmForm.code"
                                type="text"
                                inputmode="numeric"
                                maxlength="7"
                                class="field font-mono text-center text-xl tracking-widest"
                                placeholder="000 000"
                                autofocus
                            />
                        </FormField>
                        <div class="flex gap-3">
                            <button type="submit" :disabled="confirmForm.processing" class="btn-primary">
                                {{ confirmForm.processing ? 'Verifying…' : 'Confirm & Activate' }}
                            </button>
                            <button type="button" @click="cancelSetup" class="btn-secondary">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Enabled: show recovery codes + disable -->
                <div v-else class="space-y-5">
                    <!-- Recovery codes -->
                    <div v-if="recoveryCodes">
                        <p class="text-sm text-gray-400 mb-3">
                            Store these recovery codes somewhere safe. Each can be used once if you lose your authenticator.
                        </p>
                        <div class="rounded-lg border border-gray-700 bg-gray-950 p-4 font-mono text-sm">
                            <div class="grid grid-cols-2 gap-1.5">
                                <span
                                    v-for="code in recoveryCodes"
                                    :key="code"
                                    class="text-gray-300"
                                >{{ code }}</span>
                            </div>
                        </div>
                        <button
                            @click="regenerateCodes"
                            :disabled="regenForm.processing"
                            class="mt-3 text-xs text-indigo-400 hover:text-indigo-300 transition-colors"
                        >
                            {{ regenForm.processing ? 'Regenerating…' : 'Regenerate recovery codes' }}
                        </button>
                    </div>

                    <!-- Disable 2FA -->
                    <div class="border-t border-gray-800 pt-4">
                        <p class="text-sm text-gray-400 mb-3">To disable 2FA, confirm your password.</p>
                        <form @submit.prevent="disableTwoFactor" class="flex items-end gap-3">
                            <FormField label="Current Password" :error="disableForm.errors.password" class="flex-1">
                                <input v-model="disableForm.password" type="password" class="field" autocomplete="current-password" />
                            </FormField>
                            <button
                                type="submit"
                                :disabled="disableForm.processing"
                                class="mb-0.5 rounded-lg border border-red-700 bg-red-900/20 px-4 py-2.5 text-sm font-semibold text-red-300 hover:bg-red-900/40 transition-colors disabled:opacity-60"
                            >
                                Disable 2FA
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';

const props = defineProps({
    twoFactorEnabled:   Boolean,
    twoFactorSetupMode: Boolean,
    qrCodeSvg:          String,
    recoveryCodes:      Array,
});

// ── Password form ──────────────────────────────────────────────────────────
const pwdForm = useForm({
    current_password:      '',
    password:              '',
    password_confirmation: '',
});

function submitPassword() {
    pwdForm.put(route('profile.password'), {
        onSuccess: () => pwdForm.reset(),
    });
}

// ── 2FA enable / confirm ───────────────────────────────────────────────────
const enableForm  = useForm({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ password: '' });
const regenForm   = useForm({});

function enableTwoFactor() {
    enableForm.post(route('profile.two-factor.enable'));
}

function confirmTwoFactor() {
    confirmForm.post(route('profile.two-factor.confirm'), {
        onSuccess: () => confirmForm.reset(),
    });
}

function cancelSetup() {
    router.delete(route('profile.two-factor.disable-unconfirmed'));
}

function disableTwoFactor() {
    disableForm.delete(route('profile.two-factor.disable'), {
        onSuccess: () => disableForm.reset(),
    });
}

function regenerateCodes() {
    regenForm.post(route('profile.two-factor.recovery-codes'));
}
</script>

<style scoped>
@reference "tailwindcss";
.field       { @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary  { @apply rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors; }
.btn-secondary { @apply rounded-lg border border-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-60 transition-colors; }
</style>
