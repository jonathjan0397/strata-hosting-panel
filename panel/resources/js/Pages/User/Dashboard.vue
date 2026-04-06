<template>
    <AppLayout title="My Hosting">
        <PageHeader
            eyebrow="Hosting Workspace"
            title="Manage your site from one place"
            :description="`Account ${account.username} on PHP ${account.php_version}. Start with the common actions below or review usage and domains.`"
        >
            <template #actions>
                <Link v-if="hasFeature('domains')" :href="route('my.domains.create')" class="btn-primary">
                    Add Domain
                </Link>
                <Link v-if="hasFeature('backups')" :href="route('my.backups.index')" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">
                    Backups
                </Link>
            </template>
        </PageHeader>

        <!-- Status banner if suspended -->
        <div v-if="account.status === 'suspended'" class="mb-5 rounded-xl border border-amber-700 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
            Your account is suspended. Contact support to restore access.
        </div>

        <!-- Stat cards -->
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Domains" :value="domainCount" color="indigo" />
            <StatCard label="Databases" :value="databaseCount" color="emerald" />
            <StatCard label="Mailboxes" :value="emailCount" color="amber" />
            <StatCard label="FTP Accounts" :value="ftpCount" color="gray" />
        </div>

        <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-gray-300">Common Tasks</h2>
                    <p class="mt-1 text-xs text-gray-500">Shortcuts for the daily cPanel-style workflows.</p>
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <ActionCard
                    v-if="hasFeature('domains')"
                    :href="route('my.domains.index')"
                    title="Websites"
                    description="Manage domains, SSL, redirects, PHP, directory privacy, and hotlink protection."
                    cta="Open websites"
                />
                <ActionCard
                    v-if="hasFeature('email')"
                    :href="route('my.email.delivery')"
                    title="Email"
                    description="Review delivery, spam activity, filters, autoresponders, mailboxes, and forwarders."
                    cta="Open email tools"
                />
                <ActionCard
                    v-if="hasFeature('file_manager')"
                    :href="route('my.files.index')"
                    title="Files"
                    description="Browse files, upload assets, manage permissions, and restore paths from backups."
                    cta="Open files"
                />
                <ActionCard
                    v-if="hasFeature('metrics')"
                    :href="route('my.metrics.index')"
                    title="Metrics and Logs"
                    description="Inspect resource usage, access logs, error logs, and PHP error output."
                    cta="Open diagnostics"
                />
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
                    </tbody>
                </table>
                <EmptyState
                    v-if="!account.domains?.length"
                    title="No domains yet"
                    description="Add your first domain to start managing SSL, redirects, DNS, email, and security options."
                >
                    <template #actions>
                        <Link v-if="hasFeature('domains')" :href="route('my.domains.create')" class="btn-primary">
                            Add Domain
                        </Link>
                    </template>
                </EmptyState>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionCard from '@/Components/ActionCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ResourceBar from '@/Components/ResourceBar.vue';
import StatCard from '@/Components/StatCard.vue';

defineProps({
    account:       Object,
    domainCount:   Number,
    databaseCount: Number,
    emailCount:    Number,
    ftpCount:      Number,
});

const page = usePage();
const accountFeatures = computed(() => page.props.auth?.user?.account?.features ?? []);

function hasFeature(feature) {
    return page.props.auth?.user?.roles?.includes('admin') || accountFeatures.value.includes(feature);
}
</script>
