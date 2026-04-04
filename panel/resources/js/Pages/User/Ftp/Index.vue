<template>
    <AppLayout title="FTP Accounts">
        <div class="mb-6">
            <h2 class="text-sm font-semibold text-gray-300">FTP Accounts ({{ ftpAccounts.length }})</h2>
        </div>

        <!-- Create form -->
        <div class="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 class="text-sm font-semibold text-gray-300 mb-4">Create FTP Account</h3>
            <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2">
                <FormField label="Username" :error="form.errors.username">
                    <input
                        v-model="form.username"
                        type="text"
                        placeholder="ftpuser"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </FormField>
                <FormField label="Password" :error="form.errors.password">
                    <input
                        v-model="form.password"
                        type="password"
                        placeholder="Min. 8 characters"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </FormField>
                <FormField label="Quota (MB, 0 = unlimited)" :error="form.errors.quota_mb">
                    <input
                        v-model.number="form.quota_mb"
                        type="number"
                        min="0"
                        placeholder="0"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </FormField>
                <div class="flex items-end justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors"
                    >
                        Create
                    </button>
                </div>
            </form>
        </div>

        <!-- FTP list -->
        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Home dir</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quota</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="ftp in ftpAccounts" :key="ftp.id" class="hover:bg-gray-800/40 transition-colors">
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-100">{{ ftp.username }}</td>
                        <td class="px-5 py-3.5 text-sm font-mono text-xs text-gray-400">{{ ftp.home_dir ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ ftp.quota_mb ? `${ftp.quota_mb} MB` : 'Unlimited' }}</td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    @click="openPwModal(ftp)"
                                    class="text-xs text-gray-400 hover:text-gray-200 transition-colors"
                                >
                                    Change pw
                                </button>
                                <ConfirmButton
                                    :href="route('my.ftp.destroy', ftp.id)"
                                    method="delete"
                                    label="Delete"
                                    color="red"
                                    :confirm-message="`Delete FTP account ${ftp.username}?`"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="ftpAccounts.length === 0">
                        <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">No FTP accounts yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Change password modal -->
        <div v-if="pwTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                <h3 class="text-sm font-semibold text-gray-200 mb-4">Change password for <span class="font-mono">{{ pwTarget.username }}</span></h3>
                <form @submit.prevent="submitPw" class="space-y-4">
                    <FormField label="New password" :error="pwForm.errors.password">
                        <input
                            v-model="pwForm.password"
                            type="password"
                            placeholder="Min. 8 characters"
                            class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                            autofocus
                        />
                    </FormField>
                    <div class="flex justify-end gap-3">
                        <button type="button" @click="pwTarget = null" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" :disabled="pwForm.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

defineProps({
    account:     Object,
    ftpAccounts: Array,
});

const form = useForm({ username: '', password: '', quota_mb: 0 });

function submit() {
    form.post(route('my.ftp.store'), { onSuccess: () => form.reset() });
}

const pwTarget = ref(null);
const pwForm   = useForm({ password: '' });

function openPwModal(ftp) {
    pwTarget.value = ftp;
    pwForm.reset();
}

function submitPw() {
    pwForm.put(route('my.ftp.password', pwTarget.value.id), {
        onSuccess: () => { pwTarget.value = null; pwForm.reset(); },
    });
}
</script>
