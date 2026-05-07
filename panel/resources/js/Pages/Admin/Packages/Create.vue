<template>
    <AppLayout title="New Hosting Package">
        <PackageForm
            :feature-lists="featureLists"
            :php-versions="phpVersions"
            submit-label="Create Package"
            submit-route="admin.packages.store"
            back-route="admin.packages.index"
            title="Create Hosting Package"
            description="Build reusable plans for admins and resellers with package-based quotas and defaults."
        />
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PackageForm from './Partials/PackageForm.vue';

defineProps({
    featureLists: Array,
    phpVersions: Array,
});
</script>
