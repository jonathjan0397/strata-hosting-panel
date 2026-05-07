<template>
    <AppLayout :title="`DNS - ${domain.domain}`">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="DNS"
                :title="domain.domain"
                description="Manage DNS records, update managed defaults, and import BIND zone data for this domain."
            >
                <template #actions>
                    <Link :href="route('my.domains.show', domain.id)" class="text-sm font-medium text-indigo-400 transition-colors hover:text-indigo-300">
                        Back to Domain
                    </Link>
                </template>
            </PageHeader>

            <template v-if="!zone">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-8 text-center max-w-lg mx-auto">
                    <h3 class="text-base font-semibold text-gray-200 mb-2">DNS zone not configured</h3>
                    <p class="text-sm text-gray-400">Your administrator needs to provision a DNS zone for this domain.</p>
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
                                :href="route('my.dns.records.destroy', record.id)"
                                method="delete"
                                label="Delete"
                                :confirm-message="`Delete ${record.type} record for ${record.name}?`"
                                color="red"
                            />
                        </div>
                        <div v-if="records.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">
                            No records yet. Use the button above to add one.
                        </div>
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
                            <textarea v-model="importForm.zone_text" rows="10" placeholder="; Paste zone file here..." class="field font-mono text-xs" required></textarea>
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
import PageHeader from '@/Components/PageHeader.vue';

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
    createForm.post(route('my.dns.records.store', props.zone.id), {
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
    importForm.post(route('my.dns.import', props.domain.id), {
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

    editForm.put(route('my.dns.records.update', editingRecord.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEditRecord(),
    });
}

function restoreRecord(record) {
    router.post(route('my.dns.records.restore', record.id), {}, {
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
