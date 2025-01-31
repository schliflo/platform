import { Component, Mixin } from 'src/core/shopware';
import Criteria from 'src/core/data-new/criteria.data';
import template from './sw-theme-modal.html.twig';
import './sw-theme-modal.scss';

Component.register('sw-theme-modal', {
    template,

    inject: [
        'repositoryFactory',
        'context'
    ],

    mixins: [
        Mixin.getByName('listing')
    ],

    data() {
        return {
            selected: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            term: null,
            total: null,
            themes: []
        };
    },

    computed: {
        themeRepository() {
            return this.repositoryFactory.create('theme');
        }
    },

    methods: {
        getList() {
            this.isLoading = true;
            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('previewMedia');

            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            if (this.term !== null) {
                criteria.setTerm(this.term);
            }

            return this.themeRepository.search(criteria, this.context).then((searchResult) => {
                this.total = searchResult.total;
                this.themes = searchResult;
                this.isLoading = false;

                return this.themes;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        selectLayout() {
            this.$emit('modal-theme-select', this.selected);
            this.closeModal();
        },

        selectItem(themeId) {
            this.selected = themeId;
        },

        onSearch(value) {
            this.term = value.length > 0 ? value.length : null;

            this.page = 1;
            this.getList();
        },

        onSelection(themeId) {
            this.selected = themeId;
        },

        closeModal() {
            this.$emit('modal-close');
            this.selected = null;
            this.term = null;
        }
    }
});
