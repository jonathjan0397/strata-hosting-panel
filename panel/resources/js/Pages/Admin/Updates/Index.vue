<template>
    <AppLayout title="OS Updates">
        <div class="space-y-6 p-6">

            <!-- Header -->
            <div>
                <h1 class="text-lg font-semibold text-gray-100">OS Updates</h1>
                <p class="mt-0.5 text-sm text-gray-400">Check and apply in-place package upgrades per node. This does not run a dist-upgrade or install new packages.</p>
            </div>

            <!-- Node selector -->
            <div class="flex items-center gap-3">
                <select v-model="selectedNode" @change="checkUpdates" class="field">
                    <option value="">- Select a node -</option>
                    <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }} ({{ n.hostname }})</option>
                </select>
                <button
                    v-if="selectedNode"
                    @click="checkUpdates"
                    :disabled="checking"
                    class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50"
                >{{ checking ? 'Checking...' : 'Refresh' }}</button>
            </div>

            <template v-if="selectedNode">
                <!-- Error -->
                <div v-if="loadError" class="rounded-xl border border-red-700/40 bg-red-900/20 px-4 py-2.5 text-sm text-red-300">{{ loadError }}</div>

                <!-- Package count banner -->
                <div v-if="packages !== null" :class="packages.length ? 'border-yellow-700/40 bg-yellow-900/20 text-yellow-300' : 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300'"
                    class="rounded-xl border px-4 py-3 flex items-center justify-between">
                    <span class="text-sm font-medium">
                        {{ packages.length ? `${packages.length} package${packages.length > 1 ? 's' : ''} available` : 'System is up to date.' }}
                    </span>
                    <button
                        v-if="packages.length"
                        @click="applyUpdates"
                        :disabled="applying"
                        class="rounded-lg bg-yellow-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-yellow-500 disabled:opacity-60 transition-colors"
                    >{{ applying ? 'Upgrading...' : 'Apply Updates' }}</button>
                </div>

                <!-- Apply result -->
                <div v-if="applyResult" class="rounded-xl border border-gray-700 bg-gray-900 p-4">
                    <p class="text-xs font-semibold mb-2" :class="applyResult.status === 'upgraded' ? 'text-emerald-400' : 'text-red-400'">
                        {{ applyResult.status === 'upgraded' ? 'Upgrade complete.' : 'Upgrade failed.' }}
                    </p>
                    <pre class="text-xs text-gray-400 whitespace-pre-wrap max-h-64 overflow-y-auto">{{ applyResult.output }}</pre>
                </div>

                <!-- Package list -->
                <div v-if="packages && packages.length" class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Package</th>
                                <th class="px-5 py-3">Current</th>
                                <th class="px-5 py-3">New</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="pkg in packages" :key="pkg.name" class="hover:bg-gray-800/40">
                                <td class="px-5 py-3 font-mono text-gray-200">{{ pkg.name }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ pkg.old_version || '-' }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-emerald-400">{{ pkg.new_version || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    nodes: { type: Array, default: () => [] },
});

const selectedNode = ref('');
const packages = ref(null);
const checking = ref(false);
const applying = ref(false);
const loadError = ref('');
const applyResult = ref(null);

async function checkUpdates() {
    if (!selectedNode.value) return;
    checking.value = true;
    loadError.value = '';
    applyResult.value = null;
    try {
        const res = await fetch(route('admin.updates.available') + '?node_id=' + selectedNode.value);
        const data = await res.json();
        if (data.error) { loadError.value = data.error; packages.value = []; }
        else { packages.value = data.packages ?? []; }
    } catch (e) {
        loadError.value = 'Failed to check updates.';
    } finally {
        checking.value = false;
    }
}

async function applyUpdates() {
    if (!confirm('Apply all pending updates on this node? The upgrade runs in the background and may take a few minutes.')) return;
    applying.value = true;
    applyResult.value = null;
    try {
        const res = await fetch(route('admin.updates.apply'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ node_id: selectedNode.value }),
        });
        applyResult.value = await res.json();
        if (applyResult.value.status === 'upgraded') await checkUpdates();
    } catch (e) {
        applyResult.value = { status: 'error', output: 'Request failed.' };
    } finally {
        applying.value = false;
    }
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
