<template>
    <AppLayout title="Disk Usage">
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-100">Disk Usage</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        Inspect which directories are using storage inside
                        <span class="font-mono text-gray-500">/var/www/{{ account.username }}</span>.
                    </p>
                </div>
                <Link :href="route('my.files.index')" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-200 transition-colors hover:border-gray-500 hover:text-white">
                    Open File Manager
                </Link>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-72 flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-400">Path</label>
                        <input v-model="path" type="text" class="field w-full" placeholder="/" />
                    </div>
                    <button
                        type="button"
                        class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-50"
                        :disabled="loading"
                        @click="loadUsage"
                    >
                        {{ loading ? 'Scanning...' : 'Scan Usage' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-200 transition-colors hover:border-gray-500 hover:text-white"
                        :disabled="path === '/'"
                        @click="goUp"
                    >
                        Up One Level
                    </button>
                </div>

                <div v-if="error" class="mt-4 rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-400">
                    {{ error }}
                </div>

                <div v-if="usage" class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Scanned Path</p>
                        <p class="mt-2 font-mono text-sm text-gray-200">{{ usage.path }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Size</p>
                        <p class="mt-2 text-xl font-semibold text-gray-100">{{ formatBytes(usage.size) }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Objects</p>
                        <p class="mt-2 text-sm text-gray-200">{{ usage.files }} files, {{ usage.dirs }} directories</p>
                    </div>
                </div>

                <div v-if="usage?.truncated" class="mt-4 rounded-lg border border-amber-700 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
                    Scan was capped for safety. Narrow the path to inspect deeper usage.
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-200">Largest Items</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Path</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Type</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Size</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Objects</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="!usage || usage.entries.length === 0">
                            <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">No usage data loaded.</td>
                        </tr>
                        <tr v-for="entry in usage?.entries ?? []" :key="entry.path" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-100">{{ entry.path }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ entry.is_dir ? 'Directory' : 'File' }}</td>
                            <td class="px-5 py-3.5 text-right font-mono text-sm text-gray-200">{{ formatBytes(entry.size) }}</td>
                            <td class="px-5 py-3.5 text-right text-sm text-gray-400">{{ entry.files }} files, {{ entry.dirs }} dirs</td>
                            <td class="px-5 py-3.5 text-right">
                                <button
                                    v-if="entry.is_dir"
                                    type="button"
                                    class="text-sm font-semibold text-indigo-300 hover:text-indigo-200"
                                    @click="scanPath(entry.path)"
                                >
                                    Inspect
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

defineProps({
    account: Object,
});

const path = ref('/');
const usage = ref(null);
const error = ref('');
const loading = ref(false);

async function loadUsage() {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await axios.get(route('my.disk-usage.show'), {
            params: { path: path.value || '/' },
        });
        usage.value = data;
        path.value = data.path ?? path.value;
    } catch (err) {
        error.value = err?.response?.data?.error ?? 'Unable to load disk usage.';
        usage.value = null;
    } finally {
        loading.value = false;
    }
}

function scanPath(nextPath) {
    path.value = nextPath;
    loadUsage();
}

function goUp() {
    const parts = path.value.split('/').filter(Boolean);
    parts.pop();
    path.value = parts.length ? `/${parts.join('/')}` : '/';
    loadUsage();
}

function formatBytes(bytes) {
    const value = Number(bytes ?? 0);
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

onMounted(loadUsage);
</script>

<style scoped>
@reference "tailwindcss";

.field {
    @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
