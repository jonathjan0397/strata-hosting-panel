<template>
    <div class="max-w-4xl">
        <div class="mb-6">
            <Link :href="route(backRoute)" class="text-sm text-gray-500 transition-colors hover:text-gray-300">
                &lt;- Back to feature lists
            </Link>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-1 text-sm font-semibold text-gray-200">{{ title }}</h3>
                <p class="mb-4 text-xs text-gray-500">{{ description }}</p>

                <div class="grid gap-4 md:grid-cols-2">
                    <FormField label="Name" :error="form.errors.name">
                        <input v-model="form.name" type="text" class="field" placeholder="Starter Shared" />
                    </FormField>
                    <FormField label="Slug" :error="form.errors.slug">
                        <input v-model="form.slug" type="text" class="field" placeholder="Auto-generated if left blank" />
                    </FormField>
                    <FormField label="Description" :error="form.errors.description" class="md:col-span-2">
                        <textarea v-model="form.description" rows="3" class="field" placeholder="Core tools available to starter shared accounts." />
                    </FormField>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-1 text-sm font-semibold text-gray-200">Enabled Features</h3>
                <p class="mb-4 text-xs text-gray-500">These toggles define which sections show up in a package-backed account.</p>

                <div class="grid gap-3 md:grid-cols-2">
                    <label
                        v-for="(label, key) in featureCatalog"
                        :key="key"
                        class="flex items-start gap-3 rounded-lg border border-gray-800 bg-gray-950/40 p-3"
                    >
                        <input
                            v-model="form.features"
                            :value="key"
                            type="checkbox"
                            class="mt-1 h-4 w-4 rounded border-gray-700 bg-gray-800 text-indigo-600 focus:ring-indigo-500"
                        />
                        <div>
                            <div class="text-sm font-medium text-gray-200">{{ label }}</div>
                            <div class="text-xs font-mono text-gray-500">{{ key }}</div>
                        </div>
                    </label>
                </div>
                <div v-if="form.errors.features" class="mt-3 text-sm text-rose-400">{{ form.errors.features }}</div>
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
    featureCatalog: Object,
    featureList: {
        type: Object,
        default: null,
    },
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
    name: props.featureList?.name ?? '',
    slug: props.featureList?.slug ?? '',
    description: props.featureList?.description ?? '',
    features: [...(props.featureList?.features ?? [])],
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
