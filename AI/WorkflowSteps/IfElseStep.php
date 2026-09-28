<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\ConditionGroupFactory;

/**
 * Selects a `true` or `false` transition using ExFace ConditionGroup UXON.
 */
class IfElseStep extends AbstractAiWorkflowStep
{
    private ?UxonObject $conditionsUxon = null;

    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'if_else';
    }

    /** {@inheritDoc} */
    public function getExpectedOutcomes() : array
    {
        return ['true', 'false'];
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        if ($this->conditionsUxon === null) {
            throw new InvalidArgumentException('IfElse step "' . $this->getId() . '" requires conditions');
        }
        $data = $this->mapInput($context->getData());
        $rowIndexes = $data->getRowIndexes();
        $rowIndex = reset($rowIndexes);
        if ($rowIndex === false) {
            throw new InvalidArgumentException('IfElse step "' . $this->getId() . '" cannot evaluate empty data');
        }
        $matches = ConditionGroupFactory::createFromUxon(
            $this->getWorkbench(),
            $this->conditionsUxon->copy(),
            $data->getMetaObject()
        )->evaluate($data, $rowIndex);
        return new AiWorkflowStepResult($matches ? 'true' : 'false', $context->getData());
    }

    /**
     * @uxon-property conditions
     * @uxon-type \exface\Core\CommonLogic\Model\ConditionGroup
     * @uxon-required true
     */
    protected function setConditions(UxonObject $conditions) : IfElseStep
    {
        $this->conditionsUxon = $conditions->copy();
        return $this;
    }
}