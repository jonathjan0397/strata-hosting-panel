<template>
    <AppLayout :title="account.username">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link :href="route('admin.accounts.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold font-mono text-gray-100">{{ account.username }}</h2>
                        <AccountStatusBadge :status="account.status" />
                    </div>
                    <p class="text-sm text-gray-400">{{ account.user?.email }} · {{ account.node?.name }}</p>
                </div>
            </div>
            <!-- Actions -->
            <div class="flex items-center gap-2">
                <Link
                    :href="route('admin.domains.create', { account_id: account.id })"
                    class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
                >
                    + Add Domain
                </Link>
                <template v-if="account.status === 'active'">
                    <ConfirmButton
                        :href="route('admin.accounts.suspend', account.id)"
                        method="post"
                        label="Suspend"
                        :confirm-message="`Suspend account ${account.username}?`"
                        color="amber"
                    />
                </template>
                <template v-else-if="account.status === 'suspended'">
                    <ConfirmButton
                        :href="route('admin.accounts.unsuspend', account.id)"
                        method="post"
                        label="Unsuspend"
                        color="emerald"
                    />
                </template>
                <ConfirmButton
                    :href="route('admin.accounts.destroy', account.id)"
                    method="delete"
                    label="Delete Account"
                    :confirm-message="`Permanently delete ${account.username} and all their files?`"
                    color="red"
                />
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-3">
            <!-- Account details -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Details</h3>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Node</dt>
                        <dd class="text-gray-200">{{ account.node?.name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">PHP</dt>
                        <dd class="text-gray-200 font-mono">{{ account.php_version }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Home dir</dt>
                        <dd class="text-gray-200 font-mono text-xs">/home/{{ account.username }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="text-gray-200">{{ account.created_at }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Resource usage -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Resources</h3>
                <div class="space-y-4">
                    <ResourceBar
                        label="Disk"
                        :used="account.disk_used_mb"
                        :limit="account.disk_limit_mb"
                        unit="MB"
                    />
                    <ResourceBar
                        label="Bandwidth"
                        :used="account.bandwidth_used_mb"
                        :limit="account.bandwidth_limit_mb"
                        unit="MB"
                    />
                    <div class="grid grid-cols-3 gap-3 pt-2">
                        <div class="text-center">
                            <p class="text-lg font-semibold text-gray-100">{{ account.domains?.length ?? 0 }}</p>
                            <p class="text-xs text-gray-500">Domains</p>
                        </div>
                        <div class="text-center">
                            <p class="text-lg font-semibold text-gray-100">{{ account.max_email_accounts || '∞' }}</p>
                            <p class="text-xs text-gray-500">Email limit</p>
                        </div>
                        <div class="text-center">
                            <p class="text-lg font-semibold text-gray-100">{{ account.max_databases || '∞' }}</p>
                            <p class="text-xs text-gray-500">DB limit</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick links -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Quick Links</h3>
                <div class="space-y-2">
                    <Link
                        :href="route('admin.nodes.status', account.node_id)"
                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-400 hover:bg-gray-800 hover:text-gray-200 transition-colors"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3M12 3v4.5" />
                        </svg>
                        Node Status
                    </Link>
                    <Link
                        :href="route('admin.accounts.databases', account.id)"
                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-400 hover:bg-gray-800 hover:text-gray-200 transition-colors"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                        </svg>
                        Databases
                    </Link>
                    <Link
                        :href="route('admin.accounts.ftp', account.id)"
                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-400 hover:bg-gray-800 hover:text-gray-200 transition-colors"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h-.75A2.25 2.25 0 0 0 4.5 9.75v7.5a2.25 2.25 0 0 0 2.25 2.25h7.5a2.25 2.25 0 0 0 2.25-2.25v-7.5a2.25 2.25 0 0 0-2.25-2.25h-.75m-6 3.75 3 3m0 0 3-3m-3 3V1.5m6 9h.75a2.25 2.25 0 0 1 2.25 2.25v7.5a2.25 2.25 0 0 1-2.25 2.25h-7.5a2.25 2.25 0 0 1-2.25-2.25v-.75" />
                        </svg>
                        FTP Accounts
                    </Link>
                </div>
            </div>
        </div>

        <!-- Domains -->
        <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-300">Domains ({{ account.domains?.length ?? 0 }})</h3>
                <Link
                    :href="route('admin.domains.create', { account_id: account.id })"
                    class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors"
                >
                    + Add Domain
                </Link>
            </div>
            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Domain</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Type</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">PHP</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">SSL</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="domain in account.domains" :key="domain.id" class="hover:bg-gray-800/40 transition-colors">
                            <td class="px-5 py-3 text-sm font-mono text-gray-100">{{ domain.domain }}</td>
                            <td class="px-5 py-3 text-sm text-gray-400">{{ domain.type }}</td>
                            <td class="px-5 py-3 text-sm font-mono text-gray-400">{{ domain.php_version ?? account.php_version }}</td>
                            <td class="px-5 py-3 text-sm">
                                <span v-if="domain.ssl_enabled" class="text-emerald-400 text-xs">Active</span>
                                <span v-else class="text-gray-500 text-xs">None</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('admin.domains.show', domain.id)" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                                    Manage
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!account.domains?.length">
                            <td colspan="5" class="px-5 py-6 text-center text-sm text-gray-500">
                                No domains yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AccountStatusBadge from '@/Components/AccountStatusBadge.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import ResourceBar from '@/Components/ResourceBar.vue';

defineProps({ account: Object });
</script>
