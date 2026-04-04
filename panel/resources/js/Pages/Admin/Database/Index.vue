<template>
    <AppLayout :title="`Databases — ${account.username}`">

        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('admin.accounts.show', account.id)" class="text-gray-500 hover:text-gray-300 transition-colors">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <div>
                <h2 class="text-lg font-semibold text-gray-100">Databases</h2>
                <p class="text-sm text-gray-400 font-mono">{{ account.username }}</p>
            </div>
        </div>

        <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                <h3 class="text-sm font-semibold text-gray-200">
                    MySQL Databases
                    <span class="ml-2 text-xs font-normal text-gray-500">{{ databases.length }}</span>
                </h3>
                <button @click="showCreate = !showCreate" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                    + Create
                </button>
            </div>

            <!-- Create form -->
            <div v-if="showCreate" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                <form @submit.prevent="submitCreate" class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Database name</label>
                            <input v-model="form.db_name" type="text" placeholder="e.g. alice_wp" class="field font-mono text-xs" required />
                            <p v-if="errors.db_name" class="mt-1 text-xs text-red-400">{{ errors.db_name }}</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">DB user</label>
                            <input v-model="form.db_user" type="text" placeholder="e.g. alice_wp" class="field font-mono text-xs" required />
                            <p v-if="errors.db_user" class="mt-1 text-xs text-red-400">{{ errors.db_user }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="form.password" type="password" placeholder="Password (min 8)" class="field" required />
                        <input v-model="form.note" type="text" placeholder="Note (optional)" class="field text-xs" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">
                            Create Database
                        </button>
                        <button type="button" @click="showCreate = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                    </div>
                </form>
            </div>

            <!-- Database list -->
            <div class="divide-y divide-gray-800">
                <div v-for="db in databases" :key="db.id" class="flex items-center justify-between px-5 py-3">
                    <div>
                        <p class="text-sm font-mono text-gray-100">{{ db.db_name }}</p>
                        <p class="text-xs text-gray-500">
                            User: <span class="font-mono">{{ db.db_user }}</span>
                            <span v-if="db.note" class="ml-2 text-gray-600">· {{ db.note }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            @click="openPwdModal(db)"
                            class="text-xs text-gray-500 hover:text-gray-300 transition-colors"
                        >
                            Password
                        </button>
                        <ConfirmButton
                            :href="route('admin.databases.destroy', db.id)"
                            method="delete"
                            label="Delete"
                            :confirm-message="`Permanently drop ${db.db_name} and user ${db.db_user}?`"
                            color="red"
                        />
                    </div>
                </div>
                <div v-if="databases.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">
                    No databases yet.
                </div>
            </div>
        </div>

        <!-- Change password modal -->
        <Teleport to="body">
            <div
                v-if="pwdModal.show"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                @click.self="pwdModal.show = false"
            >
                <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <h3 class="text-base font-semibold text-gray-100 mb-1">Change DB Password</h3>
                    <p class="text-sm text-gray-400 mb-4 font-mono">{{ pwdModal.dbUser }}</p>
                    <form @submit.prevent="submitPassword">
                        <input
                            v-model="pwdModal.password"
                            type="password"
                            placeholder="New password (min 8)"
                            class="block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none mb-4"
                            required
                        />
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="pwdModal.show = false" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" :disabled="pwdModal.busy" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                                Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    account:   Object,
    databases: Array,
});

const showCreate = ref(false);
const form   = ref({ db_name: '', db_user: '', password: '', note: '' });
const errors = ref({});

function submitCreate() {
    router.post(route('admin.accounts.databases.store', props.account.id), form.value, {
        onSuccess: () => {
            form.value  = { db_name: '', db_user: '', password: '', note: '' };
            errors.value = {};
            showCreate.value = false;
        },
        onError: (errs) => { errors.value = errs; },
    });
}

const pwdModal = reactive({ show: false, dbId: null, dbUser: '', password: '', busy: false });

function openPwdModal(db) {
    pwdModal.dbId     = db.id;
    pwdModal.dbUser   = db.db_user;
    pwdModal.password = '';
    pwdModal.show     = true;
}

function submitPassword() {
    pwdModal.busy = true;
    router.put(route('admin.databases.password', pwdModal.dbId), {
        password: pwdModal.password,
    }, {
        onFinish: () => { pwdModal.busy = false; pwdModal.show = false; },
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
