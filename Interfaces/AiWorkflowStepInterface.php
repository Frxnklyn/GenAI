<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * One configurable and executable step in an AI workflow graph.
 */
interface AiWorkflowStepInterface extends iCanBeConvertedToUxon, WorkbenchDependantInterface
{
    /**
     * Returns the stable identifier used by transitions and run logs.
     */
    public function getId() : string;

    /**
     * Returns the human-readable label used in previews and logs.
     */
    public function getCaption() : string;

    /**
     * Returns the optional node color used in Mermaid previews.
     */
    public function getColor() : ?string;

    /**
     * Returns a readable text color for the configured node color.
     */
    public function getTextColor() : string;

    /**
     * Returns the stable step type used in run logs and Mermaid styling.
     */
    public function getType() : string;

    /**
     * Returns outcomes that require configured transitions.
     *
     * @return string[]
     */
    public function getExpectedOutcomes() : array;

    /**
     * Executes the step against the shared workflow context.
     */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface;
}