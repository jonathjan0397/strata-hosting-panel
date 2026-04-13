<template>
    <div class="space-y-4">
        <CollapsiblePanel :title="title" :description="description" :content-class="'p-4'">
            <template #badge>
                <span class="rounded-full border border-gray-700 px-2.5 py-0.5 text-[11px] text-gray-400">
                    {{ usageMetrics.traffic_window_days }} day window
                </span>
            </template>

            <div class="grid gap-4 xl:grid-cols-[1.2fr,0.8fr]">
                <div class="space-y-3 rounded-xl border border-gray-800 bg-gray-950/60 p-4">
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

                <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                    <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                        <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Databases</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-100">{{ usageMetrics.overview.databases }}</p>
                        <p class="mt-1 text-xs text-gray-400">
                            {{ usageMetrics.overview.assigned_databases }} assigned, {{ usageMetrics.overview.unassigned_databases }} account-level
                        </p>
                        <p class="mt-1 text-xs text-gray-500">{{ usageMetrics.overview.database_human }} total</p>
                    </div>
                    <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                        <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Mailboxes</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-100">{{ usageMetrics.overview.mailboxes }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ formatUsage(usageMetrics.overview.email_used_mb, usageMetrics.overview.email_quota_mb, 'MB') }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                        <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Domains</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-100">{{ usageMetrics.overview.domains }}</p>
                        <p class="mt-1 text-xs text-gray-400">Per-domain activity is available below.</p>
                    </div>
                </div>
            </div>
        </CollapsiblePanel>

        <CollapsiblePanel
            title="Usage By Domain"
            description="Domain-level mailbox, database, and traffic activity."
            :default-open="false"
            :content-class="'p-0'"
        >
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.18em] text-gray-500">Domain</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.18em] text-gray-500">Email</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.18em] text-gray-500">Databases</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.18em] text-gray-500">Requests</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.18em] text-gray-500">Bandwidth</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-medium uppercase tracking-[0.18em] text-gray-500">Errors</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="usageMetrics.domains.length === 0">
                            <td colspan="6" class="px-4 py-7 text-center text-sm text-gray-500">No hosted domains yet.</td>
                        </tr>
                        <tr v-for="domain in usageMetrics.domains" :key="domain.id" class="transition-colors hover:bg-gray-800/30">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm text-gray-100">{{ domain.domain }}</span>
                                    <span v-if="domain.ssl_enabled" class="rounded-full border border-emerald-800 bg-emerald-900/30 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-300">SSL</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ domain.type }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-300">
                                <div>{{ domain.mailboxes }} mailboxes</div>
                                <div class="text-xs text-gray-500">{{ formatUsage(domain.email_used_mb, domain.email_quota_mb, 'MB') }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-300">
                                <div>{{ domain.database_count }}</div>
                                <p class="text-xs text-gray-500">{{ domain.database_human }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-300">{{ domain.requests_30d.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-sm text-gray-300">{{ domain.bandwidth_human_30d }}</td>
                            <td class="px-4 py-3 text-sm" :class="domain.errors_30d > 0 ? 'text-amber-300' : 'text-gray-500'">
                                {{ domain.errors_30d.toLocaleString() }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-800 px-4 py-2.5 text-xs text-gray-500">
                Database counts by domain come from explicit database-to-domain assignments. Unassigned databases remain visible in the overview above.
            </div>
        </CollapsiblePanel>
    </div>
</template>

<script setup>
import CollapsiblePanel from '@/Components/CollapsiblePanel.vue';
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
