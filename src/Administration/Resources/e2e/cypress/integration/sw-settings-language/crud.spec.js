// / <reference types="Cypress" />

import SettingsPageObject from '../../support/pages/module/sw-settings.page-object';
import ProductPageObject from '../../support/pages/module/sw-product.page-object';

describe('Language: Test crud operations', () => {
    beforeEach(() => {
        cy.setToInitialState()
            .then(() => {
                cy.loginViaApi();
            })
            .then(() => {
                return cy.createLanguageFixture();
            })
            .then(() => {
                return cy.createProductFixture();
            })
            .then(() => {
                cy.openInitialPage(`${Cypress.env('admin')}#/sw/settings/language/index`);
            });
    });

    it('create and read language', () => {
        const page = new SettingsPageObject();
        const productPage = new ProductPageObject();

        // Request we want to wait for later
        cy.server();
        cy.route({
            url: '/api/v1/language',
            method: 'post'
        }).as('saveData');

        cy.get('.sw-settings-language-list').should('be.visible');
        cy.get('a[href="#/sw/settings/language/create"]').click();

        // Create language
        cy.get('input[name=sw-field--language-name]').type('Japanese');
        cy.get('.sw-settings-language-detail__select-iso-code').typeSingleSelectAndCheck('ja-JP', {
            searchable: true
        });
        cy.get('.sw-settings-language-detail__select-locale').typeSingleSelectAndCheck('Japanese, Japan', {
            searchable: true
        });
        cy.get(page.elements.languageSaveAction).click();

        // Verify and check usage of customer-group
        cy.wait('@saveData').then(() => {
            cy.get(page.elements.smartBarBack).click();
            cy.get(`${page.elements.dataGridRow}--2 .sw-data-grid__cell--name`)
                .contains('Japanese');
        });

        // Check if language can be selected as translation
        cy.clickMainMenuItem({
            targetPath: '#/sw/product/index',
            mainMenuId: 'sw-catalogue',
            subMenuId: 'sw-product'
        });
        cy.clickContextMenuItem(
            '.sw-entity-listing__context-menu-edit-action',
            page.elements.contextMenuButton,
            `${page.elements.dataGridRow}--0`
        );

        productPage.changeTranslation('Japanese', 3);

        cy.get('.sw-language-info').contains('"Product name" displayed in the root language "Japanese".');
    });

    it('update and read language', () => {
        const page = new SettingsPageObject();

        // Request we want to wait for later
        cy.server();
        cy.route({
            url: '/api/v1/language/*',
            method: 'patch'
        }).as('saveData');

        cy.get('.sw-settings-language-list').should('be.visible');
        cy.clickContextMenuItem(
            '.sw-entity-listing__context-menu-edit-action',
            page.elements.contextMenuButton,
            `${page.elements.dataGridRow}--2`
        );
        cy.get('input[name=sw-field--language-name]').clear();
        cy.get('input[name=sw-field--language-name]').type('Kyoto Japanese');
        cy.get(page.elements.languageSaveAction).click();

        // Verify and check usage of customer-group
        cy.wait('@saveData').then(() => {
            cy.get(page.elements.smartBarBack).click();
            cy.get(`${page.elements.dataGridRow}--2 .sw-data-grid__cell--name`).should('be.visible')
                .contains('Kyoto Japanese');
        });
    });

    it('delete language', () => {
        const page = new SettingsPageObject();

        // Request we want to wait for later
        cy.server();
        cy.route({
            url: '/api/v1/language/*',
            method: 'delete'
        }).as('deleteData');

        cy.get('.sw-settings-language-list').should('be.visible');
        cy.clickContextMenuItem(
            `${page.elements.contextMenu}-item--danger`,
            page.elements.contextMenuButton,
            `${page.elements.dataGridRow}--2`
        );

        cy.get('.sw-modal__body')
            .contains('Are you sure you want to delete this item?');
        cy.get(`${page.elements.modal}__footer button${page.elements.primaryButton}`).click();
        cy.get(page.elements.modal).should('not.exist');

        // Verify and check usage of customer-group
        cy.wait('@deleteData').then(() => {
            cy.get(`${page.elements.dataGridRow}--2 .sw-data-grid__cell--name`).should('not.exist');
        });
    });
});
