<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\AI\WorkflowSteps\ActionStep;
use axenox\GenAI\AI\WorkflowSteps\AgentStep;
use axenox\GenAI\AI\WorkflowSteps\ForEachStep;
use axenox\GenAI\AI\WorkflowSteps\SubworkflowStep;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiGraphWorkflowInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepInterface;
use axenox\GenAI\Interfaces\AiWorkflowTransitionInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Renders runtime graph configuration as safe, namespaced Mermaid flowcharts.
 */
class StepWorkflowMermaidRenderer
{
    private const MAX_NESTING_DEPTH = 10;

    private WorkbenchInterface $workbench;

    private int $edgeIndex = 0;

    /** @var array<string, true> */
    private array $activeWorkflowAliases = [];

    /**
     * Creates a renderer using the workflow's model context.
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    /**
     * Renders a complete graph workflow without Markdown fences.
     */
    public function render(AiGraphWorkflowInterface $workflow) : string
    {
        $lines = ['flowchart LR'];
        $workflowAlias = $workflow->getAliasWithNamespace();
        $this->activeWorkflowAliases[$workflowAlias] = true;
        try {
            $this->renderGraph($workflow->getSteps(), $workflow->getTransitions(), 'Root_', $lines);
            return implode("\n", $lines);
        } finally {
            unset($this->activeWorkflowAliases[$workflowAlias]);
        }
    }

    /**
     * Renders one graph and returns its entry and exit node IDs.
     *
     * @param AiWorkflowStepInterface[] $steps
     * @param AiWorkflowTransitionInterface[] $transitions
     * @param string[] $lines
     * @return array{entry:string, exits:string[]}
     */
    private function renderGraph(array $steps, array $transitions, string $prefix, array &$lines) : array
    {
        $endpoints = [];
        $startId = '';
        $exitIds = [];
        foreach ($steps as $step) {
            $endpoint = $this->renderStep($step, $prefix, $lines);
            $endpoints[$step->getId()] = $endpoint;
            if ($step->getType() === 'start') {
                $startId = $endpoint['entry'];
            }
            if ($step->getType() === 'end') {
                $exitIds[] = $endpoint['exit'];
            }
        }

        foreach ($transitions as $transition) {
            $from = $endpoints[$transition->getFromStep()]['exit'];
            $to = $endpoints[$transition->getToStep()]['entry'];
            $label = $transition->getCaption() === ''
                ? $transition->getOutcome()
                : $transition->getCaption();
            $lines[] = '    ' . $from . ' -->|"' . $this->escapeLabel($label) . '"| ' . $to;
            $lines[] = '    linkStyle ' . $this->edgeIndex++ . ' stroke:' . $transition->getColor()
                . ($transition->getOutcome() === 'error' ? ',stroke-width:3px,stroke-dasharray:5 3' : '');
        }

        return ['entry' => $startId, 'exits' => $exitIds];
    }

    /**
     * Renders one simple, composite, or nested-workflow step.
     *
     * @param string[] $lines
     * @return array{entry:string, exit:string}
     */
    private function renderStep(AiWorkflowStepInterface $step, string $prefix, array &$lines) : array
    {
        $nodeId = $this->nodeId($prefix . $step->getId());
        if ($step instanceof AbstractCompositeWorkflowStep) {
            return $this->renderCompositeStep($step, $nodeId, $prefix, $lines);
        }
        if ($step instanceof SubworkflowStep) {
            return $this->renderSubworkflowStep($step, $nodeId, $prefix, $lines);
        }

        $caption = $this->escapeLabel($this->buildCaption($step));
        switch ($step->getType()) {
            case 'start':
            case 'end':
                $lines[] = '    ' . $nodeId . '(["' . $caption . '"])';
                break;
            case 'if_else':
            case 'switch':
                $lines[] = '    ' . $nodeId . '{"' . $caption . '"}';
                break;
            case 'action':
                $lines[] = '    ' . $nodeId . '[["' . $caption . '"]]';
                break;
            default:
                $lines[] = '    ' . $nodeId . '["' . $caption . '"]';
        }
            if ($step->getColor() !== null) {
                $lines[] = '    style ' . $nodeId . ' fill:' . $step->getColor()
                . ',color:' . $step->getTextColor() . ',stroke:#475569,stroke-width:1.5px';
            }
        return ['entry' => $nodeId, 'exit' => $nodeId];
    }

    /**
     * Renders GroupStep and ForEachStep as nested Mermaid subgraphs.
     *
     * @param string[] $lines
     * @return array{entry:string, exit:string}
     */
    private function renderCompositeStep(
        AbstractCompositeWorkflowStep $step,
        string $nodeId,
        string $prefix,
        array &$lines
    ) : array {
        $graph = $step->getGraph();
        $title = ($step instanceof ForEachStep ? 'For each: ' : 'Group: ') . $step->getCaption();
        $lines[] = '    subgraph ' . $nodeId . '_Group["' . $this->escapeLabel($title) . '"]';
        $endpoints = $this->renderGraph(
            $graph->getSteps(),
            $graph->getTransitions(),
            $prefix . $step->getId() . '_',
            $lines
        );
        if ($step instanceof ForEachStep && $endpoints['exits'] !== []) {
            foreach ($endpoints['exits'] as $exitId) {
                $lines[] = '    ' . $exitId . ' -.->|"next row"| ' . $endpoints['entry'];
                $lines[] = '    linkStyle ' . $this->edgeIndex++ . ' stroke:#667085,stroke-dasharray:4 3';
            }
        }
        $lines[] = '    end';
        return [
            'entry' => $endpoints['entry'],
            'exit' => $endpoints['exits'][0] ?? $endpoints['entry']
        ];
    }

    /**
     * Renders the complete nested workflow where possible and a bounded fallback otherwise.
     *
     * @param string[] $lines
     * @return array{entry:string, exit:string}
     */
    private function renderSubworkflowStep(
        SubworkflowStep $step,
        string $nodeId,
        string $prefix,
        array &$lines
    ) : array {
        $workflowAlias = $step->getWorkflowAlias();
        if (isset($this->activeWorkflowAliases[$workflowAlias])
            || count($this->activeWorkflowAliases) >= self::MAX_NESTING_DEPTH) {
            $lines[] = '    ' . $nodeId . '[["Workflow cycle: ' . $this->escapeLabel($workflowAlias) . '"]]';
            return ['entry' => $nodeId, 'exit' => $nodeId];
        }

        $this->activeWorkflowAliases[$workflowAlias] = true;
        try {
            $workflow = AiFactory::createWorkflowFromModelAlias($this->workbench, $workflowAlias);
            if (! $workflow instanceof AiGraphWorkflowInterface) {
                return $this->renderForeignWorkflowDiagram($workflow->getMermaidDiagram(), $nodeId, $workflowAlias, $lines);
            }
            $lines[] = '    subgraph ' . $nodeId . '_Workflow["Workflow: '
                . $this->escapeLabel($workflowAlias) . '"]';
            $endpoints = $this->renderGraph(
                $workflow->getSteps(),
                $workflow->getTransitions(),
                $prefix . $step->getId() . '_',
                $lines
            );
            $lines[] = '    end';
            return [
                'entry' => $endpoints['entry'],
                'exit' => $endpoints['exits'][0] ?? $endpoints['entry']
            ];
        } catch (\Throwable $error) {
            $lines[] = '    ' . $nodeId . '[["Workflow: ' . $this->escapeLabel($workflowAlias) . '"]]';
            return ['entry' => $nodeId, 'exit' => $nodeId];
        } finally {
            unset($this->activeWorkflowAliases[$workflowAlias]);
        }
    }

    /**
     * Namespaces and embeds a Mermaid diagram from a non-graph workflow prototype.
     *
     * @param string[] $lines
     * @return array{entry:string, exit:string}
     */
    private function renderForeignWorkflowDiagram(
        string $diagram,
        string $nodeId,
        string $workflowAlias,
        array &$lines
    ) : array {
        $diagram = preg_replace('/^\s*flowchart\s+\w+\s*\R?/i', '', $diagram, 1);
        preg_match_all('/(?<![A-Za-z0-9_])([A-Za-z][A-Za-z0-9_]*)\s*(?=\[|\{|\()/', $diagram, $matches);
        $ids = array_values(array_unique($matches[1]));
        if ($ids === []) {
            $lines[] = '    ' . $nodeId . '[["Workflow: ' . $this->escapeLabel($workflowAlias) . '"]]';
            return ['entry' => $nodeId, 'exit' => $nodeId];
        }
        $input = in_array('Input', $ids, true) ? 'Input' : (in_array('Prompt', $ids, true) ? 'Prompt' : $ids[0]);
        $output = in_array('Output', $ids, true) ? 'Output' : (in_array('Response', $ids, true) ? 'Response' : $ids[array_key_last($ids)]);
        usort($ids, static fn(string $left, string $right) : int => strlen($right) <=> strlen($left));
        foreach ($ids as $id) {
            $diagram = preg_replace('/\b' . preg_quote($id, '/') . '\b/', $nodeId . '_' . $id, $diagram);
        }
        $edgeOffset = $this->edgeIndex;
        $diagram = preg_replace_callback(
            '/\blinkStyle\s+([0-9]+(?:\s*,\s*[0-9]+)*)/',
            static function (array $matches) use ($edgeOffset) : string {
                $indexes = array_map(
                    static fn(string $index) : string => (string) ((int) trim($index) + $edgeOffset),
                    explode(',', $matches[1])
                );
                return 'linkStyle ' . implode(',', $indexes);
            },
            $diagram
        );
        preg_match_all('/-->|-\.->|==>/', $diagram, $edgeMatches);
        $this->edgeIndex += count($edgeMatches[0]);
        $lines[] = '    subgraph ' . $nodeId . '_Workflow["Workflow: '
            . $this->escapeLabel($workflowAlias) . '"]';
        foreach (preg_split('/\R/', trim($diagram)) as $line) {
            $lines[] = '        ' . $line;
        }
        $lines[] = '    end';
        return ['entry' => $nodeId . '_' . $input, 'exit' => $nodeId . '_' . $output];
    }

    /**
     * Adds target details to the configured step caption.
     */
    private function buildCaption(AiWorkflowStepInterface $step) : string
    {
        switch (true) {
            case $step instanceof AgentStep:
                return $step->getCaption() . '\nAgent: ' . $step->getAgentAlias();
            case $step instanceof ActionStep:
                return $step->getCaption() . '\nAction: ' . $step->getActionAlias();
            default:
                return $step->getCaption();
        }
    }

    /**
     * Produces a Mermaid-safe structural ID.
     */
    private function nodeId(string $id) : string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $id);
    }

    /**
     * Escapes configured text before inserting it into quoted Mermaid labels.
     */
    private function escapeLabel(string $label) : string
    {
        return str_replace(
            ["\r", "\n", '"', '[', ']', '{', '}', '|'],
            [' ', '<br/>', '&quot;', '(', ')', '(', ')', '#124;'],
            $label
        );
    }
}