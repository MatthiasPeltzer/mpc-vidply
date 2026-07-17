<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Acceptance\Support;

/**
 * Shared backend navigation helpers for acceptance tests.
 */
trait BackendSteps
{
    public function openBackendModule(string $modulePath): void
    {
        $this->switchToIFrame();
        $this->amOnPage($modulePath);
        $this->waitForElementNotVisible('#nprogress', 120);
        $this->switchToContentFrame();
        $this->waitForElement('div.module');
    }

    public function openPageLayoutModule(int $pageId): void
    {
        $this->openBackendModule('/typo3/module/web/layout?id=' . $pageId);
    }

    public function openNewContentElementWizard(): void
    {
        $this->click('typo3-backend-new-content-element-wizard-button');
        $this->switchToMainFrame();
        $this->waitForElementNotVisible('#nprogress', 120);
        $this->waitForElement('typo3-backend-new-record-wizard');
    }

    public function selectNewRecordWizardCategory(string $identifier): void
    {
        $escapedIdentifier = json_encode($identifier, JSON_THROW_ON_ERROR);
        $this->executeJS(
            'document.querySelector("typo3-backend-new-record-wizard")'
            . '.shadowRoot.querySelector("button[data-identifier=" + ' . $escapedIdentifier . ' + "]")?.click();'
        );
        $this->wait(0.5);
    }

    public function seeNewRecordWizardItem(string $label, ?string $categoryIdentifier = null): void
    {
        $this->switchToMainFrame();
        $this->waitForElement('typo3-backend-new-record-wizard');

        if ($categoryIdentifier !== null) {
            $this->selectNewRecordWizardCategory($categoryIdentifier);
        }

        $categories = (string)$this->grabAttributeFrom('typo3-backend-new-record-wizard', 'categories');
        $this->assertStringContainsString($label, $categories, 'Expected new content wizard item: ' . $label);
    }
}
