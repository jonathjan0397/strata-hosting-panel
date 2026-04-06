<template>
    <AppLayout title="Backups">
        <div class="max-w-4xl space-y-6 p-6">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold text-gray-100">Backups</h1>
                    <p class="mt-0.5 text-sm text-gray-400">On-demand and scheduled backups of your files and databases.</p>
                </div>

                <!-- Trigger backup -->
                <div class="flex items-center gap-2">
                    <select v-model="backupType" class="field text-sm">
                        <option value="full">Full backup</option>
                        <option value="files">Files only</option>
                        <option value="databases">Databases only</option>
                    </select>
                    <button
                        @click="create"
                        :disabled="creating"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                    >
                        <span v-if="creating" class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/></svg>
                            Running…
                        </span>
                        <span v-else>Run Backup</span>
                    </button>
                </div>
            </div>

            <!-- Info banner -->
            <div class="rounded-xl border border-indigo-700/40 bg-indigo-900/20 px-4 py-3 text-sm text-indigo-300">
                Backups run automatically every night at 02:00. Backups are stored on the server — download them regularly for off-site protection.
            </div>

            <!-- Table -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Date</th>
                            <th class="px-5 py-3">Type</th>
                            <th class="px-5 py-3">Size</th>
                            <th class="px-5 py-3">Trigger</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="job in jobs" :key="job.id">
                            <td class="px-5 py-3.5 text-gray-300 font-mono text-xs">{{ job.created_at }}</td>
                            <td class="px-5 py-3.5">
                                <span :class="typeClass(job.type)" class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize">
                                    {{ job.type }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-gray-400 font-mono text-xs">{{ job.size_human ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-gray-500 text-xs capitalize">{{ job.trigger }}</td>
                            <td class="px-5 py-3.5">
                                <span :class="statusClass(job.status)" class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize">
                                    {{ job.status }}
                                </span>
                                <p v-if="job.error" class="mt-0.5 text-xs text-red-400 truncate max-w-xs" :title="job.error">{{ job.error }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-3">
                                <a
                                    v-if="job.status === 'complete'"
                                    :href="route('my.backups.download', job.id)"
                                    class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors"
                                >Download</a>
                                <button
                                    v-if="job.status === 'complete'"
                                    @click="restore(job.id)"
                                    class="text-xs text-emerald-400 hover:text-emerald-300 transition-colors"
                                >Restore</button>
                                <button
                                    v-if="job.status === 'complete' && job.type !== 'databases'"
                                    @click="openPathRestore(job)"
                                    class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors"
                                >Restore Path</button>
                                <button
                                    @click="remove(job.id)"
                                    class="text-xs text-red-500 hover:text-red-400 transition-colors"
                                >Delete</button>
                            </td>
                        </tr>
                        <tr v-if="jobs.length === 0">
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">
                                No backups yet. Click <strong>Run Backup</strong> to create one.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="pathRestore.job" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="w-full max-w-lg rounded-2xl border border-gray-800 bg-gray-950 p-5 shadow-2xl">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold text-gray-100">Restore a File or Directory</h2>
                            <p class="mt-1 text-sm text-gray-400">
                                Restore one path from <span class="font-mono text-gray-300">{{ pathRestore.job.filename }}</span>.
                            </p>
                        </div>
                        <button @click="closePathRestore" class="text-gray-500 hover:text-gray-300">×</button>
                    </div>

                    <div class="mt-5 space-y-4">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Source path in backup</span>
                            <input v-model="pathRestore.source_path" class="field mt-1 w-full" placeholder="public_html/index.php" />
                            <span class="mt-1 block text-xs text-gray-500">Relative to your account root. Absolute paths and .. are rejected.</span>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Restore target path</span>
                            <input v-model="pathRestore.target_path" class="field mt-1 w-full" placeholder="Leave blank to restore to the same path" />
                            <span class="mt-1 block text-xs text-gray-500">Existing files/directories at the target path will be replaced.</span>
                        </label>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-3">
                        <button @click="closePathRestore" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:bg-gray-900">
                            Cancel
                        </button>
                        <button
                            @click="restorePath"
                            :disabled="pathRestore.submitting || !pathRestore.source_path"
                            class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-500 disabled:opacity-60"
                        >
                            {{ pathRestore.submitting ? 'Restoring...' : 'Restore Path' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ jobs: { type: Array, default: () => [] } });

const backupType = ref('full');
const creating   = ref(false);
const pathRestore = ref({
    job: null,
    source_path: '',
    target_path: '',
    submitting: false,
});

function create() {
    creating.value = true;
    router.post(route('my.backups.store'), { type: backupType.value }, {
        onFinish: () => { creating.value = false; },
    });
}

function restore(id) {
    if (!confirm('Restore this backup? This will overwrite your current files.')) return;
    router.post(route('my.backups.restore', id));
}

function openPathRestore(job) {
    pathRestore.value = {
        job,
        source_path: '',
        target_path: '',
        submitting: false,
    };
}

function closePathRestore(force = false) {
    if (pathRestore.value.submitting && !force) return;
    pathRestore.value = {
        job: null,
        source_path: '',
        target_path: '',
        submitting: false,
    };
}

function restorePath() {
    if (!pathRestore.value.job || !pathRestore.value.source_path) return;
    if (!confirm('Restore this path? Existing files at the target path will be replaced.')) return;

    pathRestore.value.submitting = true;
    router.post(route('my.backups.restore-path', pathRestore.value.job.id), {
        source_path: pathRestore.value.source_path,
        target_path: pathRestore.value.target_path || null,
    }, {
        onFinish: () => {
            pathRestore.value.submitting = false;
        },
        onSuccess: () => {
            closePathRestore(true);
        },
    });
}

function remove(id) {
    if (!confirm('Delete this backup? This cannot be undone.')) return;
    router.delete(route('my.backups.destroy', id));
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
