<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Factories\AiWorkflowStepFactory;
use axenox\GenAI\Interfaces\AiWorkflowStepInterface;
use axenox\GenAI\Interfaces\AiWorkflowTransitionInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Reusable nested graph configuration for composite workflow steps.
 */
class AiWorkflowGraphDefinition
{
    /** @var AiWorkflowStepInterface[] */
    private array $steps = [];

    /** @var AiWorkflowTransitionInterface[] */
    private array $transitions = [];

    /**
     * Creates and validates a nested graph from Action-like UXON lists.
     */
    public function __construct(
        WorkbenchInterface $workbench,
        UxonObject $steps,
        UxonObject $transitions
    ) {
        foreach ($steps as $stepUxon) {
            $step = AiWorkflowStepFactory::createFromUxon(
                $workbench,
                UxonObject::fromAnything($stepUxon)
            );
            if (isset($this->steps[$step->getId()])) {
                throw new InvalidArgumentException('Duplicate nested workflow step id "' . $step->getId() . '"');
            }
            $this->steps[$step->getId()] = $step;
        }
        foreach ($transitions as $transitionUxon) {
            $this->transitions[] = new AiWorkflowTransition(UxonObject::fromAnything($transitionUxon));
        }
        (new StepWorkflowValidator())->validateGraph($this->steps, $this->transitions);
    }

    /**
     * @return AiWorkflowStepInterface[]
     */
    public function getSteps() : array
    {
        return $this->steps;
    }

    /**
     * @return AiWorkflowTransitionInterface[]
     */
    public function getTransitions() : array
    {
        return $this->transitions;
    }
}