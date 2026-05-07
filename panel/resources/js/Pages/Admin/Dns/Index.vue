<template>
    <AppLayout title="DNS Zones">
        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search domains…"
                    class="field w-64"
                    @input="doSearch"
                />
            </div>

            <div class="rounded-xl border border-gray-800 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/60">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Domain</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Account</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Zone Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Records</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="domains.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">No domains found.</td>
                        </tr>
                        <tr v-for="d in domains.data" :key="d.id" class="hover:bg-gray-900/40">
                            <td class="px-4 py-3 font-medium text-gray-100">{{ d.domain }}</td>
                            <td class="px-4 py-3 text-gray-400">
                                <span v-if="d.account">{{ d.account.username }}</span>
                                <span v-else class="text-gray-600">—</span>
                            </td>
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
                                <div class="flex items-center justify-end gap-3">
                                    <Link
                                        v-if="d.dns_zone"
                                        :href="route('admin.dns.show', d.id)"
                                        class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors"
                                    >Manage DNS</Link>
                                    <form v-else @submit.prevent="provision(d)">
                                        <button type="submit" class="text-xs text-emerald-400 hover:text-emerald-300 transition-colors">
                                            Provision Zone
                                        </button>
                                    </form>
                                    <Link
                                        :href="route('admin.domains.show', d.id)"
                                        class="text-xs text-gray-400 hover:text-gray-200 transition-colors"
                                    >Domain</Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="domains.last_page > 1" class="flex items-center gap-2">
                <Link
                    v-for="link in domains.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    v-html="link.label"
                    class="px-3 py-1.5 rounded text-xs"
                    :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-gray-200'"
                />
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    domains: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');

let searchTimeout;
function doSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('admin.dns.index'), { search: search.value }, { preserveState: true, replace: true });
    }, 300);
}

function provision(domain) {
    router.post(route('admin.dns.provision', domain.id));
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
