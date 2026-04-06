<template>
    <AppLayout title="Delivery Tracking">
        <div class="space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-100">Delivery Tracking</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        Search recent mail delivery log activity for a hosted domain or mailbox on
                        <span v-if="account.node" class="font-mono text-gray-500">{{ account.node.name }}</span>.
                    </p>
                </div>
                <Link :href="route('my.email.spam')" class="text-sm text-indigo-400 transition-colors hover:text-indigo-300">
                    Spam Overview
                </Link>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <form class="grid gap-4 md:grid-cols-4" @submit.prevent="loadEntries">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-400">Domain</label>
                        <select v-model="filters.domain_id" class="field w-full">
                            <option :value="null">Any domain</option>
                            <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.domain }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-400">Mailbox</label>
                        <select v-model="filters.mailbox_id" class="field w-full">
                            <option :value="null">Any mailbox</option>
                            <option v-for="mailbox in filteredMailboxes" :key="mailbox.id" :value="mailbox.id">{{ mailbox.email }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-400">Log source</label>
                        <select v-model="filters.service" class="field w-full">
                            <option value="postfix">Postfix</option>
                            <option value="dovecot">Dovecot</option>
                            <option value="all">All mail logs</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-400">Lines</label>
                        <select v-model="filters.lines" class="field w-full">
                            <option :value="60">60</option>
                            <option :value="120">120</option>
                            <option :value="200">200</option>
                        </select>
                    </div>
                    <div class="md:col-span-4 flex items-center gap-3">
                        <button
                            type="submit"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-50"
                            :disabled="loading"
                        >
                            {{ loading ? 'Searching...' : 'Search Delivery Logs' }}
                        </button>
                        <span v-if="summary" class="text-sm text-gray-500">{{ summary }}</span>
                    </div>
                </form>

                <div v-if="error" class="mt-4 rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-400">
                    {{ error }}
                </div>

                <pre class="mt-4 max-h-[32rem] overflow-auto rounded-lg border border-gray-800 bg-gray-950 p-4 text-xs leading-5 text-gray-300">{{ formattedEntries }}</pre>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    account: Object,
    domains: Array,
    mailboxes: Array,
});

const loading = ref(false);
const error = ref('');
const entries = ref([]);
const query = ref('');
const checkedLogs = ref([]);
const filters = reactive({
    domain_id: null,
    mailbox_id: null,
    service: 'postfix',
    lines: 120,
});

const filteredMailboxes = computed(() => {
    if (!filters.domain_id) {
        return props.mailboxes;
    }

    return props.mailboxes.filter((mailbox) => mailbox.domain_id === filters.domain_id);
});

const summary = computed(() => {
    if (!query.value) {
        return '';
    }

    return `${entries.value.length} matching line(s) for ${query.value}${checkedLogs.value.length ? ` in ${checkedLogs.value.join(', ')}` : ''}`;
});

const formattedEntries = computed(() => {
    if (!entries.value.length) {
        return 'No matching delivery log entries yet.';
    }

    return entries.value.join('\n');
});

async function loadEntries() {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await axios.get(route('my.email.delivery.search'), {
            params: {
                domain_id: filters.domain_id,
                mailbox_id: filters.mailbox_id,
                service: filters.service,
                lines: filters.lines,
            },
        });

        query.value = data.query ?? '';
        checkedLogs.value = data.checked_logs ?? [];
        entries.value = data.entries ?? [];
    } catch (err) {
        error.value = err?.response?.data?.error ?? 'Unable to load delivery tracking logs.';
        query.value = '';
        checkedLogs.value = [];
        entries.value = [];
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
@reference "tailwindcss";

.field {
    @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
