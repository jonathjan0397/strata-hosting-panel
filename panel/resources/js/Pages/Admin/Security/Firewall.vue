<template>
    <AppLayout title="Firewall Rules">
        <div class="space-y-6 p-6">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold text-gray-100">Firewall Rules</h1>
                    <p class="mt-0.5 text-sm text-gray-400">Manage UFW rules per node.</p>
                </div>
            </div>

            <!-- Node selector -->
            <div class="flex items-center gap-3">
                <select v-model="selectedNode" @change="loadRules" class="field">
                    <option value="">— Select a node —</option>
                    <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }} ({{ n.hostname }})</option>
                </select>
                <button
                    v-if="selectedNode"
                    @click="loadRules"
                    :disabled="loading"
                    class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50"
                >Refresh</button>
            </div>

            <template v-if="selectedNode">
                <!-- Status banner -->
                <div v-if="status" :class="status === 'active' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300' : 'border-yellow-700/40 bg-yellow-900/20 text-yellow-300'"
                    class="rounded-xl border px-4 py-2.5 text-sm font-medium">
                    UFW status: {{ status }}
                </div>

                <!-- Error -->
                <div v-if="error" class="rounded-xl border border-red-700/40 bg-red-900/20 px-4 py-2.5 text-sm text-red-300">{{ error }}</div>

                <!-- Add rule form -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h2 class="text-sm font-semibold text-gray-300 mb-4">Add Rule</h2>
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Type</label>
                            <select v-model="form.type" class="field">
                                <option value="allow">Allow</option>
                                <option value="deny">Deny</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Port</label>
                            <input v-model="form.port" type="text" placeholder="80 or 8000:9000" class="field w-36" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Protocol</label>
                            <select v-model="form.proto" class="field">
                                <option value="">Any</option>
                                <option value="tcp">TCP</option>
                                <option value="udp">UDP</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">From IP (optional)</label>
                            <input v-model="form.from" type="text" placeholder="1.2.3.4" class="field w-36" />
                        </div>
                        <button
                            @click="addRule"
                            :disabled="adding || !form.port"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                        >{{ adding ? 'Adding…' : 'Add Rule' }}</button>
                    </div>
                    <p v-if="addError" class="mt-2 text-xs text-red-400">{{ addError }}</p>
                </div>

                <!-- Rules table -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">#</th>
                                <th class="px-5 py-3">To</th>
                                <th class="px-5 py-3">Action</th>
                                <th class="px-5 py-3">From</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-if="loading">
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">Loading…</td>
                            </tr>
                            <tr v-else-if="!rules.length">
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">No rules found.</td>
                            </tr>
                            <tr v-for="rule in rules" :key="rule.number" class="hover:bg-gray-800/40">
                                <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ rule.number }}</td>
                                <td class="px-5 py-3 font-mono text-gray-200">{{ rule.to }}</td>
                                <td class="px-5 py-3">
                                    <span :class="rule.action.includes('ALLOW') ? 'bg-emerald-900/40 text-emerald-400' : 'bg-red-900/40 text-red-400'"
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold">{{ rule.action }}</span>
                                </td>
                                <td class="px-5 py-3 text-gray-400">{{ rule.from }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button
                                        @click="deleteRule(rule.number)"
                                        class="text-xs text-red-500 hover:text-red-400 transition-colors"
                                    >Delete</button>
                                </td>
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

const props = defineProps({
    nodes: { type: Array, default: () => [] },
});

const selectedNode = ref('');
const rules    = ref([]);
const status   = ref('');
const loading  = ref(false);
const error    = ref('');
const adding   = ref(false);
const addError = ref('');

const form = ref({ type: 'allow', port: '', proto: '', from: '' });

async function loadRules() {
    if (!selectedNode.value) return;
    loading.value = true;
    error.value   = '';
    try {
        const res = await fetch(route('admin.security.firewall.rules') + '?node_id=' + selectedNode.value);
        const data = await res.json();
        if (data.error) { error.value = data.error; rules.value = []; }
        else { rules.value = data.rules ?? []; status.value = data.status ?? ''; }
    } catch (e) {
        error.value = 'Failed to load rules.';
    } finally {
        loading.value = false;
    }
}

async function addRule() {
    addError.value = '';
    adding.value   = true;
    try {
        const res = await fetch(route('admin.security.firewall.add'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ node_id: selectedNode.value, ...form.value }),
        });
        if (!res.ok) { const d = await res.json(); addError.value = d.message ?? 'Error adding rule.'; }
        else { form.value.port = ''; form.value.from = ''; await loadRules(); }
    } catch (e) {
        addError.value = 'Request failed.';
    } finally {
        adding.value = false;
    }
}

async function deleteRule(number) {
    if (!confirm(`Delete rule #${number}?`)) return;
    const res = await fetch(route('admin.security.firewall.delete'), {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: JSON.stringify({ node_id: selectedNode.value, number }),
    });
    if (res.ok) await loadRules();
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
