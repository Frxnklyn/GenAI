<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Common\Workflows\AiWorkflowSwitchCase;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Selects the first matching named case or a required default outcome.
 */
class SwitchStep extends AbstractAiWorkflowStep
{
    /** @var AiWorkflowSwitchCase[] */
    private array $cases = [];

    private string $defaultOutcome = 'otherwise';

    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'switch';
    }

    /** {@inheritDoc} */
    public function getExpectedOutcomes() : array
    {
        return array_merge(
            array_map(
                static fn(AiWorkflowSwitchCase $case) : string => $case->getOutcome(),
                $this->cases
            ),
            [$this->defaultOutcome]
        );
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        $data = $this->mapInput($context->getData());
        $rowIndexes = $data->getRowIndexes();
        $rowIndex = reset($rowIndexes);
        if ($rowIndex === false) {
            throw new InvalidArgumentException('Switch step "' . $this->getId() . '" cannot evaluate empty data');
        }
        foreach ($this->cases as $case) {
            if ($case->matches($data, $rowIndex)) {
                return new AiWorkflowStepResult($case->getOutcome(), $context->getData());
            }
        }
        return new AiWorkflowStepResult($this->defaultOutcome, $context->getData());
    }

    /**
     * @uxon-property cases
     * @uxon-type \axenox\GenAI\Common\Workflows\AiWorkflowSwitchCase[]
     * @uxon-required true
     */
    protected function setCases(UxonObject $cases) : SwitchStep
    {
        $this->cases = [];
        $outcomes = [];
        foreach ($cases as $caseUxon) {
            $case = new AiWorkflowSwitchCase($this->getWorkbench(), UxonObject::fromAnything($caseUxon));
            if (isset($outcomes[$case->getOutcome()])) {
                throw new InvalidArgumentException('Duplicate Switch case outcome "' . $case->getOutcome() . '"');
            }
            $outcomes[$case->getOutcome()] = true;
            $this->cases[] = $case;
        }
        return $this;
    }

    /**
     * @uxon-property default_outcome
     * @uxon-type string
     * @uxon-default otherwise
     */
    protected function setDefaultOutcome(string $outcome) : SwitchStep
    {
        $this->defaultOutcome = strtolower(trim($outcome));
        return $this;
    }
}