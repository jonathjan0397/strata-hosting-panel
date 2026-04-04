<template>
    <AppLayout title="New Reseller">
        <div class="max-w-2xl">
            <div class="mb-6">
                <Link :href="route('admin.resellers.index')" class="text-sm text-gray-500 hover:text-gray-300 transition-colors">
                    ← Back to resellers
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Account details -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-200 mb-4">Reseller Account</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <FormField label="Full Name" :error="form.errors.name">
                            <input v-model="form.name" type="text" class="field" placeholder="Acme Hosting" />
                        </FormField>
                        <FormField label="Email" :error="form.errors.email">
                            <input v-model="form.email" type="email" class="field" placeholder="reseller@example.com" />
                        </FormField>
                        <FormField label="Password" :error="form.errors.password" class="col-span-2">
                            <input v-model="form.password" type="password" class="field" placeholder="Min. 12 characters" />
                        </FormField>
                    </div>
                </div>

                <!-- Resource quotas -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-200 mb-1">Resource Quotas</h3>
                    <p class="text-xs text-gray-500 mb-4">
                        These are pool limits across all of the reseller's clients. Leave blank for unlimited.
                    </p>
                    <div class="grid grid-cols-3 gap-4">
                        <FormField label="Max Accounts" :error="form.errors.quota_accounts">
                            <input v-model.number="form.quota_accounts" type="number" min="1" class="field" placeholder="∞" />
                        </FormField>
                        <FormField label="Disk Pool (MB)" :error="form.errors.quota_disk_mb">
                            <input v-model.number="form.quota_disk_mb" type="number" min="0" class="field" placeholder="∞" />
                        </FormField>
                        <FormField label="Bandwidth Pool (MB)" :error="form.errors.quota_bandwidth_mb">
                            <input v-model.number="form.quota_bandwidth_mb" type="number" min="0" class="field" placeholder="∞" />
                        </FormField>
                        <FormField label="Max Domains" :error="form.errors.quota_domains">
                            <input v-model.number="form.quota_domains" type="number" min="0" class="field" placeholder="∞" />
                        </FormField>
                        <FormField label="Max Email Accounts" :error="form.errors.quota_email_accounts">
                            <input v-model.number="form.quota_email_accounts" type="number" min="0" class="field" placeholder="∞" />
                        </FormField>
                        <FormField label="Max Databases" :error="form.errors.quota_databases">
                            <input v-model.number="form.quota_databases" type="number" min="0" class="field" placeholder="∞" />
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
                        <span v-else>Create Reseller</span>
                    </button>
                    <Link :href="route('admin.resellers.index')" class="text-sm text-gray-500 hover:text-gray-300">
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

const form = useForm({
    name:                  '',
    email:                 '',
    password:              '',
    quota_accounts:        null,
    quota_disk_mb:         null,
    quota_bandwidth_mb:    null,
    quota_domains:         null,
    quota_email_accounts:  null,
    quota_databases:       null,
});

function submit() {
    form.post(route('admin.resellers.store'));
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
