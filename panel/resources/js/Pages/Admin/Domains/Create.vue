<template>
    <AppLayout title="Add Domain">
        <div class="max-w-xl">
            <div class="mb-6">
                <Link :href="route('admin.domains.index')" class="text-sm text-gray-500 hover:text-gray-300 transition-colors">
                    ← Back to domains
                </Link>
            </div>

            <form @submit.prevent="submit" class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-5">
                <h3 class="text-sm font-semibold text-gray-200">Add Domain</h3>

                <FormField label="Domain Name" :error="form.errors.domain">
                    <input
                        v-model="form.domain"
                        type="text"
                        class="field font-mono"
                        placeholder="example.com"
                        @input="form.domain = form.domain.toLowerCase()"
                    />
                </FormField>

                <FormField label="Account" :error="form.errors.account_id">
                    <select v-model="form.account_id" class="field">
                        <option value="">Select account…</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </FormField>

                <div class="grid grid-cols-2 gap-4">
                    <FormField label="Domain Type" :error="form.errors.type">
                        <select v-model="form.type" class="field">
                            <option value="main">Main Domain</option>
                            <option value="addon">Addon Domain</option>
                            <option value="subdomain">Subdomain</option>
                            <option value="parked">Parked</option>
                        </select>
                    </FormField>

                    <FormField label="PHP Version" :error="form.errors.php_version">
                        <select v-model="form.php_version" class="field">
                            <option value="">Inherit from account</option>
                            <option v-for="v in phpVersions" :key="v" :value="v">PHP {{ v }}</option>
                        </select>
                    </FormField>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                    >
                        <span v-if="form.processing">Adding…</span>
                        <span v-else>Add Domain</span>
                    </button>
                    <Link :href="route('admin.domains.index')" class="text-sm text-gray-500 hover:text-gray-300">Cancel</Link>
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
    accounts:    Array,
    phpVersions: Array,
    preselect:   [String, Number, null],
});

const form = useForm({
    domain:      '',
    account_id:  props.preselect ?? '',
    type:        'main',
    php_version: '',
});

function submit() {
    form.post(route('admin.domains.store'));
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
