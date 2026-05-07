<template>
    <AppLayout title="Edit Hosting Package">
        <PackageForm
            :package="package"
            :feature-lists="featureLists"
            :php-versions="phpVersions"
            submit-label="Save Changes"
            :submit-route="route('admin.packages.update', package.id)"
            method="put"
            back-route="admin.packages.index"
            title="Edit Hosting Package"
            description="Tune package quotas, defaults, and reseller availability."
        />
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PackageForm from './Partials/PackageForm.vue';

defineProps({
    package: Object,
    featureLists: Array,
    phpVersions: Array,
});
</script>
