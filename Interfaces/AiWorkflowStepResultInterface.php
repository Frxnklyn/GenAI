<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Typed output of one workflow step.
 */
interface AiWorkflowStepResultInterface
{
    /**
     * Returns the outcome used to select the next transition.
     */
    public function getOutcome() : string;

    /**
     * Returns optional structured output data.
     */
    public function getData() : ?DataSheetInterface;

    /**
     * Returns an optional AI response produced by this step.
     */
    public function getResponse() : ?AiResponseInterface;
}