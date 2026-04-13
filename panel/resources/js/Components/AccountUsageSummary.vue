<template>
    <div class="space-y-5">
        <div class="grid gap-5 xl:grid-cols-[1.1fr,0.9fr]">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-200">{{ title }}</h3>
                        <p class="mt-1 text-xs text-gray-500">{{ description }}</p>
                    </div>
                    <span class="rounded-full border border-gray-700 px-3 py-1 text-xs text-gray-400">
                        {{ usageMetrics.traffic_window_days }} day traffic window
                    </span>
                </div>

                <div class="mt-5 space-y-4">
                    <ResourceBar
                        label="Disk Space"
                        :used="usageMetrics.overview.disk_used_mb"
                        :limit="usageMetrics.overview.disk_limit_mb"
                        unit="MB"
                    />
                    <ResourceBar
                        label="Email Storage"
                        :used="usageMetrics.overview.email_used_mb"
                        :limit="usageMetrics.overview.email_quota_mb"
                        unit="MB"
                    />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-1">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Databases</p>
                    <p class="mt-2 text-3xl font-semibold text-gray-100">{{ usageMetrics.overview.databases }}</p>
                    <p class="mt-2 text-sm text-gray-400">
                        {{ usageMetrics.overview.assigned_databases }} domain-assigned, {{ usageMetrics.overview.unassigned_databases }} account-level
                    </p>
                    <p class="mt-1 text-xs text-gray-500">{{ usageMetrics.overview.database_human }} total storage</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Mailboxes</p>
                    <p class="mt-2 text-3xl font-semibold text-gray-100">{{ usageMetrics.overview.mailboxes }}</p>
                    <p class="mt-2 text-sm text-gray-400">{{ formatUsage(usageMetrics.overview.email_used_mb, usageMetrics.overview.email_quota_mb, 'MB') }}</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Hosted Domains</p>
                    <p class="mt-2 text-3xl font-semibold text-gray-100">{{ usageMetrics.overview.domains }}</p>
                    <p class="mt-2 text-sm text-gray-400">Traffic and mailbox usage are broken down below by domain.</p>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <div class="border-b border-gray-800 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-200">Usage By Domain</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Domain</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Email</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Databases</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">30 Day Requests</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">30 Day Bandwidth</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Errors</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="usageMetrics.domains.length === 0">
                            <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">No hosted domains yet.</td>
                        </tr>
                        <tr v-for="domain in usageMetrics.domains" :key="domain.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm text-gray-100">{{ domain.domain }}</span>
                                    <span v-if="domain.ssl_enabled" class="rounded-full border border-emerald-800 bg-emerald-900/30 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-300">SSL</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ domain.type }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-300">
                                <div>{{ domain.mailboxes }} mailboxes</div>
                                <div class="text-xs text-gray-500">{{ formatUsage(domain.email_used_mb, domain.email_quota_mb, 'MB') }}</div>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-300">
                                <span>{{ domain.database_count }}</span>
                                <p class="text-xs text-gray-500">{{ domain.database_human }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-300">{{ domain.requests_30d.toLocaleString() }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-300">{{ domain.bandwidth_human_30d }}</td>
                            <td class="px-5 py-3.5 text-sm" :class="domain.errors_30d > 0 ? 'text-amber-300' : 'text-gray-500'">
                                {{ domain.errors_30d.toLocaleString() }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-800 px-5 py-3 text-xs text-gray-500">
                Database counts by domain come from explicit database-to-domain assignments. Unassigned databases remain visible in the overview above.
            </div>
        </div>
    </div>
</template>

<script setup>
import ResourceBar from '@/Components/ResourceBar.vue';

defineProps({
    usageMetrics: {
        type: Object,
        required: true,
    },
    title: {
        type: String,
        default: 'Usage Overview',
    },
    description: {
        type: String,
        default: 'Disk space, email storage, databases, and traffic are grouped here for quick review.',
    },
});

function formatUsage(used, limit, unit) {
    if (!limit || limit <= 0) {
        return `${used.toLocaleString()} ${unit} used`;
    }

    return `${used.toLocaleString()} / ${limit.toLocaleString()} ${unit}`;
}
</script>
