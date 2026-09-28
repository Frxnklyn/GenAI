<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;

/**
 * Entry point of a graph workflow.
 */
class StartStep extends AbstractAiWorkflowStep
{
    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'start';
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        return new AiWorkflowStepResult('success', $context->getData());
    }
}