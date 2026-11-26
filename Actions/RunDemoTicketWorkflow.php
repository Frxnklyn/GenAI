<?php

namespace axenox\GenAI\Actions;

use axenox\GenAI\Common\DemoTicketReview;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

class RunDemoTicketWorkflow extends AbstractAction implements iModifyData
{
    private string $scenario = '';

    /**
     * Selects one built-in synthetic ticket; the action does not consume selected rows.
     *
     * @uxon-property scenario
     * @uxon-type [draft,clarify,incomplete]
     * @uxon-required true
     */
    protected function setScenario(string $scenario) : RunDemoTicketWorkflow
    {
        $this->scenario = $scenario;
        return $this;
    }

    /** Runs the demo and shows the workflow's result. */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $response = DemoTicketReview::run($this->getWorkbench(), $this->scenario);
        return ResultFactory::createMessageResult($task, $response->getMessage());
    }
}
