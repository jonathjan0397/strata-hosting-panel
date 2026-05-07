<template>
    <AppLayout title="Audit Log">
        <div class="space-y-4">

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <input
                    v-model="form.search"
                    type="search"
                    placeholder="Search action, IP, or email…"
                    class="field w-72"
                    @input="debounceSearch"
                />
                <select v-model="form.actor" @change="applyFilters" class="field w-36">
                    <option value="">All actors</option>
                    <option value="admin">Admin</option>
                    <option value="reseller">Reseller</option>
                    <option value="user">User</option>
                    <option value="system">System</option>
                    <option value="api">API</option>
                </select>
                <select v-model="form.action" @change="applyFilters" class="field w-48">
                    <option value="">All actions</option>
                    <optgroup label="Auth">
                        <option value="auth.">auth.*</option>
                        <option value="profile.">profile.*</option>
                    </optgroup>
                    <optgroup label="Hosting">
                        <option value="domain.">domain.*</option>
                        <option value="ssl.">ssl.*</option>
                        <option value="email.">email.*</option>
                        <option value="database.">database.*</option>
                        <option value="ftp.">ftp.*</option>
                        <option value="dns.">dns.*</option>
                    </optgroup>
                    <optgroup label="Admin">
                        <option value="account.">account.*</option>
                        <option value="node.">node.*</option>
                        <option value="reseller.">reseller.*</option>
                    </optgroup>
                </select>
                <button
                    v-if="form.search || form.actor || form.action"
                    @click="clearFilters"
                    class="text-xs text-gray-400 hover:text-gray-200 transition-colors"
                >
                    Clear filters
                </button>
                <span class="ml-auto text-xs text-gray-500">
                    {{ logs.total.toLocaleString() }} entries
                </span>
            </div>

            <!-- Table -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 text-left">
                            <th class="px-4 py-3 text-xs font-semibold text-gray-400 w-40">Time</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-400 w-20">Actor</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-400">User</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-400">Action</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-400 w-32">IP</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-400 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <template v-if="logs.data.length">
                            <tr
                                v-for="log in logs.data"
                                :key="log.id"
                                class="hover:bg-gray-800/50 transition-colors"
                            >
                                <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap font-mono">
                                    {{ formatDate(log.created_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="actorClass(log.actor_type)">
                                        {{ log.actor_type ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-300 max-w-[200px] truncate">
                                    {{ log.user?.email ?? '—' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-200">
                                    {{ log.action }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-400">
                                    {{ log.ip_address ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="log.payload && Object.keys(log.payload).length"
                                        @click="togglePayload(log.id)"
                                        class="text-gray-500 hover:text-gray-300 transition-colors"
                                        title="Show payload"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                            <!-- Payload expansion row -->
                            <template v-for="log in logs.data" :key="'p-' + log.id">
                                <tr v-if="expandedId === log.id" class="bg-gray-950">
                                    <td colspan="6" class="px-4 py-3">
                                        <pre class="text-xs text-gray-400 font-mono whitespace-pre-wrap break-all">{{ JSON.stringify(log.payload, null, 2) }}</pre>
                                    </td>
                                </tr>
                            </template>
                        </template>
                        <tr v-else>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No log entries found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="logs.last_page > 1" class="flex items-center justify-between">
                <p class="text-xs text-gray-500">
                    Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}
                </p>
                <div class="flex gap-1">
                    <Link
                        v-for="link in logs.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        :class="[
                            'inline-flex items-center justify-center rounded px-2.5 py-1 text-xs transition-colors',
                            link.active
                                ? 'bg-indigo-600 text-white'
                                : link.url
                                    ? 'text-gray-400 hover:text-gray-200 hover:bg-gray-800'
                                    : 'text-gray-600 cursor-default',
                        ]"
                        v-html="link.label"
                        preserve-scroll
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    logs:    Object,
    filters: Object,
});

const form = reactive({
    search: props.filters.search ?? '',
    actor:  props.filters.actor  ?? '',
    action: props.filters.action ?? '',
});

const expandedId = ref(null);

let searchTimer = null;
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
}

function applyFilters() {
    router.get(route('admin.audit-log.index'), form, { preserveState: true, replace: true });
}

function clearFilters() {
    form.search = '';
    form.actor  = '';
    form.action = '';
    applyFilters();
}

function togglePayload(id) {
    expandedId.value = expandedId.value === id ? null : id;
}

function formatDate(iso) {
    const d = new Date(iso);
    return d.toLocaleDateString('en-CA') + ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function actorClass(type) {
    const map = {
        admin:    'bg-indigo-900/50 text-indigo-300',
        reseller: 'bg-purple-900/50 text-purple-300',
        user:     'bg-gray-800 text-gray-300',
        system:   'bg-amber-900/40 text-amber-300',
        api:      'bg-cyan-900/40 text-cyan-300',
    };
    return map[type] ?? 'bg-gray-800 text-gray-400';
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
