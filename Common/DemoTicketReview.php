<?php

namespace axenox\GenAI\Common;

use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\WorkbenchInterface;

final class DemoTicketReview
{
    /** Passes one local sample ticket to the installed workflow without touching real tickets. */
    public static function run(WorkbenchInterface $workbench, string $scenario) : AiResponseInterface
    {
        $row = match ($scenario) {
            'draft' => ['TICKET_ID' => 'DEMO-001', 'DESCRIPTION' => 'In der Listenansicht der Demo-Tabelle fehlt seit heute die Schaltfläche für den CSV-Export.', 'DETAILS' => '', 'RETRIED' => 'no'],
            'clarify' => ['TICKET_ID' => 'DEMO-002', 'DESCRIPTION' => '', 'DETAILS' => 'CSV-Export fehlt in der Listenansicht.', 'RETRIED' => 'no'],
            'incomplete' => ['TICKET_ID' => 'DEMO-003', 'DESCRIPTION' => '', 'DETAILS' => '', 'RETRIED' => 'no'],
            default => throw new \InvalidArgumentException('Choose draft, clarify or incomplete.'),
        };
        $row += ['TRIAGE_DECISION' => '', 'TRIAGE_MESSAGE' => ''];
        $workflow = AiFactory::createWorkflowFromModelAlias($workbench, 'axenox.GenAI.demo_ticket_review');
        $object = $workbench->model()->getObject('axenox.GenAI.AI_WORKFLOW');
        $ticket = MetaObjectFactory::createTemporary(
            $workbench, 'Demo ticket', '', 'exface.Core.MySqlBuilder', $object->getDataConnection(), false
        );
        foreach (array_keys($row) as $attribute) {
            MetaObjectFactory::addAttributeTemporary($ticket, $attribute, $attribute, $attribute, 'exface.Core.String');
        }
        $prompt = new AiPrompt($workbench);
        $prompt->setPrompt('Demo ticket review');
        $prompt->setInputData(DataSheetFactory::createFromObject($ticket)->addRow($row));
        $response = $workflow->handle($prompt);
        if (($response->getJson()['status'] ?? null) === 'invalid_decision') {
            throw new \UnexpectedValueException($response->getMessage());
        }
        return $response;
    }
}
