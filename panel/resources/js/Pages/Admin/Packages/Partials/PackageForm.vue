<template>
    <div class="max-w-5xl">
        <div class="mb-6">
            <Link :href="route(backRoute)" class="text-sm text-gray-500 transition-colors hover:text-gray-300">
                &lt;- Back to packages
            </Link>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-1 text-sm font-semibold text-gray-200">{{ title }}</h3>
                <p class="mb-4 text-xs text-gray-500">{{ description }}</p>

                <div class="grid gap-4 md:grid-cols-2">
                    <FormField label="Name" :error="form.errors.name">
                        <input v-model="form.name" type="text" class="field" placeholder="Business Pro" />
                    </FormField>
                    <FormField label="Slug" :error="form.errors.slug">
                        <input v-model="form.slug" type="text" class="field" placeholder="Auto-generated if left blank" />
                    </FormField>
                    <FormField label="Feature List" :error="form.errors.feature_list_id">
                        <select v-model="form.feature_list_id" class="field">
                            <option :value="null">No feature list</option>
                            <option v-for="featureList in featureLists" :key="featureList.id" :value="featureList.id">{{ featureList.name }}</option>
                        </select>
                    </FormField>
                    <FormField label="Default PHP Version" :error="form.errors.php_version">
                        <select v-model="form.php_version" class="field">
                            <option v-for="version in phpVersions" :key="version" :value="version">{{ version }}</option>
                        </select>
                    </FormField>
                    <FormField label="Description" :error="form.errors.description" class="md:col-span-2">
                        <textarea v-model="form.description" rows="3" class="field" placeholder="Balanced limits for business hosting accounts." />
                    </FormField>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-1 text-sm font-semibold text-gray-200">Resource Limits</h3>
                <p class="mb-4 text-xs text-gray-500">Leave fields blank to treat them as unlimited.</p>

                <div class="grid gap-4 md:grid-cols-3">
                    <FormField label="Disk Limit (MB)" :error="form.errors.disk_limit_mb">
                        <input v-model.number="form.disk_limit_mb" type="number" min="0" class="field" placeholder="Unlimited" />
                    </FormField>
                    <FormField label="Bandwidth Limit (MB)" :error="form.errors.bandwidth_limit_mb">
                        <input v-model.number="form.bandwidth_limit_mb" type="number" min="0" class="field" placeholder="Unlimited" />
                    </FormField>
                    <FormField label="Max Domains" :error="form.errors.max_domains">
                        <input v-model.number="form.max_domains" type="number" min="0" class="field" placeholder="Unlimited" />
                    </FormField>
                    <FormField label="Max Subdomains" :error="form.errors.max_subdomains">
                        <input v-model.number="form.max_subdomains" type="number" min="0" class="field" placeholder="Unlimited" />
                    </FormField>
                    <FormField label="Max Email Accounts" :error="form.errors.max_email_accounts">
                        <input v-model.number="form.max_email_accounts" type="number" min="0" class="field" placeholder="Unlimited" />
                    </FormField>
                    <FormField label="Max Databases" :error="form.errors.max_databases">
                        <input v-model.number="form.max_databases" type="number" min="0" class="field" placeholder="Unlimited" />
                    </FormField>
                    <FormField label="Max FTP Accounts" :error="form.errors.max_ftp_accounts">
                        <input v-model.number="form.max_ftp_accounts" type="number" min="0" class="field" placeholder="Unlimited" />
                    </FormField>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-4 text-sm font-semibold text-gray-200">Availability</h3>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-lg border border-gray-800 bg-gray-950/40 p-4">
                        <input v-model="form.available_to_resellers" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-700 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                        <div>
                            <div class="text-sm font-medium text-gray-200">Available to resellers</div>
                            <div class="text-xs text-gray-500">Allow reseller accounts to assign this package to their customers.</div>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-gray-800 bg-gray-950/40 p-4">
                        <input v-model="form.is_active" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-700 bg-gray-800 text-indigo-600 focus:ring-indigo-500" />
                        <div>
                            <div class="text-sm font-medium text-gray-200">Active package</div>
                            <div class="text-xs text-gray-500">Inactive packages remain assigned to existing accounts but cannot be selected for new ones.</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60"
                >
                    <span v-if="form.processing">{{ submitLabel }}...</span>
                    <span v-else>{{ submitLabel }}</span>
                </button>
                <Link :href="route(backRoute)" class="text-sm text-gray-500 hover:text-gray-300">Cancel</Link>
            </div>
        </form>
    </div>
</template>

<script setup>
import FormField from '@/Components/FormField.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    package: {
        type: Object,
        default: null,
    },
    featureLists: Array,
    phpVersions: Array,
    submitLabel: String,
    submitRoute: String,
    method: {
        type: String,
        default: 'post',
    },
    backRoute: String,
    title: String,
    description: String,
});

const form = useForm({
    name: props.package?.name ?? '',
    slug: props.package?.slug ?? '',
    description: props.package?.description ?? '',
    feature_list_id: props.package?.feature_list_id ?? null,
    php_version: props.package?.php_version ?? props.phpVersions[0],
    disk_limit_mb: props.package?.disk_limit_mb ?? null,
    bandwidth_limit_mb: props.package?.bandwidth_limit_mb ?? null,
    max_domains: props.package?.max_domains ?? null,
    max_subdomains: props.package?.max_subdomains ?? null,
    max_email_accounts: props.package?.max_email_accounts ?? null,
    max_databases: props.package?.max_databases ?? null,
    max_ftp_accounts: props.package?.max_ftp_accounts ?? null,
    available_to_resellers: Boolean(props.package?.available_to_resellers ?? false),
    is_active: Boolean(props.package?.is_active ?? true),
});

function submit() {
    if (props.method === 'put') {
        form.put(props.submitRoute);
        return;
    }

    form.post(route(props.submitRoute));
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
