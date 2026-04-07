<template>
    <AppLayout title="Web Disk">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Files"
                title="Web Disk"
                description="Connect desktop file tools to your hosted files using jailed FTPS access."
            >
                <template #actions>
                    <Link :href="route('my.ftp.index')" class="btn-primary">Manage FTP Accounts</Link>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-3">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5 lg:col-span-2">
                    <h3 class="text-sm font-semibold text-gray-200">Connection Settings</h3>
                    <p class="mt-1 text-sm text-gray-500">Use these settings in FileZilla, Cyberduck, WinSCP, Transmit, or another desktop file client.</p>

                    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
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
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3 sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Account Root</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.root }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-xl border border-amber-800 bg-amber-950/30 p-5">
                    <h3 class="text-sm font-semibold text-amber-200">Security Note</h3>
                    <p class="mt-2 text-sm leading-6 text-amber-100/80">
                        Strata exposes Web Disk-style desktop access through FTPS accounts. Passwords are created from the FTP Accounts page and are not shown again.
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-200">Available File Access Accounts</h3>
                </div>
                <table v-if="ftpAccounts.length" class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Username</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Home Directory</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Quota</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="ftp in ftpAccounts" :key="ftp.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-100">{{ ftp.username }}</td>
                            <td class="px-5 py-3.5 font-mono text-xs text-gray-400">{{ ftp.home_dir || connection.root }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ ftp.quota_mb ? `${ftp.quota_mb} MB` : 'Unlimited' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No file access accounts"
                    description="Create an FTP account first, then use it with the Web Disk connection settings."
                >
                    <template #actions>
                        <Link :href="route('my.ftp.index')" class="btn-primary">Create FTP Account</Link>
                    </template>
                </EmptyState>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Client Setup</h3>
                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                        <h4 class="text-sm font-semibold text-gray-200">1. Create Credentials</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-500">Create or choose an FTP account. Use a unique password for each device or collaborator.</p>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                        <h4 class="text-sm font-semibold text-gray-200">2. Connect With FTPS</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-500">Set protocol to FTP over TLS, use the node hostname, port 21, and your FTP username/password.</p>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                        <h4 class="text-sm font-semibold text-gray-200">3. Save the Profile</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-500">Save the connection in your client for drag-and-drop file access to the jailed home directory.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    account: Object,
    connection: Object,
    ftpAccounts: Array,
});
</script>
