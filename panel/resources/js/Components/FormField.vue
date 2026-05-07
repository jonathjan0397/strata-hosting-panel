<template>
    <div :class="$attrs.class">
        <label v-if="label" class="block text-sm font-medium text-gray-300 mb-1.5">{{ label }}</label>
        <slot />
        <p v-if="error" class="mt-1.5 text-xs text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
defineProps({
    label: String,
    error: String,
});
defineOptions({ inheritAttrs: false });
</script>
