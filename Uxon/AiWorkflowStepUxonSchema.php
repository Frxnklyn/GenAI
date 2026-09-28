<?php
namespace axenox\GenAI\Uxon;

use axenox\GenAI\AI\WorkflowSteps\StartStep;
use axenox\GenAI\Common\Selectors\AiWorkflowStepSelector;
use axenox\GenAI\Factories\AiWorkflowStepFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Uxon\UxonSchema;

/**
 * Resolves concrete workflow step properties from each nested `alias` value.
 */
class AiWorkflowStepUxonSchema extends UxonSchema
{
    /**
     * Returns the schema's stable display name.
     */
    public static function getSchemaName() : string
    {
        return 'AI workflow step';
    }

    /** {@inheritDoc} */
    public function getPrototypeClass(UxonObject $uxon, array $path, string $rootPrototypeClass = null) : string
    {
        $class = $rootPrototypeClass ?? $this->getDefaultPrototypeClass();
        foreach ($uxon as $key => $value) {
            if (strcasecmp($key, 'alias') === 0) {
                $class = $this->getPrototypeClassFromSelector((string) $value);
                break;
            }
        }
        if (count($path) > 1) {
            return parent::getPrototypeClass($uxon, $path, $class);
        }
        return $class;
    }

    /** {@inheritDoc} */
    public function createValidationObject(
        UxonObject $uxon,
        string $prototype = null,
        UiPageInterface $page = null,
        mixed $parent = null
    ) : ?\axenox\GenAI\Interfaces\AiWorkflowStepInterface {
        if (! $uxon->hasProperty('alias') && $prototype !== null) {
            $uxon->setProperty('alias', $prototype);
        }
        return AiWorkflowStepFactory::createFromUxon($this->getWorkbench(), $uxon);
    }

    /**
     * Resolves a step selector and keeps autosuggest usable while an alias is incomplete.
     */
    protected function getPrototypeClassFromSelector(string $selectorString) : string
    {
        try {
            return AiWorkflowStepFactory::findClass(
                new AiWorkflowStepSelector($this->getWorkbench(), $selectorString)
            );
        } catch (\Throwable $error) {
            $exception = new RuntimeException(
                'Error loading AI workflow step autosuggest; using StartStep as fallback',
                null,
                $error
            );
            $this->getWorkbench()->getLogger()->logException($exception, LoggerInterface::DEBUG);
            return $this->getDefaultPrototypeClass();
        }
    }

    /** {@inheritDoc} */
    protected function getDefaultPrototypeClass() : string
    {
        return '\\' . StartStep::class;
    }
}