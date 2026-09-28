<?php
namespace axenox\GenAI\Common\Workflows;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Base class for steps that own a nested workflow graph.
 */
abstract class AbstractCompositeWorkflowStep extends AbstractAiWorkflowStep
{
    private ?UxonObject $stepsUxon = null;

    private ?UxonObject $transitionsUxon = null;

    private ?AiWorkflowGraphDefinition $graph = null;

    /**
     * Returns the validated nested graph.
     */
    public function getGraph() : AiWorkflowGraphDefinition
    {
        if ($this->graph === null) {
            if ($this->stepsUxon === null || $this->transitionsUxon === null) {
                throw new InvalidArgumentException(
                    'Composite workflow step "' . $this->getId() . '" requires steps and transitions'
                );
            }
            $this->graph = new AiWorkflowGraphDefinition(
                $this->getWorkbench(),
                $this->stepsUxon,
                $this->transitionsUxon
            );
        }
        return $this->graph;
    }

    /**
     * @uxon-property steps
     * @uxon-type \axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep[]
     * @uxon-required true
     */
    protected function setSteps(UxonObject $steps) : AbstractCompositeWorkflowStep
    {
        $this->stepsUxon = $steps->copy();
        $this->graph = null;
        return $this;
    }

    /**
     * @uxon-property transitions
     * @uxon-type \axenox\GenAI\Common\Workflows\AiWorkflowTransition[]
     * @uxon-required true
     */
    protected function setTransitions(UxonObject $transitions) : AbstractCompositeWorkflowStep
    {
        $this->transitionsUxon = $transitions->copy();
        $this->graph = null;
        return $this;
    }
}