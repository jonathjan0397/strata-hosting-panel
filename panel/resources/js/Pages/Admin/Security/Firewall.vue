<template>
    <AppLayout title="Firewall Rules">
        <div class="space-y-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold text-gray-100">Firewall Rules</h1>
                    <p class="mt-0.5 text-sm text-gray-400">Manage UFW rules and IP blocks per node.</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <select v-model="selectedNode" @change="loadRules" class="field">
                    <option value="">Select a node</option>
                    <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }} ({{ n.hostname }})</option>
                </select>
                <button
                    v-if="selectedNode"
                    @click="loadRules"
                    :disabled="loading"
                    class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 transition-colors hover:bg-gray-800 disabled:opacity-50"
                >{{ loading ? 'Refreshing...' : 'Refresh' }}</button>
            </div>

            <template v-if="selectedNode">
                <div
                    v-if="status"
                    :class="status === 'active' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300' : 'border-yellow-700/40 bg-yellow-900/20 text-yellow-300'"
                    class="rounded-xl border px-4 py-2.5 text-sm font-medium"
                >
                    UFW status: {{ status }}
                </div>

                <div v-if="error" class="rounded-xl border border-red-700/40 bg-red-900/20 px-4 py-2.5 text-sm text-red-300">{{ error }}</div>

                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <div class="mb-4">
                        <h2 class="text-sm font-semibold text-gray-300">IP Blocker</h2>
                        <p class="mt-1 text-xs text-gray-500">Deny all inbound traffic from an IP address or CIDR range on this node.</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">IP address or CIDR</label>
                            <input v-model="blockForm.ip" type="text" placeholder="203.0.113.10 or 203.0.113.0/24" class="field w-72" @keyup.enter="blockIp" />
                        </div>
                        <button
                            @click="blockIp"
                            :disabled="blocking || !blockForm.ip"
                            class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-600 disabled:opacity-60"
                        >{{ blocking ? 'Blocking...' : 'Block IP' }}</button>
                    </div>
                    <p v-if="blockError" class="mt-2 text-xs text-red-400">{{ blockError }}</p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h2 class="mb-4 text-sm font-semibold text-gray-300">Add Rule</h2>
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">Type</label>
                            <select v-model="form.type" class="field">
                                <option value="allow">Allow</option>
                                <option value="deny">Deny</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">Port</label>
                            <input v-model="form.port" type="text" placeholder="80 or 8000:9000" class="field w-36" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">Protocol</label>
                            <select v-model="form.proto" class="field">
                                <option value="">Any</option>
                                <option value="tcp">TCP</option>
                                <option value="udp">UDP</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">From IP (optional)</label>
                            <input v-model="form.from" type="text" placeholder="1.2.3.4" class="field w-36" />
                        </div>
                        <button
                            @click="addRule"
                            :disabled="adding || !form.port"
                            class="btn-primary"
                        >{{ adding ? 'Adding...' : 'Add Rule' }}</button>
                    </div>
                    <p v-if="addError" class="mt-2 text-xs text-red-400">{{ addError }}</p>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
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
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">Loading...</td>
                            </tr>
                            <tr v-else-if="!rules.length">
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">No rules found.</td>
                            </tr>
                            <tr v-for="rule in rules" :key="rule.number" class="hover:bg-gray-800/40">
                                <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ rule.number }}</td>
                                <td class="px-5 py-3 font-mono text-gray-200">{{ rule.to }}</td>
                                <td class="px-5 py-3">
                                    <span
                                        :class="rule.action.includes('ALLOW') ? 'bg-emerald-900/40 text-emerald-400' : 'bg-red-900/40 text-red-400'"
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    >{{ rule.action }}</span>
                                </td>
                                <td class="px-5 py-3 text-gray-400">{{ rule.from }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button @click="deleteRule(rule.number)" class="text-xs text-red-500 transition-colors hover:text-red-400">Delete</button>
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

defineProps({
    nodes: { type: Array, default: () => [] },
});

const selectedNode = ref('');
const rules = ref([]);
const status = ref('');
const loading = ref(false);
const error = ref('');
const adding = ref(false);
const addError = ref('');
const blocking = ref(false);
const blockError = ref('');

const form = ref({ type: 'allow', port: '', proto: '', from: '' });
const blockForm = ref({ ip: '' });

async function loadRules() {
    if (!selectedNode.value) return;
    loading.value = true;
    error.value = '';
    try {
        const res = await fetch(route('admin.security.firewall.rules') + '?node_id=' + selectedNode.value);
        const data = await res.json();
        if (data.error) {
            error.value = data.error;
            rules.value = [];
        } else {
            rules.value = data.rules ?? [];
            status.value = data.status ?? '';
        }
    } catch (e) {
        error.value = 'Failed to load rules.';
    } finally {
        loading.value = false;
    }
}

async function addRule() {
    addError.value = '';
    adding.value = true;
    try {
        const res = await fetch(route('admin.security.firewall.add'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ node_id: selectedNode.value, ...form.value }),
        });
        if (!res.ok) {
            const d = await res.json().catch(() => ({}));
            addError.value = d.message ?? 'Error adding rule.';
        } else {
            form.value.port = '';
            form.value.from = '';
            await loadRules();
        }
    } catch (e) {
        addError.value = 'Request failed.';
    } finally {
        adding.value = false;
    }
}

async function blockIp() {
    blockError.value = '';
    blocking.value = true;
    try {
        const res = await fetch(route('admin.security.firewall.block-ip'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ node_id: selectedNode.value, ip: blockForm.value.ip }),
        });
        if (!res.ok) {
            const d = await res.json().catch(() => ({}));
            blockError.value = d.message ?? 'Error blocking IP.';
        } else {
            blockForm.value.ip = '';
            await loadRules();
        }
    } catch (e) {
        blockError.value = 'Request failed.';
    } finally {
        blocking.value = false;
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
