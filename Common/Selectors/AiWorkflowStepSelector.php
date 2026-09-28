<?php
namespace axenox\GenAI\Common\Selectors;

use axenox\GenAI\Interfaces\Selectors\AiWorkflowStepSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

/**
 * Resolves names used for polymorphic AI workflow steps.
 */
class AiWorkflowStepSelector extends AbstractSelector implements AiWorkflowStepSelectorInterface
{
    use ResolvableNameSelectorTrait;

    /**
     * Returns the human-readable component type.
     */
    public function getComponentType() : string
    {
        return 'AI workflow step';
    }
}