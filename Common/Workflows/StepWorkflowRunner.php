<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Interfaces\AiGraphWorkflowInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepInterface;
use axenox\GenAI\Interfaces\AiWorkflowTransitionInterface;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Executes one validated workflow graph by following typed step outcomes.
 */
class StepWorkflowRunner
{
    /**
     * Traverses the graph until an EndStep returns the final AI response.
     */
    public function execute(
        AiGraphWorkflowInterface $workflow,
        AiWorkflowExecutionContextInterface $context
    ) : AiResponseInterface {
        return $this->executeGraph($workflow->getSteps(), $workflow->getTransitions(), $context);
    }

    /**
     * Traverses a reusable graph definition.
     *
     * @param AiWorkflowStepInterface[] $steps
     * @param AiWorkflowTransitionInterface[] $transitions
     */
    public function executeGraph(
        array $steps,
        array $transitions,
        AiWorkflowExecutionContextInterface $context
    ) : AiResponseInterface {
        $currentStep = null;
        foreach ($steps as $step) {
            if ($step->getType() === 'start') {
                $currentStep = $step;
                break;
            }
        }
        if ($currentStep === null) {
            throw new InvalidArgumentException('Cannot execute a graph workflow without a StartStep');
        }

        while (true) {
            $context->consumeStep($currentStep->getId());
            $stepUid = $context->getRunLog()->startStep(
                $currentStep->getId(),
                $currentStep->getType(),
                $context->getParentStepUid(),
                null,
                $currentStep->getCaption()
            );
            $context->setCurrentStepUid($stepUid);
            try {
                $result = $currentStep->execute($context);
            } catch (\Throwable $error) {
                $context->getRunLog()->finishStep(
                    $stepUid,
                    WorkflowRunLog::STATUS_FAILED,
                    'error',
                    null,
                    null,
                    $error
                );
                $transition = $this->findTransition($transitions, $currentStep->getId(), 'error');
                if ($transition === null) {
                    throw $error;
                }
                $context->setVariable('error.class', get_class($error));
                $context->setVariable('error.message', $error->getMessage());
                $currentStep = $steps[$transition->getToStep()];
                continue;
            }

            if ($result->getData() !== null) {
                $context->setData($result->getData());
            }
            if ($result->getResponse() !== null) {
                $context->setResponse($currentStep->getId(), $result->getResponse());
            }
            $conversationUid = null;
            if ($result->getResponse() !== null) {
                $conversationUid = $result->getResponse()->getConversationId() ?: null;
            }
            $context->getRunLog()->finishStep(
                $stepUid,
                WorkflowRunLog::STATUS_COMPLETED,
                $result->getOutcome(),
                null,
                $conversationUid
            );
            if ($currentStep->getType() === 'end') {
                if ($result->getResponse() === null) {
                    throw new InvalidArgumentException(
                        'End step "' . $currentStep->getId() . '" did not produce an AI response'
                    );
                }
                return $result->getResponse();
            }

            $transition = $this->findTransition($transitions, $currentStep->getId(), $result->getOutcome());
            if ($transition === null) {
                throw new InvalidArgumentException(
                    'No transition from step "' . $currentStep->getId()
                    . '" for outcome "' . $result->getOutcome() . '"'
                );
            }
            $currentStep = $steps[$transition->getToStep()];
        }
    }

    /**
     * Returns the unique transition matching one source and outcome.
     */
    private function findTransition(
        array $transitions,
        string $fromStep,
        string $outcome
    ) : ?AiWorkflowTransitionInterface {
        foreach ($transitions as $transition) {
            if ($transition->getFromStep() === $fromStep && $transition->getOutcome() === $outcome) {
                return $transition;
            }
        }
        return null;
    }
}