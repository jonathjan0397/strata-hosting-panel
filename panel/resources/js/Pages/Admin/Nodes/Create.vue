<template>
    <AppLayout title="Add Node">
        <div class="max-w-xl">
            <div class="mb-6 flex items-center gap-3">
                <Link :href="route('admin.nodes.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <h2 class="text-lg font-semibold text-gray-100">Add Node</h2>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-6">
                <form @submit.prevent="submit" class="space-y-6">

                    <!-- Connection -->
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Connection</h3>
                        <div class="space-y-4">
                            <FormField label="Name" :error="form.errors.name">
                                <input v-model="form.name" type="text" placeholder="e.g. us-east-1" class="field" required />
                            </FormField>
                            <div class="grid grid-cols-2 gap-3">
                                <FormField label="Hostname" :error="form.errors.hostname">
                                    <input v-model="form.hostname" type="text" placeholder="node1.example.com" class="field" required />
                                </FormField>
                                <FormField label="IP Address" :error="form.errors.ip_address">
                                    <input v-model="form.ip_address" type="text" placeholder="1.2.3.4" class="field" required />
                                </FormField>
                            </div>
                            <FormField label="Agent Port" :error="form.errors.port">
                                <input v-model.number="form.port" type="number" min="1" max="65535" class="field w-32" />
                            </FormField>
                        </div>
                    </div>

                    <hr class="border-gray-800" />

                    <!-- Web Server -->
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Web Server</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                v-for="ws in webServers"
                                :key="ws"
                                type="button"
                                @click="form.web_server = ws"
                                :class="[
                                    'flex items-center gap-2.5 rounded-lg border px-4 py-3 text-sm font-medium transition-colors',
                                    form.web_server === ws
                                        ? 'border-indigo-500 bg-indigo-900/30 text-indigo-300'
                                        : 'border-gray-700 bg-gray-800 text-gray-400 hover:border-gray-600 hover:text-gray-200'
                                ]"
                            >
                                <span class="font-mono text-base">{{ ws === 'nginx' ? 'N' : 'A' }}</span>
                                <span class="capitalize">{{ ws }}</span>
                            </button>
                        </div>
                        <p v-if="form.errors.web_server" class="mt-1 text-xs text-red-400">{{ form.errors.web_server }}</p>
                    </div>

                    <hr class="border-gray-800" />

                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">DNS Role</h3>
                        <p class="text-xs text-gray-500 mb-3">Enable this only for nodes that should host authoritative DNS zones and nameserver records.</p>
                        <label class="flex items-start gap-3 rounded-lg border border-gray-700 bg-gray-800 px-4 py-3">
                            <input v-model="form.hosts_dns" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-600 bg-gray-900 text-indigo-500 focus:ring-indigo-500" />
                            <span>
                                <span class="block text-sm font-medium text-gray-200">This node hosts DNS</span>
                                <span class="mt-1 block text-xs text-gray-500">Used for nameserver glue, authoritative zone serving, and DNS sync targets.</span>
                            </span>
                        </label>
                        <p v-if="form.errors.hosts_dns" class="mt-1 text-xs text-red-400">{{ form.errors.hosts_dns }}</p>
                    </div>

                    <hr class="border-gray-800" />

                    <!-- Accelerators -->
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Accelerators</h3>
                        <p class="text-xs text-gray-500 mb-3">Select what's installed on this node. Accounts will have these available.</p>
                        <div class="grid grid-cols-3 gap-3">
                            <button
                                v-for="acc in accelerators"
                                :key="acc"
                                type="button"
                                @click="toggleAccelerator(acc)"
                                :class="[
                                    'flex flex-col items-center gap-1 rounded-lg border px-3 py-3 text-xs font-medium transition-colors',
                                    form.accelerators.includes(acc)
                                        ? 'border-emerald-500 bg-emerald-900/20 text-emerald-300'
                                        : 'border-gray-700 bg-gray-800 text-gray-400 hover:border-gray-600 hover:text-gray-200'
                                ]"
                            >
                                <span class="font-mono font-semibold text-sm">{{ accLabel(acc) }}</span>
                                <span class="capitalize text-gray-500" :class="form.accelerators.includes(acc) ? 'text-emerald-500' : ''">{{ acc }}</span>
                            </button>
                        </div>
                        <p v-if="form.errors.accelerators" class="mt-1 text-xs text-red-400">{{ form.errors.accelerators }}</p>
                    </div>

                    <div class="pt-2 flex gap-3">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                        >
                            {{ form.processing ? 'Creating…' : 'Create Node' }}
                        </button>
                        <Link :href="route('admin.nodes.index')" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:text-gray-200 transition-colors">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>

            <div class="mt-4 rounded-xl border border-gray-700/50 bg-gray-800/40 p-4">
                <p class="text-xs text-gray-400">
                    After creating the node, you'll get an install command to run on the remote server.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';

const props = defineProps({
    webServers:   Array,
    accelerators: Array,
});

const form = useForm({
    name:         '',
    hostname:     '',
    ip_address:   '',
    port:         8743,
    web_server:   'nginx',
    hosts_dns:    false,
    accelerators: [],
});

function toggleAccelerator(acc) {
    const idx = form.accelerators.indexOf(acc);
    if (idx === -1) {
        form.accelerators.push(acc);
    } else {
        form.accelerators.splice(idx, 1);
    }
}

const accLabels = { varnish: 'V$', redis: 'R$', memcached: 'M$' };
function accLabel(acc) {
    return { varnish: 'V⚡', redis: 'R⚡', memcached: 'M⚡' }[acc] ?? acc;
}

function submit() {
    form.post(route('admin.nodes.store'));
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
