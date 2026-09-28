<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * Maps explicitly selected AI response values into workflow state.
 */
interface AiResponseDataMapperInterface extends iCanBeConvertedToUxon, WorkbenchDependantInterface
{
    /**
     * Applies this mapper to one AI response and workflow context.
     */
    public function map(
        AiResponseInterface $response,
        AiWorkflowExecutionContextInterface $context
    ) : AiWorkflowExecutionContextInterface;
}