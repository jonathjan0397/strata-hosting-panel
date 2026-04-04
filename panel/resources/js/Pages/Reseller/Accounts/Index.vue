<template>
    <AppLayout title="My Clients">
        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search clients…"
                    class="field w-64"
                    @input="doSearch"
                />
                <Link :href="route('reseller.accounts.create')" class="btn-primary">
                    New Client
                </Link>
            </div>

            <div class="rounded-xl border border-gray-800 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/60">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Client</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Node</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="accounts.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">No clients found.</td>
                        </tr>
                        <tr v-for="a in accounts.data" :key="a.id" class="hover:bg-gray-900/40">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-100">{{ a.user?.name }}</p>
                                <p class="text-xs text-gray-400">{{ a.user?.email }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-gray-300">{{ a.username }}</td>
                            <td class="px-4 py-3 text-gray-400">{{ a.node?.name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="statusClass(a.status)">
                                    {{ a.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ formatDate(a.created_at) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <button
                                        v-if="a.status === 'active'"
                                        @click="suspendAccount(a)"
                                        class="text-xs text-yellow-400 hover:text-yellow-300"
                                    >Suspend</button>
                                    <button
                                        v-else-if="a.status === 'suspended'"
                                        @click="unsuspendAccount(a)"
                                        class="text-xs text-emerald-400 hover:text-emerald-300"
                                    >Unsuspend</button>
                                    <button
                                        @click="deleteAccount(a)"
                                        class="text-xs text-red-400 hover:text-red-300"
                                    >Delete</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="accounts.last_page > 1" class="flex items-center gap-2">
                <Link
                    v-for="link in accounts.links"
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
    accounts: Object,
    filters:  Object,
});

const search = ref(props.filters?.search ?? '');

let searchTimeout;
function doSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('reseller.accounts.index'), { search: search.value }, { preserveState: true, replace: true });
    }, 300);
}

function suspendAccount(a) {
    router.post(route('reseller.accounts.suspend', a.id));
}
function unsuspendAccount(a) {
    router.post(route('reseller.accounts.unsuspend', a.id));
}
function deleteAccount(a) {
    if (confirm(`Delete ${a.username}? This will remove the account and all hosted data.`)) {
        router.delete(route('reseller.accounts.destroy', a.id));
    }
}

function statusClass(status) {
    const map = {
        active:     'bg-emerald-900/40 text-emerald-300',
        suspended:  'bg-yellow-900/40 text-yellow-300',
        terminated: 'bg-red-900/40 text-red-300',
    };
    return map[status] ?? 'bg-gray-800 text-gray-400';
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString();
}
</script>

<style scoped>
@reference "tailwindcss";
.field    { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary { @apply rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors; }
</style>
