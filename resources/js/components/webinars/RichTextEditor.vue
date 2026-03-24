<script setup lang="ts">
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';

withDefaults(defineProps<{
    modelValue: string;
    placeholder?: string;
}>(), {
    placeholder: 'Write description...',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const toolbar = [
    ['bold', 'italic', 'underline'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['link'],
    ['clean'],
];
</script>

<template>
    <div class="rounded-md border">
        <QuillEditor
            :content="modelValue ?? ''"
            content-type="html"
            theme="snow"
            :toolbar="toolbar"
            :placeholder="placeholder"
            @update:content="(value) => emit('update:modelValue', typeof value === 'string' ? value : '')"
        />
    </div>
</template>

<style scoped>
:deep(.ql-toolbar.ql-snow) {
    border: none;
    border-bottom: 1px solid hsl(var(--border));
    border-radius: 0.375rem 0.375rem 0 0;
    background: hsl(var(--muted) / 0.2);
}

:deep(.ql-container.ql-snow) {
    border: none;
    min-height: 140px;
    font-size: 0.875rem;
    border-radius: 0 0 0.375rem 0.375rem;
}

:deep(.ql-editor) {
    min-height: 140px;
    line-height: 1.5;
}
</style>
