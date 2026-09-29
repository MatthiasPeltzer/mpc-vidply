<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\EventListener;

use Mpc\MpcVidply\EventListener\StreamingContentSecurityPolicyEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

#[CoversClass(StreamingContentSecurityPolicyEventListener::class)]
final class StreamingContentSecurityPolicyEventListenerTest extends TestCase
{
    private function createEvent(Scope $scope, Policy $policy): PolicyMutatedEvent
    {
        return new PolicyMutatedEvent($scope, null, new Policy(), $policy);
    }

    /**
     * @param array<string, string> $config
     */
    private function createSubject(array $config = []): StreamingContentSecurityPolicyEventListener
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($config);

        return new StreamingContentSecurityPolicyEventListener($extensionConfiguration);
    }

    #[Test]
    public function frontendScopeExtendsConnectSrcWithBlobButNotTheWholeHttpsScheme(): void
    {
        $policy = new Policy();
        $event = $this->createEvent(Scope::frontend(), $policy);

        $this->createSubject()($event);

        $current = $event->getCurrentPolicy();
        self::assertNotSame($policy, $current, 'A new policy instance is expected after extension.');
        self::assertTrue($current->containsDirective(Directive::ConnectSrc, SourceScheme::blob));
        self::assertFalse($current->containsDirective(Directive::ConnectSrc, SourceScheme::https));
        self::assertFalse($current->containsDirective(Directive::MediaSrc, SourceScheme::https));
    }

    #[Test]
    public function frontendScopeAllowsConfiguredMediaHosts(): void
    {
        $event = $this->createEvent(Scope::frontend(), new Policy());

        $this->createSubject([
            'allowedVideoDomains' => "*.cdn.example.com\nhttps://live.example.org/path",
            'allowedAudioDomains' => 'audio.example.net, *.com',
        ])($event);

        $current = $event->getCurrentPolicy();
        foreach (['https://cdn.example.com', 'https://*.cdn.example.com', 'https://live.example.org', 'https://audio.example.net'] as $source) {
            self::assertTrue($current->containsDirective(Directive::ConnectSrc, new UriValue($source)), $source);
            self::assertTrue($current->containsDirective(Directive::MediaSrc, new UriValue($source)), $source);
        }
    }

    #[Test]
    public function bareTopLevelWildcardIsIgnored(): void
    {
        $sources = $this->createSubject(['allowedAudioDomains' => '*.com'])->buildAllowedMediaSources();

        self::assertSame([], $sources);
    }

    #[Test]
    public function backendScopeLeavesPolicyUntouched(): void
    {
        $policy = new Policy();
        $event = $this->createEvent(Scope::backend(), $policy);

        $this->createSubject(['allowedVideoDomains' => 'cdn.example.com'])($event);

        self::assertSame($policy, $event->getCurrentPolicy());
        self::assertFalse(
            $event->getCurrentPolicy()->containsDirective(Directive::ConnectSrc, SourceScheme::blob)
        );
    }
}
