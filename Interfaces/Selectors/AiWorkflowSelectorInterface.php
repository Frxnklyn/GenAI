<?php
namespace axenox\GenAI\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;

/**
 * Identifies an AI workflow prototype by namespaced alias, class name or file path.
 */
interface AiWorkflowSelectorInterface extends PrototypeSelectorInterface, AliasSelectorInterface
{}
