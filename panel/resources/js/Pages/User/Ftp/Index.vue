<template>
    <AppLayout title="FTP Accounts">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Files"
                title="FTP Accounts"
                description="Create scoped FTP users for deployments, collaborators, and legacy publishing workflows."
            />

            <div class="grid gap-4 md:grid-cols-3">
                <StatCard label="FTP Accounts" :value="ftpAccounts.length" color="indigo" />
                <StatCard label="Unlimited Quota" :value="unlimitedCount" color="emerald" />
                <StatCard label="Quota-Limited" :value="limitedCount" color="amber" />
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-4 text-sm font-semibold text-gray-300">Create FTP Account</h3>
                <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Username" :error="form.errors.username">
                        <input
                            v-model="form.username"
                            type="text"
                            placeholder="ftpuser"
                            class="field w-full"
                        />
                    </FormField>
                    <FormField label="Password" :error="form.errors.password">
                        <input
                            v-model="form.password"
                            type="password"
                            placeholder="Min. 8 characters"
                            class="field w-full"
                        />
                    </FormField>
                    <FormField label="Quota (MB, 0 = unlimited)" :error="form.errors.quota_mb">
                        <input
                            v-model.number="form.quota_mb"
                            type="number"
                            min="0"
                            placeholder="0"
                            class="field w-full"
                        />
                    </FormField>
                    <div class="flex items-end justify-end">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="btn-primary"
                        >
                            {{ form.processing ? 'Creating...' : 'Create' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Username</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Home dir</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Quota</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="ftp in ftpAccounts" :key="ftp.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 text-sm font-mono text-gray-100">
                                <div class="flex flex-col gap-1">
                                    <span>{{ ftp.username }}</span>
                                    <span v-if="ftp.migration_reset_required" class="w-fit rounded-full bg-amber-900/40 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-amber-200">
                                        Reset required after migration
                                    </span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-xs font-mono text-gray-400">{{ ftp.home_dir ?? '-' }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ ftp.quota_mb ? `${ftp.quota_mb} MB` : 'Unlimited' }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        @click="openPwModal(ftp)"
                                        class="text-xs text-gray-400 transition-colors hover:text-gray-200"
                                    >
                                        {{ ftp.migration_reset_required ? 'Set pw' : 'Change pw' }}
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
                            <td colspan="4" class="px-5 py-8">
                                <EmptyState
                                    title="No FTP accounts yet"
                                    description="Create an FTP account when a deployment tool or collaborator needs file access."
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="pwTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <h3 class="mb-4 text-sm font-semibold text-gray-200">Change password for <span class="font-mono">{{ pwTarget.username }}</span></h3>
                    <form @submit.prevent="submitPw" class="space-y-4">
                        <FormField label="New password" :error="pwForm.errors.password">
                            <input
                                v-model="pwForm.password"
                                type="password"
                                placeholder="Min. 8 characters"
                                class="field w-full"
                                autofocus
                            />
                        </FormField>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="pwTarget = null" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 transition-colors hover:bg-gray-800">
                                Cancel
                            </button>
                            <button type="submit" :disabled="pwForm.processing" class="btn-primary">
                                {{ pwForm.processing ? 'Updating...' : 'Update' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    account: Object,
    ftpAccounts: Array,
});

const unlimitedCount = computed(() => props.ftpAccounts.filter((ftp) => !ftp.quota_mb).length);
const limitedCount = computed(() => props.ftpAccounts.filter((ftp) => ftp.quota_mb).length);

const form = useForm({ username: '', password: '', quota_mb: 0 });

function submit() {
    form.post(route('my.ftp.store'), { onSuccess: () => form.reset() });
}

const pwTarget = ref(null);
const pwForm = useForm({ password: '' });

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
