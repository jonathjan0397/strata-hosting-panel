<template>
    <AppLayout title="Backup Imports">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Migration"
                title="Competitor Backup Imports"
                description="Convert cPanel and CWP account archives into Strata backup jobs that can be restored through the normal backup workflow."
            />

            <div class="rounded-xl border border-amber-500/30 bg-amber-950/20 p-5">
                <h2 class="text-sm font-semibold text-amber-100">Import scope for beta</h2>
                <p class="mt-2 text-sm leading-6 text-amber-100/80">
                    This importer converts website files and detected SQL dumps into a Strata full-backup archive. It does not recreate original cPanel/CWP mailbox passwords,
                    FTP users, app metadata, or proprietary account settings. Create the destination Strata account first, import to that account's node, then restore the generated backup.
                </p>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-100">Upload cPanel/CWP Archive</h2>
                        <p class="mt-1 text-sm text-gray-400">
                            Supported input: <span class="font-mono">.tar.gz</span> or <span class="font-mono">.tgz</span> account backups up to {{ limits.max_upload_mb }} MB.
                        </p>
                    </div>
                    <form @submit.prevent="submit" class="grid gap-3 lg:min-w-[44rem] lg:grid-cols-4" enctype="multipart/form-data">
                        <select v-model="form.account_id" class="field" required>
                            <option value="">Destination account</option>
                            <option v-for="account in accounts" :key="account.id" :value="account.id">
                                {{ account.username }}{{ account.node ? ` (${account.node})` : '' }}
                            </option>
                        </select>
                        <select v-model="form.node_id" class="field" required>
                            <option value="">Target node</option>
                            <option v-for="node in eligibleNodes" :key="node.id" :value="node.id">
                                {{ node.name }}{{ node.hostname ? ` (${node.hostname})` : '' }}
                            </option>
                        </select>
                        <select v-model="form.source_system" class="field" required>
                            <option value="auto">Auto detect</option>
                            <option value="cpanel">cPanel / WHM</option>
                            <option value="cwp">CWP</option>
                        </select>
                        <input class="field" type="file" accept=".tar.gz,.tgz,application/gzip,application/x-gzip" required @input="form.archive = $event.target.files[0]" />
                        <div class="lg:col-span-4">
                            <button type="submit" :disabled="form.processing" class="btn-primary">
                                {{ form.processing ? 'Uploading...' : 'Queue Import' }}
                            </button>
                        </div>
                    </form>
                </div>
                <div v-if="Object.keys(form.errors).length" class="mt-3 space-y-1 text-xs text-red-400">
                    <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table v-if="imports.data.length" class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Account</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Source</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Archive</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Detected</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Generated Backup</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="item in imports.data" :key="item.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5">
                                <p class="font-mono text-sm text-gray-100">{{ item.account }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ item.node }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-sm uppercase text-gray-300">{{ item.source_system }}</td>
                            <td class="px-5 py-3.5">
                                <p class="max-w-xs truncate font-mono text-xs text-gray-300" :title="item.original_filename">{{ item.original_filename }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ item.size_human }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">
                                <p>Detected: {{ item.detected_paths?.source_system ?? 'pending' }}</p>
                                <p>Home: {{ item.detected_paths?.has_home ? 'yes' : 'no' }}</p>
                                <p>public_html: {{ item.detected_paths?.has_public_html ? 'yes' : 'no' }}</p>
                                <p>SQL dumps: {{ item.detected_paths?.sql_dumps ?? 0 }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">
                                <div v-if="item.backup">
                                    <p class="max-w-xs truncate font-mono text-gray-300" :title="item.backup.filename">{{ item.backup.filename }}</p>
                                    <p class="mt-1">{{ item.backup.status }} - {{ item.backup.size_human }}</p>
                                    <Link :href="route('admin.backups.index')" class="mt-2 inline-block text-indigo-300 hover:text-indigo-200">Open backups</Link>
                                </div>
                                <span v-else>Pending conversion</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span :class="statusClass(item.status)" class="rounded-full px-2 py-0.5 text-xs font-semibold">
                                    {{ statusLabel(item.status) }}
                                </span>
                                <p v-if="item.error" class="mt-1 max-w-sm text-xs text-red-400" :title="item.error">{{ item.error }}</p>
                                <p v-else-if="item.notes" class="mt-1 max-w-sm text-xs text-gray-500">{{ item.notes }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-gray-500">
                                <p>{{ item.created_at }}</p>
                                <p v-if="item.imported_by" class="mt-1">by {{ item.imported_by }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No backup imports yet"
                    description="Upload a cPanel or CWP archive to convert it into a Strata backup job."
                />
            </div>

            <div v-if="imports.last_page > 1" class="flex items-center justify-between text-sm text-gray-500">
                <span>Page {{ imports.current_page }} of {{ imports.last_page }}</span>
                <div class="flex gap-2">
                    <Link v-if="imports.prev_page_url" :href="imports.prev_page_url" class="rounded border border-gray-700 px-3 py-1 text-gray-300 transition-colors hover:bg-gray-800">Prev</Link>
                    <Link v-if="imports.next_page_url" :href="imports.next_page_url" class="rounded border border-gray-700 px-3 py-1 text-gray-300 transition-colors hover:bg-gray-800">Next</Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    imports: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ max_upload_mb: 2048 }) },
});

const form = useForm({
    account_id: '',
    node_id: '',
    source_system: 'auto',
    archive: null,
});

const selectedAccount = computed(() => props.accounts.find((account) => Number(account.id) === Number(form.account_id)));
const eligibleNodes = computed(() => {
    if (!selectedAccount.value) return props.nodes;
    return props.nodes.filter((node) => Number(node.id) === Number(selectedAccount.value.node_id));
});

watch(selectedAccount, (account) => {
    form.node_id = account?.node_id ?? '';
});

function submit() {
    form.post(route('admin.backup-imports.store'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
}

function statusLabel(status) {
    return String(status ?? 'unknown').replaceAll('_', ' ');
}

function statusClass(status) {
    return {
        queued: 'bg-gray-800 text-gray-300',
        analyzing: 'bg-amber-900/40 text-amber-300',
        converting: 'bg-amber-900/40 text-amber-300',
        uploading: 'bg-blue-900/40 text-blue-300',
        complete: 'bg-emerald-900/40 text-emerald-300',
        failed: 'bg-red-900/40 text-red-300',
    }[status] ?? 'bg-gray-800 text-gray-300';
}
</script>
