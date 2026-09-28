<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * Connects one workflow step outcome to the next step.
 */
interface AiWorkflowTransitionInterface extends iCanBeConvertedToUxon
{
    /**
     * Returns the source step ID.
     */
    public function getFromStep() : string;

    /**
     * Returns the outcome that selects this transition.
     */
    public function getOutcome() : string;

    /**
     * Returns the destination step ID.
     */
    public function getToStep() : string;

    /**
     * Returns the optional diagram label.
     */
    public function getCaption() : string;

    /**
     * Returns the validated Mermaid route color.
     */
    public function getColor() : string;
}