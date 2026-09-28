<?php
namespace axenox\GenAI\Common\Selectors;

use axenox\GenAI\Interfaces\Selectors\AiWorkflowSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

/**
 * Generic selector for AI workflow prototypes.
 */
class AiWorkflowSelector extends AbstractSelector implements AiWorkflowSelectorInterface
{
    use ResolvableNameSelectorTrait;

    /**
     * Returns the human-readable component type.
     */
    public function getComponentType() : string
    {
        return 'AI workflow';
    }
}
