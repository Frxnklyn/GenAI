<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\UxonObject;

/**
 * Injects the fully rendered instructions of a skill as a placeholder.
 * 
 * This concept instantiates the configured skill and renders its instructions, including all of its
 * own concepts and nested skills. This is useful to reuse a skill's instructions as background
 * information inside another skill or agent, for example for an overview that summarizes several
 * skills without duplicating their text.
 * 
 * ## Example
 * 
 * ```json
 * {
 *     "instructions": "# OVERVIEW\n\n[#notes_overview#]",
 *     "concepts": {
 *         "notes_overview": {
 *             "alias": "axenox.GenAI.SkillInstructionsConcept",
 *             "skill_alias": "my.App.Notes",
 *             "use_instruction_boundary": false
 *         }
 *     }
 * }
 * ```
 */
class SkillInstructionsConcept extends AbstractConcept
{
    private ?string $skillAlias = null;
    private ?bool $useInstructionBoundary = null;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractConcept::getOutput()
     */
    protected function getOutput(): string
    {
        if ($this->skillAlias === null) {
            throw new AiConceptConfigurationError($this, 'Missing required property `skill_alias` for SkillInstructionsConcept "' . $this->getPlaceholder() . '"');
        }

        $skillUxon = new UxonObject(['alias' => $this->skillAlias]);
        if ($this->useInstructionBoundary !== null) {
            $skillUxon->setProperty('use_instruction_boundary', $this->useInstructionBoundary);
        }

        $skill = AiFactory::createSkillFromUxon($this->getAgent(), $this->getPrompt(), $this->getPlaceholder(), $skillUxon);
        return $skill->resolve([$this->getPlaceholder()])[$this->getPlaceholder()] ?? '';
    }

    /**
     * Alias of the skill whose rendered instructions should be injected.
     * 
     * @uxon-property skill_alias
     * @uxon-type metamodel:axenox.GenAI.AI_SKILL:ALIAS_WITH_NS
     * @uxon-required true
     * 
     * @param string $alias
     * @return SkillInstructionsConcept
     */
    protected function setSkillAlias(string $alias): SkillInstructionsConcept
    {
        $this->skillAlias = $alias;
        return $this;
    }

    /**
     * Set to FALSE to render the referenced skill's instructions without its own invisible AI-only
     * boundary - use this when this concept's own placeholder is already wrapped by a surrounding
     * skill, so boundaries do not end up nested inside each other.
     * 
     * @uxon-property use_instruction_boundary
     * @uxon-type boolean
     * 
     * @param bool $value
     * @return SkillInstructionsConcept
     */
    protected function setUseInstructionBoundary(bool $value): SkillInstructionsConcept
    {
        $this->useInstructionBoundary = $value;
        return $this;
    }
}
