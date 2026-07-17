<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Acceptance\Support;

/**
 * @method void useExistingSession(string $role, float|int $waitTime = 0.5)
 * @method void openBackendModule(string $modulePath)
 * @method void openPageLayoutModule(int $pageId)
 * @method void openNewContentElementWizard()
 * @method void selectNewRecordWizardCategory(string $identifier)
 * @method void seeNewRecordWizardItem(string $label, ?string $categoryIdentifier = null)
 */
class BackendTester extends \Codeception\Actor
{
    use _generated\BackendTesterActions;
    use \TYPO3\TestingFramework\Core\Acceptance\Step\FrameSteps;
    use BackendSteps;
}
