<?php
namespace axenox\GenAI\Formulas;

use axenox\GenAI\Common\Selectors\AiWorkflowSelector;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\Model\Formula;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\FormulaError;
use exface\Core\Factories\DataTypeFactory;

/**
 * Renders a configured AI workflow as Mermaid Markdown.
 */
class AiWorkflowMermaid extends Formula
{
    /**
     * Instantiates the selected workflow prototype and returns its current diagram.
     */
    public function run(string $prototypePath = null, $configUxon = null, string $workflowAlias = null)
    {
        if ($prototypePath === null || trim($prototypePath) === '') {
            return '';
        }

        try {
            $selector = new AiWorkflowSelector(
                $this->getWorkbench(),
                $workflowAlias === null || trim($workflowAlias) === '' ? $prototypePath : $workflowAlias
            );
            $uxon = $configUxon === null || $configUxon === ''
                ? new UxonObject()
                : UxonObject::fromAnything($configUxon);
            $workflow = AiFactory::createWorkflowFromPrototype($selector, $prototypePath, $uxon);

            return "```mermaid\n" . $workflow->getMermaidDiagram() . "\n```";
        } catch (\Throwable $e) {
            throw new FormulaError($this, 'Cannot render the AI workflow diagram', null, $e);
        }
    }

    /**
     * Returns the Markdown result data type.
     */
    public function getDataType()
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }
}