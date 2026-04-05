<template>
    <AppLayout title="DNS Zones">
        <div class="space-y-5">
            <div class="rounded-xl border border-gray-800 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/60">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Domain</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Zone Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Records</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="domains.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">No domains found.</td>
                        </tr>
                        <tr v-for="d in domains" :key="d.id" class="hover:bg-gray-900/40">
                            <td class="px-4 py-3 font-medium text-gray-100">{{ d.domain }}</td>
                            <td class="px-4 py-3">
                                <span v-if="d.dns_zone" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-900/40 px-2 py-0.5 text-xs font-medium text-emerald-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                    Active
                                </span>
                                <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-gray-800 px-2 py-0.5 text-xs font-medium text-gray-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-gray-600"></span>
                                    No zone
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-400">
                                {{ d.dns_zone ? d.dns_zone.records_count : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    v-if="d.dns_zone"
                                    :href="route('my.dns.show', d.id)"
                                    class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors"
                                >Manage DNS</Link>
                                <span v-else class="text-xs text-gray-600">No zone provisioned</span>
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

defineProps({
    domains: Array,
});
</script>
