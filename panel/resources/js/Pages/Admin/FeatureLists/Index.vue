<template>
    <AppLayout title="Feature Lists">
        <div class="mb-5 flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-400">{{ featureLists.length }} feature list{{ featureLists.length !== 1 ? 's' : '' }}</p>
                <p class="mt-1 text-xs text-gray-500">Define which cPanel-style tools are enabled inside a hosting package.</p>
            </div>
            <Link
                :href="route('admin.feature-lists.create')"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500"
            >
                New Feature List
            </Link>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Name</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Slug</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Enabled Features</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Packages</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-if="featureLists.length === 0">
                        <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">
                            No feature lists yet.
                        </td>
                    </tr>
                    <tr v-for="featureList in featureLists" :key="featureList.id" class="transition-colors hover:bg-gray-800/40">
                        <td class="px-5 py-3.5 text-sm font-medium text-gray-100">
                            <div>{{ featureList.name }}</div>
                            <div v-if="featureList.description" class="mt-1 text-xs text-gray-500">{{ featureList.description }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-400">{{ featureList.slug }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-300">
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="feature in featureList.features"
                                    :key="feature"
                                    class="rounded-full bg-gray-800 px-2.5 py-1 text-xs text-gray-300"
                                >
                                    {{ featureCatalog[feature] ?? feature }}
                                </span>
                                <span v-if="featureList.features.length === 0" class="text-xs text-gray-500">No features enabled</span>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ featureList.packages_count }}</td>
                        <td class="px-5 py-3.5 text-right">
                            <Link
                                :href="route('admin.feature-lists.edit', featureList.id)"
                                class="mr-3 text-xs text-indigo-400 transition-colors hover:text-indigo-300"
                            >
                                Edit
                            </Link>
                            <button
                                type="button"
                                class="text-xs text-rose-400 transition-colors hover:text-rose-300"
                                @click="destroy(featureList)"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';

defineProps({
    featureLists: Array,
    featureCatalog: Object,
});

function destroy(featureList) {
    if (!window.confirm(`Delete feature list "${featureList.name}"?`)) {
        return;
    }

    router.delete(route('admin.feature-lists.destroy', featureList.id));
}
</script>
