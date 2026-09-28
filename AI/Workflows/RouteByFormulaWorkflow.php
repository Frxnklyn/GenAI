<?php
namespace axenox\GenAI\AI\Workflows;

use axenox\GenAI\Common\AbstractAiWorkflow;
use axenox\GenAI\Common\Workflows\AiWorkflowFormulaRoute;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Selects one agent or persisted workflow using ordered ExFace formulas.
 *
 * ## Example
 *
 * ```
 * {
 *   "routes": [
 *     {
 *       "caption": "Ready and important?",
 *       "condition": "=Calc(STATUS == 'rdy' && PRIORITY >= 80)",
 *       "workflow_alias": "my.App.implementation_workflow",
 *       "color": "#2e7d32"
 *     }
 *   ],
 *   "fallback_agent_alias": "my.App.triage_agent"
 * }
 * ```
 */
class RouteByFormulaWorkflow extends AbstractAiWorkflow
{
    private const MAX_NESTED_WORKFLOWS = 10;

    /** @var array<string, true> */
    private static array $activeWorkflowAliases = [];

    /** @var array<string, true> */
    private static array $activeMermaidWorkflowAliases = [];

    /** @var AiWorkflowFormulaRoute[] */
    private array $routes = [];

    private ?string $fallbackAgentAlias = null;

    /**
    * Selects a prompt handler using formulas and returns its response unchanged.
     */
    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        if (! $prompt->hasInputData()) {
            throw new InvalidArgumentException('AI workflow "' . $this->getAliasWithNamespace() . '" requires structured input data');
        }

        $workflowAlias = $this->getAliasWithNamespace();
        if (isset(self::$activeWorkflowAliases[$workflowAlias])) {
            throw new InvalidArgumentException('Cyclic AI workflow delegation detected at "' . $workflowAlias . '"');
        }
        if (count(self::$activeWorkflowAliases) >= self::MAX_NESTED_WORKFLOWS) {
            throw new InvalidArgumentException('AI workflow nesting exceeds the limit of ' . self::MAX_NESTED_WORKFLOWS);
        }

        self::$activeWorkflowAliases[$workflowAlias] = true;
        try {
            $route = $this->selectRoute($prompt->getInputData());
            if ($route !== null) {
                $handler = $route->targetsWorkflow()
                    ? AiFactory::createWorkflowFromModelAlias($this->getWorkbench(), $route->getWorkflowAlias())
                    : AiFactory::createAgentFromString($this->getWorkbench(), $route->getAgentAlias());
                return $handler->handle($prompt);
            }

            return AiFactory::createAgentFromString($this->getWorkbench(), $this->fallbackAgentAlias)->handle($prompt);
        } finally {
            unset(self::$activeWorkflowAliases[$workflowAlias]);
        }
    }

    /**
     * Returns the first route whose formula evaluates to true.
     */
    public function selectRoute(DataSheetInterface $inputData) : ?AiWorkflowFormulaRoute
    {
        $rowIndexes = $inputData->getRowIndexes();
        $firstRowIndex = reset($rowIndexes);
        if ($firstRowIndex === false) {
            throw new InvalidArgumentException('Cannot route an AI prompt with an empty input DataSheet');
        }

        foreach ($this->routes as $route) {
            if ($route->matches($inputData, $firstRowIndex)) {
                $route->getTargetAlias();
                return $route;
            }
        }

        if ($this->fallbackAgentAlias !== null && $this->fallbackAgentAlias !== '') {
            return null;
        }

        throw new InvalidArgumentException('No formula route in AI workflow "' . $this->getAliasWithNamespace() . '" matches the input data');
    }

    /**
     * Returns the selected agent alias for agent-only configurations.
     */
    public function selectAgentAlias(DataSheetInterface $inputData) : string
    {
        $route = $this->selectRoute($inputData);
        if ($route === null) {
            return $this->fallbackAgentAlias;
        }
        if ($route->targetsWorkflow()) {
            throw new InvalidArgumentException('The selected Formula route targets workflow "' . $route->getWorkflowAlias() . '", not an agent');
        }

        return $route->getAgentAlias();
    }

    /**
     * Describes formula-based deterministic routing.
     */
    public function getDescription() : string
    {
        return 'Evaluates ordered ExFace formulas and invokes the first matching agent or persisted workflow.';
    }

    /**
     * Builds a Mermaid decision tree from the exact formulas used at runtime.
     */
    public function getMermaidDiagram() : string
    {
        $workflowAlias = $this->getAliasWithNamespace();
        if (isset(self::$activeMermaidWorkflowAliases[$workflowAlias])) {
            return $this->appendMermaidLegend(
                "flowchart LR\n    Input[\"Nested workflow input\"] --> Output[\"Cycle detected: "
                . $this->escapeMermaidLabel($workflowAlias) . '\"]'
            );
        }
        if (count(self::$activeMermaidWorkflowAliases) >= self::MAX_NESTED_WORKFLOWS) {
            return $this->appendMermaidLegend(
                "flowchart LR\n    Input[\"Nested workflow input\"] --> Output[\"Nesting limit reached\"]"
            );
        }

        self::$activeMermaidWorkflowAliases[$workflowAlias] = true;
        try {
            return $this->appendMermaidLegend($this->buildMermaidDiagram());
        } finally {
            unset(self::$activeMermaidWorkflowAliases[$workflowAlias]);
        }
    }

    /**
     * Builds this workflow and all configured nested workflow diagrams.
     */
    private function buildMermaidDiagram() : string
    {
        $lines = [
            'flowchart LR',
            '    Input["Structured prompt data"]',
            '    Output["AI response"]'
        ];
        $previousNode = 'Input';

        foreach ($this->routes as $index => $route) {
            $conditionNode = 'Condition' . $index;
            $targetNode = 'Target' . $index;
            $edgeLabel = $index === 0 ? '' : '|false / otherwise| ';
            $lines[] = '    ' . $previousNode . ' -->' . $edgeLabel . $conditionNode . '{"' . $this->escapeMermaidLabel($route->getCaption()) . '"}';
            if ($route->targetsWorkflow()) {
                $nestedDiagram = $this->buildNestedWorkflowDiagram($route, $index);
                array_push($lines, ...$nestedDiagram['lines']);
                $lines[] = '    ' . $conditionNode . ' -->|true| ' . $nestedDiagram['input'];
                $lines[] = '    ' . $nestedDiagram['output'] . ' --> Output';
            } else {
                $lines[] = '    ' . $conditionNode . ' -->|true| ' . $targetNode . '["Agent: ' . $this->escapeMermaidLabel($route->getAgentAlias()) . '"]';
                $lines[] = '    ' . $targetNode . ' --> Output';
                $lines[] = '    style ' . $targetNode . ' fill:' . $route->getColor() . ',stroke:#333,color:' . $route->getTextColor();
            }
            $previousNode = $conditionNode;
        }

        if ($this->fallbackAgentAlias !== null && $this->fallbackAgentAlias !== '') {
            $lines[] = '    ' . $previousNode . ' -->|false / otherwise| Fallback["' . $this->escapeMermaidLabel($this->fallbackAgentAlias) . '"]';
            $lines[] = '    Fallback --> Output';
        } else {
            $lines[] = '    ' . $previousNode . ' -->|false / otherwise| NoMatch["No matching formula"]';
        }

        return implode("\n", $lines);
    }

    /**
     * Loads and namespaces a nested workflow's Mermaid graph for safe embedding.
     *
     * @return array{lines: string[], input: string, output: string}
     */
    private function buildNestedWorkflowDiagram(AiWorkflowFormulaRoute $route, int $index) : array
    {
        $workflowAlias = $route->getWorkflowAlias();
        $workflow = AiFactory::createWorkflowFromModelAlias($this->getWorkbench(), $workflowAlias);
        $diagram = preg_replace('/^\s*flowchart\s+\w+\s*\R?/i', '', $workflow->getMermaidDiagram(), 1);
        $prefix = 'Nested' . $index . '_';

        preg_match_all('/^\s*([A-Za-z][A-Za-z0-9_]*)\s*(?=\[|\{|\()/m', $diagram, $nodeMatches);
        preg_match_all('/^\s*subgraph\s+([A-Za-z][A-Za-z0-9_]*)/mi', $diagram, $subgraphMatches);
        $nodeIds = array_values(array_unique(array_merge($nodeMatches[1], $subgraphMatches[1])));
        if ($nodeIds === []) {
            $targetNode = $prefix . 'Workflow';
            return [
                'lines' => ['    ' . $targetNode . '[["Workflow: ' . $this->escapeMermaidLabel($workflowAlias) . '"]]'],
                'input' => $targetNode,
                'output' => $targetNode
            ];
        }

        $inputNode = in_array('Input', $nodeIds, true) ? 'Input' : (in_array('Prompt', $nodeIds, true) ? 'Prompt' : $nodeIds[0]);
        $outputNode = in_array('Output', $nodeIds, true) ? 'Output' : (in_array('Response', $nodeIds, true) ? 'Response' : $nodeIds[array_key_last($nodeIds)]);
        usort($nodeIds, static fn(string $left, string $right) : int => strlen($right) <=> strlen($left));
        foreach ($nodeIds as $nodeId) {
            $diagram = preg_replace('/\b' . preg_quote($nodeId, '/') . '\b/', $prefix . $nodeId, $diagram);
        }

        $subgraphId = 'NestedWorkflow' . $index;
        $lines = ['    subgraph ' . $subgraphId . '["Workflow: ' . $this->escapeMermaidLabel($workflowAlias) . '"]'];
        foreach (preg_split('/\R/', trim($diagram)) as $diagramLine) {
            $lines[] = '        ' . $diagramLine;
        }
        $lines[] = '    end';
        $lines[] = '    style ' . $subgraphId . ' stroke:' . $route->getColor() . ',stroke-width:2px';

        return [
            'lines' => $lines,
            'input' => $prefix . $inputNode,
            'output' => $prefix . $outputNode
        ];
    }

    /**
    * Defines ordered formula-to-handler routes.
     *
     * @uxon-property routes
     * @uxon-type \axenox\GenAI\Common\Workflows\AiWorkflowFormulaRoute[]
    * @uxon-template [{"caption":"","condition":"=Calc(STATUS == 'rdy')","agent_alias":"","workflow_alias":"","color":"#5b8def"}]
     * @uxon-required true
     */
    protected function setRoutes(UxonObject $routes) : RouteByFormulaWorkflow
    {
        $this->routes = [];
        foreach ($routes as $route) {
            $this->routes[] = $route instanceof AiWorkflowFormulaRoute
                ? $route
                : new AiWorkflowFormulaRoute($this->getWorkbench(), UxonObject::fromAnything($route));
        }
        return $this;
    }

    /**
     * Selects an optional agent used when no formula evaluates to true.
     *
     * @uxon-property fallback_agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     */
    protected function setFallbackAgentAlias(string $agentAlias) : RouteByFormulaWorkflow
    {
        $this->fallbackAgentAlias = trim($agentAlias);
        return $this;
    }

    /**
     * Removes Mermaid control characters from configured labels.
     */
    private function escapeMermaidLabel(string $label) : string
    {
        return str_replace(["\r", "\n", '"', '[', ']', '{', '}', '|'], [' ', ' ', "'", '(', ')', '(', ')', '#124;'], $label);
    }
}