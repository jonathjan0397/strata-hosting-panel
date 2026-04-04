<template>
    <AppLayout title="Add Domain">
        <div class="mb-5 flex items-center gap-3">
            <Link :href="route('my.domains.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <h2 class="text-base font-semibold text-gray-100">Add Domain</h2>
        </div>

        <div class="max-w-lg rounded-xl border border-gray-800 bg-gray-900 p-6">
            <form @submit.prevent="submit" class="space-y-5">
                <FormField label="Domain name" :error="form.errors.domain">
                    <input
                        v-model="form.domain"
                        type="text"
                        placeholder="example.com"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </FormField>

                <FormField label="Type" :error="form.errors.type">
                    <select
                        v-model="form.type"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none"
                    >
                        <option value="addon">Addon domain</option>
                        <option value="subdomain">Subdomain</option>
                        <option value="parked">Parked (alias)</option>
                    </select>
                </FormField>

                <FormField label="PHP version" :error="form.errors.php_version">
                    <select
                        v-model="form.php_version"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none"
                    >
                        <option v-for="v in phpVersions" :key="v" :value="v">PHP {{ v }}</option>
                    </select>
                </FormField>

                <div class="flex justify-end gap-3 pt-2">
                    <Link
                        :href="route('my.domains.index')"
                        class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors"
                    >
                        Add Domain
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';

const props = defineProps({
    phpVersions: Array,
});

const form = useForm({
    domain:      '',
    type:        'addon',
    php_version: props.phpVersions?.[1] ?? '8.2',
});

function submit() {
    form.post(route('my.domains.store'));
}
</script>
