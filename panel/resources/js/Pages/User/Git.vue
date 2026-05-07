<template>
    <AppLayout title="Git Version Control">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Files"
                title="Git Version Control"
                :description="`Inspect, initialize, clone, and pull repositories inside /var/www/${account.username}.`"
            />

            <div class="grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
                <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-200">Repository Inspector</h2>
                            <p class="mt-1 text-sm text-gray-400">
                                Manage repositories inside <span class="font-mono text-gray-300">/var/www/{{ account.username }}</span>.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60"
                            :disabled="loading.status"
                            @click="loadStatus"
                        >
                            {{ loading.status ? 'Refreshing...' : 'Refresh Status' }}
                        </button>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-[minmax(0,1fr)_12rem]">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-400">Repository Path</label>
                            <input v-model="selectedPath" type="text" class="field w-full" placeholder="/public_html" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-400">Quick Select</label>
                            <select v-model="selectedPath" class="field w-full">
                                <option v-for="path in paths" :key="`${path.id}-${path.path}`" :value="path.path">
                                    {{ path.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div v-if="error" class="mt-4 rounded-lg border border-rose-800 bg-rose-900/20 px-3 py-2 text-sm text-rose-300">
                        {{ error }}
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Resolved Path</p>
                            <p class="mt-2 font-mono text-sm text-gray-200">{{ repo.absolute_path ?? 'Not loaded yet' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Remote</p>
                            <p class="mt-2 break-all font-mono text-sm text-gray-200">{{ repo.remote_url || 'No origin configured' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Branch</p>
                            <p class="mt-2 text-sm text-gray-200">{{ repo.branch || 'No commits yet' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-800 bg-gray-950 p-4">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Working Tree</p>
                            <p class="mt-2 text-sm" :class="repo.dirty ? 'text-amber-300' : 'text-emerald-300'">
                                {{ repo.dirty ? `${repo.changed_files} changed file(s)` : 'Clean' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-sm text-gray-300">
                            Ahead: <span class="font-semibold text-gray-100">{{ repo.ahead ?? 0 }}</span>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-sm text-gray-300">
                            Behind: <span class="font-semibold text-gray-100">{{ repo.behind ?? 0 }}</span>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-sm text-gray-300">
                            Last Commit: <span class="font-semibold text-gray-100">{{ repo.last_commit?.subject || 'None' }}</span>
                            <span v-if="repo.last_commit?.relative_time" class="ml-2 text-gray-500">{{ repo.last_commit.relative_time }}</span>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-200 transition-colors hover:border-gray-500 hover:text-white disabled:opacity-50"
                            :disabled="loading.init"
                            @click="initRepository"
                        >
                            {{ loading.init ? 'Initializing...' : 'Init Repository' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-500 disabled:opacity-50"
                            :disabled="loading.pull || !repo.is_repo"
                            @click="pullRepository"
                        >
                            {{ loading.pull ? 'Pulling...' : 'Pull Latest' }}
                        </button>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h2 class="text-sm font-semibold text-gray-200">Clone Remote Repository</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        HTTPS clone only in this first pass. Pick a target directory under your account and optionally pin a branch.
                    </p>

                    <form class="mt-5 space-y-4" @submit.prevent="cloneRepository">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-400">Target Path</label>
                            <input v-model="cloneForm.path" type="text" class="field w-full" placeholder="/public_html/app" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-400">Remote URL</label>
                            <input v-model="cloneForm.remote_url" type="url" class="field w-full" placeholder="https://github.com/owner/repo.git" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-400">Branch</label>
                            <input v-model="cloneForm.branch" type="text" class="field w-full" placeholder="main" />
                        </div>
                        <button
                            type="submit"
                            class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60"
                            :disabled="loading.clone"
                        >
                            {{ loading.clone ? 'Cloning...' : 'Clone Repository' }}
                        </button>
                    </form>
                </section>
            </div>

            <section class="rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h2 class="text-sm font-semibold text-gray-200">Suggested Paths</h2>
                </div>
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Label</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Path</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="path in paths" :key="`${path.id}-${path.path}`" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 text-sm text-gray-200">{{ path.label }}</td>
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-400">{{ path.path }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <button
                                    type="button"
                                    class="text-sm font-semibold text-indigo-300 hover:text-indigo-200"
                                    @click="selectPath(path.path)"
                                >
                                    Use Path
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import axios from 'axios';
import { onMounted, reactive, ref } from 'vue';

const props = defineProps({
    account: Object,
    paths: Array,
});

const selectedPath = ref(props.paths[0]?.path ?? '/public_html');
const repo = ref({
    path: selectedPath.value,
    is_repo: false,
    dirty: false,
    changed_files: 0,
    ahead: 0,
    behind: 0,
});
const error = ref('');
const loading = reactive({
    status: false,
    init: false,
    clone: false,
    pull: false,
});
const cloneForm = reactive({
    path: selectedPath.value,
    remote_url: '',
    branch: '',
});

function normalizeError(err, fallback) {
    return err?.response?.data?.error ?? err?.response?.data?.message ?? fallback;
}

function selectPath(path) {
    selectedPath.value = path;
    cloneForm.path = path;
    loadStatus();
}

async function loadStatus() {
    loading.status = true;
    error.value = '';

    try {
        const { data } = await axios.get(route('my.git.status'), {
            params: { path: selectedPath.value },
        });
        repo.value = data;
    } catch (err) {
        error.value = normalizeError(err, 'Unable to inspect repository status.');
        repo.value = {
            path: selectedPath.value,
            is_repo: false,
            dirty: false,
            changed_files: 0,
            ahead: 0,
            behind: 0,
        };
    } finally {
        loading.status = false;
    }
}

async function initRepository() {
    loading.init = true;
    error.value = '';

    try {
        const { data } = await axios.post(route('my.git.init'), {
            path: selectedPath.value,
        });
        repo.value = data;
    } catch (err) {
        error.value = normalizeError(err, 'Unable to initialize repository.');
    } finally {
        loading.init = false;
    }
}

async function cloneRepository() {
    loading.clone = true;
    error.value = '';

    try {
        const { data } = await axios.post(route('my.git.clone'), {
            path: cloneForm.path,
            remote_url: cloneForm.remote_url,
            branch: cloneForm.branch || null,
        });
        selectedPath.value = cloneForm.path;
        repo.value = data;
    } catch (err) {
        error.value = normalizeError(err, 'Unable to clone repository.');
    } finally {
        loading.clone = false;
    }
}

async function pullRepository() {
    loading.pull = true;
    error.value = '';

    try {
        const { data } = await axios.post(route('my.git.pull'), {
            path: selectedPath.value,
        });
        repo.value = data;
    } catch (err) {
        error.value = normalizeError(err, 'Unable to pull repository updates.');
    } finally {
        loading.pull = false;
    }
}

onMounted(() => {
    loadStatus();
});
</script>

<style scoped>
@reference "tailwindcss";

.field {
    @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
