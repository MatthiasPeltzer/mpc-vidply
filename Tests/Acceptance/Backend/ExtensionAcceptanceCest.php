<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Acceptance\Backend;

use Mpc\MpcVidply\Tests\Acceptance\Support\BackendTester;

final class ExtensionAcceptanceCest
{
    public function _before(BackendTester $I): void
    {
        $I->useExistingSession('admin');
    }

    public function vidplyPreviewIsRenderedInPageModule(BackendTester $I): void
    {
        $I->openPageLayoutModule(2);
        $I->see('No media items selected');
        $I->see('Please add media items to this VidPly element');
    }
}
