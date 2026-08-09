<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\DataProcessing;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use Mpc\MpcVidply\Service\Player\DownloadResolver;
use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use Mpc\MpcVidply\Service\Player\MediaFileRegistry;
use Mpc\MpcVidply\Service\Player\TrackAssembler;
use Mpc\MpcVidply\Service\Player\UrlSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Unit tests for the null-safety contract of
 * {@see VidPlyProcessor::assembleStructuredDataContext()}.
 *
 * Only the database-free guard clauses are exercised here; resolving real track
 * sources requires FAL and is covered by the functional suite. The subject is
 * instantiated without its (service-heavy) constructor.
 */
final class VidPlyProcessorStructuredDataTest extends TestCase
{
    private VidPlyProcessor $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = (new \ReflectionClass(VidPlyProcessor::class))->newInstanceWithoutConstructor();

        // The guard clauses below stop inside the track assembler, so the
        // collaborators it needs never touch FAL or the database.
        $files = (new \ReflectionClass(MediaFileRegistry::class))->newInstanceWithoutConstructor();
        $localeFormatter = new LocaleFormatter();
        $downloadResolver = new DownloadResolver($files, $localeFormatter);

        $this->setProperty('trackAssembler', new TrackAssembler(
            $files,
            new UrlSanitizer(),
            $downloadResolver,
            $localeFormatter
        ));
        $this->setProperty('downloadResolver', $downloadResolver);
        $this->setProperty('localeFormatter', $localeFormatter);
    }

    private function setProperty(string $name, mixed $value): void
    {
        (new \ReflectionProperty(VidPlyProcessor::class, $name))->setValue($this->subject, $value);
    }

    #[Test]
    public function returnsEmptyContextWhenNoMediaRecords(): void
    {
        $context = $this->subject->assembleStructuredDataContext([], new ServerRequest('https://example.com/'));

        self::assertSame(['tracks' => [], 'downloadUrl' => null], $context);
    }

    #[Test]
    public function returnsEmptyContextForUnknownMediaType(): void
    {
        $context = $this->subject->assembleStructuredDataContext(
            [['uid' => 7, 'media_type' => 'not-a-real-type']],
            new ServerRequest('https://example.com/')
        );

        self::assertSame(['tracks' => [], 'downloadUrl' => null], $context);
    }
}
