import { Component } from 'src/core/shopware';
import template from './sw-order-document-settings-storno-modal.html.twig';

Component.extend('sw-order-document-settings-storno-modal', 'sw-order-document-settings-modal', {
    template,

    props: {
        order: {
            type: Object,
            required: true
        },
        currentDocumentType: {
            type: Object,
            required: true
        }
    },

    data() {
        return {
            documentConfig: {
                custom: {
                    stornoNumber: '',
                    invoiceNumber: ''
                },
                documentNumber: 0,
                documentComment: '',
                documentDate: ''
            }
        };
    },

    computed: {
        documentPreconditionsFulfilled() {
            return !!this.documentConfig.custom.invoiceNumber;
        },

        invoices() {
            return this.order.documents;
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.numberRangeService.reserve(
                `document_${this.currentDocumentType.technicalName}`,
                this.order.salesChannelId,
                true
            ).then((response) => {
                this.documentConfig.documentNumber = response.number;
                this.documentNumberPreview = this.documentConfig.documentNumber;
                this.documentConfig.documentDate = (new Date()).toISOString();
            });
        },

        onCreateDocument(additionalAction = false) {
            const selectedInvoice = this.invoices.filter((item) => {
                return item.config.custom.invoiceNumber === this.documentConfig.custom.invoiceNumber;
            })[0];

            if (this.documentNumberPreview === this.documentConfig.documentNumber) {
                this.numberRangeService.reserve(
                    `document_${this.currentDocumentType.technicalName}`,
                    this.order.salesChannelId,
                    false
                ).then((response) => {
                    this.documentConfig.custom.stornoNumber = response.number;
                    this.$emit(
                        'document-create',
                        this.documentConfig,
                        additionalAction,
                        selectedInvoice.id
                    );
                });
            } else {
                this.documentConfig.custom.stornoNumber = this.documentConfig.documentNumber;
                this.$emit(
                    'document-create',
                    this.documentConfig,
                    additionalAction,
                    selectedInvoice.id
                );
            }
        },

        onPreview() {
            this.documentConfig.custom.stornoNumber = this.documentConfig.documentNumber;
            this.$super.onPreview();
        },

        onSelectInvoice(selected) {
            this.documentConfig.custom.invoiceNumber = selected;
        }

    }
});
