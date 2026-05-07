<template>
    <AppLayout :title="`DNS - ${domain.domain}`">
        <div class="space-y-6 p-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('admin.domains.show', domain.id)" class="text-gray-500 hover:text-gray-300 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                    </Link>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold font-mono text-gray-100">{{ domain.domain }}</h2>
                            <span v-if="zone" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-900/40 px-2.5 py-0.5 text-xs text-emerald-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Zone active
                            </span>
                            <span v-else class="rounded-full bg-gray-800 px-2.5 py-0.5 text-xs text-gray-500">No zone</span>
                        </div>
                        <p class="text-sm text-gray-400">{{ domain.account?.username }} · {{ domain.node?.name }}</p>
                    </div>
                </div>
            </div>

            <template v-if="!zone">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-8 text-center max-w-lg mx-auto">
                    <div class="h-12 w-12 rounded-full bg-indigo-900/50 flex items-center justify-center mx-auto mb-4">
                        <svg class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold text-gray-100 mb-2">DNS not configured</h3>
                    <p class="text-sm text-gray-400 mb-5">Provisioning will create an authoritative PowerDNS zone with default records for this domain.</p>
                    <Link :href="route('admin.dns.provision', domain.id)" method="post" as="button" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors">
                        Create DNS Zone
                    </Link>
                </div>
            </template>

            <template v-else>
                <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                        <h3 class="text-sm font-semibold text-gray-200">
                            DNS Records
                            <span class="ml-2 text-xs font-normal text-gray-500">{{ records.length }}</span>
                        </h3>
                        <button @click="toggleAddRecord" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                            {{ showAddRecord ? 'Cancel' : '+ Add Record' }}
                        </button>
                    </div>

                    <div v-if="showAddRecord" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                        <form @submit.prevent="submitRecord" class="space-y-3">
                            <div class="grid grid-cols-4 gap-2">
                                <input v-model="createForm.name" type="text" placeholder="Name (@, www)" class="field col-span-2 font-mono text-xs" required />
                                <select v-model="createForm.type" class="field text-xs">
                                    <option v-for="type in recordTypes" :key="type" :value="type">{{ type }}</option>
                                </select>
                                <input v-model.number="createForm.ttl" type="number" min="60" max="86400" placeholder="TTL" class="field text-xs" />
                            </div>
                            <input
                                v-if="requiresPriority(createForm.type)"
                                v-model.number="createForm.priority"
                                type="number"
                                min="0"
                                max="65535"
                                placeholder="Priority"
                                class="field w-full font-mono text-xs"
                            />
                            <textarea v-model="createForm.value" rows="2" placeholder="Record value" class="field w-full font-mono text-xs" required></textarea>
                            <p v-if="createForm.errors.name" class="text-xs text-red-400">{{ createForm.errors.name }}</p>
                            <p v-if="createForm.errors.type" class="text-xs text-red-400">{{ createForm.errors.type }}</p>
                            <p v-if="createForm.errors.ttl" class="text-xs text-red-400">{{ createForm.errors.ttl }}</p>
                            <p v-if="createForm.errors.priority" class="text-xs text-red-400">{{ createForm.errors.priority }}</p>
                            <p v-if="createForm.errors.value" class="text-xs text-red-400">{{ createForm.errors.value }}</p>
                            <button type="submit" :disabled="createForm.processing" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                                {{ createForm.processing ? 'Adding...' : 'Add Record' }}
                            </button>
                        </form>
                    </div>

                    <div class="divide-y divide-gray-800">
                        <div v-for="record in records" :key="record.id" class="flex items-center gap-3 px-5 py-3">
                            <span class="w-12 shrink-0 rounded px-1.5 py-0.5 text-xs font-mono font-semibold text-center" :class="typeClass(record.type)">{{ record.type }}</span>
                            <span class="w-40 shrink-0 font-mono text-xs text-gray-300 truncate">{{ record.name }}</span>
                            <span class="text-xs text-gray-500 shrink-0">{{ record.ttl }}s</span>
                            <span class="flex-1 font-mono text-xs text-gray-400 truncate">{{ formatRecordValue(record) }}</span>
                            <span v-if="record.managed" class="text-xs text-gray-600 shrink-0">managed</span>
                            <button type="button" class="text-xs text-sky-400 transition-colors hover:text-sky-300" @click="openEditRecord(record)">Edit</button>
                            <button v-if="record.can_restore_default" type="button" class="text-xs text-amber-400 transition-colors hover:text-amber-300" @click="restoreRecord(record)">
                                Restore Default
                            </button>
                            <ConfirmButton
                                v-if="!record.managed"
                                :href="route('admin.dns.records.destroy', record.id)"
                                method="delete"
                                label="Delete"
                                :confirm-message="`Delete ${record.type} record for ${record.name}?`"
                                color="red"
                            />
                        </div>
                        <div v-if="records.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">No records yet.</div>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-700/50 bg-gray-800/40 p-4">
                    <p class="text-xs text-gray-500">
                        Point your domain's NS records to this server to use Strata DNS. Nameserver:
                        <span class="font-mono text-gray-300">{{ domain.node?.hostname }}</span>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                        <h3 class="text-sm font-semibold text-gray-200">Import Zone File</h3>
                        <button @click="showImport = !showImport" class="text-xs text-gray-400 hover:text-gray-300 transition-colors">
                            {{ showImport ? 'Cancel' : 'Import BIND zone' }}
                        </button>
                    </div>
                    <div v-if="showImport" class="px-5 py-4">
                        <p class="text-xs text-gray-500 mb-3">Paste a BIND-format zone file. SOA and NS records are skipped. Existing records with the same name and type will be replaced.</p>
                        <form @submit.prevent="submitImport" class="space-y-3">
                            <textarea v-model="importForm.zone_text" rows="10" placeholder="; Paste BIND zone file..." class="field font-mono text-xs" required></textarea>
                            <p v-if="importForm.errors.zone_text" class="text-xs text-red-400">{{ importForm.errors.zone_text }}</p>
                            <button type="submit" :disabled="importForm.processing" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                                {{ importForm.processing ? 'Importing...' : 'Import Records' }}
                            </button>
                        </form>
                    </div>
                </div>
            </template>
        </div>

        <Teleport to="body">
            <div v-if="editingRecord" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="closeEditRecord">
                <div class="w-full max-w-xl rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <div class="mb-4">
                        <h3 class="text-base font-semibold text-gray-100">Edit DNS Record</h3>
                        <p class="mt-1 font-mono text-xs text-gray-400">{{ editingRecord.type }} {{ editingRecord.name }}</p>
                    </div>

                    <form @submit.prevent="submitRecordUpdate" class="space-y-3">
                        <div class="grid grid-cols-3 gap-3">
                            <input :value="editingRecord.name" type="text" class="field col-span-2 font-mono text-xs opacity-70" disabled />
                            <input v-model.number="editForm.ttl" type="number" min="60" max="86400" class="field font-mono text-xs" />
                        </div>
                        <input
                            v-if="requiresPriority(editingRecord.type)"
                            v-model.number="editForm.priority"
                            type="number"
                            min="0"
                            max="65535"
                            placeholder="Priority"
                            class="field w-full font-mono text-xs"
                        />
                        <textarea v-model="editForm.value" rows="4" class="field w-full font-mono text-xs" required></textarea>
                        <p v-if="editForm.errors.ttl" class="text-xs text-red-400">{{ editForm.errors.ttl }}</p>
                        <p v-if="editForm.errors.priority" class="text-xs text-red-400">{{ editForm.errors.priority }}</p>
                        <p v-if="editForm.errors.value" class="text-xs text-red-400">{{ editForm.errors.value }}</p>
                        <div class="flex justify-end gap-3">
                            <button type="button" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800" @click="closeEditRecord">Cancel</button>
                            <button type="submit" :disabled="editForm.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                {{ editForm.processing ? 'Saving...' : 'Save Record' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    domain: Object,
    zone: Object,
    records: Array,
});

const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];
const showAddRecord = ref(false);
const showImport = ref(false);
const editingRecord = ref(null);

const createForm = useForm({ name: '', type: 'A', ttl: 300, value: '', priority: null });
const importForm = useForm({ zone_text: '' });
const editForm = useForm({ ttl: 300, value: '', priority: null });

function requiresPriority(type) {
    return ['MX', 'SRV'].includes(type);
}

function formatRecordValue(record) {
    return requiresPriority(record.type) && record.priority > 0
        ? `${record.priority} ${record.value}`
        : record.value;
}

function typeClass(type) {
    const map = {
        A: 'bg-blue-900/50 text-blue-300',
        AAAA: 'bg-blue-900/50 text-blue-300',
        CNAME: 'bg-purple-900/50 text-purple-300',
        MX: 'bg-amber-900/50 text-amber-300',
        TXT: 'bg-emerald-900/50 text-emerald-300',
        SRV: 'bg-indigo-900/50 text-indigo-300',
        CAA: 'bg-rose-900/50 text-rose-300',
        NS: 'bg-gray-700 text-gray-300',
    };

    return map[type] ?? 'bg-gray-700 text-gray-300';
}

function toggleAddRecord() {
    showAddRecord.value = !showAddRecord.value;
    if (!showAddRecord.value) {
        createForm.reset();
        createForm.clearErrors();
    }
}

function submitRecord() {
    createForm.post(route('admin.dns.records.store', props.zone.id), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createForm.type = 'A';
            createForm.ttl = 300;
            createForm.priority = null;
            showAddRecord.value = false;
        },
    });
}

function submitImport() {
    importForm.post(route('admin.dns.import', props.domain.id), {
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            showImport.value = false;
        },
    });
}

function openEditRecord(record) {
    editingRecord.value = record;
    editForm.ttl = record.ttl;
    editForm.value = record.value;
    editForm.priority = record.priority || null;
    editForm.clearErrors();
}

function closeEditRecord() {
    editingRecord.value = null;
    editForm.reset();
    editForm.clearErrors();
}

function submitRecordUpdate() {
    if (!editingRecord.value) {
        return;
    }

    editForm.put(route('admin.dns.records.update', editingRecord.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEditRecord(),
    });
}

function restoreRecord(record) {
    router.post(route('admin.dns.records.restore', record.id), {}, {
        preserveScroll: true,
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
