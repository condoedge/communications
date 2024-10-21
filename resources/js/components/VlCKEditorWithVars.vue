<template>
    <div>
        <div>
            <div class="">
                <vl-form-field v-bind="$_wrapperAttributes">
                    <vlLocaleTabs :locales="locales" :activeLocale="activeLocale" @changeLocale="changeTab" />
                    <ckeditor class="vlFormControl" ref="content" v-model="currentTranslation" v-bind="$_attributes"
                        v-on="$_events" @keydown.stop :editor="editor" :config="editorConfig" />
                </vl-form-field>
            </div>
        </div>
        <div class="py-1">
            <div class="vlFormLabel" v-if="$_config('titleVariables')">
                {{ $_config("titleVariables") }}
            </div>
            <div class="flex flex-wrap gap-4 mt-2">
                <div class="flex-1" v-for="(variables, typeLabel) in allVariables" :key="typeLabel">
                    <vl-dropdown :vkompo="variables" />
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import Translatable from "vue-kompo/js/form/mixins/Translatable";
import CKEditor from "kompo-ckeditor/mixins/CKEditor";

export default {
    mixins: [CKEditor, Translatable],
    data() {
        return {
            allVariables: [],
            variableToInsert: null,
        };
    },
    methods: {
        insertVariable(payload) {
            var html =
                '<span class="mention" data-mention="' +
                payload.type +
                '">' +
                payload.label +
                "</span>";
            this.insertHtml(html);

            this.variableToInsert = null;
        },
        insertHtml(html) {
            const editor = this.$refs.content.$_instance;
            editor.model.change((writer) => {
                const viewFragment = editor.data.processor.toView(html);
                const modelFragment = editor.data.toModel(viewFragment);
                editor.model.insertContent(
                    modelFragment,
                    editor.model.document.selection
                );
            });
        },
        insertText(text) {
            const editor = this.$refs.content.$_instance;
            editor.model.change((writer) => {
                const insertPosition =
                    editor.model.document.selection.getFirstPosition();
                writer.insertText(text, insertPosition);
            });
        },
    },
    created() {
        this.allVariables = this.$_config("variables");

        this.$_vlOn("insertVariable", (payload) => this.insertVariable(payload));
    },
};
</script>
