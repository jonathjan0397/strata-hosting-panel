<template>
    <AppLayout title="New Account">
        <div class="max-w-2xl">
            <div class="mb-6">
                <Link :href="route('admin.accounts.index')" class="text-sm text-gray-500 hover:text-gray-300 transition-colors">
                    ← Back to accounts
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Account owner -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-200 mb-4">Account Owner</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <FormField label="Full Name" :error="form.errors.name">
                            <input v-model="form.name" type="text" class="field" placeholder="Jane Smith" />
                        </FormField>
                        <FormField label="Email" :error="form.errors.email">
                            <input v-model="form.email" type="email" class="field" placeholder="jane@example.com" />
                        </FormField>
                        <FormField label="Password" :error="form.errors.password" class="col-span-2">
                            <input v-model="form.password" type="password" class="field" placeholder="Min. 12 characters" />
                        </FormField>
                    </div>
                </div>

                <!-- Hosting config -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-200 mb-4">Hosting Configuration</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <FormField label="Username" :error="form.errors.username" class="col-span-2">
                            <input
                                v-model="form.username"
                                type="text"
                                class="field font-mono"
                                placeholder="janesmith"
                                @input="form.username = form.username.toLowerCase().replace(/[^a-z0-9_]/g, '')"
                            />
                            <p class="mt-1 text-xs text-gray-500">System username — lowercase, alphanumeric + underscore only.</p>
                        </FormField>
                        <FormField label="Node" :error="form.errors.node_id">
                            <select v-model="form.node_id" class="field">
                                <option value="">Select node…</option>
                                <option v-for="node in nodes" :key="node.id" :value="node.id">
                                    {{ node.name }} ({{ node.hostname }})
                                </option>
                            </select>
                        </FormField>
                        <FormField label="PHP Version" :error="form.errors.php_version">
                            <select v-model="form.php_version" class="field">
                                <option v-for="v in phpVersions" :key="v" :value="v">PHP {{ v }}</option>
                            </select>
                        </FormField>
                    </div>
                </div>

                <!-- Resource limits -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-200 mb-1">Resource Limits</h3>
                    <p class="text-xs text-gray-500 mb-4">Set to 0 for unlimited.</p>
                    <div class="grid grid-cols-3 gap-4">
                        <FormField label="Disk (MB)" :error="form.errors.disk_limit_mb">
                            <input v-model.number="form.disk_limit_mb" type="number" min="0" class="field" placeholder="0" />
                        </FormField>
                        <FormField label="Bandwidth (MB)" :error="form.errors.bandwidth_limit_mb">
                            <input v-model.number="form.bandwidth_limit_mb" type="number" min="0" class="field" placeholder="0" />
                        </FormField>
                        <FormField label="Max Domains" :error="form.errors.max_domains">
                            <input v-model.number="form.max_domains" type="number" min="0" class="field" placeholder="0" />
                        </FormField>
                        <FormField label="Max Email Accounts" :error="form.errors.max_email_accounts">
                            <input v-model.number="form.max_email_accounts" type="number" min="0" class="field" placeholder="0" />
                        </FormField>
                        <FormField label="Max Databases" :error="form.errors.max_databases">
                            <input v-model.number="form.max_databases" type="number" min="0" class="field" placeholder="0" />
                        </FormField>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                    >
                        <span v-if="form.processing">Creating…</span>
                        <span v-else>Create Account</span>
                    </button>
                    <Link :href="route('admin.accounts.index')" class="text-sm text-gray-500 hover:text-gray-300">
                        Cancel
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';

const props = defineProps({
    nodes:       Array,
    phpVersions: Array,
});

const form = useForm({
    name:                '',
    email:               '',
    password:            '',
    username:            '',
    node_id:             props.nodes[0]?.id ?? '',
    php_version:         '8.3',
    disk_limit_mb:       0,
    bandwidth_limit_mb:  0,
    max_domains:         0,
    max_email_accounts:  0,
    max_databases:       0,
});

function submit() {
    form.post(route('admin.accounts.store'));
}
</script>

<style scoped>
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
