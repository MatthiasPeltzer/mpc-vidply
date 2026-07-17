<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Acceptance\Helper;

use Codeception\Module;
use Codeception\Module\WebDriver;
use Codeception\Util\Locator;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\WebDriverKeys;

/**
 * Backend login helper (password form) for acceptance tests.
 */
class PasswordLogin extends Module
{
    protected array $config = [
        'passwords' => [],
    ];

    public function useExistingSession(string $role, float|int $waitTime = 0.5): void
    {
        $webDriver = $this->getWebDriver();
        $hasSession = $this->loadSession($role);

        $webDriver->amOnPage('/typo3');
        $webDriver->wait($waitTime);

        if (!$hasSession) {
            $this->login($role);
        }

        $webDriver->debugSection('IFRAME', 'Switch to list_frame');
        try {
            $webDriver->waitForElement('iframe[name="list_frame"]');
        } catch (TimeoutException) {
            $webDriver->debugSection('Session lost', 'Log in again');
            $this->login($role);
            $webDriver->waitForElement('iframe[name="list_frame"]');
        }
        $webDriver->switchToIFrame('list_frame');
        $webDriver->waitForElement(Locator::firstElement('div.module'));
        $webDriver->wait($waitTime);
        $webDriver->switchToIFrame();
    }

    private function loadSession(string $role): bool
    {
        $webDriver = $this->getWebDriver();
        $webDriver->webDriver->manage()->deleteCookieNamed('be_typo_user');
        $webDriver->webDriver->manage()->deleteCookieNamed('be_lastLoginProvider');

        return $webDriver->loadSessionSnapshot('login.' . $role);
    }

    private function login(string $role): void
    {
        $webDriver = $this->getWebDriver();
        $webDriver->waitForElement('body[data-typo3-login-ready]', 30);
        $password = $this->_getConfig('passwords')[$role];
        $webDriver->fillField('#t3-username', $role);
        $webDriver->fillField('#t3-password', $password);
        $webDriver->pressKey('#t3-password', WebDriverKeys::ENTER);
        $webDriver->waitForElement('.t3js-scaffold-toolbar', 30);
        $webDriver->saveSessionSnapshot('login.' . $role);
    }

    private function getWebDriver(): WebDriver
    {
        return $this->getModule('WebDriver');
    }
}
