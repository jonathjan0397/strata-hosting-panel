<template>
    <AppLayout title="Backups">
        <div class="space-y-6 p-6">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold text-gray-100">Backups</h1>
                    <p class="mt-0.5 text-sm text-gray-400">All account backup jobs across all nodes.</p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-100">Import Existing Node Backups</h2>
                        <p class="mt-1 text-sm text-gray-400">
                            Scan an account's node backup directory and register any completed archives that are not yet tracked in the panel.
                        </p>
                    </div>
                    <form @submit.prevent="importExisting" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <select v-model="importForm.account_id" class="field min-w-64" required>
                            <option value="">Choose account</option>
                            <option v-for="account in accounts" :key="account.id" :value="account.id">
                                {{ account.username }}{{ account.node ? ` (${account.node})` : '' }}
                            </option>
                        </select>
                        <button type="submit" :disabled="importForm.processing" class="btn-primary whitespace-nowrap">
                            {{ importForm.processing ? 'Importing...' : 'Scan & Import' }}
                        </button>
                    </form>
                </div>
                <p v-if="importForm.errors.account_id" class="mt-3 text-xs text-red-400">{{ importForm.errors.account_id }}</p>
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by username…"
                    class="field w-64"
                    @input="filter"
                />
                <select v-model="status" @change="filter" class="field">
                    <option value="">All statuses</option>
                    <option value="complete">Complete</option>
                    <option value="failed">Failed</option>
                    <option value="running">Running</option>
                </select>
                <div class="ml-auto flex items-center gap-3">
                    <span class="text-xs text-gray-500">{{ selectedIds.length }} selected</span>
                    <button
                        type="button"
                        :disabled="selectedIds.length === 0"
                        class="rounded-lg border border-red-700 px-3 py-2 text-xs font-semibold text-red-300 transition-colors hover:bg-red-900/20 disabled:opacity-50"
                        @click="bulkDelete"
                    >
                        Delete Selected
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-700 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                    :checked="allVisibleSelected"
                                    :disabled="jobs.data.length === 0"
                                    @change="toggleVisible($event.target.checked)"
                                />
                            </th>
                            <th class="px-5 py-3">Date</th>
                            <th class="px-5 py-3">Account</th>
                            <th class="px-5 py-3">Node</th>
                            <th class="px-5 py-3">Type</th>
                            <th class="px-5 py-3">Size</th>
                            <th class="px-5 py-3">Trigger</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="job in jobs.data" :key="job.id">
                            <td class="px-5 py-3.5">
                                <input
                                    v-model="selectedIds"
                                    type="checkbox"
                                    class="rounded border-gray-700 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                    :value="job.id"
                                />
                            </td>
                            <td class="px-5 py-3.5 text-gray-400 font-mono text-xs">{{ job.created_at }}</td>
                            <td class="px-5 py-3.5 font-mono text-gray-200">{{ job.account }}</td>
                            <td class="px-5 py-3.5 text-gray-400">{{ job.node }}</td>
                            <td class="px-5 py-3.5">
                                <span :class="typeClass(job.type)" class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize">{{ job.type }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-gray-400 font-mono text-xs">{{ job.size_human ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-gray-500 text-xs capitalize">{{ job.trigger }}</td>
                            <td class="px-5 py-3.5">
                                <span :class="statusClass(job.status)" class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize">{{ job.status }}</span>
                                <p v-if="job.error" class="mt-0.5 text-xs text-red-400 truncate max-w-xs" :title="job.error">{{ job.error }}</p>
                                <p v-if="job.restore_status" class="mt-1 text-xs" :class="job.restore_status === 'failed' ? 'text-red-400' : 'text-cyan-300'">
                                    Restore: {{ job.restore_status }}<span v-if="job.last_restored_at"> at {{ job.last_restored_at }}</span>
                                </p>
                                <p v-if="job.restore_error" class="mt-0.5 max-w-xs truncate text-xs text-red-400" :title="job.restore_error">{{ job.restore_error }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <button
                                    v-if="job.status === 'complete'"
                                    :disabled="job.restore_status === 'running'"
                                    @click="restore(job.id)"
                                    class="text-xs text-emerald-400 transition-colors hover:text-emerald-300 disabled:opacity-50"
                                >Restore</button>
                                <button
                                    @click="remove(job.id)"
                                    class="text-xs text-red-500 hover:text-red-400 transition-colors"
                                >Delete</button>
                            </td>
                        </tr>
                        <tr v-if="!jobs.data.length">
                            <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-500">No backup jobs found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="jobs.last_page > 1" class="flex items-center justify-between text-sm text-gray-500">
                <span>Page {{ jobs.current_page }} of {{ jobs.last_page }}</span>
                <div class="flex gap-2">
                    <Link v-if="jobs.prev_page_url" :href="jobs.prev_page_url" class="rounded px-3 py-1 border border-gray-700 hover:bg-gray-800 text-gray-300 transition-colors">Prev</Link>
                    <Link v-if="jobs.next_page_url" :href="jobs.next_page_url" class="rounded px-3 py-1 border border-gray-700 hover:bg-gray-800 text-gray-300 transition-colors">Next</Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    jobs:    { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    accounts: { type: Array, default: () => [] },
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const selectedIds = ref([]);
const importForm = useForm({ account_id: '' });
const visibleIds = computed(() => props.jobs.data.map((job) => job.id));
const allVisibleSelected = computed(() => visibleIds.value.length > 0 && visibleIds.value.every((id) => selectedIds.value.includes(id)));
let debounce = null;

function filter() {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        selectedIds.value = [];
        router.get(route('admin.backups.index'), { search: search.value, status: status.value }, { preserveState: true });
    }, 300);
}

function restore(id) {
    if (!confirm('Queue a restore for this backup? This will overwrite the account\'s current files when the queue worker runs.')) return;
    router.post(route('admin.backups.restore', id));
}

function remove(id) {
    if (!confirm('Delete this backup from the node and panel?')) return;
    router.delete(route('admin.backups.destroy', id));
}

function toggleVisible(checked) {
    selectedIds.value = checked ? [...visibleIds.value] : [];
}

function bulkDelete() {
    if (selectedIds.value.length === 0) return;
    if (!confirm(`Delete ${selectedIds.value.length} selected backup(s) from their nodes and the panel? Failed remote deletes will keep their panel records.`)) return;

    router.delete(route('admin.backups.bulk-destroy'), {
        data: { backup_ids: selectedIds.value },
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
        },
    });
}

function importExisting() {
    importForm.post(route('admin.backups.import-existing'), {
        preserveScroll: true,
        onSuccess: () => importForm.reset(),
    });
}

function typeClass(type) {
    return {
        full:      'bg-indigo-900/40 text-indigo-300',
        files:     'bg-blue-900/40 text-blue-300',
        databases: 'bg-purple-900/40 text-purple-300',
    }[type] ?? 'bg-gray-800 text-gray-400';
}

function statusClass(status) {
    return {
        complete: 'bg-green-900/40 text-green-400',
        running:  'bg-yellow-900/40 text-yellow-400',
        pending:  'bg-gray-800 text-gray-400',
        failed:   'bg-red-900/40 text-red-400',
    }[status] ?? 'bg-gray-800 text-gray-400';
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
