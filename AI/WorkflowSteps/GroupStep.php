<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractCompositeWorkflowStep;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Common\Workflows\StepWorkflowRunner;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;

/**
 * Executes one nested graph as a logical and visual group.
 */
class GroupStep extends AbstractCompositeWorkflowStep
{
    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'group';
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        $graph = $this->getGraph();
        $parentStepUid = $context->getParentStepUid();
        $groupStepUid = $context->getCurrentStepUid();
        $context->setParentStepUid($groupStepUid);
        try {
            $response = (new StepWorkflowRunner())->executeGraph(
                $graph->getSteps(),
                $graph->getTransitions(),
                $context
            );
        } finally {
            $context->setParentStepUid($parentStepUid)->setCurrentStepUid($groupStepUid);
        }
        if ($this->hasOutputMapper()) {
            $context->setData($this->mapOutput($context->getData()));
        }
        $context->setResponse($this->getId(), $response);
        return new AiWorkflowStepResult('success', $context->getData(), $response);
    }
}