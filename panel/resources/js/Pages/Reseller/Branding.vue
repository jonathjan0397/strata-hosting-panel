<template>
    <AppLayout title="Branding">
        <div class="max-w-lg space-y-6 p-6">

            <div>
                <h1 class="text-lg font-semibold text-gray-100">White-Label Branding</h1>
                <p class="mt-1 text-sm text-gray-400">
                    Customise the panel name and accent colour shown to your clients.
                    Leave blank to show the default Strata Hosting Panel branding.
                </p>
            </div>

            <!-- Live preview -->
            <div
                class="rounded-xl border border-gray-800 p-5 space-y-2"
                :style="previewStyle"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Preview</p>
                <p class="text-base font-semibold text-gray-100">
                    {{ form.brand_name || 'Strata Hosting Panel' }}
                </p>
                <div class="h-2 w-24 rounded-full" :style="{ background: form.brand_color || '#6366f1' }"></div>
            </div>

            <form @submit.prevent="save" class="rounded-xl border border-gray-800 bg-gray-900 p-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Panel Name</label>
                    <input
                        v-model="form.brand_name"
                        type="text"
                        maxlength="60"
                        placeholder="Strata Hosting Panel"
                        class="field w-full"
                    />
                    <p v-if="form.errors.brand_name" class="mt-1 text-xs text-red-400">{{ form.errors.brand_name }}</p>
                    <p class="mt-1 text-xs text-gray-500">Shown in the browser title and sidebar for your clients.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Accent Colour</label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model="form.brand_color"
                            type="color"
                            class="h-9 w-16 cursor-pointer rounded-lg border border-gray-700 bg-gray-800 p-1"
                        />
                        <input
                            v-model="form.brand_color"
                            type="text"
                            maxlength="7"
                            placeholder="#6366f1"
                            class="field w-32 font-mono"
                        />
                    </div>
                    <p v-if="form.errors.brand_color" class="mt-1 text-xs text-red-400">{{ form.errors.brand_color }}</p>
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                    >
                        Save Branding
                    </button>
                    <button
                        v-if="form.brand_name || form.brand_color !== '#6366f1'"
                        type="button"
                        @click="reset"
                        class="text-sm text-gray-500 hover:text-gray-300 transition-colors"
                    >
                        Reset to defaults
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    brand_name:  { type: String, default: '' },
    brand_color: { type: String, default: '#6366f1' },
});

const form = useForm({
    brand_name:  props.brand_name  ?? '',
    brand_color: props.brand_color ?? '#6366f1',
});

const previewStyle = computed(() => ({
    borderColor: (form.brand_color || '#6366f1') + '40',
    background: (form.brand_color || '#6366f1') + '08',
}));

function save() {
    form.put(route('reseller.branding.update'));
}

function reset() {
    form.brand_name  = '';
    form.brand_color = '#6366f1';
    form.put(route('reseller.branding.update'));
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
