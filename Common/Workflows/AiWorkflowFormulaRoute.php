<?php
namespace axenox\GenAI\Common\Workflows;

use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Factories\FormulaFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Configures one formula-based decision route to an AI agent or persisted workflow.
 */
class AiWorkflowFormulaRoute implements iCanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

    private WorkbenchInterface $workbench;

    private string $condition = '';

    private string $caption = '';

    private string $agentAlias = '';

    private string $workflowAlias = '';

    private string $color = '#5b8def';

    /**
     * Creates a route and imports its UXON configuration.
     */
    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        $this->importUxonObject($uxon ?? new UxonObject());
    }

    /**
     * Evaluates this route's formula against one DataSheet row.
     */
    public function matches(DataSheetInterface $inputData, int $rowIndex) : bool
    {
        $result = FormulaFactory::createFromString($this->workbench, $this->condition)
            ->evaluate($inputData, $rowIndex);

        return BooleanDataType::cast($result) === true;
    }

    /**
     * Returns the configured condition formula.
     */
    public function getCondition() : string
    {
        return $this->condition;
    }

    /**
     * Returns the diagram caption or the formula when no caption is configured.
     */
    public function getCaption() : string
    {
        return $this->caption === '' ? $this->condition : $this->caption . ': ' . $this->condition;
    }

    /**
     * Returns the target agent alias.
     */
    public function getAgentAlias() : string
    {
        return $this->agentAlias;
    }

    /**
     * Returns the target workflow alias.
     */
    public function getWorkflowAlias() : string
    {
        return $this->workflowAlias;
    }

    /**
     * Returns whether this route delegates to a persisted workflow.
     */
    public function targetsWorkflow() : bool
    {
        return $this->workflowAlias !== '';
    }

    /**
     * Returns the configured agent or workflow alias.
     */
    public function getTargetAlias() : string
    {
        if ($this->agentAlias !== '' && $this->workflowAlias !== '') {
            throw new \InvalidArgumentException('A Formula route must define either agent_alias or workflow_alias, not both');
        }
        $targetAlias = $this->targetsWorkflow() ? $this->workflowAlias : $this->agentAlias;
        if ($targetAlias === '') {
            throw new \InvalidArgumentException('A Formula route requires agent_alias or workflow_alias');
        }

        return $targetAlias;
    }

    /**
     * Returns a safe hexadecimal color for Mermaid output.
     */
    public function getColor() : string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $this->color) === 1
            ? $this->color
            : '#5b8def';
    }

    /**
     * Returns black or white text, whichever has better contrast with the configured color.
     */
    public function getTextColor() : string
    {
        $hex = ltrim($this->getColor(), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        return (($red * 299 + $green * 587 + $blue * 114) / 1000) >= 128
            ? '#000'
            : '#fff';
    }

    /**
     * Sets the formula that must evaluate to true for this route to match.
     *
     * @uxon-property condition
     * @uxon-type formula
     * @uxon-template "=Calc(STATUS == 'rdy')"
     * @uxon-required true
     */
    protected function setCondition(string $condition) : AiWorkflowFormulaRoute
    {
        $this->condition = trim($condition);
        return $this;
    }

    /**
     * Sets a human-readable decision caption for the Mermaid diagram.
     *
     * @uxon-property caption
     * @uxon-type string
     */
    protected function setCaption(string $caption) : AiWorkflowFormulaRoute
    {
        $this->caption = trim($caption);
        return $this;
    }

    /**
    * Selects the agent invoked when this formula evaluates to true.
     *
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     */
    protected function setAgentAlias(string $agentAlias) : AiWorkflowFormulaRoute
    {
        $this->agentAlias = trim($agentAlias);
        return $this;
    }

    /**
     * Selects the persisted workflow invoked when this formula evaluates to true.
     *
     * Configure either `workflow_alias` or `agent_alias`, but never both.
     *
     * @uxon-property workflow_alias
     * @uxon-type metamodel:axenox.GenAI.AI_WORKFLOW:ALIAS_WITH_NS
     */
    protected function setWorkflowAlias(string $workflowAlias) : AiWorkflowFormulaRoute
    {
        $this->workflowAlias = trim($workflowAlias);
        return $this;
    }

    /**
     * Sets the route color used by Mermaid previews.
     *
     * @uxon-property color
     * @uxon-type color
     * @uxon-default #5b8def
     */
    protected function setColor(string $color) : AiWorkflowFormulaRoute
    {
        $this->color = trim($color);
        return $this;
    }
}