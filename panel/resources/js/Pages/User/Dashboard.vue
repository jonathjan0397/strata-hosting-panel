<template>
    <AppLayout title="My Hosting">
        <!-- Status banner if suspended -->
        <div v-if="account.status === 'suspended'" class="mb-5 rounded-xl border border-amber-700 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
            Your account is suspended. Contact support to restore access.
        </div>

        <!-- Stat cards -->
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Domains</p>
                <p class="text-2xl font-semibold text-gray-100">{{ domainCount }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Databases</p>
                <p class="text-2xl font-semibold text-gray-100">{{ databaseCount }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Mailboxes</p>
                <p class="text-2xl font-semibold text-gray-100">{{ emailCount }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">FTP Accounts</p>
                <p class="text-2xl font-semibold text-gray-100">{{ ftpCount }}</p>
            </div>
        </div>

        <div class="mt-6 grid gap-5 xl:grid-cols-2">
            <!-- Account info -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Account Details</h3>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Username</dt>
                        <dd class="font-mono text-gray-200">{{ account.username }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">PHP Version</dt>
                        <dd class="font-mono text-gray-200">{{ account.php_version }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Home directory</dt>
                        <dd class="font-mono text-xs text-gray-400">/var/www/{{ account.username }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Status</dt>
                        <dd>
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="account.status === 'active'
                                    ? 'bg-emerald-900/40 text-emerald-400'
                                    : 'bg-amber-900/40 text-amber-400'"
                            >
                                {{ account.status }}
                            </span>
                        </dd>
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
                    <div class="grid grid-cols-3 gap-3 pt-2 text-center text-sm">
                        <div>
                            <p class="font-semibold text-gray-100">{{ account.max_domains || '∞' }}</p>
                            <p class="text-xs text-gray-500">Domain limit</p>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-100">{{ account.max_email_accounts || '∞' }}</p>
                            <p class="text-xs text-gray-500">Email limit</p>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-100">{{ account.max_databases || '∞' }}</p>
                            <p class="text-xs text-gray-500">DB limit</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent domains -->
        <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-300">Domains</h3>
                <Link :href="route('my.domains.index')" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                    View all
                </Link>
            </div>
            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Domain</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Type</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">SSL</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="domain in account.domains" :key="domain.id" class="hover:bg-gray-800/40 transition-colors">
                            <td class="px-5 py-3 text-sm font-mono text-gray-100">{{ domain.domain }}</td>
                            <td class="px-5 py-3 text-sm text-gray-400">{{ domain.type }}</td>
                            <td class="px-5 py-3 text-sm">
                                <span v-if="domain.ssl_enabled" class="text-emerald-400 text-xs">Active</span>
                                <span v-else class="text-gray-500 text-xs">None</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('my.domains.show', domain.id)" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                                    Manage
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!account.domains?.length">
                            <td colspan="4" class="px-5 py-6 text-center text-sm text-gray-500">No domains yet.</td>
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
import ResourceBar from '@/Components/ResourceBar.vue';

defineProps({
    account:       Object,
    domainCount:   Number,
    databaseCount: Number,
    emailCount:    Number,
    ftpCount:      Number,
});
</script>
