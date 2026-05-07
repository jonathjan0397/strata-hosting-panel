<template>
    <AppLayout title="New Feature List">
        <FeatureListForm
            :feature-catalog="featureCatalog"
            submit-label="Create Feature List"
            submit-route="admin.feature-lists.store"
            back-route="admin.feature-lists.index"
            title="Create Feature List"
            description="Bundle user-facing capabilities into reusable feature presets for hosting packages."
        />
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FeatureListForm from './Partials/FeatureListForm.vue';

defineProps({
    featureCatalog: Object,
});
</script>
