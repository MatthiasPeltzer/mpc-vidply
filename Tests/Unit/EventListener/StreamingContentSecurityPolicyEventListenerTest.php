<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\EventListener;

use Mpc\MpcVidply\EventListener\StreamingContentSecurityPolicyEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;

#[CoversClass(StreamingContentSecurityPolicyEventListener::class)]
final class StreamingContentSecurityPolicyEventListenerTest extends TestCase
{
    private function createEvent(Scope $scope, Policy $policy): PolicyMutatedEvent
    {
        return new PolicyMutatedEvent($scope, null, new Policy(), $policy);
    }

    #[Test]
    public function frontendScopeExtendsConnectSrcWithBlobAndHttps(): void
    {
        $policy = new Policy();
        $event = $this->createEvent(Scope::frontend(), $policy);

        (new StreamingContentSecurityPolicyEventListener())($event);

        $current = $event->getCurrentPolicy();
        self::assertNotSame($policy, $current, 'A new policy instance is expected after extension.');
        self::assertTrue(
            $current->containsDirective(Directive::ConnectSrc, SourceScheme::blob, SourceScheme::https)
        );
    }

    #[Test]
    public function backendScopeLeavesPolicyUntouched(): void
    {
        $policy = new Policy();
        $event = $this->createEvent(Scope::backend(), $policy);

        (new StreamingContentSecurityPolicyEventListener())($event);

        self::assertSame($policy, $event->getCurrentPolicy());
        self::assertFalse(
            $event->getCurrentPolicy()->containsDirective(Directive::ConnectSrc, SourceScheme::blob, SourceScheme::https)
        );
    }
}
