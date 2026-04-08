<template>
    <AppLayout title="Account Migrations">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Platform"
                title="Account Migrations"
                description="Queue tracked node-to-node migration steps and monitor progress from the migration table."
            />

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-100">Prepare Migration</h2>
                        <p class="mt-1 text-sm text-gray-400">
                            This queues a full source-node backup and records the target node. Transfer, restore, cutover, and cleanup remain explicit follow-up steps.
                        </p>
                    </div>
                    <form @submit.prevent="prepareMigration" class="grid gap-3 sm:grid-cols-2 lg:min-w-[34rem]">
                        <select v-model="form.account_id" class="field" required>
                            <option value="">Choose account</option>
                            <option v-for="account in accounts" :key="account.id" :value="account.id">
                                {{ account.username }}{{ account.node ? ` (${account.node})` : '' }}
                            </option>
                        </select>
                        <select v-model="form.target_node_id" class="field" required>
                            <option value="">Target node</option>
                            <option v-for="node in eligibleNodes" :key="node.id" :value="node.id">
                                {{ node.name }}{{ node.hostname ? ` (${node.hostname})` : '' }}
                            </option>
                        </select>
                        <div class="sm:col-span-2">
                            <button type="submit" :disabled="form.processing" class="btn-primary">
                                {{ form.processing ? 'Preparing...' : 'Create Migration Backup' }}
                            </button>
                        </div>
                    </form>
                </div>
                <p v-if="form.errors.account_id" class="mt-3 text-xs text-red-400">{{ form.errors.account_id }}</p>
                <p v-if="form.errors.target_node_id" class="mt-3 text-xs text-red-400">{{ form.errors.target_node_id }}</p>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table v-if="migrations.data.length" class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Account</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Source</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Target</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Source Backup</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Target Backup</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Started</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="migration in migrations.data" :key="migration.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-100">{{ migration.account }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ migration.source_node }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ migration.target_node }}</td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">
                                <div v-if="migration.backup">
                                    <p class="font-mono text-gray-300">{{ migration.backup.filename ?? 'pending filename' }}</p>
                                    <p class="mt-1 text-gray-500">{{ migration.backup.status }} - {{ migration.backup.size_human }}</p>
                                </div>
                                <span v-else>None</span>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">
                                <div v-if="migration.target_backup">
                                    <p class="font-mono text-gray-300">{{ migration.target_backup.filename ?? 'pending filename' }}</p>
                                    <p class="mt-1 text-gray-500">{{ migration.target_backup.status }} - {{ migration.target_backup.size_human }}</p>
                                </div>
                                <span v-else>Not transferred</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span :class="statusClass(migration.status)" class="rounded-full px-2 py-0.5 text-xs font-semibold">
                                    {{ statusLabel(migration.status) }}
                                </span>
                                <p v-if="migration.error" class="mt-1 max-w-sm truncate text-xs text-red-400" :title="migration.error">{{ migration.error }}</p>
                                <p v-if="migration.status === 'restored' && migration.cutover_blockers?.length" class="mt-1 max-w-sm text-xs text-amber-300">
                                    Cutover blocked: {{ migration.cutover_blockers.join(', ') }}
                                </p>
                                <p v-if="migration.status === 'complete' && migration.reset_required && Object.keys(migration.reset_required).length" class="mt-1 max-w-sm text-xs text-amber-300">
                                    Source cleanup blocked until reset-required services are handled.
                                </p>
                                <details v-if="migration.status === 'restored' && migration.remediation?.length" class="mt-2 max-w-md">
                                    <summary class="cursor-pointer text-xs text-indigo-300">Show remediation checklist</summary>
                                    <div class="mt-2 space-y-2 rounded-lg border border-amber-900/50 bg-amber-950/20 p-3">
                                        <div v-for="item in migration.remediation" :key="item.label" class="text-xs text-amber-100/90">
                                            <p class="font-semibold">{{ item.label }}: {{ item.count }}</p>
                                            <p class="mt-0.5 text-amber-100/70">{{ item.action }}</p>
                                        </div>
                                    </div>
                                </details>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-gray-500">
                                <p>{{ migration.created_at }}</p>
                                <p v-if="migration.started_by" class="mt-1">by {{ migration.started_by }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <button
                                    v-if="migration.status === 'backup_ready'"
                                    type="button"
                                    class="text-xs font-semibold text-indigo-400 transition-colors hover:text-indigo-300"
                                    @click="transferBackup(migration.id)"
                                >
                                    Transfer Backup
                                </button>
                                <button
                                    v-else-if="migration.status === 'transfer_ready'"
                                    type="button"
                                    class="text-xs font-semibold text-emerald-400 transition-colors hover:text-emerald-300"
                                    @click="restoreBackup(migration.id)"
                                >
                                    Restore Target
                                </button>
                                <button
                                    v-else-if="migration.status === 'restored' && migration.can_cutover"
                                    type="button"
                                    class="text-xs font-semibold text-blue-400 transition-colors hover:text-blue-300"
                                    @click="cutoverMigration(migration.id)"
                                >
                                    Cut Over
                                </button>
                                <span v-else-if="migration.status === 'restored'" class="text-xs text-amber-300">Blocked</span>
                                <button
                                    v-else-if="migration.status === 'complete' && migration.can_cleanup_source"
                                    type="button"
                                    class="text-xs font-semibold text-red-400 transition-colors hover:text-red-300"
                                    @click="cleanupSource(migration.id)"
                                >
                                    Cleanup Source
                                </button>
                                <span v-else-if="migration.status === 'complete'" class="text-xs text-amber-300">Reset required</span>
                                <span v-else-if="isRunning(migration.status)" class="text-xs text-amber-300">Queued</span>
                                <span v-else class="text-xs text-gray-600">-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No account migrations yet"
                    description="Create a migration backup to start tracking account movement between nodes."
                />
            </div>

            <div v-if="migrations.last_page > 1" class="flex items-center justify-between text-sm text-gray-500">
                <span>Page {{ migrations.current_page }} of {{ migrations.last_page }}</span>
                <div class="flex gap-2">
                    <Link v-if="migrations.prev_page_url" :href="migrations.prev_page_url" class="rounded border border-gray-700 px-3 py-1 text-gray-300 transition-colors hover:bg-gray-800">Prev</Link>
                    <Link v-if="migrations.next_page_url" :href="migrations.next_page_url" class="rounded border border-gray-700 px-3 py-1 text-gray-300 transition-colors hover:bg-gray-800">Next</Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    migrations: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
});

const form = useForm({
    account_id: '',
    target_node_id: '',
});

const selectedAccount = computed(() => props.accounts.find((account) => Number(account.id) === Number(form.account_id)));
const eligibleNodes = computed(() => props.nodes.filter((node) => Number(node.id) !== Number(selectedAccount.value?.node_id)));

function prepareMigration() {
    form.post(route('admin.migrations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function transferBackup(id) {
    if (!confirm('Transfer this migration backup to the target node?')) return;
    form.post(route('admin.migrations.transfer', id), {
        preserveScroll: true,
    });
}

function restoreBackup(id) {
    if (!confirm('Provision the target account and restore this backup archive? The source account will be retained.')) return;
    form.post(route('admin.migrations.restore', id), {
        preserveScroll: true,
    });
}

function cutoverMigration(id) {
    if (!confirm('Move panel ownership to the target node and reprovision domain vhosts? The source node data will be retained.')) return;
    form.post(route('admin.migrations.cutover', id), {
        preserveScroll: true,
    });
}

function cleanupSource(id) {
    if (!confirm('Permanently remove the migrated account data from the original source node?')) return;
    form.post(route('admin.migrations.cleanup-source', id), {
        preserveScroll: true,
    });
}

function statusLabel(status) {
    return String(status ?? 'unknown').replaceAll('_', ' ');
}

function statusClass(status) {
    return {
        pending: 'bg-gray-800 text-gray-300',
        backup_running: 'bg-amber-900/40 text-amber-300',
        backup_ready: 'bg-emerald-900/40 text-emerald-300',
        transfer_running: 'bg-amber-900/40 text-amber-300',
        transfer_ready: 'bg-blue-900/40 text-blue-300',
        restore_running: 'bg-amber-900/40 text-amber-300',
        restored: 'bg-emerald-900/40 text-emerald-300',
        cutover_running: 'bg-amber-900/40 text-amber-300',
        source_cleanup_running: 'bg-amber-900/40 text-amber-300',
        source_cleaned: 'bg-gray-800 text-gray-300',
        failed: 'bg-red-900/40 text-red-300',
        complete: 'bg-indigo-900/40 text-indigo-300',
    }[status] ?? 'bg-gray-800 text-gray-300';
}

function isRunning(status) {
    return ['backup_running', 'transfer_running', 'restore_running', 'cutover_running', 'source_cleanup_running'].includes(status);
}
</script>
