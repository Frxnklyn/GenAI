<?php
namespace axenox\GenAI\AI\Workflows;

use axenox\GenAI\Common\AbstractAiWorkflow;
use axenox\GenAI\Common\Workflows\AiWorkflowRoute;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Selects one agent by matching configured values against structured prompt input data.
 *
 * Routes are evaluated in order and the first match wins. A route can inspect scalar attributes
 * such as a Jira status or list-valued attributes such as labels.
 *
 * ## Example
 *
 * ```
 * {
 *   "routes": [
 *     {
 *       "attribute_alias": "status",
 *       "values": ["Ready", "Rdy"],
 *       "agent_alias": "my.App.ready_agent",
 *       "color": "#2e7d32"
 *     }
 *   ]
 * }
 * ```
 */
class RouteByDataValueWorkflow extends AbstractAiWorkflow
{
    /** @var AiWorkflowRoute[] */
    private array $routes = [];

    private ?string $fallbackAgentAlias = null;

    /**
     * Selects an agent from the prompt input and returns that agent's response unchanged.
     */
    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        if (! $prompt->hasInputData()) {
            throw new InvalidArgumentException('AI workflow "' . $this->getAliasWithNamespace() . '" requires structured input data');
        }

        $agentAlias = $this->selectAgentAlias($prompt->getInputData());
        return AiFactory::createAgentFromString($this->getWorkbench(), $agentAlias)->handle($prompt);
    }

    /**
     * Returns the first matching target agent alias without invoking the agent.
     */
    public function selectAgentAlias(DataSheetInterface $inputData) : string
    {
        $rowIndexes = $inputData->getRowIndexes();
        $firstRowIndex = reset($rowIndexes);
        if ($firstRowIndex === false) {
            throw new InvalidArgumentException('Cannot route an AI prompt with an empty input DataSheet');
        }

        foreach ($this->routes as $route) {
            $column = $inputData->getColumns()->getByExpression($route->getAttributeAlias());
            if ($column === false) {
                continue;
            }
            if ($route->matches($column->getValue($firstRowIndex), $column->getValueListDelimiter())) {
                return $route->getAgentAlias();
            }
        }

        if ($this->fallbackAgentAlias !== null && $this->fallbackAgentAlias !== '') {
            return $this->fallbackAgentAlias;
        }

        throw new InvalidArgumentException('No route in AI workflow "' . $this->getAliasWithNamespace() . '" matches the input data');
    }

    /**
     * Describes deterministic value-based agent routing.
     */
    public function getDescription() : string
    {
        return 'Routes structured prompt input to the first agent whose configured attribute values match.';
    }

    /**
     * Builds a Mermaid flowchart from the exact route order used at runtime.
     */
    public function getMermaidDiagram() : string
    {
        $lines = [
            'flowchart LR',
            '    Input["Structured prompt data"]',
            '    Output["AI response"]'
        ];
        $previousNode = 'Input';

        foreach ($this->routes as $index => $route) {
            $routeNode = 'Route' . $index;
            $agentNode = 'Agent' . $index;
            $label = $route->getAttributeAlias() . ' = ' . implode(' / ', $route->getValues());
            $edgeLabel = $index === 0 ? '' : '|otherwise| ';
            $lines[] = '    ' . $previousNode . ' -->' . $edgeLabel . $routeNode . '{"' . $this->escapeMermaidLabel($label) . '"}';
            $lines[] = '    ' . $routeNode . ' -->|match| ' . $agentNode . '["' . $this->escapeMermaidLabel($route->getAgentAlias()) . '"]';
            $lines[] = '    ' . $agentNode . ' --> Output';
            $lines[] = '    style ' . $agentNode . ' fill:' . $route->getColor() . ',stroke:#333,color:' . $route->getTextColor();
            $previousNode = $routeNode;
        }

        if ($this->fallbackAgentAlias !== null && $this->fallbackAgentAlias !== '') {
            $lines[] = '    ' . $previousNode . ' -->|otherwise| Fallback["' . $this->escapeMermaidLabel($this->fallbackAgentAlias) . '"]';
            $lines[] = '    Fallback --> Output';
        } else {
            $lines[] = '    ' . $previousNode . ' -->|otherwise| NoMatch["No matching route"]';
        }

        return $this->appendMermaidLegend(implode("\n", $lines));
    }

    /**
     * Defines ordered value-to-agent routes.
     *
     * @uxon-property routes
     * @uxon-type \axenox\GenAI\Common\Workflows\AiWorkflowRoute[]
     * @uxon-template [{"attribute_alias":"","values":[""],"agent_alias":"","color":"#5b8def"}]
     * @uxon-required true
     */
    protected function setRoutes(UxonObject $routes) : RouteByDataValueWorkflow
    {
        $this->routes = [];
        foreach ($routes as $route) {
            $this->routes[] = $route instanceof AiWorkflowRoute
                ? $route
                : new AiWorkflowRoute($this->getWorkbench(), UxonObject::fromAnything($route));
        }
        return $this;
    }

    /**
     * Selects an optional agent used when no route matches.
     *
     * @uxon-property fallback_agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     */
    protected function setFallbackAgentAlias(string $agentAlias) : RouteByDataValueWorkflow
    {
        $this->fallbackAgentAlias = trim($agentAlias);
        return $this;
    }

    /**
     * Removes Mermaid control characters from user-configured labels.
     */
    private function escapeMermaidLabel(string $label) : string
    {
        return str_replace(["\r", "\n", '"', '[', ']', '{', '}', '|'], [' ', ' ', "'", '(', ')', '(', ')', '/'], $label);
    }
}