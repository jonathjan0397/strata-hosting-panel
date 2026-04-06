<template>
    <AppLayout title="Databases">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Databases"
                title="MySQL Databases"
                description="Create databases and users for your applications, then rotate credentials when needed."
            />

            <div class="grid gap-4 md:grid-cols-3">
                <StatCard label="Databases" :value="databases.length" color="indigo" />
                <StatCard label="Package Limit" :value="databaseLimit" color="gray" />
                <StatCard label="Remaining" :value="remainingDatabases" color="emerald" />
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-4 text-sm font-semibold text-gray-300">Create Database</h3>
                <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Database name" :error="form.errors.db_name">
                        <input
                            v-model="form.db_name"
                            type="text"
                            placeholder="myapp_db"
                            class="field w-full"
                        />
                    </FormField>
                    <FormField label="DB username" :error="form.errors.db_user">
                        <input
                            v-model="form.db_user"
                            type="text"
                            placeholder="myapp_user"
                            class="field w-full"
                        />
                    </FormField>
                    <FormField label="Password" :error="form.errors.password" class="sm:col-span-2">
                        <input
                            v-model="form.password"
                            type="password"
                            placeholder="Min. 8 characters"
                            class="field w-full"
                        />
                    </FormField>
                    <div class="flex justify-end sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="btn-primary"
                        >
                            {{ form.processing ? 'Creating...' : 'Create Database' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Database</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">User</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Note</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="db in databases" :key="db.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 text-sm font-mono text-gray-100">{{ db.db_name }}</td>
                            <td class="px-5 py-3.5 text-sm font-mono text-gray-400">{{ db.db_user }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-500">{{ db.note ?? '-' }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        @click="openPwModal(db)"
                                        class="text-xs text-gray-400 transition-colors hover:text-gray-200"
                                    >
                                        Change pw
                                    </button>
                                    <ConfirmButton
                                        :href="route('my.databases.destroy', db.id)"
                                        method="delete"
                                        label="Delete"
                                        color="red"
                                        :confirm-message="`Delete database ${db.db_name}? This cannot be undone.`"
                                    />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="databases.length === 0">
                            <td colspan="4" class="px-5 py-8">
                                <EmptyState
                                    title="No databases yet"
                                    description="Create a database and matching user for your first application."
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="pwTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <h3 class="mb-4 text-sm font-semibold text-gray-200">Change password for <span class="font-mono">{{ pwTarget.db_user }}</span></h3>
                    <form @submit.prevent="submitPw" class="space-y-4">
                        <FormField label="New password" :error="pwForm.errors.password">
                            <input
                                v-model="pwForm.password"
                                type="password"
                                placeholder="Min. 8 characters"
                                class="field w-full"
                                autofocus
                            />
                        </FormField>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="pwTarget = null" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 transition-colors hover:bg-gray-800">
                                Cancel
                            </button>
                            <button type="submit" :disabled="pwForm.processing" class="btn-primary">
                                {{ pwForm.processing ? 'Updating...' : 'Update' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    account: Object,
    databases: Array,
});

const databaseLimit = computed(() => props.account.max_databases > 0 ? props.account.max_databases : 'Unlimited');
const remainingDatabases = computed(() => {
    if (props.account.max_databases <= 0) return 'Unlimited';
    return Math.max(props.account.max_databases - props.databases.length, 0);
});

const form = useForm({ db_name: '', db_user: '', password: '' });

function submit() {
    form.post(route('my.databases.store'), { onSuccess: () => form.reset() });
}

const pwTarget = ref(null);
const pwForm = useForm({ password: '' });

function openPwModal(db) {
    pwTarget.value = db;
    pwForm.reset();
}

function submitPw() {
    pwForm.put(route('my.databases.password', pwTarget.value.id), {
        onSuccess: () => { pwTarget.value = null; pwForm.reset(); },
    });
}
</script>
