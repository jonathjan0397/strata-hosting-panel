<template>
    <AppLayout title="DNS Zones">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Domains"
                title="DNS Zones"
                description="Manage DNS records for hosted domains with provisioned zones."
            />

            <div class="grid gap-4 md:grid-cols-3">
                <StatCard label="Domains" :value="domains.length" color="indigo" />
                <StatCard label="Active Zones" :value="activeZoneCount" color="emerald" />
                <StatCard label="Pending Zones" :value="pendingZoneCount" color="gray" />
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/60">
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-400">Domain</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-400">Zone Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-400">Records</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="domain in domains" :key="domain.id" class="hover:bg-gray-900/40">
                            <td class="px-4 py-3 font-medium text-gray-100">{{ domain.domain }}</td>
                            <td class="px-4 py-3">
                                <span v-if="domain.dns_zone" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-900/40 px-2 py-0.5 text-xs font-medium text-emerald-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                    Active
                                </span>
                                <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-gray-800 px-2 py-0.5 text-xs font-medium text-gray-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-gray-600"></span>
                                    No zone
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-400">
                                {{ domain.dns_zone ? domain.dns_zone.records_count : '-' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    v-if="domain.dns_zone"
                                    :href="route('my.dns.show', domain.id)"
                                    class="text-xs text-indigo-400 transition-colors hover:text-indigo-300"
                                >Manage DNS</Link>
                                <span v-else class="text-xs text-gray-600">No zone provisioned</span>
                            </td>
                        </tr>
                        <tr v-if="domains.length === 0">
                            <td colspan="4" class="px-4 py-8">
                                <EmptyState
                                    title="No domains found"
                                    description="Add a domain before managing DNS records."
                                >
                                    <template #actions>
                                        <Link :href="route('my.domains.create')" class="btn-primary">Add Domain</Link>
                                    </template>
                                </EmptyState>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    domains: Array,
});

const activeZoneCount = computed(() => props.domains.filter((domain) => domain.dns_zone).length);
const pendingZoneCount = computed(() => props.domains.length - activeZoneCount.value);
</script>
