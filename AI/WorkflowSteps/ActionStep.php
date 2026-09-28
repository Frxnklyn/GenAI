<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\ActionFactory;
use exface\Core\Interfaces\Tasks\ResultDataInterface;

/**
 * Executes one regular ExFace Action in its normal transaction boundary.
 */
class ActionStep extends AbstractAiWorkflowStep
{
    private ?UxonObject $actionUxon = null;

    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'action';
    }

    /**
     * Returns the configured Action alias for previews and diagnostics.
     */
    public function getActionAlias() : string
    {
        return $this->actionUxon === null ? '' : (string) $this->actionUxon->getProperty('alias');
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        if ($this->actionUxon === null) {
            throw new InvalidArgumentException('Action step "' . $this->getId() . '" requires action UXON');
        }
        $input = $this->mapInput($context->getData());
        $task = $context->getPrompt()->copy()->setInputData($input);
        $task->setInputDataTrusted($context->getPrompt()->isInputDataTrusted());
        $result = ActionFactory::createFromUxon($this->getWorkbench(), $this->actionUxon->copy())
            ->handle($task);

        $output = $context->getData();
        if ($result instanceof ResultDataInterface) {
            $output = $result->getData();
            if ($this->hasOutputMapper()) {
                $output = $this->mapOutput($output);
            }
        }
        return new AiWorkflowStepResult('success', $output);
    }

    /**
     * Defines the complete Action UXON, including its own alias and authorization behavior.
     *
     * @uxon-property action
     * @uxon-type \exface\Core\CommonLogic\AbstractAction
     * @uxon-required true
     */
    protected function setAction(UxonObject $action) : ActionStep
    {
        $this->actionUxon = $action->copy();
        return $this;
    }
}