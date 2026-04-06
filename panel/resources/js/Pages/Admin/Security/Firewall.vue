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

                <!-- Fail2ban section -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-300">Fail2ban</h2>
                        <button
                            @click="loadFail2ban"
                            :disabled="f2bLoading"
                            class="rounded-lg border border-gray-700 px-3 py-1.5 text-xs text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50"
                        >{{ f2bLoading ? 'Loading…' : 'Refresh' }}</button>
                    </div>

                    <div v-if="f2bError" class="rounded-lg border border-red-700/40 bg-red-900/20 px-3 py-2 text-xs text-red-300">{{ f2bError }}</div>

                    <div v-if="f2bJails.length" class="space-y-3">
                        <div v-for="jail in f2bJails" :key="jail.name" class="rounded-lg border border-gray-700 bg-gray-800/60 p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-mono text-sm text-gray-200">{{ jail.name }}</span>
                                <span :class="jail.currently_failed > 0 ? 'bg-yellow-900/40 text-yellow-400' : 'bg-gray-700/60 text-gray-400'"
                                    class="rounded-full px-2 py-0.5 text-xs">
                                    {{ jail.currently_failed }} currently failed · {{ jail.total_banned }} total banned
                                </span>
                            </div>

                            <!-- Banned IPs -->
                            <div v-if="jail.banned_ips && jail.banned_ips.length" class="mt-2 space-y-1">
                                <div v-for="ip in jail.banned_ips" :key="ip"
                                    class="flex items-center justify-between rounded bg-gray-900/60 px-3 py-1.5">
                                    <span class="font-mono text-xs text-gray-300">{{ ip }}</span>
                                    <button
                                        @click="unbanIp(jail.name, ip)"
                                        class="text-xs text-amber-500 hover:text-amber-400 transition-colors"
                                    >Unban</button>
                                </div>
                            </div>
                            <p v-else class="text-xs text-gray-500 mt-1">No banned IPs.</p>

                            <!-- Manual unban -->
                            <div class="flex items-center gap-2 mt-3">
                                <input
                                    v-model="unbanForm[jail.name]"
                                    type="text"
                                    placeholder="Enter IP to unban"
                                    class="field flex-1 text-xs py-1.5"
                                    @keyup.enter="unbanIp(jail.name, unbanForm[jail.name])"
                                />
                                <button
                                    @click="unbanIp(jail.name, unbanForm[jail.name])"
                                    :disabled="!unbanForm[jail.name]"
                                    class="rounded-lg bg-amber-600/80 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-500 disabled:opacity-50 transition-colors"
                                >Unban</button>
                            </div>
                        </div>
                    </div>
                    <p v-else-if="!f2bLoading && !f2bError" class="text-sm text-gray-500">
                        {{ f2bJails.length === 0 && selectedNode ? 'Click Refresh to load fail2ban status.' : 'No jails found.' }}
                    </p>
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

// Fail2ban state
const f2bJails   = ref([]);
const f2bLoading = ref(false);
const f2bError   = ref('');
const unbanForm  = ref({});

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

async function loadFail2ban() {
    if (!selectedNode.value) return;
    f2bLoading.value = true;
    f2bError.value   = '';
    try {
        const res  = await fetch(route('admin.security.fail2ban') + '?node_id=' + selectedNode.value);
        const data = await res.json();
        if (data.error) { f2bError.value = data.error; f2bJails.value = []; }
        else { f2bJails.value = data.jails ?? []; }
    } catch (e) {
        f2bError.value = 'Failed to load fail2ban status.';
    } finally {
        f2bLoading.value = false;
    }
}

async function unbanIp(jail, ip) {
    if (!ip) return;
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const res = await fetch(route('admin.security.unban'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ node_id: selectedNode.value, jail, ip }),
    });
    if (res.ok) {
        unbanForm.value[jail] = '';
        await loadFail2ban();
    } else {
        const d = await res.json().catch(() => ({}));
        f2bError.value = d.message ?? `Failed to unban ${ip}.`;
    }
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
