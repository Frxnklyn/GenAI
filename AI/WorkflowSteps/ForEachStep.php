<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractCompositeWorkflowStep;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Common\Workflows\StepWorkflowRunner;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Executes a nested graph once for each mapped input row in stable order.
 */
class ForEachStep extends AbstractCompositeWorkflowStep
{
    private const MAX_ITERATIONS_HARD_LIMIT = 10000;

    private int $maxIterations = 100;

    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'for_each';
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        if (! $this->hasOutputMapper()) {
            throw new InvalidArgumentException(
                'ForEach step "' . $this->getId() . '" requires an explicit output_mapper'
            );
        }
        $input = $this->mapInput($context->getData());
        if ($input->countRows() > $this->maxIterations) {
            throw new InvalidArgumentException(
                'ForEach step "' . $this->getId() . '" exceeds max_iterations ' . $this->maxIterations
            );
        }

        $originalData = $context->getData();
        $accumulator = null;
        $lastResponse = null;
        $graph = $this->getGraph();
        $parentStepUid = $context->getParentStepUid();
        $forEachStepUid = $context->getCurrentStepUid();
        $context->setParentStepUid($forEachStepUid);
        try {
            foreach ($input->getRows() as $index => $row) {
                $iterationData = $input->copy()->removeRows()->addRow($row);
                $context->setVariable('loop.index', $index);
                $context->setData($iterationData);
                $lastResponse = (new StepWorkflowRunner())->executeGraph(
                    $graph->getSteps(),
                    $graph->getTransitions(),
                    $context
                );
                $mapped = $this->mapOutput($context->getData());
                if ($accumulator === null) {
                    $accumulator = $mapped->copy()->removeRows();
                }
                $accumulator->addRows($mapped->getRows());
            }
        } finally {
            $context->setParentStepUid($parentStepUid)->setCurrentStepUid($forEachStepUid);
        }

        if ($accumulator === null) {
            $accumulator = $originalData->copy()->removeRows();
        }
        $context->setData($accumulator);
        if ($lastResponse !== null) {
            $context->setResponse($this->getId(), $lastResponse);
        }
        return new AiWorkflowStepResult('success', $accumulator, $lastResponse);
    }

    /**
     * Sets the maximum number of loop iterations.
     *
     * @uxon-property max_iterations
     * @uxon-type integer
     * @uxon-default 100
     */
    protected function setMaxIterations(int $maxIterations) : ForEachStep
    {
        if ($maxIterations < 1 || $maxIterations > self::MAX_ITERATIONS_HARD_LIMIT) {
            throw new InvalidArgumentException(
                'ForEach max_iterations must be between 1 and ' . self::MAX_ITERATIONS_HARD_LIMIT
            );
        }
        $this->maxIterations = $maxIterations;
        return $this;
    }
}