<script setup lang="ts">
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';

withDefaults(defineProps<{
    modelValue: string;
    placeholder?: string;
    maxPlainTextLength?: number | null;
}>(), {
    placeholder: 'Write description...',
    maxPlainTextLength: null,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const getPlainTextFromHtml = (value: string): string =>
    value
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

const escapeHtml = (value: string): string =>
    value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

const toSafeParagraphHtml = (value: string): string => {
    const trimmed = value.trim();
    if (trimmed === '') {
        return '';
    }

    return `<p>${escapeHtml(trimmed)}</p>`;
};

const onContentUpdate = (value: unknown): void => {
    const html = typeof value === 'string' ? value : '';

    if (maxPlainTextLength === null || maxPlainTextLength === undefined) {
        emit('update:modelValue', html);
        return;
    }

    const plain = getPlainTextFromHtml(html);
    if (plain.length <= maxPlainTextLength) {
        emit('update:modelValue', html);
        return;
    }

    const clamped = plain.slice(0, maxPlainTextLength);
    emit('update:modelValue', toSafeParagraphHtml(clamped));
};

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
            @update:content="onContentUpdate"
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
