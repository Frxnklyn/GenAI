<?php
namespace axenox\GenAI\Interfaces;

/**
 * Contract for AI workflows configured as directed graphs of executable steps.
 */
interface AiGraphWorkflowInterface extends AiWorkflowInterface
{
    /**
     * Returns the configured workflow steps indexed by their stable IDs.
     *
     * @return AiWorkflowStepInterface[]
     */
    public function getSteps() : array;

    /**
     * Returns all configured transitions in declaration order.
     *
     * @return AiWorkflowTransitionInterface[]
     */
    public function getTransitions() : array;

    /**
     * Executes this graph inside an existing workflow context.
     */
    public function executeInContext(AiWorkflowExecutionContextInterface $context) : AiResponseInterface;
}