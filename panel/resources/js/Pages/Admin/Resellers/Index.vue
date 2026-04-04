<template>
    <AppLayout title="Resellers">
        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search resellers…"
                        class="field w-64"
                        @input="doSearch"
                    />
                </div>
                <Link :href="route('admin.resellers.create')" class="btn-primary">
                    New Reseller
                </Link>
            </div>

            <div class="rounded-xl border border-gray-800 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/60">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Clients</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Account Quota</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Joined</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="resellers.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
                                No resellers found.
                            </td>
                        </tr>
                        <tr v-for="r in resellers.data" :key="r.id" class="hover:bg-gray-900/40 transition-colors">
                            <td class="px-4 py-3 font-medium text-gray-100">{{ r.name }}</td>
                            <td class="px-4 py-3 text-gray-400">{{ r.email }}</td>
                            <td class="px-4 py-3 text-gray-300">{{ r.reseller_clients_count }}</td>
                            <td class="px-4 py-3 text-gray-300">
                                {{ r.quota_accounts !== null ? r.quota_accounts : '∞' }}
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ formatDate(r.created_at) }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="route('admin.resellers.show', r.id)" class="text-xs text-indigo-400 hover:text-indigo-300">
                                    View →
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="resellers.last_page > 1" class="flex items-center gap-2">
                <Link
                    v-for="link in resellers.links"
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
    resellers: Object,
    filters:   Object,
});

const search = ref(props.filters?.search ?? '');

let searchTimeout;
function doSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('admin.resellers.index'), { search: search.value }, { preserveState: true, replace: true });
    }, 300);
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString();
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
.btn-primary {
    @apply rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors;
}
</style>
