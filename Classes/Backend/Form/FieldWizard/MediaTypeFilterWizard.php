<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Form\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Field wizard to add JavaScript module for media type filtering
 */
class MediaTypeFilterWizard extends AbstractNode
{
    public function render(): array
    {
        $result = $this->initializeResultArray();
        
        // Only add JavaScript if this is the media type field
        if (($this->data['parameterArray']['fieldConf']['config']['type'] ?? '') === 'select'
            && ($this->data['fieldName'] ?? '') === 'tx_mpcvidply_media_type'
        ) {
            $result['javaScriptModules'][] = JavaScriptModuleInstruction::create(
                '@mpc/mpc-vidply/MediaTypeFilter.js'
            );
        }
        
        return $result;
    }
}

