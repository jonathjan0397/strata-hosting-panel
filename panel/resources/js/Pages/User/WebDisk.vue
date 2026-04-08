<template>
    <AppLayout title="Web Disk">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Files"
                title="Web Disk"
                description="Create dedicated WebDAV credentials for secure desktop access to your hosted files."
            />

            <div class="grid gap-5 lg:grid-cols-3">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5 lg:col-span-2">
                    <h3 class="text-sm font-semibold text-gray-200">Connection Settings</h3>
                    <p class="mt-1 text-sm text-gray-500">Use these settings in Finder, Windows Explorer, Cyberduck, WinSCP, Transmit, or any WebDAV client.</p>

                    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">URL</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.url }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Protocol</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.protocol }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Host</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.host }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Port</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.port }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Encryption</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.encryption }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Account Root</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.root }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-xl border border-emerald-800 bg-emerald-950/30 p-5">
                    <h3 class="text-sm font-semibold text-emerald-200">Credential Policy</h3>
                    <p class="mt-2 text-sm leading-6 text-emerald-100/80">
                        Web Disk uses dedicated WebDAV credentials over TLS on port 2078. Do not reuse your panel, FTP, database, or mailbox password.
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-4 text-sm font-semibold text-gray-300">Create Web Disk Account</h3>
                <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Username" :error="form.errors.username">
                        <input
                            v-model="form.username"
                            type="text"
                            placeholder="webdiskuser"
                            class="field w-full"
                        />
                    </FormField>
                    <FormField label="Password" :error="form.errors.password">
                        <input
                            v-model="form.password"
                            type="password"
                            placeholder="Min. 12 characters"
                            class="field w-full"
                        />
                    </FormField>
                    <div class="flex items-end justify-end sm:col-span-2">
                        <button type="submit" :disabled="form.processing" class="btn-primary">
                            {{ form.processing ? 'Creating...' : 'Create Web Disk Account' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-200">Web Disk Accounts</h3>
                </div>
                <table v-if="webDavAccounts.length" class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Username</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Home Directory</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="webdav in webDavAccounts" :key="webdav.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-100">{{ webdav.username }}</td>
                            <td class="px-5 py-3.5 font-mono text-xs text-gray-400">{{ webdav.home_dir || connection.root }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">
                                <span v-if="webdav.migration_reset_required" class="rounded-full bg-amber-900/40 px-2 py-0.5 text-xs font-semibold text-amber-200">
                                    Reset required
                                </span>
                                <span v-else>{{ webdav.active ? 'Active' : 'Disabled' }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        @click="openPwModal(webdav)"
                                        class="text-xs text-gray-400 transition-colors hover:text-gray-200"
                                    >
                                        {{ webdav.migration_reset_required ? 'Set pw' : 'Change pw' }}
                                    </button>
                                    <ConfirmButton
                                        :href="route('my.web-disk.destroy', webdav.id)"
                                        method="delete"
                                        label="Delete"
                                        color="red"
                                        :confirm-message="`Delete Web Disk account ${webdav.username}?`"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No Web Disk accounts"
                    description="Create a dedicated Web Disk account before connecting a desktop client."
                />
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Client Setup</h3>
                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                        <h4 class="text-sm font-semibold text-gray-200">1. Create Credentials</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-500">Create a Web Disk username and password for each device or collaborator.</p>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                        <h4 class="text-sm font-semibold text-gray-200">2. Connect With WebDAV</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-500">Use the HTTPS URL, port 2078, and the dedicated Web Disk credentials.</p>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                        <h4 class="text-sm font-semibold text-gray-200">3. Save the Profile</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-500">Save the connection in your client for drag-and-drop access to your account root.</p>
                    </div>
                </div>
            </div>

            <div v-if="pwTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <h3 class="mb-4 text-sm font-semibold text-gray-200">Change password for <span class="font-mono">{{ pwTarget.username }}</span></h3>
                    <form @submit.prevent="submitPw" class="space-y-4">
                        <FormField label="New password" :error="pwForm.errors.password">
                            <input
                                v-model="pwForm.password"
                                type="password"
                                placeholder="Min. 12 characters"
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
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    account: Object,
    connection: Object,
    webDavAccounts: Array,
});

const form = useForm({ username: '', password: '' });

function submit() {
    form.post(route('my.web-disk.store'), { onSuccess: () => form.reset() });
}

const pwTarget = ref(null);
const pwForm = useForm({ password: '' });

function openPwModal(webdav) {
    pwTarget.value = webdav;
    pwForm.reset();
}

function submitPw() {
    pwForm.put(route('my.web-disk.password', pwTarget.value.id), {
        onSuccess: () => { pwTarget.value = null; pwForm.reset(); },
    });
}
</script>
