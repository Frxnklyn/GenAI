<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Common\Workflows\AiWorkflowLegendItem;
use axenox\GenAI\Interfaces\AiWorkflowInterface;
use axenox\GenAI\Interfaces\Selectors\AiWorkflowSelectorInterface;
use exface\Core\CommonLogic\Traits\AliasTrait;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Base class for configurable AI workflow prototypes.
 *
 * Workflow prototypes coordinate prompt handlers and expose their structure for documentation and
 * administration views. Concrete workflows define their participants as UXON properties and execute
 * them in {@see AiWorkflowInterface::handle()}.
 */
abstract class AbstractAiWorkflow implements AiWorkflowInterface
{
    use AliasTrait {
        getAlias as private getPrototypeAlias;
    }
    use ICanBeConvertedToUxonTrait;

    private AiWorkflowSelectorInterface $selector;

    private WorkbenchInterface $workbench;

    /** @var AiWorkflowLegendItem[] */
    private array $legend = [];

    /**
     * Creates a workflow prototype and imports its UXON configuration.
     *
     * @param AiWorkflowSelectorInterface $selector Selector identifying this workflow prototype.
     * @param UxonObject|null $uxon Workflow-specific configuration.
     */
    public function __construct(AiWorkflowSelectorInterface $selector, UxonObject $uxon = null)
    {
        $this->selector = $selector;
        $this->workbench = $selector->getWorkbench();
        $this->importUxonObject($uxon ?? new UxonObject());
    }

    /**
     * Returns the selector identifying this workflow prototype.
     */
    public function getSelector() : AliasSelectorInterface
    {
        return $this->selector;
    }

    /**
     * Returns the logical workflow alias selected by the caller or model record.
     */
    public function getAlias()
    {
        if ($this->selector->isAlias()) {
            $selector = $this->selector->toString();
            $separatorPosition = strrpos($selector, AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER);
            return $separatorPosition === false ? $selector : substr($selector, $separatorPosition + 1);
        }
        return $this->getPrototypeAlias();
    }

    /**
     * Returns the workbench used to resolve workflow participants and services.
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * Returns the configured Mermaid legend items in display order.
     *
     * @return AiWorkflowLegendItem[]
     */
    public function getLegend() : array
    {
        return $this->legend;
    }

    /**
     * Appends the configured legend as the final Mermaid subgraph.
     */
    protected function appendMermaidLegend(string $diagram) : string
    {
        if ($this->legend === []) {
            return $diagram;
        }
        $diagramLines = preg_split('/\R/', rtrim($diagram));
        $header = array_shift($diagramLines);
        preg_match('/^\s*flowchart\s+(TB|TD|BT|RL|LR)\s*$/i', (string) $header, $directionMatch);
        $direction = strtoupper($directionMatch[1] ?? 'LR');
        $lines = [
            'flowchart TB',
            '    subgraph WorkflowDiagram[" "]',
            '        direction ' . $direction
        ];
        foreach ($diagramLines as $line) {
            $lines[] = '    ' . $line;
        }
        $lines[] = '    end';
        $lines[] = '    style WorkflowDiagram fill:transparent,stroke:transparent';
        $lines[] = '    subgraph WorkflowLegend["Legend"]';
        $lines[] = '        direction LR';
        $legendNodeIds = [];
        foreach ($this->legend as $index => $item) {
            $nodeId = 'WorkflowLegendItem' . $index;
            $legendNodeIds[] = $nodeId;
            $caption = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', ' ', ' '], $item->getCaption());
            $lines[] = '        ' . $nodeId . '["' . $caption . '"]';
            $lines[] = '        style ' . $nodeId . ' fill:' . $item->getColor()
                . ',color:' . $item->getTextColor() . ',stroke:#475569,stroke-width:1px';
        }
        for ($index = 1, $count = count($legendNodeIds); $index < $count; $index++) {
            $lines[] = '        ' . $legendNodeIds[$index - 1] . ' ~~~ ' . $legendNodeIds[$index];
        }
        $lines[] = '    end';
        $lines[] = '    style WorkflowLegend fill:#f8fafc,stroke:#cbd5e1,stroke-width:1px';
        $lines[] = '    WorkflowDiagram ~~~ WorkflowLegend';
        return implode("\n", $lines);
    }

    /**
     * Defines the color legend shown below the Mermaid workflow preview.
     *
     * @uxon-property legend
     * @uxon-type \axenox\GenAI\Common\Workflows\AiWorkflowLegendItem[]
     * @uxon-template [{"caption":"AI agent","color":"#dbeafe"}]
     */
    protected function setLegend(UxonObject $legend) : AbstractAiWorkflow
    {
        $this->legend = [];
        foreach ($legend as $itemUxon) {
            $this->legend[] = new AiWorkflowLegendItem(UxonObject::fromAnything($itemUxon));
        }
        return $this;
    }
}
