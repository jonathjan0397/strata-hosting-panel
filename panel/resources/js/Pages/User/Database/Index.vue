<template>
    <AppLayout title="Databases">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-300">
                Databases ({{ databases.length }}<template v-if="account.max_databases > 0"> / {{ account.max_databases }}</template>)
            </h2>
        </div>

        <!-- Create form -->
        <div class="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 class="text-sm font-semibold text-gray-300 mb-4">Create Database</h3>
            <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2">
                <FormField label="Database name" :error="form.errors.db_name">
                    <input
                        v-model="form.db_name"
                        type="text"
                        placeholder="myapp_db"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </FormField>
                <FormField label="DB username" :error="form.errors.db_user">
                    <input
                        v-model="form.db_user"
                        type="text"
                        placeholder="myapp_user"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </FormField>
                <FormField label="Password" :error="form.errors.password" class="sm:col-span-2">
                    <input
                        v-model="form.password"
                        type="password"
                        placeholder="Min. 8 characters"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </FormField>
                <div class="sm:col-span-2 flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors"
                    >
                        Create Database
                    </button>
                </div>
            </form>
        </div>

        <!-- Database list -->
        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Database</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Note</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="db in databases" :key="db.id" class="hover:bg-gray-800/40 transition-colors">
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-100">{{ db.db_name }}</td>
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-400">{{ db.db_user }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-500">{{ db.note ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    @click="openPwModal(db)"
                                    class="text-xs text-gray-400 hover:text-gray-200 transition-colors"
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
                        <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">No databases yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Change password modal -->
        <div v-if="pwTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                <h3 class="text-sm font-semibold text-gray-200 mb-4">Change password for <span class="font-mono">{{ pwTarget.db_user }}</span></h3>
                <form @submit.prevent="submitPw" class="space-y-4">
                    <FormField label="New password" :error="pwForm.errors.password">
                        <input
                            v-model="pwForm.password"
                            type="password"
                            placeholder="Min. 8 characters"
                            class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                            autofocus
                        />
                    </FormField>
                    <div class="flex justify-end gap-3">
                        <button type="button" @click="pwTarget = null" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" :disabled="pwForm.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

defineProps({
    account:   Object,
    databases: Array,
});

const form = useForm({ db_name: '', db_user: '', password: '' });

function submit() {
    form.post(route('my.databases.store'), { onSuccess: () => form.reset() });
}

const pwTarget = ref(null);
const pwForm   = useForm({ password: '' });

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
