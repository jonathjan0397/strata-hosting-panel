<template>
    <AppLayout :title="`Mailbox Filters - ${mailbox.email}`">
        <div class="space-y-6 p-6">
        <PageHeader
            eyebrow="Email"
            title="Mailbox Filters"
            :description="mailbox.email"
        >
            <template #actions>
                <Link :href="route('my.email.domain', mailbox.domain.id)" class="text-sm font-medium text-indigo-400 transition-colors hover:text-indigo-300">
                    Back to Mail
                </Link>
            </template>
        </PageHeader>



        <div class="grid gap-6 xl:grid-cols-[24rem,1fr]">
            <div class="space-y-6">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="mb-2 text-sm font-semibold text-gray-300">Spam Policy</h3>
                    <p class="mb-4 text-sm text-gray-400">Choose what happens when Rspamd marks mail as spam for this mailbox.</p>
                    <form @submit.prevent="updateSpamPolicy" class="space-y-4">
                        <FormField label="Action" :error="spamPolicyForm.errors.spam_action">
                            <select v-model="spamPolicyForm.spam_action" class="field">
                                <option v-for="option in spamActionOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </FormField>
                        <button type="submit" :disabled="spamPolicyForm.processing" class="btn-primary w-full">
                            {{ spamPolicyForm.processing ? 'Updating...' : 'Update Spam Policy' }}
                        </button>
                    </form>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="mb-2 text-sm font-semibold text-gray-300">Mail Archive</h3>
                    <p class="mb-4 text-sm text-gray-400">Keep a server-side copy of incoming messages in the mailbox Archive folder before other filters run.</p>
                    <form @submit.prevent="updateArchivePolicy" class="space-y-4">
                        <label class="flex items-start gap-3 rounded-lg border border-gray-800 bg-gray-950 p-3 text-sm text-gray-300">
                            <input v-model="archivePolicyForm.archive_enabled" type="checkbox" class="mt-0.5 rounded border-gray-600 bg-gray-800 text-indigo-600" />
                            <span>
                                <span class="block font-medium text-gray-200">Archive incoming mail</span>
                                <span class="mt-1 block text-xs leading-5 text-gray-500">Messages are copied to <span class="font-mono text-gray-300">Archive</span>; normal delivery, spam, and filter actions continue.</span>
                            </span>
                        </label>
                        <button type="submit" :disabled="archivePolicyForm.processing" class="btn-primary w-full">
                            {{ archivePolicyForm.processing ? 'Updating...' : 'Update Archive Policy' }}
                        </button>
                    </form>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="mb-4 text-sm font-semibold text-gray-300">New Filter</h3>
                    <form @submit.prevent="createFilter" class="space-y-4">
                        <FormField label="Name" :error="createForm.errors.name">
                            <input v-model="createForm.name" type="text" class="field" placeholder="Catch invoice mail" />
                        </FormField>
                        <FormField label="Match Field" :error="createForm.errors.match_field">
                            <select v-model="createForm.match_field" class="field">
                                <option v-for="option in fieldOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </FormField>
                        <FormField label="Operator" :error="createForm.errors.match_operator">
                            <select v-model="createForm.match_operator" class="field">
                                <option v-for="option in operatorOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </FormField>
                        <FormField label="Value" :error="createForm.errors.match_value">
                            <input v-model="createForm.match_value" type="text" class="field" placeholder="invoice" />
                        </FormField>
                        <FormField label="Action" :error="createForm.errors.action">
                            <select v-model="createForm.action" class="field">
                                <option v-for="option in actionOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </FormField>
                        <FormField v-if="createForm.action === 'redirect'" label="Redirect To" :error="createForm.errors.action_value">
                            <input v-model="createForm.action_value" type="email" class="field" placeholder="ops@example.com" />
                        </FormField>
                        <label class="flex items-center gap-2 text-sm text-gray-300">
                            <input v-model="createForm.active" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600" />
                            Enabled
                        </label>
                        <button type="submit" :disabled="createForm.processing" class="btn-primary w-full">
                            Create Filter
                        </button>
                    </form>
                </div>
            </div>

            <div class="space-y-4">
                <div v-for="filter in filters" :key="filter.id" class="rounded-xl border border-gray-800 bg-gray-900">
                    <div class="flex items-center justify-between border-b border-gray-800 px-5 py-3.5">
                        <div>
                            <p class="text-sm font-medium text-gray-200">{{ filter.name }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ describeFilter(filter) }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span :class="filter.active ? 'bg-emerald-900/40 text-emerald-300' : 'bg-gray-800 text-gray-400'" class="rounded-full px-2 py-0.5 text-xs font-medium">
                                {{ filter.active ? 'Enabled' : 'Disabled' }}
                            </span>
                            <button type="button" class="text-xs text-indigo-400 transition-colors hover:text-indigo-300" @click="toggleEdit(filter.id)">
                                {{ editingId === filter.id ? 'Close' : 'Edit' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="editingId === filter.id" class="px-5 py-4">
                        <form @submit.prevent="updateFilter(filter.id)" class="grid gap-4 md:grid-cols-2">
                            <FormField label="Name" :error="forms[filter.id].errors.name" class="md:col-span-2">
                                <input v-model="forms[filter.id].name" type="text" class="field" />
                            </FormField>
                            <FormField label="Match Field" :error="forms[filter.id].errors.match_field">
                                <select v-model="forms[filter.id].match_field" class="field">
                                    <option v-for="option in fieldOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </FormField>
                            <FormField label="Operator" :error="forms[filter.id].errors.match_operator">
                                <select v-model="forms[filter.id].match_operator" class="field">
                                    <option v-for="option in operatorOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </FormField>
                            <FormField label="Value" :error="forms[filter.id].errors.match_value" class="md:col-span-2">
                                <input v-model="forms[filter.id].match_value" type="text" class="field" />
                            </FormField>
                            <FormField label="Action" :error="forms[filter.id].errors.action">
                                <select v-model="forms[filter.id].action" class="field">
                                    <option v-for="option in actionOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </FormField>
                            <FormField v-if="forms[filter.id].action === 'redirect'" label="Redirect To" :error="forms[filter.id].errors.action_value">
                                <input v-model="forms[filter.id].action_value" type="email" class="field" />
                            </FormField>
                            <label class="flex items-center gap-2 text-sm text-gray-300 md:col-span-2">
                                <input v-model="forms[filter.id].active" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-600" />
                                Enabled
                            </label>
                            <div class="flex items-center gap-3 md:col-span-2">
                                <button type="submit" :disabled="forms[filter.id].processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60">
                                    Save Filter
                                </button>
                                <ConfirmButton
                                    :href="route('my.email.filters.destroy', filter.id)"
                                    method="delete"
                                    label="Delete"
                                    color="red"
                                    :confirm-message="`Delete filter ${filter.name}?`"
                                />
                            </div>
                        </form>
                    </div>
                </div>

                <div v-if="filters.length === 0" class="rounded-xl border border-gray-800 bg-gray-900 px-5 py-8 text-center text-sm text-gray-500">
                    No mailbox filters yet.
                </div>
            </div>
        </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    mailbox: Object,
    filters: Array,
    fieldOptions: Array,
    operatorOptions: Array,
    actionOptions: Array,
    spamActionOptions: Array,
});

const editingId = ref(null);
const spamPolicyForm = useForm({
    spam_action: props.mailbox.spam_action ?? 'inbox',
});
const archivePolicyForm = useForm({
    archive_enabled: props.mailbox.archive_enabled ?? false,
});
const createForm = useForm({
    name: '',
    match_field: 'subject',
    match_operator: 'contains',
    match_value: '',
    action: 'discard',
    action_value: '',
    active: true,
});

const forms = reactive({});
for (const filter of props.filters) {
    forms[filter.id] = useForm({
        name: filter.name,
        match_field: filter.match_field,
        match_operator: filter.match_operator,
        match_value: filter.match_value,
        action: filter.action,
        action_value: filter.action_value ?? '',
        active: filter.active,
    });
}

function createFilter() {
    createForm.post(route('my.email.filters.store', props.mailbox.id), {
        onSuccess: () => createForm.reset('name', 'match_value', 'action_value'),
    });
}

function updateSpamPolicy() {
    spamPolicyForm.put(route('my.email.spam-policy.update', props.mailbox.id), {
        preserveScroll: true,
    });
}

function updateArchivePolicy() {
    archivePolicyForm.put(route('my.email.archive-policy.update', props.mailbox.id), {
        preserveScroll: true,
    });
}

function updateFilter(filterId) {
    forms[filterId].put(route('my.email.filters.update', filterId), {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function toggleEdit(filterId) {
    editingId.value = editingId.value === filterId ? null : filterId;
}

function describeFilter(filter) {
    const action = filter.action === 'redirect'
        ? `redirect to ${filter.action_value}`
        : 'discard message';

    return `${filter.match_field} ${filter.match_operator} "${filter.match_value}" -> ${action}`;
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
