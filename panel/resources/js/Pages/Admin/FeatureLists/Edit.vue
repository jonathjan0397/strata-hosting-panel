<template>
    <AppLayout title="Edit Feature List">
        <FeatureListForm
            :feature-catalog="featureCatalog"
            :feature-list="featureList"
            submit-label="Save Changes"
            :submit-route="route('admin.feature-lists.update', featureList.id)"
            method="put"
            back-route="admin.feature-lists.index"
            title="Edit Feature List"
            description="Update the feature set attached to packages and reseller offerings."
        />
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FeatureListForm from './Partials/FeatureListForm.vue';

defineProps({
    featureList: Object,
    featureCatalog: Object,
});
</script>
